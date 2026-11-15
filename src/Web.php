<?php
namespace FreePBX\modules\Openprov;

/**
 * Where phones reach OpenProv (DESIGN.md D3): /openprov/ on the web server
 * FreePBX already runs, on the ports Apache already listens on (80/443, or
 * whatever System Admin Port Management set). <webroot>/openprov is a symlink
 * to the module's htdocs/ directory; htdocs/.htaccess routes every request to
 * htdocs/index.php.
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

	private string $apacheDir;
	/** @var callable(string): array{0:int,1:string} */
	private $run;
	private bool $root;
	private float $settle;

	/**
	 * $opts (tests): apache_dir, run (fn(cmd) => [rc, output]), root (bool),
	 * settle (seconds to wait after a reload before trusting is-active).
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
			'legacy' => $this->legacy(),
			'command' => self::COMMAND,
		];
	}

	/**
	 * Fetch PATH/_ping through Apache the way a phone would (same scheme and
	 * port as the phone URLs, certificate not verified: this checks routing,
	 * not trust). Returns {ok, url, status, message}.
	 */
	public function check(string $host): array
	{
		$scheme = (string) $this->settings->get('phone_scheme');
		$port = (int) $this->settings->get($scheme === 'https' ? 'https_port' : 'http_port');
		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = "[$host]";
		}
		$url = "$scheme://$host:$port" . self::PATH . self::PING;
		if (!function_exists('curl_init')) {
			return ['ok' => false, 'url' => $url, 'status' => 0, 'message' => 'php-curl is not available'];
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => false,
		]);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$ok = $code === 200 && $body === self::PING_BODY;
		$msg = match (true) {
			$ok => 'Apache serves OpenProv at ' . self::PATH . '/',
			$body === false => "No answer ($err). Is $port the port FreePBX's web server uses for $scheme? Set {$scheme}_port to match System Admin Port Management.",
			$code === 404 => 'HTTP 404: ' . self::PATH . ' is not published, or Apache ignores htdocs/.htaccess (FreePBX needs AllowOverride All on its web root, and mod_rewrite)',
			default => "HTTP $code from $url",
		};
		return ['ok' => $ok, 'url' => $url, 'status' => $code, 'message' => $msg];
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
			$ok = true;
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
	 * 0.1.x opened its port with a FreePBX firewall custom service. Phones now
	 * use FreePBX's own web ports, which the firewall already covers (its
	 * Web Management service), so the custom service only leaves 8443 open.
	 */
	public function removeFirewallService(): ?string
	{
		if (!class_exists('\FreePBX', false)) {
			return null;
		}
		try {
			$fw = \FreePBX::Firewall();
		} catch (\Throwable $e) {
			return null;
		}
		$id = null;
		foreach ((array) $fw->getAllCustomServices() as $cid => $svc) {
			if (is_array($svc) && ($svc['name'] ?? '') === self::FIREWALL_SERVICE) {
				$id = $svc['custid'] ?? $cid;
			}
		}
		if ($id === null) {
			return null;
		}
		// Closing it in every zone is the documented API; delete it too where
		// this firewall version has a method for that.
		$fw->setCustomServiceZones($id, []);
		foreach (['deleteCustomService', 'removeCustomService', 'delCustomService'] as $m) {
			if (method_exists($fw, $m)) {
				$fw->$m($id);
				break;
			}
		}
		$restarted = '';
		if ($this->root && method_exists($fw, 'isEnabled') && $fw->isEnabled()) {
			[$rc] = ($this->run)('fwconsole firewall restart');
			$restarted = $rc === 0 ? ' (firewall restarted)' : ' (firewall restart failed; run fwconsole firewall restart)';
		}
		return 'Firewall: removed the "' . self::FIREWALL_SERVICE . '" service' . $restarted;
	}
}
