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
use FreePBX\modules\Openprov\WebServer;

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
		$web = new WebServer($settings, __DIR__ . '/web');
		return $this->parts = compact('settings', 'store', 'catalog', 'pbx', 'vpn', 'assets', 'builder', 'web');
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
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			[$ok, $msg] = $p['web']->install();
			out('OpenProv web server: ' . $msg);
		} else {
			out('OpenProv: run "fwconsole openprov web --install" as root to enable the provisioning web server.');
		}
	}

	public function uninstall()
	{
		try {
			\FreePBX::Job()->removeAll('openprov');
		} catch (\Throwable $e) {
		}
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->parts()['web']->uninstall();
		}
	}

	/**
	 * Called by FreePBX during Apply Config / fwconsole reload. Re-sync phones
	 * whose configuration changed (e.g. a user's display name was edited in
	 * FreePBX or the dashboard). DESIGN.md D8.
	 */
	public function genConfig()
	{
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
