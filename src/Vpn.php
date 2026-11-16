<?php
namespace FreePBX\modules\Openprov;

use PDO;

/**
 * System Admin (sysadmin Pro) OpenVPN integration. Reads the clients System
 * Admin already generated and packs them as a Yealink OpenVPN tar:
 *   vpn.cnf
 *   keys/ca.crt, keys/client.crt, keys/client.key
 * with paths under /config/openvpn/keys/ as the phone expects.
 */
class Vpn
{
	public function __construct(private PDO $db, private string $dir = '/etc/openvpn')
	{
	}

	public function serverEnabled(): bool
	{
		return $this->option('vpnserver_enabled') === '1';
	}

	/** id => ['id','description','enabled','serverremote','remote'] */
	public function clients(): array
	{
		$raw = $this->option('vpnclients');
		$list = $raw ? json_decode($raw, true) : [];
		$out = [];
		foreach ((array) $list as $id => $c) {
			$out[(int) $id] = [
				'id' => (int) $id,
				'description' => (string) ($c['description'] ?? ''),
				'enabled' => ($c['enabled'] ?? '0') === '1',
				'serverremote' => (string) ($c['serverremote'] ?? ''),
				'remote' => (string) ($c['remote'] ?? ''),
				'useserverremote' => ($c['useserverremote'] ?? '1') === '1',
				'hasFiles' => is_readable($this->clientFile((int) $id, 'key')),
				// Certificate lifetime: System Admin generates these with
				// Certificate Manager's validity (Advanced Settings,
				// CERT_DAYS_VAL); OpenProv only reads them.
				'expires' => $this->certExpiry($this->clientFile((int) $id, 'crt')),
				'caExpires' => $this->certExpiry($this->dir . '/sysadmin_ca.crt'),
				// The server checks this CRL (crl-verify): when it passes its
				// nextUpdate, OpenVPN refuses every client.
				'crlExpires' => $this->crlExpiry(),
			];
		}
		ksort($out);
		return $out;
	}

	/**
	 * Connected clients from the OpenVPN status file (status-version 3):
	 * client id => ['address' => tunnel IP, 'real' => ip:port, 'since' => ts].
	 */
	public function connectedClients(): array
	{
		$out = [];
		foreach (glob($this->dir . '/sysadmin_server*-status.log') ?: [] as $file) {
			foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
				$f = explode("\t", $line);
				if (($f[0] ?? '') === 'CLIENT_LIST' && preg_match('/^client(\d+)$/', $f[1] ?? '', $m)) {
					$out[(int) $m[1]] = ['address' => $f[3] ?? '', 'real' => $f[2] ?? '', 'since' => (int) ($f[8] ?? 0)];
				}
			}
		}
		return $out;
	}

	/** Tunnel address of the server (first host of vpnserver_range). */
	public function serverTunnelAddress(): ?string
	{
		$range = $this->option('vpnserver_range');
		if (!$range || !preg_match('#^(\d+\.\d+\.\d+)\.\d+#', $range, $m)) {
			return null;
		}
		return $m[1] . '.1';
	}

	/** The tunnel subnet as [network, prefix], from vpnserver_range ("10.8.0.0/255.255.255.0"). */
	public function tunnelNetwork(): ?array
	{
		$range = (string) $this->option('vpnserver_range');
		if (!preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+\.\d+\.\d+\.\d+|\d{1,2})$#', $range, $m)) {
			return null;
		}
		$prefix = str_contains($m[2], '.') ? strlen(str_replace('0', '', decbin(ip2long($m[2])))) : (int) $m[2];
		return [$m[1], $prefix];
	}

	/** Did a request from $ip come through the VPN tunnel? */
	public function isTunnelAddress(string $ip): bool
	{
		$n = $this->tunnelNetwork();
		return $n !== null && $ip !== '' && Util::ipAllowed($ip, [$n[0] . '/' . $n[1]]);
	}

	public function port(): int
	{
		return (int) ($this->option('vpnserver_port') ?: 1194);
	}

	/**
	 * The client linked to a userman user: an explicit vpn_allowed list wins,
	 * else the auto-created vpn_client link.
	 */
	public function clientForUser(int $uid): ?int
	{
		$st = $this->db->prepare("SELECT `key`, val FROM userman_users_settings WHERE uid = ? AND module = 'sysadmin' AND `key` IN ('vpn_allowed', 'vpn_client')");
		$st->execute([$uid]);
		$kv = [];
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$kv[$r['key']] = $r['val'];
		}
		if (!empty($kv['vpn_allowed'])) {
			$allowed = json_decode($kv['vpn_allowed'], true);
			if (is_array($allowed) && $allowed) {
				return (int) reset($allowed);
			}
		}
		return isset($kv['vpn_client']) && $kv['vpn_client'] !== '' ? (int) $kv['vpn_client'] : null;
	}

	/**
	 * SHA-256 of System Admin's VPN CA. It changes only when the whole VPN
	 * server is rebuilt (new CA), which cuts every tunnel at once.
	 */
	public function caFingerprint(): ?string
	{
		$pem = @file_get_contents($this->dir . '/sysadmin_ca.crt');
		if ($pem === false) {
			return null;
		}
		$fp = @openssl_x509_fingerprint($pem, 'sha256');
		return $fp === false ? null : $fp;
	}

	/** nextUpdate of System Admin's CRL (unix time), or null. */
	public function crlExpiry(): ?int
	{
		$out = [];
		exec('openssl crl -in ' . escapeshellarg($this->dir . '/sysadmin_crl.pem') . ' -noout -nextupdate 2>/dev/null', $out, $rc);
		if ($rc !== 0 || !preg_match('/nextUpdate=(.+)$/', implode("\n", $out), $m)) {
			return null;
		}
		$t = strtotime(trim($m[1]));
		return $t === false ? null : $t;
	}

	/** notAfter of a PEM certificate file (unix time), or null. */
	public function certExpiry(string $file): ?int
	{
		$pem = @file_get_contents($file);
		if ($pem === false) {
			return null;
		}
		$x = @openssl_x509_parse($pem);
		return is_array($x) && isset($x['validTo_time_t']) ? (int) $x['validTo_time_t'] : null;
	}

	/**
	 * Changes whenever what yealinkTar() would hand the phone changes: the
	 * CA, the client certificate or key (a VPN rebuild or a regenerated
	 * client), or the server address/port. Put in the profile URL so the
	 * phone's config changes, OpenProv's change detection sends check-sync,
	 * and the phone downloads the new profile instead of keeping a dead one.
	 */
	public function profileVersion(int $clientId): ?string
	{
		$parts = [];
		foreach ([$this->dir . '/sysadmin_ca.crt', $this->clientFile($clientId, 'crt'), $this->clientFile($clientId, 'key')] as $f) {
			$v = @file_get_contents($f);
			if ($v === false) {
				return null;
			}
			$parts[] = $v;
		}
		$c = $this->clients()[$clientId] ?? [];
		$parts[] = ($c['serverremote'] ?? '') . '|' . $this->option('vpnserver_serverremote') . '|' . $this->port() . '|' . $this->option('vpnserver_renegsec');
		return substr(sha1(implode("\0", $parts)), 0, 12);
	}

	/**
	 * Build the Yealink tar for a client. $remotes is the ordered list of
	 * "host port" entries to write; null = derive from System Admin.
	 */
	public function yealinkTar(int $clientId, ?array $remotes = null): ?string
	{
		$ca = @file_get_contents($this->dir . '/sysadmin_ca.crt');
		$crt = @file_get_contents($this->clientFile($clientId, 'crt'));
		$key = @file_get_contents($this->clientFile($clientId, 'key'));
		if ($ca === false || $crt === false || $key === false) {
			return null;
		}
		$clients = $this->clients();
		$c = $clients[$clientId] ?? null;
		if ($remotes === null) {
			$remotes = [];
			$port = $this->port();
			$server = $c['serverremote'] ?? '' ?: $this->option('vpnserver_serverremote');
			if ($server) {
				$remotes[] = "$server $port";
			}
		}
		$conf = "client\ndev tun\nproto udp\nresolv-retry 60\nnobind\npersist-key\npersist-tun\n"
			. "remote-cert-tls server\n"
			. "ca /config/openvpn/keys/ca.crt\ncert /config/openvpn/keys/client.crt\nkey /config/openvpn/keys/client.key\n"
			. "comp-lzo\nverb 3\nreneg-sec " . ((int) ($this->option('vpnserver_renegsec') ?: 3600)) . "\n";
		foreach (array_unique($remotes) as $r) {
			$conf .= "remote $r\n";
		}
		return Tar::build([
			'vpn.cnf' => $conf,
			'keys/ca.crt' => $ca,
			'keys/client.crt' => $crt,
			'keys/client.key' => $key,
		]);
	}

	private function clientFile(int $id, string $ext): string
	{
		return $this->dir . "/clients/sysadmin_client$id.$ext";
	}

	private function option(string $key): ?string
	{
		try {
			$st = $this->db->prepare('SELECT `value` FROM sysadmin_options WHERE `key` = ?');
			$st->execute([$key]);
			$v = $st->fetchColumn();
			return $v === false ? null : (string) $v;
		} catch (\Throwable $e) {
			return null; // sysadmin not installed
		}
	}
}
