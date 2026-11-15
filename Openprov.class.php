<?php
namespace FreePBX\modules;

use FreePBX\modules\Openprov\Assets;
use FreePBX\modules\Openprov\Brand\Yealink;
use FreePBX\modules\Openprov\Catalog;
use FreePBX\modules\Openprov\ConfigBuilder;
use FreePBX\modules\Openprov\Notifier;
use FreePBX\modules\Openprov\Pbx;
use FreePBX\modules\Openprov\ProvisionServer;
use FreePBX\modules\Openprov\Service;
use FreePBX\modules\Openprov\Settings;
use FreePBX\modules\Openprov\Store;
use FreePBX\modules\Openprov\Vpn;
use FreePBX\modules\Openprov\Web;

spl_autoload_register(function ($class) {
	$prefix = 'FreePBX\\modules\\Openprov\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});

class Openprov extends \FreePBX_Helpers implements \BMO
{
	private ?Service $service = null;
	private ?ProvisionServer $server = null;
	private array $parts = [];

	public function __construct($freepbx = null)
	{
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		parent::__construct($freepbx);
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
	}

	// ---- wiring --------------------------------------------------------------

	private function parts(): array
	{
		if ($this->parts) {
			return $this->parts;
		}
		$settings = new Settings(
			fn($k) => $this->getConfig($k),
			fn($k, $v) => $this->setConfig($k, $v)
		);
		$store = new Store($this->db);
		$catalog = new Catalog(__DIR__ . '/models');
		\FreePBX\modules\Openprov\Features::load($store, $catalog);
		$pbx = new Pbx($this->db, $this->FreePBX);
		$vpn = new Vpn($this->db);
		$assets = new Assets($store, $this->assetDir());
		$builder = new ConfigBuilder($store, $catalog, $pbx, $vpn, $assets, $settings, ['yealink' => new Yealink()]);
		$tls = new \FreePBX\modules\Openprov\TlsService($this->bmo('Pm2'), __DIR__ . '/node/tls-service.js', $this->assetDir() . '/tls', fn() => $this->certificate());
		$web = new Web($settings, $this->webRoot(), __DIR__ . '/htdocs', [
			'service' => $tls,
			'secret' => fn() => $this->proxySecret(),
			'sysadmin' => fn() => $this->sysadminOptions(),
		]);
		return $this->parts = compact('settings', 'store', 'catalog', 'pbx', 'vpn', 'assets', 'builder', 'web', 'tls');
	}

	/** Another module's BMO object, or null when it isn't installed/enabled. */
	private function bmo(string $name): ?object
	{
		try {
			$m = $this->FreePBX->Modules->getInfo(strtolower($name));
			if (($m[strtolower($name)]['status'] ?? null) !== MODULE_STATUS_ENABLED) {
				return null;
			}
			return $this->FreePBX->$name;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** System Admin Port Management (sysadmin_options), [] without System Admin. */
	private function sysadminOptions(): array
	{
		try {
			return (array) $this->db->query("SELECT `key`, `value` FROM sysadmin_options")->fetchAll(\PDO::FETCH_KEY_PAIR);
		} catch (\Throwable $e) {
			return [];
		}
	}

	/** Certificate Manager's default certificate: {cert, key, ca?}. */
	private function certificate(): array
	{
		$files = [];
		$certman = $this->bmo('Certman');
		if ($certman) {
			$files = (array) ($certman->getDefaultCertDetails()['files'] ?? []);
		}
		$cert = $files['fullchain'] ?? $files['crt'] ?? '/etc/asterisk/keys/default.crt';
		$key = $files['key'] ?? '/etc/asterisk/keys/default.key';
		return ['cert' => $cert, 'key' => $key];
	}

	/**
	 * Shared by OpenProv's HTTPS service and htdocs/index.php: requests that
	 * carry it from loopback come from the service (phone IP in
	 * X-Forwarded-For, TLS terminated there).
	 */
	public function proxySecret(): string
	{
		$s = (string) $this->getConfig('tls_service_secret');
		if (strlen($s) < 32) {
			$s = bin2hex(random_bytes(24));
			$this->setConfig('tls_service_secret', $s);
		}
		return $s;
	}

	public function webRoot(): string
	{
		$root = '/var/www/html';
		try {
			$root = $this->FreePBX->Config->get('AMPWEBROOT') ?: $root;
		} catch (\Throwable $e) {
		}
		return rtrim($root, '/');
	}

	public function assetDir(): string
	{
		$base = '/var/lib/asterisk';
		try {
			$base = $this->FreePBX->Config->get('ASTVARLIBDIR') ?: $base;
		} catch (\Throwable $e) {
		}
		return rtrim($base, '/') . '/openprov';
	}

	public function service(): Service
	{
		if ($this->service) {
			return $this->service;
		}
		$p = $this->parts();
		return $this->service = new Service(
			$p['store'], $p['catalog'], $p['pbx'], $p['vpn'], $p['assets'], $p['settings'], $p['builder'], new Notifier(), $p['web']
		);
	}

	public function provisionServer(): ProvisionServer
	{
		if ($this->server) {
			return $this->server;
		}
		$p = $this->parts();
		return $this->server = new ProvisionServer($p['store'], $p['catalog'], $p['builder'], $p['settings'], $p['assets'], $p['vpn']);
	}

	// ---- BMO lifecycle ---------------------------------------------------------

	public function install()
	{
		$p = $this->parts();
		$p['settings']->ensureSecrets();
		$p['assets']->ensureDir();
		@chown($this->assetDir(), 'asterisk');
		@chgrp($this->assetDir(), 'asterisk');
		foreach (['/files', '/cache'] as $sub) {
			@chown($this->assetDir() . $sub, 'asterisk');
			@chgrp($this->assetDir() . $sub, 'asterisk');
		}
		if (!$p['store']->templates()) {
			$seed = json_decode((string) file_get_contents(__DIR__ . '/seeds/default-template.json'), true);
			if (is_array($seed)) {
				try {
					$tpl = $this->service()->saveTemplate($seed);
					$p['settings']->set('default_template_id', $tpl['id']);
				} catch (\Throwable $e) {
					out('OpenProv: could not seed default template: ' . $e->getMessage());
				}
			}
		}
		try {
			\FreePBX::Job()->addClass('openprov', 'sync', \FreePBX\modules\Openprov\Job::class, '*/5 * * * *');
		} catch (\Throwable $e) {
			out('OpenProv: could not register the sync job: ' . $e->getMessage());
		}
		$this->migrateSettings();
		// Phones are served at /openprov/ on FreePBX's own web server. Nothing
		// here writes Apache configuration (DESIGN.md D3).
		[, $msg] = $this->webInstall();
		out('OpenProv: ' . str_replace("\n", "\nOpenProv: ", $msg));
	}

	/**
	 * 0.1.x stored https_port/http_port (and TLS files, firewall zones) for
	 * its own vhost. The ports now name FreePBX's web ports, so the old
	 * values (8443, 8088) must not carry over into phone URLs. Runs once
	 * (again after restoring a 0.1 backup: Restore.php).
	 */
	public function migrateSettings(): void
	{
		if ($this->getConfig('web_layout') === 'path') {
			return;
		}
		$old = [];
		foreach (['https_port', 'http_port', 'tls_cert', 'tls_key', 'tls_chain'] as $k) {
			$v = $this->getConfig($k);
			if ($v !== null && $v !== false && $v !== '') {
				$old[] = "$k=$v";
				$this->setConfig($k, false); // deletes the key
			}
		}
		if ($old) {
			out('OpenProv: phones now use /openprov/ on FreePBX\'s HTTPS port (detected), or OpenProv\'s own HTTPS service when FreePBX has none. Cleared the 0.1 vhost settings: ' . implode(', ', $old));
		}
		$this->setConfig('web_layout', 'path');
	}

	/**
	 * Publish /openprov and, as root, remove the 0.1.x vhost. Used by
	 * install/upgrade, fwconsole reload (root) and fwconsole openprov web
	 * --install. Keeps a FreePBX notification up while the vhost is still
	 * there. As asterisk (GUI) it never touches Apache. Returns [ok, message].
	 */
	public function webInstall(): array
	{
		$web = $this->parts()['web'];
		[$ok, $msg] = $web->publish();
		[$ok2, $msg2] = $web->removeLegacy(); // also drops the 0.1 firewall service
		$this->legacyNotice($web->legacy()['present']);
		$state = $web->resolve();
		return [$ok && $ok2 && $state['ok'], trim($msg . "\n" . $msg2 . "\nHTTPS: " . $state['message'])];
	}

	private function legacyNotice(bool $show): void
	{
		try {
			$n = $this->FreePBX->Notifications;
			if ($show) {
				$n->add_critical('openprov', 'legacy_vhost', _('OpenProv: remove the old provisioning vhost'),
					sprintf(_('OpenProv 0.1 installed an Apache vhost (/etc/apache2/sites-enabled/openprov.conf) with its own Listen port. If another service takes that port, Apache fails to start and the FreePBX GUI goes down. OpenProv no longer uses it. Remove it as root: %s (or run fwconsole reload as root).'), Web::COMMAND),
					'', true, false);
			} else {
				$n->delete('openprov', 'legacy_vhost');
			}
		} catch (\Throwable $e) {
		}
	}

	public function uninstall()
	{
		try {
			\FreePBX::Job()->removeAll('openprov');
		} catch (\Throwable $e) {
		}
		$web = $this->parts()['web'];
		$this->parts()['tls']->stop();
		try {
			$web->removeFirewallService();
		} catch (\Throwable $e) {
		}
		$web->unpublish();
		if ($web->legacy()['present']) {
			[, $msg] = $web->removeLegacy();
			out('OpenProv: ' . $msg);
		}
		$this->legacyNotice(false);
	}

	/**
	 * Called by FreePBX during Apply Config / fwconsole reload. Re-sync phones
	 * whose configuration changed (e.g. a user's display name was edited in
	 * FreePBX or the dashboard). DESIGN.md D8.
	 */
	public function genConfig()
	{
		// fwconsole reload as root: finish an upgrade done from the GUI by
		// removing the 0.1.x vhost. Never as asterisk (GUI Apply Config).
		try {
			if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $this->parts()['web']->legacy()['present']) {
				$this->webInstall();
			} else {
				$this->parts()['web']->resolve();
			}
		} catch (\Throwable $e) {
		}
		try {
			$p = $this->parts();
			if ($p['settings']->get('resync_on_apply_config')) {
				$this->service()->syncChanged();
			}
			$days = (int) $p['settings']->get('event_retention_days');
			if ($days > 0) {
				$p['store']->pruneEvents(time() - $days * 86400);
			}
		} catch (\Throwable $e) {
			// Never break a reload.
		}
		return [];
	}

	/**
	 * fwconsole start (boot): start OpenProv's HTTPS service if that's how
	 * phones reach it. Uses the stored decision rather than re-detecting, in
	 * case Apache isn't up yet.
	 */
	public function startFreepbx($output = null)
	{
		try {
			$p = $this->parts();
			$state = $p['settings']->webState();
			if (($state['mode'] ?? '') === 'service' && !empty($state['upstream'])) {
				[$ok, $msg] = $p['tls']->ensure((int) $state['port'], (int) $state['upstream'], $this->proxySecret());
				if (is_object($output)) {
					$output->writeln(($ok ? '' : '<error>') . 'OpenProv: ' . $msg . ($ok ? '' : '</error>'));
				}
			}
		} catch (\Throwable $e) {
		}
	}

	public function stopFreepbx($output = null)
	{
		try {
			$this->parts()['tls']->stop();
		} catch (\Throwable $e) {
		}
	}

	/** Certificate Manager made another certificate the default: use it. */
	public function setDefaultCert($details = null)
	{
		try {
			$p = $this->parts();
			$state = $p['settings']->webState();
			if (($state['mode'] ?? '') === 'service' && !empty($state['upstream'])) {
				$p['tls']->ensure((int) $state['port'], (int) $state['upstream'], $this->proxySecret(), true);
			}
		} catch (\Throwable $e) {
		}
	}

	public function writeConfig($conf)
	{
	}

	// ---- GUI -------------------------------------------------------------------

	public function doConfigPageInit($page)
	{
	}

	public function showPage()
	{
		$svc = $this->service();
		return load_view(__DIR__ . '/views/main.php', [
			'info' => $svc->info(),
		]);
	}

	public function getActionBar($request)
	{
		return [];
	}

	public function ajaxRequest($req, &$setting)
	{
		return $req === 'api';
	}

	/**
	 * Single AJAX entry point: ajax.php?module=openprov&command=api&action=<name>
	 * with a JSON body {..args..}. Returns {status, data|message}.
	 */
	private static function iniBytes(string $v): int
	{
		$n = (int) $v;
		return match (strtolower(substr(trim($v), -1))) {
			'g' => $n << 30,
			'm' => $n << 20,
			'k' => $n << 10,
			default => $n,
		};
	}

	public function ajaxHandler()
	{
		$action = (string) ($_REQUEST['action'] ?? '');
		$args = [];
		// A request over post_max_size arrives with every field dropped; say so
		// instead of failing on a "missing" argument.
		$len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
		if ($len > 0 && !$_POST && !$_FILES && $len > self::iniBytes((string) ini_get('post_max_size'))) {
			return ['status' => false, 'message' => 'Upload too large for PHP (' . round($len / 1048576, 1) . ' MB > post_max_size ' . ini_get('post_max_size') . ').'];
		}
		$raw = file_get_contents('php://input');
		if ($action === 'upload.chunk') {
			// Raw bytes of one piece of a file; parameters in the query string.
			$args = ['id' => (string) ($_GET['id'] ?? ''), 'offset' => (int) ($_GET['offset'] ?? -1), '_raw' => (string) $raw];
		} elseif ($raw !== '' && $raw !== false && str_starts_with(trim($raw), '{')) {
			$args = json_decode($raw, true) ?: [];
		} else {
			$args = $_POST;
		}
		// Server-side paths are for the CLI only: over AJAX they would let a
		// GUI session publish any file the web server can read.
		unset($args['path']);
		try {
			$data = (new \FreePBX\modules\Openprov\Api($this->service()))->call($action, $args, $_FILES);
			return ['status' => true, 'data' => $data];
		} catch (\InvalidArgumentException $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		} catch (\Throwable $e) {
			return ['status' => false, 'message' => 'Error: ' . $e->getMessage()];
		}
	}
}
