<?php
namespace FreePBX\modules\Openprov;

/**
 * Every OpenProv operation. The GUI (AJAX), fwconsole and GraphQL are thin
 * wrappers around this class (DESIGN.md D11). All inputs and outputs are
 * plain arrays so they map 1:1 onto JSON.
 */
class Service
{
	public const VERSION = '0.1.2';
	private bool $deferSync = false;

	public function __construct(
		private Store $store,
		private Catalog $catalog,
		private Pbx $pbx,
		private Vpn $vpn,
		private Assets $assets,
		private Settings $settings,
		private ConfigBuilder $builder,
		private Notifier $notifier,
		private WebServer $web
	) {
	}

	// ---- meta --------------------------------------------------------------

	public function info(): array
	{
		return [
			'version' => self::VERSION,
			'web' => $this->web->status(),
			'counts' => [
				'devices' => count($this->store->devices()),
				'templates' => count($this->store->templates()),
				'assets' => count($this->store->assets()),
			],
			'bootstrap_url' => $this->builder->baseUrl('internal'),
			'vpn_server' => $this->vpn->serverEnabled(),
		];
	}

	public function getSettings(bool $reveal = false): array
	{
		$s = $this->settings->all();
		$s['device_secret'] = $s['device_secret'] === '' ? '' : '********'; // never shown, even with reveal
		if (!$reveal) {
			foreach (['bootstrap_pass', 'admin_password', 'user_password'] as $k) {
				$s[$k] = $s[$k] === '' ? '' : '********';
			}
		}
		$s['internal_host_effective'] = $this->settings->internalHost();
		return $s;
	}

	public function setSettings(array $values): array
	{
		$fwKeys = ['https_port', 'http_port', 'firewall_zones'];
		$fwBefore = array_map(fn ($k) => $this->settings->get($k), $fwKeys);
		foreach ($values as $k => $v) {
			if ($k === 'internal_host_effective' || $v === '********') {
				continue;
			}
			$this->settings->set($k, $v);
		}
		if (array_map(fn ($k) => $this->settings->get($k), $fwKeys) !== $fwBefore) {
			try {
				$this->web->ensureFirewall();
			} catch (\Throwable $e) {
				// firewall module absent or refused; the README covers the manual step
			}
		}
		$this->afterChange();
		return $this->getSettings();
	}

	public function models(?string $brand = null): array
	{
		return $this->catalog->models($brand);
	}

	public function schema(string $brand = 'yealink'): array
	{
		return $this->catalog->schema($brand);
	}

	public function keyTypes(string $brand = 'yealink'): array
	{
		return $this->builder->brand($brand)->keyTypes();
	}

	public function keyTargets(): array
	{
		return $this->pbx->keyTargets();
	}

	public function extensions(): array
	{
		return array_values($this->pbx->extensions());
	}

	public function vpnClients(): array
	{
		return array_values($this->vpn->clients());
	}

	// ---- templates ---------------------------------------------------------

	private function templateRef($ref): array
	{
		$t = is_numeric($ref) ? $this->store->template((int) $ref) : $this->store->templateByName((string) $ref);
		if (!$t) {
			throw new \InvalidArgumentException("Template '$ref' not found");
		}
		return $t;
	}

	public function listTemplates(): array
	{
		$counts = [];
		foreach ($this->store->devices() as $d) {
			if ($d['template_id']) {
				$counts[$d['template_id']] = ($counts[$d['template_id']] ?? 0) + 1;
			}
		}
		return array_map(fn($t) => $t + ['devices' => $counts[$t['id']] ?? 0], $this->store->templates());
	}

	public function getTemplate($ref): array
	{
		$t = $this->templateRef($ref);
		if (!empty($t['unreadable'])) {
			throw new \RuntimeException("Template '{$t['name']}' has unreadable stored " . implode(' and ', $t['unreadable']) . ' (invalid JSON); fix the row or restore it');
		}
		$t['keys'] = $this->store->keys('template', $t['id']);
		// What this template inherits: the parent chain over the built-in
		// defaults (the editor colours each field as inherited or set here).
		$pid = $t['parent_id'] ?: null;
		$ghost = ['id' => 0, 'brand' => $t['brand'], 'template_id' => $pid, 'settings' => []];
		$t['parent_view'] = [
			'name' => $pid ? ($this->store->template($pid)['name'] ?? null) : null,
			'chain' => $pid ? array_map(fn($x) => ['id' => $x['id'], 'name' => $x['name']], $this->builder->templateChain($pid)) : [],
			'settings' => $this->builder->effectiveSettings($ghost, false),
			'sources' => $this->builder->settingSources($t['brand'], $pid),
			'keys' => $pid ? $this->builder->effectiveKeys($ghost, false) : (object) [],
		];
		return $t;
	}

	public function saveTemplate(array $in): array
	{
		$existing = !empty($in['id']) ? $this->templateRef($in['id']) : null;
		$t = $existing ?? ['settings' => [], 'raw' => [], 'brand' => 'yealink', 'description' => '', 'parent_id' => null];
		if (isset($in['name'])) {
			$name = trim((string) $in['name']);
			if ($name === '') {
				throw new \InvalidArgumentException('Template name is required');
			}
			$other = $this->store->templateByName($name);
			if ($other && (!$existing || $other['id'] !== $existing['id'])) {
				throw new \InvalidArgumentException("A template named '$name' already exists");
			}
			$t['name'] = $name;
		}
		if (empty($t['name'])) {
			throw new \InvalidArgumentException('Template name is required');
		}
		foreach (['description', 'brand'] as $k) {
			if (isset($in[$k])) {
				$t[$k] = (string) $in[$k];
			}
		}
		if (array_key_exists('parent', $in) || array_key_exists('parent_id', $in)) {
			$p = array_key_exists('parent', $in) ? $in['parent'] : $in['parent_id'];
			$t['parent_id'] = ($p === null || $p === '' || $p === 0) ? null : $this->templateRef($p)['id'];
		}
		if (isset($in['settings'])) {
			$t['settings'] = $this->cleanSettings((array) $in['settings'], $t['brand']);
		}
		if (isset($in['raw'])) {
			$t['raw'] = $this->cleanRaw($in['raw']);
		}
		$id = $this->store->saveTemplate($t);
		if ($existing && $t['parent_id'] !== null && in_array($id, array_column($this->builder->templateChain($t['parent_id']), 'id'), true)) {
			$this->store->saveTemplate(['parent_id' => null] + $t + ['id' => $id]);
			throw new \InvalidArgumentException('A template cannot inherit from itself');
		}
		if (isset($in['keys'])) {
			$this->writeKeys('template', $id, (array) $in['keys'], $in['keysBank'] ?? null);
		}
		$this->pruneRedundantKeys();
		$this->afterChange();
		return $this->getTemplate($id);
	}

	public function deleteTemplate($ref): array
	{
		$t = $this->templateRef($ref);
		$this->store->deleteTemplate($t['id']);
		$this->afterChange();
		return ['deleted' => $t['id']];
	}

	public function copyTemplate($ref, string $newName): array
	{
		$t = $this->getTemplate($ref);
		$copy = $this->saveTemplate([
			'name' => $newName, 'brand' => $t['brand'], 'description' => $t['description'],
			'parent' => $t['parent_id'], 'settings' => $t['settings'], 'raw' => $t['raw'],
		]);
		$this->store->setKeys('template', $copy['id'], $t['keys']);
		return $this->getTemplate($copy['id']);
	}

	// ---- devices -----------------------------------------------------------

	private function deviceRef($ref): array
	{
		$d = null;
		if (is_int($ref) || (is_string($ref) && ctype_digit($ref) && strlen($ref) < 12)) {
			$d = $this->store->device((int) $ref);
		}
		if (!$d && ($mac = Util::mac((string) $ref))) {
			$d = $this->store->deviceByMac($mac);
		}
		if (!$d) {
			throw new \InvalidArgumentException("Device '$ref' not found");
		}
		return $d;
	}

	private function publicDevice(array $d, bool $reveal = false): array
	{
		$model = $this->catalog->model($d['brand'], $d['model']);
		$d['lines'] = $this->store->lines($d['id']);
		$d['mac_pretty'] = Util::macPretty($d['mac']);
		$d['model_name'] = $model['name'] ?? $d['model'];
		$d['line_keys'] = (int) ($model['lineKeys'] ?? 0);
		$d['in_sync'] = $d['last_hash'] !== '' ? null : false;
		// The phone's web admin password (shared, per-device or overridden).
		try {
			$d['admin_password'] = $this->builder->adminPassword($this->builder->effectiveSettings($d), $d);
		} catch (\Throwable $e) {
			$d['admin_password'] = '';
		}
		$d['admin_password_source'] = ($d['settings']['admin_password'] ?? '') !== '' ? 'phone' : (($this->settings->get('per_device_admin_password')) ? 'per-device' : 'shared');
		if (!$reveal) {
			$d['prov_pass'] = $d['prov_pass'] === '' ? '' : '********';
			$d['admin_password'] = $d['admin_password'] === '' ? '' : '********';
		}
		return $d;
	}

	public function listDevices(): array
	{
		$tpl = [];
		foreach ($this->store->templates() as $t) {
			$tpl[$t['id']] = $t['name'];
		}
		return array_map(function ($d) use ($tpl) {
			$raw = $d;
			$d = $this->publicDevice($d);
			$d['template'] = $d['template_id'] ? ($tpl[$d['template_id']] ?? null) : null;
			$d['sync'] = $this->syncState($raw);
			$d['in_sync'] = $d['sync'] === 'current' ? true : ($d['sync'] === 'never' ? null : false);
			return $d;
		}, $this->store->devices());
	}

	/** 'never' (no fetch yet), 'current', 'pending' (notified, not fetched), 'stale' (changed, not notified), 'error'. */
	private function syncState(array $d): string
	{
		if ($d['last_hash'] === '') {
			return 'never';
		}
		try {
			$hash = $this->builder->render($d)['hash'];
		} catch (\Throwable $e) {
			return 'error';
		}
		if ($hash === $d['last_hash']) {
			return 'current';
		}
		return $hash === $d['notified_hash'] ? 'pending' : 'stale';
	}

	/**
	 * Light status for live updates in the GUI: whether the phone has the
	 * current config, when it last fetched, and its requests since $since.
	 */
	public function deviceSync($ref, int $since = 0): array
	{
		$d = $this->deviceRef($ref);
		$events = array_values(array_filter($this->store->events(100, $d['mac']), fn($e) => (int) $e['ts'] >= $since));
		usort($events, fn($a, $b) => [$a['ts'], $a['id'] ?? 0] <=> [$b['ts'], $b['id'] ?? 0]);
		return [
			'sync' => $this->syncState($d),
			'last_seen' => (int) $d['last_seen'],
			'last_ip' => $d['last_ip'],
			'auto_resync' => (bool) $this->settings->get('auto_resync'),
			'now' => time(),
			'events' => array_map(fn($e) => ['ts' => (int) $e['ts'], 'ip' => $e['ip'], 'path' => $e['path'], 'status' => (int) $e['status'], 'detail' => $e['detail']], $events),
		];
	}

	// ---- dashboard access policy ------------------------------------------

	/** Settings that can never be delegated to the dashboard, whatever is ticked. */
	public const NEVER_FROM_DASHBOARD = [
		'admin_password', 'user_password', 'vpn', 'prov_network', 'firmware', 'keep_local_changes', 'manage_keys',
		'resync_minutes', 'sip_server', 'sip_port', 'sip_transport', 'web_https_only',
	];

	private function csv(string $key): array
	{
		return array_values(array_filter(array_map('trim', explode(',', (string) $this->settings->get($key)))));
	}

	/**
	 * What the dashboard may change on this PBX, with enough detail to build
	 * its forms (labels, options).
	 */
	/**
	 * Settings the dashboard can be allowed to change: simple types it can
	 * show (on/off, number, choice, text), never the security/provisioning
	 * ones, add-on feature parameters or computed values.
	 */
	public static function dashboardSettable(array $s): bool
	{
		return in_array($s['type'] ?? '', ['bool', 'number', 'select', 'text'], true)
			&& empty($s['feature']) && empty($s['computed'])
			&& !in_array($s['id'], self::NEVER_FROM_DASHBOARD, true);
	}

	public function dashboardPolicy(string $brand = 'yealink'): array
	{
		$on = (bool) $this->settings->get('dashboard_access');
		$types = $this->builder->brand($brand)->keyTypes();
		$schema = [];
		foreach ($this->catalog->schema($brand) as $s) {
			$schema[$s['id']] = $s;
		}
		$settings = [];
		foreach ($this->csv('dashboard_settings') as $id) {
			if (isset($schema[$id]) && self::dashboardSettable($schema[$id])) {
				$settings[] = array_intersect_key($schema[$id], array_flip(['id', 'label', 'group', 'type', 'options', 'default', 'help', 'min', 'max']));
			}
		}
		$features = [];
		$all = $this->catalog->features($brand);
		foreach ($this->csv('dashboard_features') as $id) {
			if (isset($all[$id])) {
				$features[] = ['id' => $id, 'name' => $all[$id]['label'] ?? $id, 'description' => $all[$id]['description'] ?? ''];
			}
		}
		return [
			'version' => self::VERSION,
			'changes_allowed' => $on,
			'key_types' => $on ? array_values(array_intersect($this->csv('dashboard_key_types'), array_keys($types))) : [],
			'rename_extensions' => $on && (bool) $this->settings->get('dashboard_rename'),
			'edit_user_details' => $on && (bool) $this->settings->get('dashboard_user_details'),
			'settings' => $on ? $settings : [],
			'features' => $on ? $features : [],
		];
	}

	/**
	 * Enforce the policy for a change from a remote (dashboard) caller.
	 * Returns the arguments to run with (device.save is narrowed to settings).
	 */
	public function checkRemoteChange(string $action, array $a): array
	{
		$p = $this->dashboardPolicy();
		$deny = fn(string $why) => throw new \InvalidArgumentException("Not allowed from the dashboard: $why (OpenProv → Dashboard tab)");
		if (!$p['changes_allowed']) {
			$deny('changes are turned off');
		}
		switch ($action) {
			case 'resync':
			case 'sync.changed':
				return $a;
			case 'keys.set':
				if (($a['scope'] ?? '') !== 'device') {
					$deny('only a phone\'s own keys can be changed, not templates');
				}
				$d = $this->deviceRef($a['ref'] ?? '');
				$allowed = $p['key_types'];
				$existing = [];
				foreach ($this->store->keys('device', $d['id']) as $k) {
					$existing[$k['bank'] . '/' . $k['position']] = $k;
				}
				$same = fn(array $x, ?array $y) => $y && ($x['type'] ?? '') === $y['type'] && (string) ($x['value'] ?? '') === $y['value'] && (string) ($x['label'] ?? '') === $y['label']
					&& max(1, (int) ($x['account'] ?? 1)) === (int) $y['account'];
				$incoming = [];
				foreach ((array) ($a['keys'] ?? []) as $k) {
					$k = (array) $k;
					$type = (string) ($k['type'] ?? '');
					$pos = ($k['bank'] ?? $a['bank'] ?? 'line') . '/' . (int) ($k['position'] ?? 0);
					// a PBX-managed key sent back unchanged is fine
					if ($type !== '' && $type !== 'inherit' && !in_array($type, $allowed, true) && !$same($k, $existing[$pos] ?? null)) {
						$deny("key type '$type'");
					}
					$incoming[($k['bank'] ?? $a['bank'] ?? 'line') . '/' . (int) ($k['position'] ?? 0)] = $k;
				}
				// Keys of types the dashboard may not set are managed on the
				// PBX: a replace may not remove or change them.
				if (!isset($a['replace']) || $a['replace']) {
					foreach ($this->store->keys('device', $d['id']) as $k) {
						if (in_array($k['type'], $allowed, true) || (isset($a['bank']) && $k['bank'] !== $a['bank'])) {
							continue;
						}
						$in = $incoming[$k['bank'] . '/' . $k['position']] ?? null;
						if (!$in || !$same($in, $k)) {
							$deny("key {$k['position']} ({$k['type']}) is managed on the PBX");
						}
					}
				}
				return $a;
			case 'device.save':
				$d = $this->deviceRef($a['id'] ?? ($a['mac'] ?? ''));
				$extra = array_diff(array_keys($a), ['id', 'mac', 'settings']);
				if ($extra) {
					$deny('only phone settings can be changed (' . implode(', ', $extra) . ')');
				}
				$ok = array_column($p['settings'], 'id');
				foreach ($p['features'] as $f) {
					$ok[] = "feature.{$f['id']}";
				}
				$settings = $d['settings'];
				foreach ((array) ($a['settings'] ?? []) as $k => $v) {
					if (!in_array($k, $ok, true)) {
						$deny("setting '$k'");
					}
					if ($v === null || $v === '') {
						unset($settings[$k]);
					} else {
						$settings[$k] = $v;
					}
				}
				return ['id' => $d['id'], 'settings' => $settings];
			case 'extension.update':
				if (isset($a['name']) && !$p['rename_extensions']) {
					$deny('renaming extensions');
				}
				if (array_intersect(array_keys($a), ['fname', 'lname', 'displayname', 'email']) && !$p['edit_user_details']) {
					$deny('editing User Management details');
				}
				return $a;
		}
		$deny("action '$action'");
	}

	// ---- extension identity (name + User Management) ---------------------

	/**
	 * Update an extension's name and/or its User Management user's details
	 * in one go, then update every phone that shows it.
	 * $in: extension, name?, fname?, lname?, displayname?, email?
	 */
	public function updateExtension(array $in): array
	{
		$ext = trim((string) ($in['extension'] ?? ''));
		if (!preg_match('/^\d{1,10}$/', $ext)) {
			throw new \InvalidArgumentException('extension must be digits');
		}
		$clean = function (string $k, int $max) use ($in): ?string {
			if (!array_key_exists($k, $in) || $in[$k] === null) {
				return null;
			}
			$v = trim(preg_replace('/\s+/', ' ', (string) $in[$k]));
			if (strlen($v) > $max || preg_match('/["<>\\\\\x00-\x1f]/', $v)) {
				throw new \InvalidArgumentException("$k: at most $max characters, without \" < > \\");
			}
			return $v;
		};
		$out = ['extension' => $ext];
		$name = $clean('name', 50);
		if ($name !== null) {
			if ($name === '') {
				throw new \InvalidArgumentException('name can\'t be empty');
			}
			$out['rename'] = $this->pbx->renameExtension($ext, $name);
			$out['needs_reload'] = true; // Asterisk's caller ID: Apply Config (the caller batches it)
		}
		$fields = [];
		foreach (['fname' => 50, 'lname' => 50, 'displayname' => 100, 'email' => 120] as $k => $max) {
			$v = $clean($k, $max);
			if ($v !== null) {
				if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
					throw new \InvalidArgumentException('email is not a valid address');
				}
				$fields[$k] = $v;
			}
		}
		if ($fields) {
			$out['user'] = $this->pbx->updateUserDetails($ext, $fields);
		}
		$out['phones'] = $this->syncChanged();
		return $out;
	}

	/** Name, User Management details and phones of one extension (for the dashboard's form). */
	public function extensionInfo(string $ext): array
	{
		$u = $this->pbx->userFor($ext);
		return [
			'extension' => $ext,
			'name' => $this->pbx->displayName($ext),
			'user' => $u ? array_intersect_key($u, array_flip(['uid', 'username', 'fname', 'lname', 'displayname', 'email'])) : null,
			'phones' => array_map(fn($d) => ['mac' => $d['mac'], 'model' => $d['model'], 'name' => $d['name']], $this->devicesForExtension($ext)),
		];
	}

	// ---- add-on features --------------------------------------------------

	/** Every feature (built-in and custom) with its slot, compiled lines and usage. */
	public function listFeatures(string $brand = 'yealink'): array
	{
		$usage = $this->featureUsage();
		$out = [];
		foreach ($this->catalog->features($brand) as $id => $f) {
			$out[] = [
				'id' => $id, 'name' => $f['label'] ?? $id, 'description' => $f['description'] ?? '', 'custom' => !empty($f['custom']),
				'slot' => $f['slot'], 'requires' => $f['requires'], 'claims' => $f['claims'], 'variables' => $f['variables'],
				'globals' => $f['globals'], 'lines' => $f['lines'], 'remove' => $f['remove'],
				'source' => $f['source'] ?? null, 'used_by' => $usage[$id] ?? [],
			];
		}
		return $out;
	}

	/** feature id => ["Template X: On", "Phone Y: Off", …] */
	private function featureUsage(): array
	{
		$out = [];
		$scan = function (string $what, array $settings) use (&$out) {
			foreach ($settings as $k => $v) {
				if (preg_match('/^feature\.([a-z0-9_]+)$/', $k, $m) && in_array((string) $v, ['0', '1'], true)) {
					$out[$m[1]][] = $what . ': ' . ($v === '1' ? 'On' : 'Off');
				}
			}
		};
		foreach ($this->store->templates() as $t) {
			$scan('template ' . $t['name'], $t['settings']);
		}
		foreach ($this->store->devices() as $d) {
			$scan('phone ' . ($d['name'] ?: $d['mac']), $d['settings']);
		}
		return $out;
	}

	/** Create or update a custom feature (see Features::compile for the shapes). */
	public function saveFeature(array $in, string $brand = 'yealink'): array
	{
		$id = strtolower(trim((string) ($in['id'] ?? '')));
		if (!preg_match('/^[a-z0-9_]{2,30}$/', $id)) {
			throw new \InvalidArgumentException('Feature id: 2-30 lowercase letters, digits or _');
		}
		if ($this->catalog->isBuiltinFeature($brand, $id)) {
			throw new \InvalidArgumentException("'$id' is a built-in feature; pick another id");
		}
		$name = trim((string) ($in['name'] ?? ''));
		if ($name === '') {
			throw new \InvalidArgumentException('Feature name is required');
		}
		$kind = (string) ($in['kind'] ?? 'softkey_url');
		$def = ['kind' => $kind, 'name' => $name, 'description' => trim((string) ($in['description'] ?? ''))];
		if ($kind === 'softkey_url') {
			$label = trim((string) ($in['button_label'] ?? ''));
			$url = trim((string) ($in['url'] ?? ''));
			if ($label === '' || strlen($label) > 32) {
				throw new \InvalidArgumentException('Softkey label: 1-32 characters');
			}
			if (!preg_match('#^https?://\S+$#', $url)) {
				throw new \InvalidArgumentException('URL must start with http:// or https://');
			}
			$states = array_values(array_intersect(array_keys(Features::STATES), (array) ($in['states'] ?? [])));
			if (!$states) {
				throw new \InvalidArgumentException('Pick at least one screen to show the softkey on');
			}
			$def += ['button_label' => $label, 'url' => $url, 'states' => $states];
		} elseif ($kind === 'advanced') {
			$vars = [];
			foreach ((array) ($in['variables'] ?? []) as $v) {
				$vid = trim((string) ($v['id'] ?? ''));
				if ($vid === '') {
					continue;
				}
				if (!preg_match('/^[a-z0-9_]{1,30}$/', $vid) || in_array($vid, ['ext', 'pbx', 'mac', 'lineKey', 'softkey'], true)) {
					throw new \InvalidArgumentException("Variable id '$vid': lowercase letters, digits, _ (not ext/pbx/mac)");
				}
				$vars[] = ['id' => $vid, 'label' => trim((string) ($v['label'] ?? $vid)) ?: $vid, 'default' => (string) ($v['default'] ?? '')];
			}
			foreach (['lines', 'remove', 'globals'] as $k) {
				Features::parseLines((string) ($in[$k] ?? '')); // validates
			}
			if (trim((string) ($in['lines'] ?? '')) === '') {
				throw new \InvalidArgumentException('Advanced features need at least one config line');
			}
			$def += [
				'requires' => array_values(array_intersect(array_keys(Features::CAPABILITIES), (array) ($in['requires'] ?? []))),
				'claims' => ['lineKey' => empty($in['claims']['lineKey']) ? 0 : 1, 'softkey' => empty($in['claims']['softkey']) ? 0 : 1],
				'variables' => $vars,
				'lines' => (string) ($in['lines'] ?? ''), 'remove' => (string) ($in['remove'] ?? ''), 'globals' => (string) ($in['globals'] ?? ''),
			];
		} else {
			throw new \InvalidArgumentException("Unknown feature kind '$kind'");
		}
		$rows = $this->store->featureRows();
		if (!empty($in['create']) && isset($rows[$id]) && $rows[$id]['def'] !== null) {
			throw new \InvalidArgumentException("A feature '$id' already exists");
		}
		$slot = $rows[$id]['slot'] ?? Features::freeSlot(array_map(fn($r) => (int) $r['slot'], $rows));
		if ($slot === null) {
			throw new \InvalidArgumentException('All ' . Features::MAX_SLOTS . ' feature slots are in use; delete a feature first');
		}
		$this->store->saveFeatureRow($id, (int) $slot, json_encode($def));
		Features::load($this->store, $this->catalog, $brand);
		$this->afterChange();
		foreach ($this->listFeatures($brand) as $f) {
			if ($f['id'] === $id) {
				return $f;
			}
		}
		return [];
	}

	/** Delete a custom feature. Refused while templates/phones still set it (On or Off). */
	public function deleteFeature(string $id, string $brand = 'yealink'): array
	{
		if ($this->catalog->isBuiltinFeature($brand, $id)) {
			throw new \InvalidArgumentException('Built-in features can\'t be deleted');
		}
		$used = $this->featureUsage()[$id] ?? [];
		if ($used) {
			throw new \InvalidArgumentException("Still set on: " . implode('; ', $used) . ". Set it to Off, let the phones update, then clear it (blank) everywhere before deleting.");
		}
		$this->store->deleteFeatureRow($id);
		Features::load($this->store, $this->catalog, $brand);
		return ['deleted' => $id];
	}

	/** Phones whose lines include this extension (a user's own phones). */
	public function devicesForExtension(string $ext): array
	{
		return array_values(array_filter($this->listDevices(), fn($d) => in_array($ext, array_column($d['lines'], 'extension'), true)));
	}

	public function getDevice($ref, bool $reveal = false): array
	{
		$d = $this->deviceRef($ref);
		$out = $this->publicDevice($d, $reveal);
		$out['keys'] = $this->store->keys('device', $d['id']);
		try {
			$render = $this->builder->render($d);
			$out['in_sync'] = $d['last_hash'] === '' ? null : $render['hash'] === $d['last_hash'];
			$out['warnings'] = $render['context']['features']['warnings'];
			$out['effective'] = [
				'settings' => $render['context']['settings'],
				'keys' => $render['context']['keys'],
				'network' => $render['context']['network'],
				'vpn_client' => $render['context']['vpn_client'],
				'features' => $render['context']['features']['active'],
				'accounts' => array_map(fn($a) => array_diff_key($a, ['secret' => 1]), $render['context']['accounts']),
			];
			$tctx = $this->builder->context($d, true);
			$out['template_view'] = [
				'name' => $d['template_id'] ? ($this->store->template($d['template_id'])['name'] ?? null) : null,
				'chain' => array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name']], $this->builder->templateChain($d['template_id'])),
				'settings' => $tctx['settings'],
				'sources' => $this->builder->settingSources($d['brand'], $d['template_id'] ?: null),
				'keys' => $tctx['keys'],
			];
		} catch (\Throwable $e) {
			$out['render_error'] = $e->getMessage();
		}
		return $out;
	}

	public function saveDevice(array $in): array
	{
		$existing = null;
		if (!empty($in['id'])) {
			$existing = $this->deviceRef($in['id']);
		} elseif (!empty($in['mac']) && ($m = Util::mac((string) $in['mac']))) {
			$existing = $this->store->deviceByMac($m);
		}
		$d = $existing ?? ['brand' => 'yealink', 'enabled' => true, 'settings' => [], 'raw' => [], 'name' => '', 'template_id' => null, 'claimable' => true];
		if (isset($in['mac'])) {
			$mac = Util::mac((string) $in['mac']);
			if (!$mac) {
				throw new \InvalidArgumentException('Invalid MAC address');
			}
			$other = $this->store->deviceByMac($mac);
			if ($other && (!$existing || $other['id'] !== $existing['id'])) {
				throw new \InvalidArgumentException('MAC already provisioned as device ' . $other['id']);
			}
			$d['mac'] = $mac;
		}
		if (empty($d['mac'])) {
			throw new \InvalidArgumentException('MAC address is required');
		}
		if (isset($in['brand'])) {
			$d['brand'] = (string) $in['brand'];
		}
		if (isset($in['model'])) {
			$d['model'] = preg_replace('/^SIP-/i', '', (string) $in['model']);
		}
		if (empty($d['model']) || !$this->catalog->model($d['brand'], $d['model'])) {
			throw new \InvalidArgumentException("Unknown model '" . ($d['model'] ?? '') . "' (see: fwconsole openprov models)");
		}
		if (array_key_exists('template', $in) || array_key_exists('template_id', $in)) {
			$ref = array_key_exists('template', $in) ? $in['template'] : $in['template_id'];
			$d['template_id'] = ($ref === null || $ref === '' || $ref === 0) ? null : $this->templateRef($ref)['id'];
		} elseif (!$existing && (int) $this->settings->get('default_template_id') > 0) {
			$d['template_id'] = (int) $this->settings->get('default_template_id');
		}
		foreach (['name'] as $k) {
			if (isset($in[$k])) {
				$d[$k] = trim((string) $in[$k]);
			}
		}
		if (isset($in['enabled'])) {
			$d['enabled'] = (bool) $in['enabled'];
		}
		if (isset($in['settings'])) {
			$d['settings'] = $this->cleanSettings((array) $in['settings'], $d['brand'], true);
		}
		if (isset($in['raw'])) {
			$d['raw'] = $this->cleanRaw($in['raw']);
		}
		if (!$existing) {
			$d['prov_user'] = $d['mac'];
			$d['prov_pass'] = Util::secret(18);
			$d['claimable'] = true;
		}
		$id = $this->store->saveDevice($d);
		if (isset($in['lines'])) {
			$this->writeLines($id, (array) $in['lines'], $d);
		} elseif (isset($in['extension'])) {
			$this->writeLines($id, [['account' => 1, 'extension' => (string) $in['extension']]], $d);
		}
		if (isset($in['keys'])) {
			$this->writeKeys('device', $id, (array) $in['keys'], $in['keysBank'] ?? null);
		}
		$this->pruneRedundantKeys('device', $id);
		if ($d['name'] === '' && ($lines = $this->store->lines($id))) {
			$name = $this->pbx->displayName($lines[0]['extension']);
			$this->store->saveDevice(['id' => $id, 'name' => trim($lines[0]['extension'] . ' ' . $name)] + $this->store->device($id));
		}
		$this->afterChange();
		return $this->getDevice($id);
	}

	public function deleteDevice($ref): array
	{
		$d = $this->deviceRef($ref);
		$this->store->deleteDevice($d['id']);
		return ['deleted' => $d['id'], 'mac' => $d['mac']];
	}

	/** Allow bootstrap credentials again (after a factory reset); optionally rotate. */
	public function rearmDevice($ref, bool $rotate = true): array
	{
		$d = $this->deviceRef($ref);
		if ($rotate) {
			$d['prov_pass'] = Util::secret(18);
		}
		$d['claimable'] = true;
		$this->store->saveDevice($d);
		$this->store->touchDevice($d['id'], ['cert_pin' => '']); // re-learn the phone's certificate
		return $this->getDevice($d['id']);
	}

	private function writeLines(int $deviceId, array $lines, array $device): void
	{
		$model = $this->catalog->model($device['brand'], $device['model']);
		$exts = $this->pbx->extensions();
		$clean = [];
		$seen = [];
		foreach ($lines as $i => $l) {
			$l = (array) $l;
			$acct = (int) ($l['account'] ?? ($i + 1));
			if ($acct < 1 || $acct > (int) $model['accounts']) {
				throw new \InvalidArgumentException("Account $acct is out of range for {$model['id']} (1-{$model['accounts']})");
			}
			if (isset($seen[$acct])) {
				throw new \InvalidArgumentException("Account $acct listed twice");
			}
			$seen[$acct] = true;
			$ext = trim((string) ($l['extension'] ?? ''));
			$custom = $l['custom'] ?? null;
			if (!$custom && !isset($exts[$ext])) {
				throw new \InvalidArgumentException("Extension '$ext' does not exist");
			}
			$clean[] = ['account' => $acct, 'extension' => $ext, 'label' => (string) ($l['label'] ?? ''), 'custom' => $custom];
		}
		$this->store->setLines($deviceId, $clean);
	}

	// ---- keys --------------------------------------------------------------

	/**
	 * Key input: [{bank?, position, type, value?, label?, account?}].
	 * Positions are 1-based. Unknown types are rejected.
	 */
	private function writeKeys(string $scope, int $scopeId, array $keys, ?string $bank, bool $replace = true): void
	{
		$brand = 'yealink';
		$max = null;
		if ($scope === 'device') {
			$d = $this->store->device($scopeId);
			$brand = $d['brand'];
			$model = $this->catalog->model($d['brand'], $d['model']);
			$max = ['line' => (int) $model['lineKeys']];
			if (!empty($model['expansion'])) {
				for ($m = 1; $m <= (int) $model['expansion']['modules']; $m++) {
					$max["exp$m"] = (int) $model['expansion']['keysPerModule'];
				}
			}
		}
		$types = $this->builder->brand($brand)->keyTypes();
		$clean = [];
		foreach ($keys as $k) {
			$k = (array) $k;
			$b = (string) ($k['bank'] ?? $bank ?? 'line');
			if (!preg_match('/^(line|exp[1-9])$/', $b)) {
				throw new \InvalidArgumentException("Unknown key bank '$b'");
			}
			$pos = (int) ($k['position'] ?? 0);
			if ($pos < 1 || ($max !== null && isset($max[$b]) && $pos > $max[$b]) || ($max !== null && !isset($max[$b]))) {
				throw new \InvalidArgumentException("Key position $b/$pos is out of range for this model");
			}
			$type = (string) ($k['type'] ?? '');
			if ($type !== 'inherit' && !isset($types[$type]) && !preg_match('/^[a-z]+:\d{1,3}$/', $type)) {
				throw new \InvalidArgumentException("Unknown key type '$type'");
			}
			$clean[] = [
				'bank' => $b, 'position' => $pos, 'type' => $type,
				'value' => trim((string) ($k['value'] ?? '')), 'label' => trim((string) ($k['label'] ?? '')),
				'account' => max(1, (int) ($k['account'] ?? 1)), 'extra' => $k['extra'] ?? null,
			];
		}
		if ($replace) {
			$this->store->setKeys($scope, $scopeId, $clean, $bank);
		} else {
			$existing = [];
			foreach ($this->store->keys($scope, $scopeId) as $k) {
				$existing[$k['bank'] . '/' . $k['position']] = $k;
			}
			foreach ($clean as $k) {
				$existing[$k['bank'] . '/' . $k['position']] = $k;
			}
			$this->store->setKeys($scope, $scopeId, array_values($existing));
		}
	}

	/** Replace or merge keys on a template ("template") or device ("device"). */
	public function setKeys(string $scope, $ref, array $keys, ?string $bank = null, bool $replace = true): array
	{
		$id = $scope === 'template' ? $this->templateRef($ref)['id'] : $this->deviceRef($ref)['id'];
		$this->writeKeys($scope, $id, $keys, $bank, $replace);
		$scope === 'device' ? $this->pruneRedundantKeys('device', $id) : $this->pruneRedundantKeys();
		$this->afterChange();
		return $this->store->keys($scope, $id);
	}

	/**
	 * Drop keys that only repeat what is inherited, so they follow the
	 * template again: a phone's (or child template's) key identical to the
	 * template's at that position, or a "blank" where nothing is inherited.
	 * The phone's effective keys don't change, so nothing is re-sent.
	 * No arguments = every template and phone (after a template changed).
	 * Returns the number of keys removed.
	 */
	public function pruneRedundantKeys(?string $scope = null, ?int $id = null): int
	{
		$targets = [];
		if ($scope !== null) {
			$targets[] = [$scope, $id];
		} else {
			foreach ($this->store->templates() as $t) {
				$targets[] = ['template', $t['id']];
			}
			foreach ($this->store->devices() as $d) {
				$targets[] = ['device', $d['id']];
			}
		}
		$removed = 0;
		foreach ($targets as [$sc, $sid]) {
			if ($sc === 'device') {
				$d = $this->store->device($sid);
				$inherited = $d ? $this->builder->effectiveKeys($d, false) : [];
			} else {
				$t = $this->store->template($sid);
				$pid = $t['parent_id'] ?? null;
				$inherited = $pid ? $this->builder->effectiveKeys(['id' => 0, 'template_id' => $pid, 'brand' => $t['brand'], 'settings' => []], false) : [];
			}
			$own = $this->store->keys($sc, $sid);
			$keep = [];
			foreach ($own as $k) {
				$i = $inherited[$k['bank']][$k['position']] ?? null;
				$inhEmpty = $i === null || $i['type'] === 'none';
				$redundant = $k['type'] === 'inherit'
					|| ($k['type'] === 'none' && $inhEmpty)
					|| (!$inhEmpty && $k['type'] === $i['type'] && (string) $k['value'] === (string) $i['value']
						&& (string) $k['label'] === (string) $i['label'] && (int) $k['account'] === (int) $i['account']
						&& ($k['extra'] ?: null) == ($i['extra'] ?: null));
				$redundant ? $removed++ : $keep[] = $k;
			}
			if (count($keep) !== count($own)) {
				$this->store->setKeys($sc, $sid, $keep);
			}
		}
		return $removed;
	}

	/**
	 * Fill consecutive BLF keys with extensions. $extensions = list or 'all'
	 * (every extension except those on the device itself).
	 */
	public function fillBlf(string $scope, $ref, $extensions, int $start = 1, string $bank = 'line', string $type = 'blf'): array
	{
		$exclude = [];
		if ($scope === 'device') {
			$exclude = array_column($this->store->lines($this->deviceRef($ref)['id']), 'extension');
		}
		$list = $extensions === 'all' ? array_keys($this->pbx->extensions()) : (array) $extensions;
		$keys = [];
		$pos = max(1, $start);
		foreach ($list as $ext) {
			$ext = (string) $ext;
			if (in_array($ext, $exclude, true)) {
				continue;
			}
			$keys[] = ['bank' => $bank, 'position' => $pos++, 'type' => $type, 'value' => $ext];
		}
		return $this->setKeys($scope, $ref, $keys, $bank, false);
	}

	// ---- rendering & sync ---------------------------------------------------

	public function render($ref, bool $maskSecrets = true): array
	{
		$d = $this->deviceRef($ref);
		$r = $this->builder->render($d);
		$text = $r['text'];
		if ($maskSecrets) {
			$text = preg_replace('/^((?:static\.)?(?:account\.\d+\.password|auto_provision\.server\.password|security\.user_password)\s*=\s*)(.*)$/m', '$1********', $text);
			$text = preg_replace('#(https?://[^:/\s]+:)[^@\s]+@#', '$1********@', $text);
		}
		return ['mac' => $d['mac'], 'hash' => $r['hash'], 'text' => $text];
	}

	/** Endpoint to NOTIFY for a device (its first local account). */
	private function endpointFor(array $device): ?string
	{
		foreach ($this->store->lines($device['id']) as $l) {
			if (empty($l['custom']) && $l['extension'] !== '') {
				return $l['extension'];
			}
		}
		return null;
	}

	/**
	 * Ask phones to re-fetch. $target: device ref, list of refs, ['extension' => '201'] or 'all'.
	 */
	public function resync($target, bool $reboot = false): array
	{
		$devices = [];
		if ($target === 'all') {
			$devices = array_filter($this->store->devices(), fn($d) => $d['enabled']);
		} elseif (is_array($target) && isset($target['extension'])) {
			$devices = $this->devicesShowing((string) $target['extension']);
		} else {
			foreach ((array) $target as $ref) {
				$devices[] = $this->deviceRef($ref);
			}
		}
		$out = [];
		foreach ($devices as $d) {
			$res = $this->pushUpdate($d, $reboot, true);
			if ($res['ok']) {
				try {
					$this->store->touchDevice($d['id'], ['notified_hash' => $this->builder->render($d)['hash']]);
				} catch (\Throwable $e) {
				}
			}
			$out[] = ['device' => $d['id'], 'mac' => $d['mac']] + $res;
		}
		return $out;
	}

	/**
	 * Make a phone pick up its new configuration.
	 * - Normal phones: SIP NOTIFY check-sync (reboot=true when $reboot).
	 * - Phones with OpenVPN on: Yealink 185.x ignores check-sync while the
	 *   tunnel is up, so (vpn_update_method=reboot) reboot through the phone's
	 *   web API, only when the line is idle and inside reboot_window unless
	 *   $manual. Returns ok/deferred/method/message.
	 */
	public function pushUpdate(array $d, bool $reboot = false, bool $manual = false): array
	{
		$ep = $this->endpointFor($d);
		$vpnClient = null;
		try {
			$vpnClient = $this->builder->context($d)['vpn_client'];
		} catch (\Throwable $e) {
		}
		$method = $this->settings->get('vpn_update_method');
		if ($method === 'auto') {
			// check-sync works for every phone as long as its provisioning URL
			// is reachable the way the phone sends it: tunnel-bound models (T87W
			// 185.87.x) are given the tunnel address (ConfigBuilder), so they
			// re-sync over the VPN like the rest. 'reboot' remains a manual choice.
			$method = 'notify';
		}
		if ($vpnClient !== null && $method !== 'notify') {
			if ($method === 'none') {
				return ['ok' => false, 'deferred' => false, 'method' => 'none', 'endpoint' => $ep, 'message' => 'VPN phone: automatic updates disabled (applies at next reboot)'];
			}
			if (!$manual && !$this->settings->inRebootWindow()) {
				return ['ok' => false, 'deferred' => true, 'method' => 'phone-api-reboot', 'endpoint' => $ep, 'message' => 'outside reboot window'];
			}
			if ($this->settings->get('reboot_only_when_idle') && $ep !== null && $this->endpointBusy($ep)) {
				return ['ok' => false, 'deferred' => true, 'method' => 'phone-api-reboot', 'endpoint' => $ep, 'message' => 'line in use; will retry'];
			}
			$res = $this->rebootViaApi($d, $vpnClient);
			$this->store->logEvent(['mac' => $d['mac'], 'ip' => $res['host'] ?? '', 'path' => '(push) reboot via phone API', 'status' => $res['ok'] ? 200 : 502, 'detail' => $res['message']]);
			return $res + ['deferred' => !$res['ok'], 'method' => 'phone-api-reboot', 'endpoint' => $ep];
		}
		if ($ep === null) {
			return ['ok' => false, 'deferred' => false, 'method' => 'notify', 'endpoint' => null, 'message' => 'no local account to notify'];
		}
		$res = $this->notifier->notify($ep, Notifier::eventFor($d['brand'], $reboot ? 'reboot' : 'sync'));
		return $res + ['deferred' => false, 'method' => 'notify', 'endpoint' => $ep];
	}

	private function endpointBusy(string $ext): bool
	{
		global $astman;
		if (!isset($astman) || !$astman || !method_exists($astman, 'ExtensionState')) {
			return false;
		}
		$r = $astman->ExtensionState($ext, 'ext-local');
		$status = (int) ($r['Status'] ?? 0);
		return $status > 0 && $status !== 4 && ($status & (1 | 2 | 8 | 16)) !== 0;
	}

	/** Reboot through the phone's web API; tries the VPN tunnel address, then the last seen IP. */
	public function rebootViaApi(array $d, ?int $vpnClient = null): array
	{
		$hosts = [];
		if ($vpnClient !== null) {
			$c = $this->vpn->connectedClients()[$vpnClient] ?? null;
			if ($c && $c['address'] !== '') {
				$hosts[] = $c['address'];
			}
		}
		if ($d['last_ip'] !== '') {
			$hosts[] = $d['last_ip'];
		}
		$hosts = array_values(array_unique($hosts));
		if (!$hosts) {
			return ['ok' => false, 'message' => 'no known address for the phone'];
		}
		$ctx = $this->builder->context($d);
		$err = [];
		foreach ($hosts as $h) {
			try {
				$pc = new PhoneClient($h, $ctx['passwords']['admin'], 'admin', 8, $this->phonePin($d, $h));
				$pc->login();
				if ($pc->reboot()) {
					return ['ok' => true, 'host' => $h, 'message' => "rebooting via $h"];
				}
				$err[] = "$h: reboot refused";
			} catch (\Throwable $e) {
				$err[] = $e->getMessage();
			}
		}
		return ['ok' => false, 'host' => $hosts[0], 'message' => implode('; ', $err)];
	}

	/**
	 * Capture the phone's screen: the PBX fetches https://<phone>/screencapture
	 * with the admin password (tunnel address first for VPN phones, then the
	 * last seen IP). Returns ['mime', 'data' (base64), 'host', 'ts'].
	 */
	public function screenshot($ref): array
	{
		$d = $this->deviceRef($ref);
		$ctx = $this->builder->context($d);
		$hosts = [];
		if ($ctx['vpn_client'] !== null) {
			$c = $this->vpn->connectedClients()[$ctx['vpn_client']] ?? null;
			if ($c && $c['address'] !== '') {
				$hosts[] = $c['address'];
			}
		}
		if ($d['last_ip'] !== '') {
			$hosts[] = $d['last_ip'];
		}
		$hosts = array_values(array_unique($hosts));
		if (!$hosts) {
			throw new \RuntimeException('No known address for this phone yet (it has never fetched its config).');
		}
		$errors = [];
		foreach ($hosts as $h) {
			$hostPart = str_contains($h, ':') ? "[$h]" : $h;
			try {
				$pin = $this->phonePin($d, $h);
			} catch (\Throwable $e) {
				$errors[] = $e->getMessage();
				continue;
			}
			$ch = curl_init("https://$hostPart/screencapture");
			curl_setopt_array($ch, PhoneClient::curlPin($pin) + [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
				CURLOPT_USERPWD => 'admin:' . $ctx['passwords']['admin'],
				CURLOPT_SSL_VERIFYPEER => false, // phones use their factory certificate
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 20,
			]);
			$body = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
			$err = curl_error($ch);
			curl_close($ch);
			if ($body === false) {
				$errors[] = "$h: $err";
				continue;
			}
			$mime = $this->imageMime($body);
			if ($code === 200 && $mime !== null) {
				return ['mime' => $mime, 'data' => base64_encode($body), 'host' => $h, 'ts' => time()];
			}
			$errors[] = "$h: HTTP $code" . ($code === 404 || $code === 403 || $code === 412
				? ' (the phone refused: it needs this config with "Allow screenshots from the PBX" on; update the phone first)'
				: ($code === 401 ? ' (admin password rejected)' : ($type !== '' ? " ($type)" : '')));
		}
		throw new \RuntimeException('Screenshot failed: ' . implode('; ', $errors));
	}

	private function imageMime(string $b): ?string
	{
		if (str_starts_with($b, "\xFF\xD8")) {
			return 'image/jpeg';
		}
		if (str_starts_with($b, "\x89PNG")) {
			return 'image/png';
		}
		if (str_starts_with($b, 'BM')) {
			return 'image/bmp';
		}
		return null;
	}

	private function cleanDistinctive(string $v): string
	{
		if (trim($v) === 'none') {
			return 'none';
		}
		$lines = [];
		foreach (preg_split('/\r\n|\r|\n/', trim($v)) as $line) {
			if (trim($line) === '') {
				continue;
			}
			if (!str_contains($line, '=')) {
				throw new \InvalidArgumentException("Distinctive ring: '$line' must be text=ringtone");
			}
			[$text, $ref] = array_map('trim', explode('=', $line, 2));
			if ($text === '' || strlen($text) > 32 || strpbrk($text, '&=') !== false) {
				throw new \InvalidArgumentException("Distinctive ring: text '$text' must be 1-32 characters without & or =");
			}
			if (!preg_match('/^(asset:\d+|Resource:[A-Za-z0-9_.\- ]+\.wav)$/i', $ref)) {
				throw new \InvalidArgumentException("Distinctive ring: '$ref' must be asset:<id> or Resource:<built-in>.wav");
			}
			if (str_starts_with($ref, 'asset:') && (($a = $this->store->asset((int) substr($ref, 6))) === null || $a['kind'] !== 'ringtone')) {
				throw new \InvalidArgumentException("Distinctive ring: no uploaded ringtone $ref");
			}
			$lines[] = "$text=$ref";
		}
		if (count($lines) > 10) {
			throw new \InvalidArgumentException('Distinctive ring: at most 10 rules');
		}
		return $lines ? implode("\n", $lines) : 'none';
	}

	// ---- built-in ringtones ---------------------------------------------------
	// Yealink's own ringtones can't ship with OpenProv (they're Yealink's), so
	// they are fetched once from a phone of each model through its web API and
	// cached on the PBX (as 16-bit PCM WAV, so browsers can play them).

	private function builtinDir(string $model = ''): string
	{
		return rtrim($this->assets->dir(), '/') . '/builtin' . ($model !== '' ? '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $model) : '');
	}

	/** Cached built-in ringtones: model => [names in the phone's order]. */
	public function builtinRingtones(): array
	{
		$out = [];
		foreach (glob($this->builtinDir() . '/*/index.json') ?: [] as $f) {
			$names = json_decode((string) file_get_contents($f), true);
			if (is_array($names)) {
				$out[basename(dirname($f))] = $names;
			}
		}
		ksort($out);
		return $out;
	}

	/** Fetch a phone's built-in ringtones into the cache (for listening in the GUI). */
	public function fetchBuiltinRingtones($ref): array
	{
		$d = $this->deviceRef($ref);
		$ctx = $this->builder->context($d);
		$errors = [];
		foreach ($this->phoneHosts($d, $ctx) as $h) {
			try {
				$pc = new PhoneClient($h, $ctx['passwords']['admin'], 'admin', 8, $this->phonePin($d, $h));
				$pc->login();
				$list = array_values(array_filter($pc->resourceList('ringtone'), fn($r) => str_starts_with((string) ($r['value'] ?? ''), 'Resource:')));
				if (!$list) {
					throw new \RuntimeException("$h: the phone listed no built-in ringtones");
				}
				$dir = $this->builtinDir($d['model']);
				if (!is_dir($dir)) {
					@mkdir($dir, 0750, true);
				}
				$names = [];
				foreach ($list as $r) {
					$name = basename((string) $r['display']);
					$wav = $pc->resourceFile((string) $r['path']);
					if ($wav === null || !preg_match('/^[A-Za-z0-9_.\- ]+\.wav$/i', $name)) {
						continue;
					}
					file_put_contents("$dir/$name", Audio::toPcmWav($wav));
					$names[] = $name;
				}
				file_put_contents("$dir/index.json", json_encode($names));
				foreach (glob("$dir/*") ?: [] as $f) {
					Assets::own($f);
				}
				Assets::own($dir);
				Assets::own($this->builtinDir());
				return ['model' => $d['model'], 'names' => $names, 'host' => $h];
			} catch (\Throwable $e) {
				$errors[] = $e->getMessage();
			}
		}
		throw new \RuntimeException('Could not fetch ringtones: ' . ($errors ? implode('; ', $errors) : 'no known address for the phone'));
	}

	/**
	 * Audio for the GUI's play buttons: ref = asset:<id> | <id> (uploaded) or
	 * Resource:<name> (built-in, from the cache; $model first if given).
	 */
	public function ringtoneAudio(string $ref, ?string $model = null): array
	{
		if (preg_match('/^Resource:([A-Za-z0-9_.\- ]+\.wav)$/i', $ref, $m)) {
			$cached = $this->builtinRingtones();
			$models = array_unique(array_filter(array_merge([$model], array_keys($cached))));
			foreach ($models as $mod) {
				$f = $this->builtinDir((string) $mod) . '/' . $m[1];
				if (is_file($f)) {
					return ['mime' => 'audio/wav', 'data' => base64_encode((string) file_get_contents($f)), 'model' => $mod];
				}
			}
			throw new \RuntimeException("{$m[1]} isn't cached yet: fetch the built-in ringtones from a phone (Files tab)");
		}
		$id = (int) preg_replace('/^asset:/', '', $ref);
		$a = $this->store->asset($id);
		if (!$a || $a['kind'] !== 'ringtone' || !is_file($path = $this->assets->path($a))) {
			throw new \RuntimeException('No such ringtone');
		}
		$wav = (string) file_get_contents($path);
		if (substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
			throw new \RuntimeException('Not a WAV file');
		}
		return ['mime' => 'audio/wav', 'data' => base64_encode(Audio::toPcmWav($wav))];
	}

	/**
	 * The pinned public key of the phone at $host, checking its identity on
	 * first use (certificate CN = MAC). Throws if it isn't that phone.
	 */
	private function phonePin(array $d, string $host): string
	{
		$fresh = $this->store->device($d['id']);
		if (($fresh['cert_pin'] ?? '') !== '') {
			return $fresh['cert_pin'];
		}
		$pin = PhoneClient::identify($host, $d['mac']);
		$this->store->touchDevice($d['id'], ['cert_pin' => $pin]);
		return $pin;
	}

	/** Addresses to reach a phone's web server at: VPN tunnel first, then its last seen IP. */
	private function phoneHosts(array $d, array $ctx): array
	{
		$hosts = [];
		if ($ctx['vpn_client'] !== null) {
			$c = $this->vpn->connectedClients()[$ctx['vpn_client']] ?? null;
			if ($c && $c['address'] !== '') {
				$hosts[] = $c['address'];
			}
		}
		if ($d['last_ip'] !== '') {
			$hosts[] = $d['last_ip'];
		}
		return array_values(array_unique($hosts));
	}

	/** Devices whose config shows an extension: as a line or on a key. */
	public function devicesShowing(string $ext): array
	{
		$byId = [];
		foreach ($this->store->devicesForExtension($ext) as $d) {
			$byId[$d['id']] = $d;
		}
		foreach ($this->store->devices() as $d) {
			if (isset($byId[$d['id']]) || !$d['enabled']) {
				continue;
			}
			try {
				$keys = $this->builder->effectiveKeys($d);
			} catch (\Throwable $e) {
				continue;
			}
			foreach ($keys as $slots) {
				foreach ($slots as $k) {
					if ($k['value'] === $ext) {
						$byId[$d['id']] = $d;
						break 2;
					}
				}
			}
		}
		return array_values($byId);
	}

	/**
	 * Notify every enabled device whose rendered config differs from what it
	 * last fetched (and that we have not already notified for this version).
	 */
	public function syncChanged(bool $dryRun = false): array
	{
		$out = [];
		$this->pbx->forgetCache();
		foreach ($this->store->devices() as $d) {
			if (!$d['enabled'] || $d['last_hash'] === '') {
				continue;
			}
			try {
				$hash = $this->builder->render($d)['hash'];
			} catch (\Throwable $e) {
				continue;
			}
			if ($hash === $d['last_hash'] || $hash === $d['notified_hash']) {
				continue;
			}
			if ($dryRun) {
				$out[] = ['device' => $d['id'], 'mac' => $d['mac'], 'ok' => true, 'message' => 'would notify'];
				continue;
			}
			$res = $this->pushUpdate($d);
			if ($res['ok'] || !$res['deferred']) {
				// Deferred pushes (busy line, outside window, phone unreachable)
				// stay pending and are retried by the OpenProv job.
				$this->store->touchDevice($d['id'], ['notified_hash' => $hash]);
			}
			$out[] = ['device' => $d['id'], 'mac' => $d['mac']] + $res;
		}
		return $out;
	}

	/** Run several changes, then sync once. */
	public function batch(callable $fn)
	{
		$this->deferSync = true;
		try {
			return $fn($this);
		} finally {
			$this->deferSync = false;
			$this->afterChange();
		}
	}

	private function afterChange(): void
	{
		if ($this->deferSync || !$this->settings->get('auto_resync')) {
			return;
		}
		try {
			$this->syncChanged();
		} catch (\Throwable $e) {
			// Never fail a save because a phone could not be notified.
		}
	}

	// ---- assets ------------------------------------------------------------

	public function listAssets(?string $kind = null): array
	{
		return $this->store->assets($kind);
	}

	/** $source: a readable path, or ['base64' => ...]. */
	public function addAsset(string $kind, string $name, $source, array $meta = []): array
	{
		$tmp = null;
		if (is_array($source) && isset($source['upload'])) {
			$tmp = $this->uploadPath((string) $source['upload']);
			if (!is_file($tmp)) {
				throw new \InvalidArgumentException('Upload not found (it may have expired)');
			}
			$source = $tmp;
		} elseif (is_array($source) && isset($source['base64'])) {
			$tmp = tempnam(sys_get_temp_dir(), 'opa');
			file_put_contents($tmp, base64_decode((string) $source['base64'], true) ?: '');
			$source = $tmp;
		}
		try {
			return $this->assets->add($kind, $name, (string) $source, $meta);
		} finally {
			if ($tmp) {
				@unlink($tmp);
			}
		}
	}

	/**
	 * Receive one piece of a GUI upload. Pieces arrive in order (offset = bytes
	 * received so far), which keeps every request under PHP's post_max_size /
	 * upload_max_filesize (2 MB / 8 MB by default; firmware is ~40 MB).
	 */
	public function uploadChunk(string $id, int $offset, string $data): array
	{
		$path = $this->uploadPath($id);
		if ($offset === 0) {
			foreach (glob(dirname($path) . '/*.part') ?: [] as $old) {
				if (filemtime($old) < time() - 86400) {
					@unlink($old); // abandoned uploads
				}
			}
			@unlink($path);
		}
		clearstatcache(true, $path);
		$have = is_file($path) ? filesize($path) : 0;
		if ($offset !== $have) {
			throw new \InvalidArgumentException("Upload out of order (expected offset $have, got $offset)");
		}
		if ($have + strlen($data) > 1 << 30) {
			@unlink($path);
			throw new \InvalidArgumentException('Upload too large (1 GB limit)');
		}
		file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
		return ['received' => $have + strlen($data)];
	}

	private function uploadPath(string $id): string
	{
		if (!preg_match('/^[a-f0-9]{16,64}$/', $id)) {
			throw new \InvalidArgumentException('Bad upload id');
		}
		$dir = rtrim($this->assets->dir(), '/') . '/uploads';
		if (!is_dir($dir)) {
			@mkdir($dir, 0750, true);
		}
		return "$dir/$id.part";
	}

	public function deleteAsset(int $id): array
	{
		$this->assets->delete($id);
		$this->afterChange();
		return ['deleted' => $id];
	}

	// ---- monitoring --------------------------------------------------------

	public function events(int $limit = 200, ?string $mac = null): array
	{
		return $this->store->events($limit, $mac !== null ? Util::mac($mac) : null);
	}

	/** Phones that asked for configs but are not set up here, plus registered Yealinks. */
	public function discovered(int $days = 7): array
	{
		$out = [];
		foreach ($this->store->unknownMacs(time() - $days * 86400) as $r) {
			$model = $this->catalog->modelFromUserAgent((string) $r['ua']);
			$out[$r['mac']] = [
				'mac' => $r['mac'], 'mac_pretty' => Util::macPretty($r['mac']), 'ip' => $r['ip'], 'ua' => $r['ua'],
				'model' => $model['id'] ?? null, 'last_seen' => (int) $r['last_seen'], 'source' => 'provisioning request',
				'extension' => null,
			];
		}
		// Registrations from phones OpenProv already manages (matched by line
		// extension or last provisioning IP: Wi-Fi models answer ARP with a
		// different MAC than the one they provision with).
		$knownExt = [];
		$knownIp = [];
		foreach ($this->store->devices() as $d) {
			foreach ($this->store->lines($d['id']) as $l) {
				$knownExt[$l['extension']] = true;
			}
			if ($d['last_ip'] !== '') {
				$knownIp[$d['last_ip']] = true;
			}
		}
		foreach ($this->registeredPhones() as $p) {
			if (isset($knownExt[$p['extension']]) || isset($knownIp[$p['ip']])) {
				continue;
			}
			if ($p['mac'] && ($this->store->deviceByMac($p['mac']) || isset($out[$p['mac']]))) {
				continue;
			}
			$key = $p['mac'] ?? ('ip:' . $p['ip']);
			$out[$key] = $p + ['source' => 'SIP registration'];
		}
		return array_values($out);
	}

	/** Registered phones from the PJSIP registrar (AstDB) with MAC via ARP when on-link. */
	public function registeredPhones(): array
	{
		global $astman;
		if (!isset($astman) || !$astman) {
			return [];
		}
		$res = $astman->command('database show registrar/contact');
		$text = is_array($res) ? (string) ($res['data'] ?? '') : (string) $res;
		$out = [];
		foreach (preg_split('/\n/', $text) as $line) {
			if (!preg_match('#^/registrar/contact/([^;]+?);@[^:]*:\s*(\{.*\})\s*$#', trim($line), $m)) {
				continue;
			}
			$c = json_decode($m[2], true);
			if (!is_array($c)) {
				continue;
			}
			$ua = (string) ($c['user_agent'] ?? '');
			$model = $this->catalog->modelFromUserAgent($ua);
			if (!$model) {
				continue;
			}
			$ip = (string) ($c['via_addr'] ?? '');
			if ($ip === '' && preg_match('/@([0-9.]+)/', (string) ($c['uri'] ?? ''), $mm)) {
				$ip = $mm[1];
			}
			$mac = null;
			if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
				$neigh = (string) @shell_exec('ip neigh show ' . escapeshellarg($ip) . ' 2>/dev/null');
				if (preg_match('/lladdr\s+([0-9a-f:]{17})/i', $neigh, $mm)) {
					$mac = Util::mac($mm[1]);
				}
			}
			$out[] = [
				'mac' => $mac, 'mac_pretty' => $mac ? Util::macPretty($mac) : null, 'ip' => $ip, 'ua' => $ua,
				'model' => $model['id'], 'extension' => (string) ($c['endpoint'] ?? $m[1]), 'last_seen' => time(),
			];
		}
		return $out;
	}

	/** Adopt a discovered phone: create the device with one line. */
	public function adopt(string $mac, string $model, ?string $extension = null, $template = null): array
	{
		$in = ['mac' => $mac, 'model' => $model];
		if ($extension) {
			$in['lines'] = [['account' => 1, 'extension' => $extension]];
		}
		if ($template !== null) {
			$in['template'] = $template;
		}
		return $this->saveDevice($in);
	}

	// ---- import / export ---------------------------------------------------

	public function exportDevicesCsv(): string
	{
		$fh = fopen('php://temp', 'w+');
		fputcsv($fh, ['mac', 'model', 'template', 'name', 'extension', 'extension2', 'enabled']);
		foreach ($this->listDevices() as $d) {
			$lines = array_column($d['lines'], 'extension', 'account');
			fputcsv($fh, [$d['mac'], $d['model'], $d['template'] ?? '', $d['name'], $lines[1] ?? '', $lines[2] ?? '', $d['enabled'] ? '1' : '0']);
		}
		rewind($fh);
		return (string) stream_get_contents($fh);
	}

	public function importDevicesCsv(string $csv): array
	{
		$rows = array_map('str_getcsv', array_filter(preg_split('/\r\n|\r|\n/', trim($csv))));
		$head = array_map('strtolower', array_map('trim', array_shift($rows) ?? []));
		$done = [];
		$errors = [];
		$this->batch(function () use ($rows, $head, &$done, &$errors) {
			foreach ($rows as $i => $row) {
				$r = array_combine($head, array_pad($row, count($head), ''));
				$lines = [];
				if (($r['extension'] ?? '') !== '') {
					$lines[] = ['account' => 1, 'extension' => $r['extension']];
				}
				if (($r['extension2'] ?? '') !== '') {
					$lines[] = ['account' => 2, 'extension' => $r['extension2']];
				}
				try {
					$in = ['mac' => $r['mac'] ?? '', 'model' => $r['model'] ?? '', 'name' => $r['name'] ?? '', 'enabled' => ($r['enabled'] ?? '1') !== '0'];
					if (($r['template'] ?? '') !== '') {
						$in['template'] = $r['template'];
					}
					if ($lines) {
						$in['lines'] = $lines;
					}
					$done[] = $this->saveDevice($in)['mac'];
				} catch (\Throwable $e) {
					$errors[] = ['row' => $i + 2, 'error' => $e->getMessage()];
				}
			}
		});
		return ['imported' => $done, 'errors' => $errors];
	}

	/** Copy commercial EPM extension mappings (endpoint_extensions) into OpenProv. */
	public function importEpm(bool $dryRun = true, ?string $template = null, array $modelMap = []): array
	{
		try {
			$rows = $this->store->pdo()->query('SELECT ext, brand, mac, model, account, vpn FROM endpoint_extensions')->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return ['error' => 'EPM tables not found'];
		}
		$plan = [];
		foreach ($rows as $r) {
			$mac = Util::mac((string) $r['mac']);
			$model = $modelMap[$r['model']] ?? preg_replace('/^SIP-/i', '', (string) $r['model']);
			$ext = explode('-', (string) $r['ext'])[0];
			$acct = (int) preg_replace('/\D/', '', (string) $r['account']) ?: 1;
			$issue = null;
			if (strcasecmp((string) $r['brand'], 'yealink') !== 0) {
				$issue = 'brand ' . $r['brand'] . ' not supported';
			} elseif (!$mac) {
				$issue = 'no MAC';
			} elseif (!$this->catalog->model('yealink', $model)) {
				$issue = "model $model unknown (pass a model map, e.g. {\"SIP-T57W\":\"T87W\"})";
			}
			$plan[$mac ?? $r['ext']]['issues'][] = $issue;
			$plan[$mac ?? $r['ext']]['mac'] = $mac;
			$plan[$mac ?? $r['ext']]['model'] = $model;
			$plan[$mac ?? $r['ext']]['lines'][] = ['account' => $acct, 'extension' => $ext];
			if ($r['vpn'] !== null && $r['vpn'] !== '') {
				$plan[$mac ?? $r['ext']]['vpn'] = (string) $r['vpn'];
			}
		}
		$result = [];
		foreach ($plan as $key => $p) {
			$issues = array_values(array_filter($p['issues']));
			$entry = ['mac' => $p['mac'], 'model' => $p['model'], 'lines' => $p['lines'], 'vpn' => $p['vpn'] ?? null, 'issues' => $issues];
			if (!$dryRun && !$issues) {
				try {
					$in = ['mac' => $p['mac'], 'model' => $p['model'], 'lines' => $p['lines']];
					if ($template) {
						$in['template'] = $template;
					}
					if (isset($p['vpn'])) {
						$in['settings'] = ['vpn' => $p['vpn']];
					}
					$this->saveDevice($in);
					$entry['imported'] = true;
				} catch (\Throwable $e) {
					$entry['issues'][] = $e->getMessage();
				}
			}
			$result[] = $entry;
		}
		return ['dryRun' => $dryRun, 'devices' => $result];
	}

	// ---- web server ---------------------------------------------------------

	public function webStatus(): array
	{
		return $this->web->status();
	}

	public function webConfig(): string
	{
		return $this->web->config();
	}

	public function webInstall(): array
	{
		[$ok, $msg] = $this->web->install();
		return ['ok' => $ok, 'message' => $msg];
	}

	public function webUninstall(): array
	{
		[$ok, $msg] = $this->web->uninstall();
		return ['ok' => $ok, 'message' => $msg];
	}

	// ---- validation helpers -------------------------------------------------

	private function cleanSettings(array $settings, string $brand, bool $device = false): array
	{
		$schema = [];
		foreach ($this->catalog->schema($brand) as $s) {
			$schema[$s['id']] = $s;
		}
		$out = [];
		foreach ($settings as $k => $v) {
			if ($device && $k === 'expansion_modules') {
				$out[$k] = (string) (int) $v;
				continue;
			}
			if (!isset($schema[$k])) {
				throw new \InvalidArgumentException("Unknown setting '$k'");
			}
			if ($v === null || $v === '') {
				continue; // blank = inherit
			}
			$v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
			$def = $schema[$k];
			if ($def['type'] === 'select' && isset($def['options']) && !in_array($v, array_column($def['options'], 'value'), true)) {
				throw new \InvalidArgumentException("Invalid value '$v' for '$k'");
			}
			if ($def['type'] === 'bool' && !in_array($v, ['0', '1'], true)) {
				throw new \InvalidArgumentException("'$k' must be 0 or 1");
			}
			if ($def['type'] === 'number' && !is_numeric($v)) {
				throw new \InvalidArgumentException("'$k' must be a number");
			}
			if ($def['type'] === 'vpn' && !in_array($v, ['off', 'auto'], true) && !ctype_digit($v)) {
				throw new \InvalidArgumentException("'vpn' must be off, auto or a client id");
			}
			if ($def['type'] === 'number' && ((isset($def['min']) && $v < $def['min']) || (isset($def['max']) && $v > $def['max']))) {
				throw new \InvalidArgumentException("'$k' must be between {$def['min']} and {$def['max']}");
			}
			if ($def['type'] === 'ringtone' && !ctype_digit($v) && !preg_match('/^(asset:\d+|Resource:[A-Za-z0-9_.\- ]+\.wav)$/i', $v)) {
				throw new \InvalidArgumentException("'$k' must be an uploaded ringtone or Resource:<built-in>.wav");
			}
			if ($def['type'] === 'distinctive') {
				$v = $this->cleanDistinctive($v);
			}
			if (str_starts_with($def['type'], 'asset:') && !ctype_digit($v)) {
				throw new \InvalidArgumentException("'$k' must be an asset id");
			}
			if ($k === 'timezone') {
				try {
					new \DateTimeZone($v);
				} catch (\Throwable $e) {
					throw new \InvalidArgumentException("Unknown time zone '$v'");
				}
			}
			if (in_array($k, ['admin_password', 'user_password'], true) && strtolower($v) === 'admin') {
				throw new \InvalidArgumentException("Do not use 'admin' as the phone password");
			}
			$out[$k] = $v;
		}
		return $out;
	}

	/** Raw keys from a map or "key = value" text. */
	private function cleanRaw($raw): array
	{
		$map = is_string($raw) ? Util::parseKv($raw) : (array) $raw;
		$out = [];
		foreach ($map as $k => $v) {
			$k = trim((string) $k);
			if (!preg_match('/^[A-Za-z0-9_.\-\[\]]+(#\d+)?$/', $k)) {
				throw new \InvalidArgumentException("Invalid raw key '$k'");
			}
			$out[$k] = Util::cfgValue((string) $v);
		}
		return $out;
	}
}
