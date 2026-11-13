<?php
namespace FreePBX\modules\Openprov;

use PDO;

/**
 * All SQL lives here. Rows come back as plain arrays with JSON columns decoded.
 */
class Store
{
	public function __construct(private PDO $db)
	{
	}

	public function pdo(): PDO
	{
		return $this->db;
	}

	private function all(string $sql, array $args = []): array
	{
		$st = $this->db->prepare($sql);
		$st->execute($args);
		return $st->fetchAll(PDO::FETCH_ASSOC);
	}

	private function one(string $sql, array $args = []): ?array
	{
		$st = $this->db->prepare($sql);
		$st->execute($args);
		$r = $st->fetch(PDO::FETCH_ASSOC);
		return $r === false ? null : $r;
	}

	private function exec(string $sql, array $args = []): int
	{
		$st = $this->db->prepare($sql);
		$st->execute($args);
		return $st->rowCount();
	}

	// ---- templates ---------------------------------------------------------

	private function tpl(?array $r): ?array
	{
		if (!$r) {
			return null;
		}
		$r['id'] = (int) $r['id'];
		$r['parent_id'] = $r['parent_id'] === null ? null : (int) $r['parent_id'];
		self::decodeJson($r, ['settings', 'raw']);
		$r['updated_at'] = (int) $r['updated_at'];
		return $r;
	}

	public function templates(): array
	{
		return array_map(fn($r) => $this->tpl($r), $this->all('SELECT * FROM openprov_templates ORDER BY name'));
	}

	public function template(int $id): ?array
	{
		return $this->tpl($this->one('SELECT * FROM openprov_templates WHERE id = ?', [$id]));
	}

	public function templateByName(string $name): ?array
	{
		return $this->tpl($this->one('SELECT * FROM openprov_templates WHERE name = ?', [$name]));
	}

	public function saveTemplate(array $t): int
	{
		$args = [
			$t['name'],
			$t['brand'] ?? 'yealink',
			$t['parent_id'] ?? null,
			$t['description'] ?? '',
			Util::jsonEncode((object) ($t['settings'] ?? [])),
			Util::jsonEncode((object) ($t['raw'] ?? [])),
			time(),
		];
		if (!empty($t['id'])) {
			$args[] = (int) $t['id'];
			$this->exec('UPDATE openprov_templates SET name=?, brand=?, parent_id=?, description=?, settings=?, raw=?, updated_at=? WHERE id=?', $args);
			return (int) $t['id'];
		}
		$this->exec('INSERT INTO openprov_templates (name, brand, parent_id, description, settings, raw, updated_at) VALUES (?,?,?,?,?,?,?)', $args);
		return (int) $this->db->lastInsertId();
	}

	public function deleteTemplate(int $id): void
	{
		$this->exec('DELETE FROM openprov_keys WHERE scope = ? AND scope_id = ?', ['template', $id]);
		$this->exec('UPDATE openprov_templates SET parent_id = NULL WHERE parent_id = ?', [$id]);
		$this->exec('UPDATE openprov_devices SET template_id = NULL WHERE template_id = ?', [$id]);
		$this->exec('DELETE FROM openprov_templates WHERE id = ?', [$id]);
	}

	// ---- devices -----------------------------------------------------------

	private function dev(?array $r): ?array
	{
		if (!$r) {
			return null;
		}
		foreach (['id', 'last_seen', 'updated_at'] as $k) {
			$r[$k] = (int) $r[$k];
		}
		$r['template_id'] = $r['template_id'] === null ? null : (int) $r['template_id'];
		$r['enabled'] = (bool) $r['enabled'];
		$r['claimable'] = (bool) $r['claimable'];
		self::decodeJson($r, ['settings', 'raw']);
		return $r;
	}

	/**
	 * Decode JSON columns. One that won't decode (e.g. a raw newline put in by
	 * a foreign restore) becomes [] and is listed in $r['unreadable'], so the
	 * config builder can refuse to render instead of silently sending defaults.
	 */
	private static function decodeJson(array &$r, array $cols): void
	{
		foreach ($cols as $c) {
			$raw = $r[$c];
			$r[$c] = Util::jsonDecode($raw);
			if ($raw !== null && $raw !== '' && !is_array(json_decode((string) $raw, true))) {
				$r['unreadable'][] = $c;
			}
		}
	}

	public function devices(): array
	{
		return array_map(fn($r) => $this->dev($r), $this->all('SELECT * FROM openprov_devices ORDER BY name, mac'));
	}

	public function device(int $id): ?array
	{
		return $this->dev($this->one('SELECT * FROM openprov_devices WHERE id = ?', [$id]));
	}

	public function deviceByMac(string $mac): ?array
	{
		return $this->dev($this->one('SELECT * FROM openprov_devices WHERE mac = ?', [$mac]));
	}

	public function saveDevice(array $d): int
	{
		$cols = ['mac', 'brand', 'model', 'template_id', 'name', 'enabled', 'settings', 'raw', 'prov_user', 'prov_pass', 'claimable'];
		$vals = [
			$d['mac'],
			$d['brand'] ?? 'yealink',
			$d['model'],
			$d['template_id'] ?? null,
			$d['name'] ?? '',
			!empty($d['enabled']) ? 1 : 0,
			Util::jsonEncode((object) ($d['settings'] ?? [])),
			Util::jsonEncode((object) ($d['raw'] ?? [])),
			$d['prov_user'] ?? '',
			$d['prov_pass'] ?? '',
			!empty($d['claimable']) ? 1 : 0,
		];
		if (!empty($d['id'])) {
			$set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
			$this->exec("UPDATE openprov_devices SET $set, updated_at = ? WHERE id = ?", [...$vals, time(), (int) $d['id']]);
			return (int) $d['id'];
		}
		$this->exec('INSERT INTO openprov_devices (' . implode(',', $cols) . ', updated_at) VALUES (' . str_repeat('?,', count($cols)) . '?)', [...$vals, time()]);
		return (int) $this->db->lastInsertId();
	}

	public function touchDevice(int $id, array $fields): void
	{
		$allowed = ['last_seen', 'last_ip', 'last_ua', 'last_fw', 'last_hash', 'notified_hash', 'claimable', 'cert_pin'];
		$set = [];
		$args = [];
		foreach ($fields as $k => $v) {
			if (in_array($k, $allowed, true)) {
				$set[] = "$k = ?";
				$args[] = is_bool($v) ? (int) $v : $v;
			}
		}
		if ($set) {
			$args[] = $id;
			$this->exec('UPDATE openprov_devices SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
		}
	}

	public function deleteDevice(int $id): void
	{
		$this->exec('DELETE FROM openprov_lines WHERE device_id = ?', [$id]);
		$this->exec('DELETE FROM openprov_keys WHERE scope = ? AND scope_id = ?', ['device', $id]);
		$this->exec('DELETE FROM openprov_devices WHERE id = ?', [$id]);
	}

	// ---- lines -------------------------------------------------------------

	public function lines(int $deviceId): array
	{
		return array_map(function ($r) {
			$r['id'] = (int) $r['id'];
			$r['device_id'] = (int) $r['device_id'];
			$r['account'] = (int) $r['account'];
			$r['custom'] = Util::jsonDecode($r['custom']);
			return $r;
		}, $this->all('SELECT * FROM openprov_lines WHERE device_id = ? ORDER BY account', [$deviceId]));
	}

	/** Replace all lines of a device. */
	public function setLines(int $deviceId, array $lines): void
	{
		$this->exec('DELETE FROM openprov_lines WHERE device_id = ?', [$deviceId]);
		foreach ($lines as $l) {
			$this->exec('INSERT INTO openprov_lines (device_id, account, extension, label, custom) VALUES (?,?,?,?,?)', [
				$deviceId,
				(int) $l['account'],
				(string) ($l['extension'] ?? ''),
				(string) ($l['label'] ?? ''),
				empty($l['custom']) ? null : Util::jsonEncode($l['custom']),
			]);
		}
	}

	/** Devices that have a given extension on any line. */
	public function devicesForExtension(string $ext): array
	{
		return array_map(fn($r) => $this->dev($r), $this->all(
			'SELECT d.* FROM openprov_devices d JOIN openprov_lines l ON l.device_id = d.id WHERE l.extension = ? GROUP BY d.id',
			[$ext]
		));
	}

	// ---- keys --------------------------------------------------------------

	public function keys(string $scope, int $scopeId): array
	{
		return array_map(function ($r) {
			$r['id'] = (int) $r['id'];
			$r['scope_id'] = (int) $r['scope_id'];
			$r['position'] = (int) $r['position'];
			$r['account'] = (int) $r['account'];
			$r['extra'] = Util::jsonDecode($r['extra']);
			return $r;
		}, $this->all('SELECT * FROM openprov_keys WHERE scope = ? AND scope_id = ? ORDER BY bank, position', [$scope, $scopeId]));
	}

	/** Replace the keys of one bank (or all banks when $bank is null). */
	public function setKeys(string $scope, int $scopeId, array $keys, ?string $bank = null): void
	{
		if ($bank === null) {
			$this->exec('DELETE FROM openprov_keys WHERE scope = ? AND scope_id = ?', [$scope, $scopeId]);
		} else {
			$this->exec('DELETE FROM openprov_keys WHERE scope = ? AND scope_id = ? AND bank = ?', [$scope, $scopeId, $bank]);
		}
		foreach ($keys as $k) {
			$this->exec('REPLACE INTO openprov_keys (scope, scope_id, bank, position, type, value, label, account, extra) VALUES (?,?,?,?,?,?,?,?,?)', [
				$scope,
				$scopeId,
				$k['bank'] ?? ($bank ?? 'line'),
				(int) $k['position'],
				(string) $k['type'],
				(string) ($k['value'] ?? ''),
				(string) ($k['label'] ?? ''),
				(int) ($k['account'] ?? 1),
				empty($k['extra']) ? null : Util::jsonEncode($k['extra']),
			]);
		}
	}

	// ---- add-on features (custom definitions + permanent slots) ---------------

	/** id => ['slot' => int, 'def' => json|null] */
	public function featureRows(): array
	{
		$out = [];
		foreach ($this->all('SELECT id, slot, def FROM openprov_features ORDER BY slot') as $r) {
			$out[$r['id']] = ['slot' => (int) $r['slot'], 'def' => $r['def']];
		}
		return $out;
	}

	public function saveFeatureRow(string $id, int $slot, ?string $def): void
	{
		$this->exec('DELETE FROM openprov_features WHERE id = ?', [$id]);
		$this->exec('INSERT INTO openprov_features (id, slot, def, updated_at) VALUES (?,?,?,?)', [$id, $slot, $def, time()]);
	}

	public function deleteFeatureRow(string $id): void
	{
		$this->exec('DELETE FROM openprov_features WHERE id = ?', [$id]);
	}

	/** Every key (template or device) that points at an extension value. */
	public function keysReferencing(string $value): array
	{
		return $this->all('SELECT scope, scope_id FROM openprov_keys WHERE value = ? GROUP BY scope, scope_id', [$value]);
	}

	// ---- assets ------------------------------------------------------------

	private function assetRow(?array $r): ?array
	{
		if (!$r) {
			return null;
		}
		foreach (['id', 'size', 'created_at'] as $k) {
			$r[$k] = (int) $r[$k];
		}
		return $r;
	}

	public function assets(?string $kind = null): array
	{
		$rows = $kind === null
			? $this->all('SELECT * FROM openprov_assets ORDER BY kind, name')
			: $this->all('SELECT * FROM openprov_assets WHERE kind = ? ORDER BY name', [$kind]);
		return array_map(fn($r) => $this->assetRow($r), $rows);
	}

	public function asset(int $id): ?array
	{
		return $this->assetRow($this->one('SELECT * FROM openprov_assets WHERE id = ?', [$id]));
	}

	public function assetByName(string $kind, string $name): ?array
	{
		return $this->assetRow($this->one('SELECT * FROM openprov_assets WHERE kind = ? AND name = ?', [$kind, $name]));
	}

	public function addAsset(array $a): int
	{
		$this->exec('INSERT INTO openprov_assets (kind, name, filename, mime, size, sha256, model, version, created_at) VALUES (?,?,?,?,?,?,?,?,?)', [
			$a['kind'], $a['name'], $a['filename'], $a['mime'] ?? '', (int) ($a['size'] ?? 0),
			$a['sha256'] ?? '', $a['model'] ?? '', $a['version'] ?? '', time(),
		]);
		return (int) $this->db->lastInsertId();
	}

	public function deleteAsset(int $id): void
	{
		$this->exec('DELETE FROM openprov_assets WHERE id = ?', [$id]);
	}

	// ---- events ------------------------------------------------------------

	public function logEvent(array $e): void
	{
		$this->exec('INSERT INTO openprov_events (ts, mac, ip, ua, path, status, detail) VALUES (?,?,?,?,?,?,?)', [
			time(),
			substr((string) ($e['mac'] ?? ''), 0, 12),
			substr((string) ($e['ip'] ?? ''), 0, 64),
			substr((string) ($e['ua'] ?? ''), 0, 255),
			substr((string) ($e['path'] ?? ''), 0, 255),
			(int) ($e['status'] ?? 0),
			substr((string) ($e['detail'] ?? ''), 0, 255),
		]);
	}

	public function events(int $limit = 200, ?string $mac = null): array
	{
		$limit = max(1, min(5000, $limit));
		$rows = $mac === null
			? $this->all("SELECT * FROM openprov_events ORDER BY id DESC LIMIT $limit")
			: $this->all("SELECT * FROM openprov_events WHERE mac = ? ORDER BY id DESC LIMIT $limit", [$mac]);
		return array_map(function ($r) {
			$r['id'] = (int) $r['id'];
			$r['ts'] = (int) $r['ts'];
			$r['status'] = (int) $r['status'];
			return $r;
		}, $rows);
	}

	/** MACs that asked for configs but are not provisioned here (discovery). */
	public function unknownMacs(int $sinceTs): array
	{
		return $this->all(
			"SELECT e.mac, MAX(e.ts) AS last_seen, MAX(e.ip) AS ip, MAX(e.ua) AS ua, COUNT(*) AS hits
			 FROM openprov_events e LEFT JOIN openprov_devices d ON d.mac = e.mac
			 WHERE e.mac <> '' AND d.id IS NULL AND e.ts >= ?
			   AND e.detail = 'unknown device (discovered)'
			 GROUP BY e.mac ORDER BY last_seen DESC",
			[$sinceTs]
		);
	}

	public function recentEvent(string $ip, string $path, int $status, int $sinceTs): bool
	{
		return (bool) $this->one('SELECT 1 AS x FROM openprov_events WHERE ts >= ? AND ip = ? AND path = ? AND status = ? LIMIT 1', [$sinceTs, substr($ip, 0, 64), substr($path, 0, 255), $status]);
	}

	public function pruneEvents(int $olderThanTs): int
	{
		return $this->exec('DELETE FROM openprov_events WHERE ts < ?', [$olderThanTs]);
	}
}
