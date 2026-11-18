<?php
namespace FreePBX\modules\Openprov;

/**
 * Where phones reach OpenProv (DESIGN.md D3): /openprov/ on the web server
 * FreePBX already runs. <webroot>/openprov is a symlink to the module's
 * htdocs/ directory; htdocs/.htaccess routes every request to
 * htdocs/index.php. For HTTPS, resolve() picks FreePBX's own HTTPS port
 * (System Admin Port Management, or Apache's 443) when it serves /openprov,
 * and otherwise OpenProv's HTTPS service (TlsService, under pm2) on its own
 * port, which forwards to /openprov over loopback HTTP.
 *
 * OpenProv never writes Apache configuration, adds Listen directives or
 * reloads Apache. 0.1.x did (its own vhost on 8443), and on 2026-11-15 that
 * vhost took Apache down when Sangoma Connect already held 8443. The one
 * Apache action left is removing that old vhost: it only makes Apache's
 * configuration smaller, it runs only as root from fwconsole (module
 * install/upgrade, fwconsole reload, fwconsole openprov web --install), never
 * from the web GUI, and it brings Apache back if Apache is down.
 */
class Web
{
	public const PATH = '/openprov';
	/** Answered by htdocs/index.php once FreePBX has bootstrapped. */
	public const PING = '/_ping';
	public const PING_BODY = "openprov ok\n";
	public const FIREWALL_SERVICE = 'OpenProv provisioning';
	public const COMMAND = 'fwconsole openprov web --install';
	/** Header carrying pingToken(): /_ping answers nobody else. */
	public const PING_HEADER = 'X-OpenProv-Ping';

	public static function pingToken(string $secret): string
	{
		return hash_hmac('sha256', 'openprov-ping', $secret);
	}

	private string $apacheDir;
	/** @var callable(string): array{0:int,1:string} */
	private $run;
	private bool $root;
	private float $settle;
	/** @var callable(): array<string,int|string> System Admin's sysadmin_options */
	private $sysadmin;
	/** @var callable(string): bool Does this URL answer PING_BODY? */
	private $probe;
	/** @var callable(): string */
	private $secret;
	private ?TlsService $service;
	/** @var callable(): ?object FreePBX's Firewall BMO */
	private $firewall;

	/**
	 * $opts: apache_dir, run (fn(cmd) => [rc, output]), root (bool), settle
	 * (seconds to wait after a reload before trusting is-active), sysadmin,
	 * probe, secret, service, firewall (see the properties). Defaults suit
	 * tests; Openprov.class.php passes the real ones.
	 */
	public function __construct(private Settings $settings, private string $webRoot, private string $htdocs, array $opts = [])
	{
		$this->apacheDir = rtrim($opts['apache_dir'] ?? '/etc/apache2', '/');
		$this->run = $opts['run'] ?? function (string $cmd): array {
			$out = [];
			exec($cmd . ' 2>&1', $out, $rc);
			return [$rc, implode("\n", $out)];
		};
		$this->root = $opts['root'] ?? (function_exists('posix_geteuid') && posix_geteuid() === 0);
		$this->settle = (float) ($opts['settle'] ?? 2.0);
		$this->sysadmin = $opts['sysadmin'] ?? fn() => [];
		$this->probe = $opts['probe'] ?? fn(string $url) => $this->fetch($url)['ok'];
		$this->secret = $opts['secret'] ?? fn() => '';
		$this->service = $opts['service'] ?? null;
		$this->firewall = $opts['firewall'] ?? function () {
			if (!class_exists('\FreePBX', false)) {
				return null;
			}
			try {
				return \FreePBX::Firewall();
			} catch (\Throwable $e) {
				return null;
			}
		};
	}

	// ---- the /openprov path -------------------------------------------------

	public function link(): string
	{
		return rtrim($this->webRoot, '/') . self::PATH;
	}

	public function published(): bool
	{
		$link = $this->link();
		return is_link($link) && realpath($link) !== false && realpath($link) === realpath($this->htdocs);
	}

	/** A symlink to this module's htdocs, or to an OpenProv htdocs that moved (dangling). */
	private function ours(string $link): bool
	{
		if (!is_link($link)) {
			return false;
		}
		$target = rtrim((string) readlink($link), '/');
		return $target === rtrim($this->htdocs, '/') || realpath($link) === realpath($this->htdocs)
			|| preg_match('#/openprov/htdocs$#', $target) === 1;
	}

	/**
	 * Create <webroot>/openprov -> htdocs. Needs write access to the web root
	 * only (asterisk owns it), so it works from the GUI's module admin too.
	 * Returns [ok, message].
	 */
	public function publish(): array
	{
		$link = $this->link();
		if ($this->published()) {
			return [true, 'Phones are served at ' . self::PATH . '/ on FreePBX\'s web server'];
		}
		if ($this->ours($link)) {
			@unlink($link); // stale (module directory moved)
		} elseif (file_exists($link) || is_link($link)) {
			return [false, "$link exists and isn't OpenProv's; move it away, then run " . self::COMMAND];
		}
		if (!is_dir($this->htdocs)) {
			return [false, "Missing {$this->htdocs}; reinstall the module"];
		}
		if (!@symlink($this->htdocs, $link)) {
			return [false, "Could not create $link (check permissions on {$this->webRoot})"];
		}
		if ($this->root && function_exists('lchown')) {
			@lchown($link, 'asterisk');
			@lchgrp($link, 'asterisk');
		}
		return [true, 'Phones are served at ' . self::PATH . '/ on FreePBX\'s web server'];
	}

	public function unpublish(): void
	{
		if ($this->ours($this->link())) {
			@unlink($this->link());
		}
	}

	public function status(): array
	{
		return [
			'path' => self::PATH . '/',
			'published' => $this->published(),
			'link' => $this->link(),
			'https' => $this->settings->webState(),
			'service' => $this->service ? ['online' => $this->service->online(), 'reported' => $this->service->reported()] : null,
			'legacy' => $this->legacy(),
			'command' => self::COMMAND,
		];
	}

	// ---- how phones reach /openprov over HTTPS --------------------------------

	/** System Admin Port Management ports: [https candidates, http candidates]. */
	private function sysadminPorts(): array
	{
		$ssl = $plain = [];
		foreach ((array) ($this->sysadmin)() as $k => $v) {
			$v = (int) $v;
			if ($v < 1 || $v > 65535 || !preg_match('/^(ssl)?(acp|ucp|hpro|restapi|restapps|xml)$/', (string) $k, $m)) {
				continue;
			}
			if ($m[1] === 'ssl') {
				$ssl[$m[2]] = $v;
			} else {
				$plain[$m[2]] = $v;
			}
		}
		// Prefer System Admin's provisioning port, then the admin GUI's.
		$order = fn(array $a) => array_values(array_unique(array_merge(
			array_values(array_intersect_key($a, ['hpro' => 1])),
			array_values(array_intersect_key($a, ['acp' => 1])),
			array_values($a)
		)));
		return [$order($ssl), $order($plain)];
	}

	private function ping(string $scheme, int $port): bool
	{
		return ($this->probe)("$scheme://127.0.0.1:$port" . self::PATH . self::PING);
	}

	/**
	 * Work out where phones reach /openprov, start or stop OpenProv's HTTPS
	 * service to match, and store the result (Settings::webState(), which
	 * phone URLs use). Called on install, Apply Config, settings changes,
	 * fwconsole openprov web --install/--check and the GUI's Test button.
	 */
	public function resolve(): array
	{
		$state = $this->decide();
		$state['at'] = time();
		$this->settings->setWebState($state);
		return $state;
	}

	private function decide(): array
	{
		$mode = (string) $this->settings->get('https_mode');
		[$ssl, $plain] = $this->sysadminPorts();
		$http = array_values(array_unique(array_merge($plain, [80, (int) $this->settings->get('http_port')])));

		if ($this->settings->get('phone_scheme') === 'http') {
			$this->stopService();
			$port = (int) $this->settings->get('http_port');
			$ok = $this->ping('http', $port);
			return ['mode' => 'http', 'port' => $port, 'ok' => $ok, 'message' => $ok ? "Plain HTTP on FreePBX's web server, port $port (lab only)" : "Nothing answers /openprov over HTTP on port $port (http_port)"];
		}

		if ($mode !== 'service') {
			$explicit = (int) $this->settings->get('https_port');
			foreach ($explicit > 0 ? [$explicit] : array_values(array_unique(array_merge($ssl, [443]))) as $port) {
				if ($this->ping('https', $port)) {
					$this->stopService();
					return ['mode' => 'freepbx', 'port' => $port, 'ok' => true, 'message' => "FreePBX's web server, HTTPS port $port"];
				}
			}
			if ($mode === 'freepbx' || $explicit > 0) {
				$this->stopService();
				$port = $explicit ?: 443;
				return ['mode' => 'freepbx', 'port' => $port, 'ok' => false, 'message' => "FreePBX's web server doesn't serve /openprov over HTTPS on port $port. Enable HTTPS in System Admin → Port Management (then set https_port, or 0 to detect), or set https_mode to auto or service."];
			}
		}

		// No HTTPS on FreePBX's web server: OpenProv's own HTTPS service.
		$port = (int) $this->settings->get('service_port');
		$upstream = null;
		foreach ($http as $p) {
			if ($p > 0 && $this->ping('http', $p)) {
				$upstream = $p;
				break;
			}
		}
		if ($upstream === null) {
			return ['mode' => 'service', 'port' => $port, 'ok' => false, 'message' => 'OpenProv\'s HTTPS service forwards to /openprov on FreePBX\'s HTTP port, but none answered (tried ' . implode(', ', array_filter($http)) . '). Run ' . self::COMMAND . ', and check fwconsole openprov web --check.'];
		}
		if (!$this->service) {
			return ['mode' => 'service', 'port' => $port, 'ok' => false, 'message' => 'OpenProv\'s HTTPS service is not available'];
		}
		[$ok, $msg] = $this->service->ensure($port, $upstream, ($this->secret)());
		$fw = '';
		try {
			$fw = (string) $this->ensureFirewallService($port);
		} catch (\Throwable $e) {
			$fw = 'Firewall: could not add the service (' . $e->getMessage() . ')';
		}
		if ($ok && !$this->ping('https', $port)) {
			$ok = false;
			$msg = "OpenProv's HTTPS service is up on port $port but /openprov doesn't answer through it";
		}
		return ['mode' => 'service', 'port' => $port, 'upstream' => $upstream, 'ok' => $ok, 'message' => rtrim($msg, '.') . ($fw !== '' ? ". $fw" : '')];
	}

	private function stopService(): void
	{
		$this->service?->stop();
		try {
			$this->removeFirewallService();
		} catch (\Throwable $e) {
		}
	}

	/** Phone-side view of the current state: fetch /openprov/_ping on the LAN address. */
	public function check(string $host): array
	{
		$state = $this->settings->webState();
		$scheme = ($state['mode'] ?? '') === 'http' ? 'http' : 'https';
		$port = (int) ($state['port'] ?? 0) ?: ($scheme === 'https' ? 443 : 80);
		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = "[$host]";
		}
		$url = "$scheme://$host:$port" . self::PATH . self::PING;
		$r = $this->fetch($url);
		$where = ($state['mode'] ?? '') === 'service' ? "OpenProv's HTTPS service" : "FreePBX's web server";
		$msg = match (true) {
			!$r['ok'] && isset($state['ok']) && !$state['ok'] => (string) $state['message'],
			$r['ok'] => "Phones reach OpenProv at $scheme://$host" . ($port === ($scheme === 'https' ? 443 : 80) ? '' : ":$port") . self::PATH . " ($where)",
			$r['body'] === false => "No answer from $url ({$r['error']})",
			$r['status'] === 404 => "HTTP 404 from $url: " . self::PATH . ' is not published, or Apache ignores htdocs/.htaccess (FreePBX needs AllowOverride All on its web root, and mod_rewrite)',
			default => "HTTP {$r['status']} from $url",
		};
		return ['ok' => $r['ok'], 'url' => $url, 'status' => $r['status'], 'message' => $msg, 'https' => $state];
	}

	/** GET a URL without verifying the certificate (routing, not trust). */
	private function fetch(string $url): array
	{
		if (!function_exists('curl_init')) {
			return ['ok' => false, 'status' => 0, 'body' => false, 'error' => 'php-curl is not available'];
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER => [self::PING_HEADER . ': ' . self::pingToken((string) ($this->secret)())],
		]);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		return ['ok' => $code === 200 && $body === self::PING_BODY, 'status' => $code, 'body' => $body, 'error' => $err];
	}

	/** Open OpenProv's HTTPS service port in the zones from firewall_zones. */
	public function ensureFirewallService(int $port): ?string
	{
		$fw = ($this->firewall)();
		if (!$fw) {
			return null;
		}
		$zones = array_values(array_intersect(
			array_filter(array_map('trim', explode(',', (string) $this->settings->get('firewall_zones')))),
			['trusted', 'internal', 'other', 'external']
		));
		$id = $this->firewallServiceId($fw);
		if ($id === null) {
			$fw->addCustomService(self::FIREWALL_SERVICE, 'tcp', (string) $port);
			$id = $this->firewallServiceId($fw);
		} else {
			$fw->editCustomService($id, self::FIREWALL_SERVICE, 'tcp', (string) $port);
		}
		if ($id !== null) {
			$fw->setCustomServiceZones($id, $zones);
		}
		return "Firewall: TCP $port open for zones " . (implode(', ', $zones) ?: 'none');
	}

	private function firewallServiceId(object $fw): mixed
	{
		foreach ((array) $fw->getAllCustomServices() as $cid => $svc) {
			if (is_array($svc) && ($svc['name'] ?? '') === self::FIREWALL_SERVICE) {
				return $svc['custid'] ?? $cid;
			}
		}
		return null;
	}

	// ---- the 0.1.x vhost ----------------------------------------------------

	public function legacyConf(): string
	{
		return $this->apacheDir . '/sites-available/openprov.conf';
	}

	public function legacyLink(): string
	{
		return $this->apacheDir . '/sites-enabled/openprov.conf';
	}

	/** The 0.1.x vhost, if any is left: {present, enabled, ports}. */
	public function legacy(): array
	{
		$enabled = is_link($this->legacyLink()) || is_file($this->legacyLink());
		$present = $enabled || is_file($this->legacyConf());
		$ports = [];
		foreach ([$this->legacyConf(), $this->legacyLink()] as $f) {
			if (is_readable($f) && preg_match_all('/^\s*Listen\s+(?:\S+:)?(\d+)/mi', (string) file_get_contents($f), $m)) {
				$ports = array_merge($ports, array_map('intval', $m[1]));
			}
		}
		return ['present' => $present, 'enabled' => $enabled, 'ports' => array_values(array_unique($ports))];
	}

	/**
	 * Remove the 0.1.x vhost (a2dissite + delete), then make sure Apache runs
	 * on what's left. Root only. Returns [ok, message].
	 */
	public function removeLegacy(): array
	{
		$legacy = $this->legacy();
		$msgs = [];
		if ($legacy['present']) {
			if (!$this->root) {
				return [false, 'The OpenProv 0.1 Apache vhost (' . $this->legacyConf() . ') is still installed. Remove it as root: ' . self::COMMAND];
			}
			foreach ([$this->legacyLink(), $this->legacyConf()] as $f) {
				if ((is_link($f) || is_file($f)) && !@unlink($f)) {
					return [false, "Could not delete $f"];
				}
			}
			$msgs[] = 'Removed the old provisioning vhost (port ' . (implode(', ', $legacy['ports']) ?: '?') . ')';
			[$ok, $m] = $this->reloadApache();
			$msgs[] = $m;
		} else {
			// Nothing of 0.1's left; resolve() owns the firewall service now.
			return [true, ''];
		}
		try {
			$fw = $this->removeFirewallService();
			if ($fw !== null) {
				$msgs[] = $fw;
			}
		} catch (\Throwable $e) {
			$msgs[] = 'Firewall: could not remove the "' . self::FIREWALL_SERVICE . '" service (' . $e->getMessage() . '); remove it in Connectivity → Firewall → Services';
		}
		return [$ok, implode("\n", $msgs)];
	}

	private function apacheActive(): bool
	{
		return ($this->run)('systemctl is-active --quiet apache2')[0] === 0;
	}

	/**
	 * After our vhost is gone: reload if Apache runs, start it if it doesn't
	 * (the 2026-11-15 crash loop). Never act on a config that fails
	 * configtest; with our file gone, that's someone else's config and a
	 * reload would only make things worse.
	 */
	private function reloadApache(): array
	{
		[$rc, $out] = ($this->run)('apache2ctl configtest');
		if ($rc !== 0) {
			return [false, "Did not reload Apache: apache2ctl configtest fails on the remaining configuration (not OpenProv's):\n$out"];
		}
		if ($this->apacheActive()) {
			($this->run)('systemctl reload apache2');
		} else {
			($this->run)('systemctl reset-failed apache2');
			($this->run)('systemctl restart apache2');
		}
		// A graceful restart that can't bind fails after systemctl returns:
		// give it time, then check Apache stayed up. One restart if not.
		$this->pause();
		$up = $this->apacheActive();
		if (!$up) {
			($this->run)('systemctl reset-failed apache2');
			($this->run)('systemctl restart apache2');
			$this->pause();
			$up = $this->apacheActive();
		}
		if ($up) {
			return [true, 'Apache reloaded and running'];
		}
		[, $status] = ($this->run)('systemctl --no-pager --lines=5 status apache2');
		return [false, "Apache is not running. Check: systemctl status apache2\n$status"];
	}

	private function pause(): void
	{
		if ($this->settle > 0) {
			usleep((int) ($this->settle * 1e6));
		}
	}

	/**
	 * Drop the "OpenProv provisioning" firewall custom service: 0.1.x's 8443,
	 * or the HTTPS service's port once FreePBX's own HTTPS is used (whose
	 * ports the firewall's Web Management service already covers).
	 */
	public function removeFirewallService(): ?string
	{
		$fw = ($this->firewall)();
		if (!$fw) {
			return null;
		}
		$id = $this->firewallServiceId($fw);
		if ($id === null) {
			return null;
		}
		$fw->setCustomServiceZones($id, []);
		$fw->deleteCustomService($id);
		return 'Firewall: removed the "' . self::FIREWALL_SERVICE . '" service';
	}
}
