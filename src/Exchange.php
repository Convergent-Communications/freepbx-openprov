<?php
namespace FreePBX\modules\Openprov;

/**
 * Export OpenProv's configuration as one JSON document, and import one
 * (from this PBX or another): templates with their keys, phones with lines
 * and keys, custom add-on features, uploaded files and module settings.
 *
 * What stays out of a file:
 *  - Settings that describe this machine (host names, ports, HTTPS mode,
 *    firewall zones, allowed networks, VPN endpoints): they would point
 *    another PBX's phones at the wrong place.
 *  - Passwords and credentials (bootstrap credential, phone admin/user
 *    passwords, device_secret, each phone's provisioning credential), unless
 *    the export asks for them with secrets. Without them the importing PBX
 *    keeps its own; phones new to it get a fresh credential and must
 *    bootstrap (or be re-armed) there.
 *  - Firmware, unless asked for with firmware (40-90 MB each). Without it,
 *    settings that name a firmware file are matched to a file of the same
 *    name already uploaded on the importing PBX, or dropped with a warning.
 *  - The request log, discovered phones and sync state.
 *
 * Import merges: templates match by name, phones by MAC, features by id,
 * files by content (SHA-256). Nothing on this PBX that the file doesn't
 * mention is deleted. A dry run makes every change inside a transaction and
 * rolls it back, so it reports exactly what the import would do (and every
 * error it would hit) without changing anything.
 */
class Exchange
{
	public const FORMAT = 1;

	/** Settings that belong to this machine: never exported or imported. */
	public const MACHINE_SETTINGS = [
		'internal_host', 'external_host', 'phone_scheme', 'https_mode', 'https_port', 'http_port', 'service_port',
		'firewall_zones', 'internet_ports', 'allowed_networks', 'vpn_external_remote', 'vpn_provisioning_host',
	];

	/** Exported and imported only with secrets. */
	public const SECRET_SETTINGS = ['bootstrap_user', 'bootstrap_pass', 'admin_password', 'user_password', 'device_secret'];

	/** Settings holding a template id; exported as the template's name. */
	public const TEMPLATE_SETTINGS = ['default_template_id', 'auto_adopt_template'];

	public function __construct(
		private Service $svc,
		private Store $store,
		private Settings $settings,
		private Assets $assets,
		private Catalog $catalog,
		private Vpn $vpn
	) {
	}

	// ---- export -----------------------------------------------------------------

	/** $opts: secrets (bool), firmware (bool). */
	public function export(array $opts = []): array
	{
		$secrets = !empty($opts['secrets']);
		$firmware = !empty($opts['firmware']);
		$tplName = [];
		foreach ($this->store->templates() as $t) {
			$tplName[$t['id']] = $t['name'];
		}

		$settings = [];
		foreach (array_keys(Settings::DEFAULTS) as $k) {
			if (in_array($k, self::MACHINE_SETTINGS, true) || (!$secrets && in_array($k, self::SECRET_SETTINGS, true))) {
				continue;
			}
			$v = $this->settings->get($k);
			if (in_array($k, self::TEMPLATE_SETTINGS, true)) {
				$settings[$k] = $tplName[(int) $v] ?? null;
				continue;
			}
			$settings[$k] = $v;
		}

		$features = [];
		foreach ($this->store->featureRows() as $id => $row) {
			$def = $row['def'] !== null ? json_decode((string) $row['def'], true) : null;
			if (is_array($def)) {
				$features[] = ['id' => (string) $id] + $def;
			}
		}

		$assets = [];
		$skipped = [];
		foreach ($this->store->assets() as $a) {
			$entry = array_intersect_key($a, array_flip(['id', 'kind', 'name', 'mime', 'size', 'sha256', 'model', 'version']));
			$path = $this->assets->path($a);
			if ($a['kind'] === 'firmware' && !$firmware) {
				$entry['data'] = null;
				$skipped[] = $a['name'];
			} elseif (is_file($path)) {
				$entry['data'] = base64_encode((string) file_get_contents($path));
			} else {
				$entry['data'] = null;
				$entry['missing'] = true;
			}
			$assets[] = $entry;
		}

		$keys = fn(string $scope, int $id) => array_map(
			fn($k) => array_intersect_key($k, array_flip(['bank', 'position', 'type', 'value', 'label', 'account', 'extra'])),
			$this->store->keys($scope, $id)
		);
		$templates = [];
		foreach ($this->store->templates() as $t) {
			$templates[] = [
				'name' => $t['name'], 'brand' => $t['brand'], 'description' => $t['description'],
				'parent' => $t['parent_id'] ? ($tplName[$t['parent_id']] ?? null) : null,
				'settings' => (object) $t['settings'], 'raw' => (object) $t['raw'], 'keys' => $keys('template', $t['id']),
			];
		}

		$devices = [];
		foreach ($this->store->devices() as $d) {
			$e = [
				'mac' => $d['mac'], 'brand' => $d['brand'], 'model' => $d['model'], 'name' => $d['name'], 'enabled' => $d['enabled'],
				'template' => $d['template_id'] ? ($tplName[$d['template_id']] ?? null) : null,
				'settings' => (object) $d['settings'], 'raw' => (object) $d['raw'],
				'lines' => array_map(fn($l) => array_intersect_key($l, array_flip(['account', 'extension', 'label', 'custom'])), $this->store->lines($d['id'])),
				'keys' => $keys('device', $d['id']),
			];
			if ($secrets) {
				$e['credentials'] = ['prov_user' => $d['prov_user'], 'prov_pass' => $d['prov_pass'], 'claimable' => $d['claimable']];
			}
			$devices[] = $e;
		}

		return [
			'openprov_export' => self::FORMAT,
			'version' => Service::VERSION,
			'exported_at' => gmdate('c'),
			'includes' => ['secrets' => $secrets, 'firmware' => $firmware],
			'firmware_not_included' => $skipped,
			'settings' => $settings,
			'features' => $features,
			'assets' => $assets,
			'templates' => $templates,
			'devices' => $devices,
		];
	}

	// ---- import -----------------------------------------------------------------

	/**
	 * $opts: dryRun (bool, default true), settings (bool, default true: apply
	 * the file's module settings), secrets (bool, default false: also take the
	 * file's passwords and phone credentials, replacing this PBX's).
	 * Returns {dryRun, templates, devices, features, assets, settings: {created,
	 * updated, ...}, warnings[], errors[]}.
	 */
	public function import(array $doc, array $opts = []): array
	{
		if ((int) ($doc['openprov_export'] ?? 0) !== self::FORMAT) {
			throw new \InvalidArgumentException('Not an OpenProv export (or a newer format than this version reads)');
		}
		$dry = !array_key_exists('dryRun', $opts) || !empty($opts['dryRun']);
		$withSettings = !array_key_exists('settings', $opts) || !empty($opts['settings']);
		$withSecrets = !empty($opts['secrets']);
		if ($withSecrets && empty($doc['includes']['secrets'])) {
			throw new \InvalidArgumentException('This file was exported without passwords and credentials');
		}

		$r = [
			'dryRun' => $dry,
			'features' => ['created' => [], 'updated' => []],
			'assets' => ['added' => [], 'existing' => [], 'missing' => []],
			'templates' => ['created' => [], 'updated' => []],
			'devices' => ['created' => [], 'updated' => []],
			'settings' => ['changed' => []],
			'warnings' => [],
			'errors' => [],
		];
		$pdo = $this->store->pdo();
		$newFiles = [];
		$work = function () use ($doc, $withSettings, $withSecrets, $dry, &$r, &$newFiles) {
			$map = $this->importAssets((array) ($doc['assets'] ?? []), $r, $newFiles);
			$this->importFeatures((array) ($doc['features'] ?? []), $r);
			$this->importTemplates((array) ($doc['templates'] ?? []), $map, $r);
			$this->importDevices((array) ($doc['devices'] ?? []), $map, $withSecrets, $r);
			if ($withSettings) {
				$this->importSettings((array) ($doc['settings'] ?? []), $withSecrets, $dry, $r);
			}
		};

		if ($dry) {
			$pdo->beginTransaction();
			try {
				$this->svc->withoutSync($work);
			} finally {
				$pdo->rollBack();
				// Files added for the preview: their rows are gone with the rollback.
				foreach ($newFiles as $f) {
					@unlink($f);
				}
				Features::load($this->store, $this->catalog);
			}
		} else {
			$this->svc->batch($work);
		}
		return $r;
	}

	/** Returns old asset id => new asset id. */
	private function importAssets(array $list, array &$r, array &$newFiles): array
	{
		$map = [];
		foreach ($list as $a) {
			$a = (array) $a;
			$oldId = (int) ($a['id'] ?? 0);
			$kind = (string) ($a['kind'] ?? '');
			$name = (string) ($a['name'] ?? '');
			$label = "$kind $name";
			if (!isset(Assets::KINDS[$kind]) || $oldId < 1) {
				$r['errors'][] = "File '$label': unknown kind or id";
				continue;
			}
			$found = null;
			foreach ($this->store->assets($kind) as $have) {
				if (($a['sha256'] ?? '') !== '' && $have['sha256'] === $a['sha256']) {
					$found = $have;
					break;
				}
			}
			if (!$found && empty($a['data'])) {
				// Not in the file (firmware left out): a file of the same name here.
				$found = $this->store->assetByName($kind, $name);
				if (!$found) {
					$r['assets']['missing'][] = $label;
					$r['warnings'][] = "File '$label' isn't in the export or on this PBX: settings that use it are left out. Upload it, then import again.";
					continue;
				}
			}
			if ($found) {
				$map[$oldId] = (int) $found['id'];
				$r['assets']['existing'][] = $label;
				continue;
			}
			$bytes = base64_decode((string) $a['data'], true);
			if ($bytes === false || ($a['sha256'] ?? '') !== '' && hash('sha256', $bytes) !== $a['sha256']) {
				$r['errors'][] = "File '$label': its contents in the export are damaged";
				continue;
			}
			$tmp = tempnam(sys_get_temp_dir(), 'opx');
			try {
				file_put_contents($tmp, $bytes);
				$added = $this->assets->add($kind, $name, $tmp, ['model' => (string) ($a['model'] ?? ''), 'version' => (string) ($a['version'] ?? '')]);
				$map[$oldId] = (int) $added['id'];
				if (empty($added['duplicate'])) {
					$newFiles[] = $this->assets->path($added);
				}
				$r['assets']['added'][] = $label;
			} catch (\Throwable $e) {
				$r['errors'][] = "File '$label': " . $e->getMessage();
			} finally {
				@unlink($tmp);
			}
		}
		return $map;
	}

	private function importFeatures(array $list, array &$r): void
	{
		$rows = $this->store->featureRows();
		foreach ($list as $f) {
			$f = (array) $f;
			$id = (string) ($f['id'] ?? '');
			try {
				$this->svc->saveFeature($f);
				$r['features'][isset($rows[$id]) && $rows[$id]['def'] !== null ? 'updated' : 'created'][] = $id;
			} catch (\Throwable $e) {
				$r['errors'][] = "Feature '$id': " . $e->getMessage();
			}
		}
	}

	private function importTemplates(array $list, array $assetMap, array &$r): void
	{
		$ids = [];
		// Pass 1: create or update each template, without parents (a parent
		// may come later in the file).
		foreach ($list as $t) {
			$t = (array) $t;
			$name = trim((string) ($t['name'] ?? ''));
			$brand = (string) ($t['brand'] ?? 'yealink');
			try {
				$have = $this->store->templateByName($name);
				$in = [
					'name' => $name, 'brand' => $brand, 'description' => (string) ($t['description'] ?? ''),
					'settings' => $this->remapSettings((array) ($t['settings'] ?? []), $brand, $assetMap, "template '$name'", $r),
					'raw' => (array) ($t['raw'] ?? []),
					'keys' => array_map(fn($k) => (array) $k, (array) ($t['keys'] ?? [])),
				];
				if ($have) {
					$in['id'] = $have['id'];
				}
				$ids[$name] = (int) $this->svc->saveTemplate($in)['id'];
				$r['templates'][$have ? 'updated' : 'created'][] = $name;
			} catch (\Throwable $e) {
				$r['errors'][] = "Template '$name': " . $e->getMessage();
			}
		}
		// Pass 2: parents, by name (from the file, or already on this PBX).
		foreach ($list as $t) {
			$t = (array) $t;
			$name = trim((string) ($t['name'] ?? ''));
			if (!isset($ids[$name])) {
				continue;
			}
			$parent = $t['parent'] ?? null;
			try {
				$pid = null;
				if ($parent !== null && $parent !== '') {
					$p = $this->store->templateByName((string) $parent);
					if (!$p) {
						throw new \InvalidArgumentException("its parent '$parent' isn't in the file or on this PBX");
					}
					$pid = $p['id'];
				}
				if (($this->store->template($ids[$name])['parent_id'] ?? null) !== $pid) {
					$this->svc->saveTemplate(['id' => $ids[$name], 'parent' => $pid]);
				}
			} catch (\Throwable $e) {
				$r['errors'][] = "Template '$name': " . $e->getMessage();
			}
		}
	}

	private function importDevices(array $list, array $assetMap, bool $withSecrets, array &$r): void
	{
		foreach ($list as $d) {
			$d = (array) $d;
			$mac = Util::mac((string) ($d['mac'] ?? ''));
			$label = $mac ? Util::macPretty($mac) . (($d['name'] ?? '') !== '' ? " ({$d['name']})" : '') : (string) ($d['mac'] ?? '?');
			if (!$mac) {
				$r['errors'][] = "Phone '$label': invalid MAC address";
				continue;
			}
			$brand = (string) ($d['brand'] ?? 'yealink');
			try {
				$have = $this->store->deviceByMac($mac);
				$tpl = null;
				if (($d['template'] ?? null) !== null && $d['template'] !== '') {
					$t = $this->store->templateByName((string) $d['template']);
					if (!$t) {
						throw new \InvalidArgumentException("template '{$d['template']}' isn't in the file or on this PBX");
					}
					$tpl = $t['id'];
				}
				$in = [
					'mac' => $mac, 'brand' => $brand, 'model' => (string) ($d['model'] ?? ''), 'name' => (string) ($d['name'] ?? ''),
					'enabled' => !empty($d['enabled']), 'template' => $tpl,
					'settings' => $this->remapSettings((array) ($d['settings'] ?? []), $brand, $assetMap, "phone $label", $r),
					'raw' => (array) ($d['raw'] ?? []),
					'lines' => array_map(fn($l) => (array) $l, (array) ($d['lines'] ?? [])),
					'keys' => array_map(fn($k) => (array) $k, (array) ($d['keys'] ?? [])),
				];
				if ($have) {
					$in['id'] = $have['id'];
				}
				$saved = $this->svc->saveDevice($in);
				$cred = (array) ($d['credentials'] ?? []);
				if ($withSecrets && ($cred['prov_pass'] ?? '') !== '') {
					$row = $this->store->device((int) $saved['id']);
					$row['prov_user'] = (string) ($cred['prov_user'] ?? $mac);
					$row['prov_pass'] = (string) $cred['prov_pass'];
					$row['claimable'] = !empty($cred['claimable']);
					$this->store->saveDevice($row);
				}
				$r['devices'][$have ? 'updated' : 'created'][] = $label;
			} catch (\Throwable $e) {
				$r['errors'][] = "Phone $label: " . $e->getMessage();
			}
		}
	}

	private function importSettings(array $values, bool $withSecrets, bool $dry, array &$r): void
	{
		foreach ($values as $k => $v) {
			$k = (string) $k;
			if (!array_key_exists($k, Settings::DEFAULTS) || in_array($k, self::MACHINE_SETTINGS, true)) {
				continue;
			}
			if (in_array($k, self::SECRET_SETTINGS, true) && (!$withSecrets || $v === '' || $v === null)) {
				continue;
			}
			if (in_array($k, self::TEMPLATE_SETTINGS, true)) {
				if ($v === null || $v === '') {
					$v = 0;
				} else {
					$t = $this->store->templateByName((string) $v);
					if (!$t) {
						$r['warnings'][] = "Setting $k: template '$v' isn't on this PBX; left as it was";
						continue;
					}
					$v = $t['id'];
				}
			}
			$before = $this->settings->get($k);
			if ((string) (is_bool($v) ? (int) $v : $v) === (string) (is_bool($before) ? (int) $before : $before)) {
				continue;
			}
			$r['settings']['changed'][] = $k;
			if ($dry) {
				continue; // module settings aren't in the rolled-back transaction
			}
			try {
				$this->settings->set($k, $v);
			} catch (\Throwable $e) {
				$r['errors'][] = "Setting $k: " . $e->getMessage();
			}
		}
	}

	/**
	 * Point a template's or phone's settings at this PBX's copies of the
	 * exported files (ids differ between PBXes), and check VPN client ids.
	 */
	private function remapSettings(array $settings, string $brand, array $assetMap, string $who, array &$r): array
	{
		$types = [];
		foreach ($this->catalog->schema($brand) as $s) {
			$types[$s['id']] = $s['type'];
		}
		$clients = null;
		$groups = null;
		foreach ($settings as $k => $v) {
			$type = $types[$k] ?? '';
			$v = is_scalar($v) ? (string) $v : '';
			if ($v === '') {
				continue;
			}
			$id = null;
			if (str_starts_with($type, 'asset:') && ctype_digit($v)) {
				$id = (int) $v;
				$fmt = fn(int $n) => (string) $n;
			} elseif ($type === 'ringtone' && preg_match('/^(asset:)?(\d+)$/', $v, $m)) {
				$id = (int) $m[2];
				$fmt = fn(int $n) => $m[1] . $n;
			} elseif ($type === 'distinctive') {
				$lost = false;
				$v2 = preg_replace_callback('/asset:(\d+)/', function ($m) use ($assetMap, &$lost) {
					if (!isset($assetMap[(int) $m[1]])) {
						$lost = true;
						return $m[0];
					}
					return 'asset:' . $assetMap[(int) $m[1]];
				}, $v);
				if ($lost) {
					unset($settings[$k]);
					$r['warnings'][] = ucfirst($who) . ": $k left out (a ringtone it uses is missing)";
				} else {
					$settings[$k] = $v2;
				}
				continue;
			} elseif ($type === 'phonebook' && ctype_digit($v)) {
				$groups ??= array_column($this->svc->phonebooks(), 'id');
				if (!in_array((int) $v, $groups, true)) {
					unset($settings[$k]);
					$r['warnings'][] = ucfirst($who) . ": $k left out (Contact Manager group $v doesn't exist on this PBX)";
				}
				continue;
			} elseif ($type === 'vpn' && ctype_digit($v)) {
				$clients ??= $this->vpn->clients();
				if (!isset($clients[(int) $v])) {
					$settings[$k] = 'auto';
					$r['warnings'][] = ucfirst($who) . ": VPN client $v doesn't exist on this PBX; set to auto (the user's own VPN client)";
				}
				continue;
			}
			if ($id === null) {
				continue;
			}
			if (isset($assetMap[$id])) {
				$settings[$k] = $fmt($assetMap[$id]);
			} else {
				unset($settings[$k]);
				$r['warnings'][] = ucfirst($who) . ": $k left out (the file it uses is missing)";
			}
		}
		return $settings;
	}
}
