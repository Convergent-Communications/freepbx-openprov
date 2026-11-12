<?php
namespace FreePBX\modules\Openprov;

use FreePBX\modules\Openprov\Brand\Brand;

/**
 * Builds a device's configuration from the layers described in
 * docs/DESIGN.md (D5): model defaults < schema settings < brand-computed keys
 * < raw keys (template chain, then device) < protected keys.
 */
class ConfigBuilder
{
	/** Yealink time zone names for common Olson zones (offset, name). */
	private const TZ = [
		'America/New_York' => ['-5', 'United States-Eastern Time'],
		'America/Detroit' => ['-5', 'United States-Eastern Time'],
		'America/Indiana/Indianapolis' => ['-5', 'United States-Eastern Time'],
		'America/Chicago' => ['-6', 'United States-Central Time'],
		'America/Denver' => ['-7', 'United States-Mountain Time'],
		'America/Phoenix' => ['-7', 'United States-MST'],
		'America/Los_Angeles' => ['-8', 'United States-Pacific Time'],
		'America/Anchorage' => ['-9', 'United States-Alaska Time'],
		'Pacific/Honolulu' => ['-10', 'United States-Hawaii-Aleutian'],
		'US/Eastern' => ['-5', 'United States-Eastern Time'],
		'US/Central' => ['-6', 'United States-Central Time'],
		'US/Mountain' => ['-7', 'United States-Mountain Time'],
		'US/Arizona' => ['-7', 'United States-MST'],
		'US/Pacific' => ['-8', 'United States-Pacific Time'],
		'US/Alaska' => ['-9', 'United States-Alaska Time'],
		'US/Hawaii' => ['-10', 'United States-Hawaii-Aleutian'],
		'America/Halifax' => ['-4', 'Canada(Atlantic Time)'],
		'America/St_Johns' => ['-3:30', 'Canada(Newfoundland)'],
		'America/Toronto' => ['-5', 'Canada(Eastern Time)'],
		'America/Winnipeg' => ['-6', 'Canada(Central Time)'],
		'America/Edmonton' => ['-7', 'Canada(Mountain Time)'],
		'America/Vancouver' => ['-8', 'Canada(Pacific Time)'],
		'Europe/London' => ['0', 'United Kingdom(London)'],
		'Europe/Dublin' => ['0', 'Ireland(Dublin)'],
		'Europe/Paris' => ['+1', 'France(Paris)'],
		'Europe/Berlin' => ['+1', 'Germany(Berlin)'],
		'Australia/Sydney' => ['+10', 'Australia(Sydney,Melbourne,Canberra)'],
		'UTC' => ['0', 'GMT'],
	];

	/** @param array<string,Brand> $brands */
	public function __construct(
		private Store $store,
		private Catalog $catalog,
		private Pbx $pbx,
		private Vpn $vpn,
		private Assets $assets,
		private Settings $settings,
		private array $brands
	) {
	}

	public function brand(string $id): Brand
	{
		if (!isset($this->brands[$id])) {
			throw new \InvalidArgumentException("Unknown brand '$id'");
		}
		return $this->brands[$id];
	}

	/** Template chain from root to leaf (cycle-safe). */
	public function templateChain(?int $templateId): array
	{
		$chain = [];
		$seen = [];
		while ($templateId && !isset($seen[$templateId])) {
			$seen[$templateId] = true;
			$t = $this->store->template($templateId);
			if (!$t) {
				break;
			}
			array_unshift($chain, $t);
			$templateId = $t['parent_id'];
		}
		return $chain;
	}

	/** Friendly settings: schema defaults < template chain < device. */
	public function effectiveSettings(array $device, bool $includeDevice = true): array
	{
		$s = $this->catalog->schemaDefaults($device['brand']);
		foreach ($this->templateChain($device['template_id']) as $t) {
			$s = array_merge($s, array_map('strval', $t['settings']));
		}
		return $includeDevice ? array_merge($s, array_map('strval', $device['settings'])) : $s;
	}

	/**
	 * Where each inherited setting comes from, for a template chain:
	 * id => template name (last one in the chain that sets it), 'default'
	 * (OpenProv's built-in default, which is sent to the phone) or '' (nothing
	 * set anywhere: OpenProv sends nothing and the phone keeps its own value).
	 */
	public function settingSources(string $brand, ?int $templateId): array
	{
		$src = [];
		foreach ($this->catalog->schemaDefaults($brand) as $id => $v) {
			$src[$id] = (string) $v !== '' ? 'default' : '';
		}
		foreach ($this->templateChain($templateId) as $t) {
			foreach ($t['settings'] as $id => $v) {
				if ((string) $v !== '') {
					$src[$id] = $t['name'];
				}
			}
		}
		return $src;
	}

	/** Raw keys: template chain then device. */
	public function effectiveRaw(array $device): array
	{
		$raw = [];
		foreach ($this->templateChain($device['template_id']) as $t) {
			$raw = array_merge($raw, $t['raw']);
		}
		return array_merge($raw, $device['raw']);
	}

	/**
	 * Effective keys per bank and position: template chain < device. A device
	 * key of type 'inherit' is skipped; 'none' blanks a template key.
	 */
	public function effectiveKeys(array $device, bool $includeDevice = true): array
	{
		$banks = [];
		$layers = array_map(fn($t) => $this->store->keys('template', $t['id']), $this->templateChain($device['template_id']));
		if ($includeDevice) {
			$layers[] = $this->store->keys('device', $device['id']);
		}
		foreach ($layers as $keys) {
			foreach ($keys as $k) {
				if ($k['type'] === 'inherit') {
					continue;
				}
				$banks[$k['bank']][$k['position']] = $k;
			}
		}
		foreach ($banks as &$b) {
			ksort($b);
		}
		return $banks;
	}

	/** Olson zone → Yealink [offset, name]. */
	public function yealinkTimezone(string $zone): array
	{
		if ($zone === '') {
			$zone = $this->pbx->timezone();
		}
		if (isset(self::TZ[$zone])) {
			return ['offset' => self::TZ[$zone][0], 'name' => self::TZ[$zone][1]];
		}
		try {
			$tz = new \DateTimeZone($zone);
			$y = (int) date('Y');
			$std = min($tz->getOffset(new \DateTime("$y-01-15", $tz)), $tz->getOffset(new \DateTime("$y-07-15", $tz)));
		} catch (\Throwable $e) {
			$std = 0;
		}
		$h = intdiv(abs($std), 3600);
		$m = intdiv(abs($std) % 3600, 60);
		$off = ($std < 0 ? '-' : '+') . $h . ($m ? ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
		return ['offset' => $std === 0 ? '0' : $off, 'name' => ''];
	}

	/** Host phones should use on a provisioning network. */
	public function hostFor(string $network): string
	{
		if ($network === 'vpn') {
			$h = trim((string) $this->settings->get('vpn_provisioning_host'));
			if ($h !== '') {
				return $h;
			}
			$t = $this->vpn->serverTunnelAddress();
			if ($t) {
				return $t;
			}
		}
		if ($network === 'external' && $this->settings->get('external_host') !== '') {
			return (string) $this->settings->get('external_host');
		}
		return $this->settings->internalHost();
	}

	public function baseUrl(string $network, ?array $creds = null): string
	{
		$scheme = (string) $this->settings->get('phone_scheme');
		$port = $scheme === 'https' ? (int) $this->settings->get('https_port') : (int) $this->settings->get('http_port');
		$host = $this->hostFor($network);
		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}
		$auth = $creds ? rawurlencode($creds['user']) . ':' . rawurlencode($creds['pass']) . '@' : '';
		$default = $scheme === 'https' ? 443 : 80;
		return $scheme . '://' . $auth . $host . ($port && $port !== $default ? ':' . $port : '');
	}

	/** Resolve the VPN client for a device, or null. */
	public function vpnClient(array $device, array $settings, array $lines): ?int
	{
		$mode = trim((string) ($settings['vpn'] ?? 'off'));
		if ($mode === '' || $mode === 'off') {
			return null;
		}
		if (ctype_digit($mode)) {
			return (int) $mode;
		}
		if ($mode === 'auto') {
			foreach ($lines as $l) {
				if ($l['extension'] !== '' && empty($l['custom'])) {
					$uid = $this->pbx->usermanUid($l['extension']);
					if ($uid !== null) {
						return $this->vpn->clientForUser($uid);
					}
				}
			}
		}
		return null;
	}

	/** Build the brand-neutral context for a device. */
	/**
	 * $templateOnly: what the phone would get from its template chain alone
	 * (no device settings or keys) — used to show "template value vs this
	 * phone" in editors.
	 */
	public function context(array $device, bool $templateOnly = false, ?string $requestIp = null): array
	{
		$model = $this->catalog->model($device['brand'], $device['model']);
		if (!$model) {
			throw new \RuntimeException("Unknown model {$device['brand']}/{$device['model']}");
		}
		$s = $this->effectiveSettings($device, !$templateOnly);
		$nameSource = ($s['name_source'] ?? 'extension') === 'user' ? 'user' : 'extension';
		$lines = $this->store->lines($device['id']);
		$vpnClient = $this->vpnClient($device, $s, $lines);
		$network = $s['prov_network'] ?? 'internal';
		// Models whose provisioning downloader is bound to the VPN interface
		// while the tunnel is up (T87W 185.87.x: "durl use network interface
		// tun0") can only provision from an address reached through the
		// tunnel, so they always use the VPN network once they have a client.
		$tunnelBound = !empty($model['quirks']['vpnBlocksProvisioning']);
		if ($vpnClient !== null && $tunnelBound) {
			$network = 'vpn';
		}
		if ($network === 'vpn' && $vpnClient === null) {
			$network = 'internal';
		}
		// Phones reach these before the tunnel is up, so use a direct address.
		$directNetwork = $network === 'vpn' ? ($this->settings->get('external_host') !== '' ? 'external' : 'internal') : $network;
		// Is the phone already on its tunnel? (the request came from the VPN
		// subnet, or — when rendering for sync checks — it last did.)
		$seenIp = $requestIp ?? (string) ($device['last_ip'] ?? '');
		$onTunnel = $vpnClient !== null && $this->vpn->isTunnelAddress($seenIp);
		// Files and the VPN profile: through the tunnel once the phone is on
		// it (a tunnel-bound phone can't fetch anything else then); otherwise
		// a direct address, as on first contact the tunnel doesn't exist yet.
		$fileNetwork = ($network === 'vpn' && !$onTunnel) ? $directNetwork : $network;
		$creds = ['user' => $device['prov_user'], 'pass' => $device['prov_pass']];

		$sipHost = trim((string) ($s['sip_server'] ?? '')) ?: $this->hostFor($network);
		$sipPort = trim((string) ($s['sip_port'] ?? '')) ?: (string) $this->pbx->pjsipPort();
		$accountFormat = (string) ($s['account_label_format'] ?? '{ext}');

		$accounts = [];
		$primaryExt = '';
		foreach ($lines as $l) {
			if ($l['account'] < 1 || $l['account'] > (int) $model['accounts']) {
				continue;
			}
			if (!empty($l['custom'])) {
				$c = $l['custom'];
				$accounts[] = [
					'account' => $l['account'], 'extension' => (string) ($c['user'] ?? ''),
					'label' => $l['label'] !== '' ? $l['label'] : (string) ($c['user'] ?? ''),
					'display_name' => (string) ($c['display_name'] ?? $l['label']),
					'auth' => (string) ($c['auth'] ?? $c['user'] ?? ''), 'user' => (string) ($c['user'] ?? ''),
					'secret' => (string) ($c['secret'] ?? ''), 'server' => (string) ($c['server'] ?? $sipHost),
					'port' => (string) ($c['port'] ?? $sipPort), 'custom' => true,
				];
				continue;
			}
			$sip = $this->pbx->sipAccount($l['extension']);
			if (!$sip) {
				continue;
			}
			if ($primaryExt === '') {
				$primaryExt = $l['extension'];
			}
			$np = $this->pbx->nameParts($l['extension'], $nameSource) ?? ['name' => '', 'first' => null, 'last' => null];
			$name = $np['name'];
			$accounts[] = [
				'account' => $l['account'], 'extension' => $l['extension'],
				'label' => $l['label'] !== '' ? $l['label'] : Util::label($accountFormat, $l['extension'], $name, $np['first'], $np['last']),
				'display_name' => $name !== '' ? $name : $l['extension'],
				'auth' => $sip['auth'], 'user' => $sip['user'], 'secret' => $sip['secret'],
				'server' => $sipHost, 'port' => $sipPort, 'custom' => false,
			];
		}

		// Keys with resolved values and labels
		$labelFormat = (string) ($s['label_format'] ?? '{name}');
		$featureNames = [];
		foreach ($this->pbx->featureCodes() as $f) {
			$featureNames[$f['code']] = preg_replace('/^\d+:\s*/', '', preg_replace('/\s*\(temp\)$/', '', $f['description']));
		}
		$accountLabels = [];
		foreach ($accounts as $a) {
			$accountLabels[$a['account']] = $a['label'];
		}
		$keys = [];
		foreach ($this->effectiveKeys($device, !$templateOnly) as $bank => $slots) {
			foreach ($slots as $pos => $k) {
				$value = str_replace('{ext}', $primaryExt, (string) $k['value']);
				$label = (string) $k['label'];
				if ($label === '') {
					$np = $this->pbx->nameParts($value, $nameSource);
					if ($k['type'] === 'line') {
						$label = $accountLabels[$k['account']] ?? '';
					} elseif ($np !== null) {
						$label = Util::label($labelFormat, $value, $np['name'], $np['first'], $np['last']);
					} elseif (isset($featureNames[$value])) {
						$label = $featureNames[$value];
					} elseif ($k['type'] === 'park_slot') {
						$label = 'Park ' . $value;
					} else {
						$label = $value;
					}
				}
				// raw_* = as stored (before {ext} and automatic labels), so the
				// GUI can copy an inherited key without freezing its label.
				$keys[$bank][$pos] = ['type' => $k['type'], 'value' => $value, 'label' => $label, 'account' => $k['account'],
					'raw_value' => (string) $k['value'], 'raw_label' => (string) $k['label']];
			}
		}

		// Assets
		// prov_direct: reachable without the tunnel (bootstrap answers use it).
		$urls = ['prov' => $this->baseUrl($network), 'prov_direct' => $this->baseUrl($directNetwork)];
		$assetBase = $this->baseUrl($fileNetwork, $creds);
		$wallpaper = function (string $id) use ($assetBase, $model, $device): ?array {
			if ($id === '' || !($a = $this->store->asset((int) $id)) || $a['kind'] !== 'wallpaper') {
				return null;
			}
			$name = Assets::phoneFileName($a, $model['wallpaper'] ? 'jpg' : '');
			return ['url' => "$assetBase/a/wallpaper/{$a['id']}/" . rawurlencode($device['model']) . "/$name", 'name' => $name];
		};
		if ($w = $wallpaper((string) ($s['wallpaper'] ?? ''))) {
			$urls['wallpaper'] = $w['url'];
			$urls['wallpaper_name'] = $w['name'];
		}
		// Wallpaper with the key panel expanded: blank = the same file. A
		// different file is a second download in the same pass (tested T87W).
		$wx = $wallpaper((string) ($s['wallpaper_expanded'] ?? '')) ?? ($w ?: null);
		if ($wx) {
			$urls['wallpaper_expanded_name'] = $wx['name'];
			if (!$w || $wx['name'] !== $w['name']) {
				$urls['wallpaper_expanded'] = $wx['url'];
			}
		}

		// Ringtones are referenced by name (Resource:<built-in> or
		// Config:<uploaded file>): the phone numbers custom ringtones
		// alphabetically after its built-ins, so numbers shift (TESTING.md).
		// Every uploaded ringtone in use is downloaded, all in one pass.
		$ringFiles = [];
		$ringRef = function (string $ref) use (&$ringFiles, $assetBase): ?string {
			if (preg_match('/^Resource:[A-Za-z0-9_.\- ]+$/', $ref)) {
				return $ref;
			}
			$id = (int) preg_replace('/^asset:/', '', $ref);
			if ($id <= 0 || !($a = $this->store->asset($id)) || $a['kind'] !== 'ringtone') {
				return null;
			}
			$name = Assets::phoneFileName($a);
			$ringFiles[$name] = "$assetBase/a/ringtone/{$a['id']}/$name";
			return 'Config:' . $name;
		};
		$ringType = ($s['ringtone'] ?? '') !== '' ? $ringRef((string) $s['ringtone']) : null;
		$distinctive = [];
		foreach (self::parseDistinctive((string) ($s['distinctive_ring'] ?? '')) as $row) {
			if (($ringer = $ringRef($row['ref'])) !== null) {
				$distinctive[] = ['text' => $row['text'], 'ringer' => $ringer];
			}
		}
		$urls['ringtones'] = $ringFiles;
		$urls['ring_type'] = $ringType;
		if (($s['firmware'] ?? '') !== '' && ($a = $this->store->asset((int) $s['firmware'])) && $a['kind'] === 'firmware') {
			$urls['firmware'] = "$assetBase/a/firmware/{$a['id']}/" . rawurlencode($a['name']);
		}
		if ($vpnClient !== null) {
			$urls['vpn'] = $this->baseUrl($onTunnel ? $network : $directNetwork, $creds) . '/' . $device['mac'] . '-vpn.tar';
		}

		$multicast = [];
		foreach (preg_split('/\r\n|\r|\n/', (string) ($s['multicast'] ?? '')) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			[$addr, $label] = array_pad(explode(',', $line, 2), 2, '');
			$multicast[] = ['address' => trim($addr), 'label' => trim($label) !== '' ? trim($label) : 'Paging'];
		}

		return [
			'device' => $device,
			'model' => $model,
			'settings' => $s,
			'network' => $network,
			'on_tunnel' => $onTunnel,
			'tunnel_bound' => $tunnelBound,
			'accounts' => $accounts,
			'primary_extension' => $primaryExt,
			'keys' => $keys,
			'urls' => $urls,
			'vpn_client' => $vpnClient,
			'prov' => $creds,
			'passwords' => [
				'admin' => $this->adminPassword($s, $device),
				'user' => (string) (($s['user_password'] ?? '') !== '' ? $s['user_password'] : $this->settings->get('user_password')),
			],
			'timezone' => $this->yealinkTimezone((string) ($s['timezone'] ?? '')),
			'multicast' => $multicast,
			'resync_minutes' => (int) ($s['resync_minutes'] ?? 1440),
			'manage_keys' => ($s['manage_keys'] ?? '1') === '1',
			'screenshot_from' => ($s['remote_screenshot'] ?? '1') === '1' ? $this->pbxSourceAddresses() : [],
			'features' => $this->featureLines($s, $model, $keys, $device, $primaryExt, $this->hostFor($network)),
			'distinctive' => $distinctive,
			'distinctive_managed' => trim((string) ($s['distinctive_ring'] ?? '')) !== '',
			'expansion_used' => (int) ($device['settings']['expansion_modules'] ?? 0) > 0,
		];
	}

	/**
	 * Addresses the PBX talks to phones from (the phone's Action URI allow
	 * list): its LAN address and, when System Admin's VPN server is on, its
	 * tunnel address. Host names are resolved, since the phone wants IPs.
	 */
	public function pbxSourceAddresses(): array
	{
		$ips = [];
		foreach ([$this->settings->internalHost(), $this->vpn->serverEnabled() ? $this->vpn->serverTunnelAddress() : null] as $h) {
			if ($h === null || $h === '') {
				continue;
			}
			$ip = filter_var($h, FILTER_VALIDATE_IP) ? $h : gethostbyname($h);
			if (filter_var($ip, FILTER_VALIDATE_IP)) {
				$ips[] = $ip;
			}
		}
		return array_values(array_unique($ips));
	}

	/**
	 * Add-on feature config (models/<brand>/features/*.json).
	 * - A feature is managed when its setting is On (1) or Off (0); blank = untouched.
	 * - Slots are given out over the managed features in a fixed order, so they
	 *   stay put: softkeys 1, 2, …; hidden line keys counting down from the
	 *   model's last key, skipping keys the phone actually uses.
	 * - On writes the feature's lines (+ global switches); Off writes its
	 *   removal lines at the same slots, because a phone keeps settings that
	 *   are merely left out of a later config.
	 * Returns ['lines' => key => value, 'active' => [id => slots], 'warnings' => [..]].
	 */
	public function featureLines(array $s, array $model, array $keys, array $device, string $ext, string $pbx): array
	{
		$out = ['lines' => [], 'active' => [], 'warnings' => []];
		$used = [];
		foreach ($keys['line'] ?? [] as $pos => $k) {
			if (($k['type'] ?? '') !== '' && $k['type'] !== 'none') {
				$used[(int) $pos] = true;
			}
		}
		// Free line keys from the top (keys the phone really uses are skipped).
		$free = [];
		for ($n = (int) $model['lineKeys']; $n >= 1; $n--) {
			if (!isset($used[$n])) {
				$free[] = $n;
			}
		}
		$globals = [];
		foreach ($this->catalog->features($device['brand']) as $id => $f) {
			$state = (string) ($s["feature.$id"] ?? '');
			if ($state !== '1' && $state !== '0') {
				continue;
			}
			$slot = (int) ($f['slot'] ?? 0);
			if ($slot < 1) {
				$out['warnings'][] = ($f['label'] ?? $id) . ': no feature slot available';
				continue;
			}
			$missing = array_diff($f['requires'], $model['capabilities'] ?? []);
			if ($missing) {
				if ($state === '1') {
					$out['warnings'][] = ($f['label'] ?? $id) . ": not available on the {$model['id']} (needs " . implode(', ', $missing) . ')';
				}
				continue;
			}
			// Slots come from the feature's permanent slot only, so other
			// features coming and going never move it.
			$slots = [];
			if (!empty($f['claims']['lineKey'])) {
				if (!isset($free[$slot - 1])) {
					$out['warnings'][] = ($f['label'] ?? $id) . ': no free line key left';
					continue;
				}
				$slots['lineKey'] = $free[$slot - 1];
			}
			if (!empty($f['claims']['softkey'])) {
				$slots['softkey'] = $slot;
			}
			$common = ['{ext}' => $ext, '{pbx}' => $pbx, '{mac}' => $device['mac'] ?? ''];
			$vars = [];
			foreach ($f['variables'] as $v) {
				$val = (string) ($s["feature.$id.{$v['id']}"] ?? ($v['default'] ?? ''));
				$vars['{' . $v['id'] . '}'] = strtr($val, $common);
			}
			$map = $common + $vars;
			foreach ($slots as $k => $n) {
				$map['{' . $k . '}'] = (string) $n;
			}
			foreach ($state === '1' ? $f['lines'] : $f['remove'] as $k => $v) {
				$out['lines'][strtr($k, $map)] = strtr((string) $v, $map);
			}
			if ($state === '1') {
				$globals += $f['globals'];
				$out['active'][$id] = $slots;
			}
		}
		// Global switches are turned on while any feature needs them, never off
		// (other things on the phone may rely on them).
		$out['lines'] = $globals + $out['lines'];
		return $out;
	}

	/**
	 * The phone's admin password: a template/phone admin_password, else (with
	 * per_device_admin_password) a 10-digit password derived from
	 * device_secret and the MAC (stable, never stored), else the global one.
	 */
	public function adminPassword(array $s, array $device): string
	{
		if (($s['admin_password'] ?? '') !== '') {
			return (string) $s['admin_password'];
		}
		if ($this->settings->get('per_device_admin_password') && (string) $this->settings->get('device_secret') !== '' && ($device['mac'] ?? '') !== '') {
			$h = hash_hmac('sha256', 'admin:' . $device['mac'], (string) $this->settings->get('device_secret'));
			$digits = '';
			foreach (str_split($h, 4) as $chunk) {
				$digits .= (string) (hexdec($chunk) % 10);
			}
			return substr($digits, 0, 10);
		}
		return (string) $this->settings->get('admin_password');
	}

	/**
	 * Distinctive-ring rules, one per line: "text=ref" where ref is
	 * Resource:<built-in>.wav or asset:<id>. "none" = no rules (clears the phone).
	 */
	public static function parseDistinctive(string $value): array
	{
		$rows = [];
		foreach (preg_split('/\r\n|\r|\n/', trim($value)) as $line) {
			$line = trim($line);
			if ($line === '' || $line === 'none' || !str_contains($line, '=')) {
				continue;
			}
			[$text, $ref] = array_map('trim', explode('=', $line, 2));
			if ($text !== '' && $ref !== '') {
				$rows[] = ['text' => $text, 'ref' => $ref];
			}
		}
		return array_slice($rows, 0, 10);
	}

	/** Schema-mapped (non-computed) settings → vendor keys. */
	public function schemaKeys(array $ctx): array
	{
		$out = [];
		$accounts = array_column($ctx['accounts'], 'account');
		foreach ($this->catalog->schema($ctx['device']['brand']) as $def) {
			if (!empty($def['computed']) || empty($def['keys'])) {
				continue;
			}
			$value = (string) ($ctx['settings'][$def['id']] ?? '');
			if ($value === '') {
				continue; // blank = leave the phone's own value alone
			}
			foreach ($def['keys'] as $keyTpl => $valTpl) {
				$val = strtr((string) $valTpl, ['{value}' => $value, '{invert}' => $value === '1' ? '0' : '1', '{nonzero}' => (float) $value != 0 ? '1' : '0']);
				if (!empty($def['perAccount'])) {
					foreach ($accounts as $n) {
						$out[str_replace('{account}', (string) $n, $keyTpl)] = $val;
					}
				} else {
					$out[$keyTpl] = $val;
				}
			}
		}
		return $out;
	}

	/** Bootstrap answer: credential + URL only (see Brand::bootstrap). */
	public function renderBootstrap(array $device, ?string $requestIp = null): string
	{
		$ctx = $this->context($device, false, $requestIp);
		$brand = $this->brand($device['brand']);
		return $brand->format($brand->bootstrap($ctx), $ctx);
	}

	/** Full render: ['text' => cfg, 'hash' => sha1, 'context' => ctx]. */
	public function render(array $device, ?string $requestIp = null): array
	{
		$ctx = $this->context($device, false, $requestIp);
		$brand = $this->brand($device['brand']);
		$compiled = $brand->compile($ctx);
		$kv = array_merge(
			array_map('strval', $ctx['model']['defaults'] ?? []),
			$this->schemaKeys($ctx),
			$compiled['base'],
			array_map('strval', $this->effectiveRaw($device)),
			$compiled['protected']
		);
		$text = $brand->format($kv, $ctx);
		return ['text' => $text, 'hash' => sha1($text), 'context' => $ctx, 'keys' => $kv];
	}
}
