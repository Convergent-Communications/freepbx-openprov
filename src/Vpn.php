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
			];
		}
		ksort($out);
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
