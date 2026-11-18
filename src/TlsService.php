<?php
namespace FreePBX\modules\Openprov;

/**
 * OpenProv's own HTTPS service (node/tls-service.js under FreePBX's pm2
 * module), used only when FreePBX's web server has no HTTPS (DESIGN.md D3).
 * It is a separate process on its own port: a port conflict stops this
 * service only, never Apache. Nothing here runs as root or touches Apache.
 */
class TlsService
{
	public const NAME = 'openprov-tls';

	/** @var callable(): array{cert:string,key:string,ca?:string} */
	private $certs;
	/** @var callable(string): array{0:int,1:string} */
	private $run;
	private float $wait;

	/**
	 * $pm2: FreePBX's Pm2 BMO object (start/getStatus/delete), or null when
	 * the pm2 module isn't installed. $dir: asterisk-writable directory for
	 * the service's config and status files.
	 */
	public function __construct(private ?object $pm2, private string $script, private string $dir, callable $certs, ?callable $run = null, float $wait = 5.0)
	{
		$this->certs = $certs;
		$this->run = $run ?? function (string $cmd): array {
			$out = [];
			exec($cmd . ' 2>&1', $out, $rc);
			return [$rc, implode("\n", $out)];
		};
		$this->wait = $wait;
	}

	public function configFile(): string
	{
		return $this->dir . '/tls-service.json';
	}

	public function statusFile(): string
	{
		return $this->dir . '/tls-service.status.json';
	}

	public function online(): bool
	{
		if (!$this->pm2) {
			return false;
		}
		try {
			$s = $this->pm2->getStatus(self::NAME);
		} catch (\Throwable $e) {
			return false;
		}
		return is_array($s) && (($s['pm2_env']['status'] ?? '') === 'online');
	}

	/** What the service last reported: {ok, error, port, pid, at} or []. */
	public function reported(): array
	{
		$v = is_readable($this->statusFile()) ? json_decode((string) file_get_contents($this->statusFile()), true) : null;
		return is_array($v) ? $v : [];
	}

	/** Is something (other than us) listening on $port? */
	public function portInUse(int $port): bool
	{
		[$rc, $out] = ($this->run)('ss -Htln ' . escapeshellarg("sport = :$port"));
		return $rc === 0 && trim($out) !== '';
	}

	/**
	 * Run the service on $port, forwarding to Apache's HTTP port $upstream.
	 * Restarts it when its configuration changed. Returns [ok, message].
	 */
	public function ensure(int $port, int $upstream, string $secret, bool $restart = false): array
	{
		if (!$this->pm2) {
			return [false, 'FreePBX\'s web server has no HTTPS port, and OpenProv\'s HTTPS service needs the pm2 module (fwconsole ma downloadinstall pm2). Or enable HTTPS in System Admin → Port Management.'];
		}
		try {
			$c = ($this->certs)();
		} catch (\Throwable $e) {
			return [false, 'No certificate for OpenProv\'s HTTPS service: ' . $e->getMessage()];
		}
		foreach (['cert', 'key'] as $k) {
			if (empty($c[$k]) || !is_readable($c[$k])) {
				return [false, "Certificate Manager's default certificate $k is not readable" . (empty($c[$k]) ? '' : " ({$c[$k]})")];
			}
		}
		$cfg = json_encode([
			'port' => $port,
			'upstreamPort' => $upstream,
			'secret' => $secret,
			'cert' => $c['cert'],
			'key' => $c['key'],
			'ca' => $c['ca'] ?? null,
			'statusFile' => $this->statusFile(),
			// A module upgrade that changes the service's code restarts it.
			'script' => is_file($this->script) ? substr((string) hash_file('sha256', $this->script), 0, 16) : '',
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$old = is_file($this->configFile()) ? (string) file_get_contents($this->configFile()) : '';
		$changed = $old !== $cfg;
		$oldPort = (int) (json_decode($old, true)['port'] ?? 0);
		if ($changed) {
			if (!is_dir($this->dir)) {
				@mkdir($this->dir, 0750, true);
			}
			if (@file_put_contents($this->configFile(), $cfg) === false) {
				return [false, 'Cannot write ' . $this->configFile()];
			}
			@chmod($this->configFile(), 0600); // holds the proxy secret
		}
		// The service runs as asterisk (pm2): when we run as root (fwconsole),
		// the directory and config must still be asterisk's.
		$this->ownByAsterisk([$this->dir, $this->configFile()]);
		$online = $this->online();
		if ($online && !$changed && !$restart) {
			return $this->outcome($port);
		}
		if ((!$online || $oldPort !== $port) && $this->portInUse($port)) {
			// Never start into a conflict; report it (GUI, fwconsole openprov web --check).
			return [false, "Port $port is already in use by another process (see: ss -tlnp 'sport = :$port'). Set service_port to a free port."];
		}
		@unlink($this->statusFile());
		try {
			$this->pm2->start(self::NAME, $this->script, ['OPENPROV_TLS_CONFIG' => $this->configFile()], true);
		} catch (\Throwable $e) {
			return [false, 'pm2 could not start ' . self::NAME . ': ' . $e->getMessage()];
		}
		// Wait for the service to report whether it could bind.
		for ($t = 0.0; $t < $this->wait && !$this->reported(); $t += 0.25) {
			usleep(250000);
		}
		return $this->outcome($port);
	}

	private function outcome(int $port): array
	{
		$r = $this->reported();
		if (!$r) {
			return [false, self::NAME . ' has not reported yet; see the asterisk log directory, ' . self::NAME . '_err.log'];
		}
		if (empty($r['ok'])) {
			return [false, 'OpenProv HTTPS service: ' . ($r['error'] ?? 'not listening')];
		}
		return [true, "OpenProv's HTTPS service is listening on port $port"];
	}

	private function ownByAsterisk(array $paths): void
	{
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			return;
		}
		foreach ($paths as $p) {
			if (file_exists($p)) {
				@chown($p, 'asterisk');
				@chgrp($p, 'asterisk');
			}
		}
	}

	public function stop(): void
	{
		if (!$this->pm2) {
			return;
		}
		try {
			if ($this->pm2->getStatus(self::NAME)) {
				$this->pm2->delete(self::NAME);
			}
		} catch (\Throwable $e) {
		}
		@unlink($this->statusFile());
	}
}
