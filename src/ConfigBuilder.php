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
	public function effectiveSettings(array $device): array
	{
		$s = $this->catalog->schemaDefaults($device['brand']);
		foreach ($this->templateChain($device['template_id']) as $t) {
			$s = array_merge($s, array_map('strval', $t['settings']));
		}
		return array_merge($s, array_map('strval', $device['settings']));
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
	public function effectiveKeys(array $device): array
	{
		$banks = [];
		$layers = array_map(fn($t) => $this->store->keys('template', $t['id']), $this->templateChain($device['template_id']));
		$layers[] = $this->store->keys('device', $device['id']);
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
	public function context(array $device): array
	{
		$model = $this->catalog->model($device['brand'], $device['model']);
		if (!$model) {
			throw new \RuntimeException("Unknown model {$device['brand']}/{$device['model']}");
		}
		$s = $this->effectiveSettings($device);
		$lines = $this->store->lines($device['id']);
		$vpnClient = $this->vpnClient($device, $s, $lines);
		$network = $s['prov_network'] ?? 'internal';
		if ($network === 'vpn' && $vpnClient === null) {
			$network = 'internal';
		}
		// Phones reach these before the tunnel is up, so use a direct address.
		$directNetwork = $network === 'vpn' ? ($this->settings->get('external_host') !== '' ? 'external' : 'internal') : $network;
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
			$name = (string) ($this->pbx->displayName($l['extension']) ?? '');
			$accounts[] = [
				'account' => $l['account'], 'extension' => $l['extension'],
				'label' => $l['label'] !== '' ? $l['label'] : Util::label($accountFormat, $l['extension'], $name),
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
		foreach ($this->effectiveKeys($device) as $bank => $slots) {
			foreach ($slots as $pos => $k) {
				$value = str_replace('{ext}', $primaryExt, (string) $k['value']);
				$label = (string) $k['label'];
				if ($label === '') {
					$name = $this->pbx->displayName($value);
					if ($k['type'] === 'line') {
						$label = $accountLabels[$k['account']] ?? '';
					} elseif ($name !== null) {
						$label = Util::label($labelFormat, $value, $name);
					} elseif (isset($featureNames[$value])) {
						$label = $featureNames[$value];
					} elseif ($k['type'] === 'park_slot') {
						$label = 'Park ' . $value;
					} else {
						$label = $value;
					}
				}
				$keys[$bank][$pos] = ['type' => $k['type'], 'value' => $value, 'label' => $label, 'account' => $k['account']];
			}
		}

		// Assets
		$urls = ['prov' => $this->baseUrl($network)];
		$assetBase = $this->baseUrl($network, $creds);
		if (($s['wallpaper'] ?? '') !== '' && ($a = $this->store->asset((int) $s['wallpaper'])) && $a['kind'] === 'wallpaper') {
			$name = Assets::phoneFileName($a, $model['wallpaper'] ? 'jpg' : '');
			$urls['wallpaper'] = "$assetBase/a/wallpaper/{$a['id']}/" . rawurlencode($device['model']) . "/$name";
			$urls['wallpaper_name'] = $name;
		}
		if (($s['ringtone'] ?? '') !== '' && ($a = $this->store->asset((int) $s['ringtone'])) && $a['kind'] === 'ringtone') {
			$name = Assets::phoneFileName($a);
			$urls['ringtone'] = "$assetBase/a/ringtone/{$a['id']}/$name";
			$urls['ringtone_name'] = $name;
		}
		if (($s['firmware'] ?? '') !== '' && ($a = $this->store->asset((int) $s['firmware'])) && $a['kind'] === 'firmware') {
			$urls['firmware'] = "$assetBase/a/firmware/{$a['id']}/" . rawurlencode($a['name']);
		}
		if ($vpnClient !== null) {
			$urls['vpn'] = $this->baseUrl($directNetwork, $creds) . '/' . $device['mac'] . '-vpn.tar';
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
			'accounts' => $accounts,
			'primary_extension' => $primaryExt,
			'keys' => $keys,
			'urls' => $urls,
			'vpn_client' => $vpnClient,
			'prov' => $creds,
			'passwords' => [
				'admin' => (string) (($s['admin_password'] ?? '') !== '' ? $s['admin_password'] : $this->settings->get('admin_password')),
				'user' => (string) (($s['user_password'] ?? '') !== '' ? $s['user_password'] : $this->settings->get('user_password')),
			],
			'timezone' => $this->yealinkTimezone((string) ($s['timezone'] ?? '')),
			'multicast' => $multicast,
			'resync_minutes' => (int) ($s['resync_minutes'] ?? 1440),
			'manage_keys' => ($s['manage_keys'] ?? '1') === '1',
			'expansion_used' => (int) ($device['settings']['expansion_modules'] ?? 0) > 0,
		];
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
				$val = strtr((string) $valTpl, ['{value}' => $value, '{invert}' => $value === '1' ? '0' : '1']);
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

	/** Full render: ['text' => cfg, 'hash' => sha1, 'context' => ctx]. */
	public function render(array $device): array
	{
		$ctx = $this->context($device);
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
