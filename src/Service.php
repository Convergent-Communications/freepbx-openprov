<?php
namespace FreePBX\modules\Openprov;

/**
 * Every OpenProv operation. The GUI (AJAX), fwconsole and GraphQL are thin
 * wrappers around this class (DESIGN.md D11). All inputs and outputs are
 * plain arrays so they map 1:1 onto JSON.
 */
class Service
{
	public const VERSION = '0.1.0';
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
		foreach ($values as $k => $v) {
			if ($k === 'internal_host_effective' || $v === '********') {
				continue;
			}
			$this->settings->set($k, $v);
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
		$t['keys'] = $this->store->keys('template', $t['id']);
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
			$p = $in['parent'] ?? $in['parent_id'];
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
		if (!$reveal) {
			$d['prov_pass'] = $d['prov_pass'] === '' ? '' : '********';
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
			$d = $this->publicDevice($d);
			$d['template'] = $d['template_id'] ? ($tpl[$d['template_id']] ?? null) : null;
			return $d;
		}, $this->store->devices());
	}

	public function getDevice($ref, bool $reveal = false): array
	{
		$d = $this->deviceRef($ref);
		$out = $this->publicDevice($d, $reveal);
		$out['keys'] = $this->store->keys('device', $d['id']);
		try {
			$render = $this->builder->render($d);
			$out['in_sync'] = $d['last_hash'] === '' ? null : $render['hash'] === $d['last_hash'];
			$out['effective'] = [
				'settings' => $render['context']['settings'],
				'keys' => $render['context']['keys'],
				'network' => $render['context']['network'],
				'vpn_client' => $render['context']['vpn_client'],
				'accounts' => array_map(fn($a) => array_diff_key($a, ['secret' => 1]), $render['context']['accounts']),
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
			$ref = $in['template'] ?? $in['template_id'];
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
		$this->afterChange();
		return $this->store->keys($scope, $id);
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
			$ep = $this->endpointFor($d);
			$res = $ep === null ? ['ok' => false, 'message' => 'no local account to notify'] : $this->notifier->notify($ep, Notifier::eventFor($d['brand'], $reboot ? 'reboot' : 'sync'));
			if ($res['ok']) {
				try {
					$this->store->touchDevice($d['id'], ['notified_hash' => $this->builder->render($d)['hash']]);
				} catch (\Throwable $e) {
				}
			}
			$out[] = ['device' => $d['id'], 'mac' => $d['mac'], 'endpoint' => $ep] + $res;
		}
		return $out;
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
			$ep = $this->endpointFor($d);
			$res = $ep === null ? ['ok' => false, 'message' => 'no local account'] : $this->notifier->notify($ep, Notifier::eventFor($d['brand'], 'sync'));
			if ($res['ok']) {
				$this->store->touchDevice($d['id'], ['notified_hash' => $hash]);
			}
			$out[] = ['device' => $d['id'], 'mac' => $d['mac'], 'endpoint' => $ep] + $res;
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
		if (is_array($source) && isset($source['base64'])) {
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
		foreach ($this->registeredPhones() as $p) {
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
			if (!preg_match('/^[A-Za-z0-9_.\-\[\]]+$/', $k)) {
				throw new \InvalidArgumentException("Invalid raw key '$k'");
			}
			$out[$k] = Util::cfgValue((string) $v);
		}
		return $out;
	}
}
