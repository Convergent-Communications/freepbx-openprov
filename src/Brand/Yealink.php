<?php
namespace FreePBX\modules\Openprov\Brand;

use FreePBX\modules\Openprov\Util;

/**
 * Yealink T2x/T4x/T5x/T8x (firmware v80+ "current style" keys).
 *
 * Request flow of a Yealink phone: <mac>.boot / y000000000000.boot (we 404 so
 * the phone falls back), y0000000000NN.cfg (model common file), <mac>.cfg.
 */
class Yealink implements Brand
{
	/** Semantic type => [Yealink DSS key code, label, value hint]. */
	private const TYPES = [
		'none' => [0, 'Unused', ''],
		'line' => [15, 'Line', ''],
		'blf' => [16, 'BLF (extension or feature code)', 'Extension or *code'],
		'speeddial' => [13, 'Speed dial', 'Number'],
		'feature' => [16, 'Feature code BLF (toggle with status light)', '*code (e.g. *271)'],
		'feature_dial' => [13, 'Feature code (no status light)', '*code'],
		'park' => [10, 'Call park', 'Park extension (e.g. 70)'],
		'park_slot' => [16, 'Park slot (BLF)', 'Slot (e.g. 71)'],
		'retrieve_park' => [56, 'Retrieve park', 'Slot'],
		'voicemail' => [12, 'Voicemail', ''],
		'dnd' => [5, 'Do not disturb', ''],
		'forward' => [2, 'Forward', ''],
		'transfer' => [3, 'Transfer', 'Number (blank = prompt)'],
		'hold' => [4, 'Hold', ''],
		'conference' => [1, 'Conference', ''],
		'intercom' => [14, 'Intercom', 'Extension'],
		'pickup' => [9, 'Directed pickup', 'Extension'],
		'group_pickup' => [23, 'Group pickup', ''],
		'dtmf' => [11, 'DTMF', 'Digits'],
		'url' => [17, 'URL', 'http(s)://…'],
		'xml' => [27, 'XML browser', 'http(s)://…'],
		'xml_group' => [22, 'XML phonebook group', ''],
		'record' => [25, 'Record', ''],
		'prefix' => [40, 'Prefix', 'Digits'],
		'paging_list' => [66, 'Paging list', ''],
		'multicast_paging' => [24, 'Multicast paging', 'address:port'],
		'hotdesk' => [34, 'Hot desking', ''],
		'ldap' => [38, 'LDAP directory', ''],
		'directory' => [61, 'Directory', ''],
		'keypad_lock' => [50, 'Keypad lock', ''],
		'call_return' => [7, 'Call return', ''],
		'sms' => [8, 'SMS', ''],
	];

	public function id(): string
	{
		return 'yealink';
	}

	public function keyTypes(): array
	{
		$out = [];
		foreach (self::TYPES as $t => [$code, $label, $hint]) {
			$out[$t] = ['label' => $label, 'value' => $hint, 'code' => $code];
		}
		return $out;
	}

	/** Vendor code for a semantic type; "yealink:NN" passes a raw code through. */
	public function code(string $type): ?int
	{
		if (isset(self::TYPES[$type])) {
			return self::TYPES[$type][0];
		}
		if (preg_match('/^yealink:(\d{1,3})$/', $type, $m)) {
			return (int) $m[1];
		}
		return null;
	}

	public function parsePath(string $path): ?array
	{
		$file = basename($path);
		if (preg_match('/^([0-9a-f]{12})\.cfg$/i', $file, $m)) {
			return ['kind' => 'config', 'mac' => strtolower($m[1])];
		}
		if (preg_match('/^y0{6,}(\d{1,3})\.cfg$/i', $file) || preg_match('/^y0+\.cfg$/i', $file)) {
			return ['kind' => 'common', 'mac' => null];
		}
		// Boot files: 404 makes the phone use the classic common + MAC flow.
		// Local overrides, contact uploads and logs are not served.
		if (preg_match('/^([0-9a-f]{12}|y0+)\.boot$/i', $file)
			|| preg_match('/^[0-9a-f]{12}-(local\.cfg|contact\.xml|calllog\.xml|favorite_setting\.xml|super_search\.xml)$/i', $file)
			|| preg_match('/\.(log|bin)$/i', $file)) {
			return ['kind' => 'ignore', 'mac' => null];
		}
		return null;
	}

	public function firmwareFromUserAgent(string $ua): string
	{
		return preg_match('/^Yealink\s+\S+\s+([0-9][0-9.]+)/i', $ua, $m) ? $m[1] : '';
	}

	public function macFromUserAgent(string $ua): ?string
	{
		return preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $ua, $m) ? Util::mac($m[1]) : null;
	}

	public function compile(array $ctx): array
	{
		$b = [];
		$p = [];
		$model = $ctx['model'];

		// Provisioning
		$b['static.auto_provision.server.url'] = $ctx['urls']['prov'];
		$b['static.auto_provision.power_on'] = '1';
		$resync = (int) $ctx['resync_minutes'];
		$b['static.auto_provision.repeat.enable'] = $resync > 0 ? '1' : '0';
		if ($resync > 0) {
			$b['static.auto_provision.repeat.minutes'] = (string) max(1, $resync);
		}
		$b['static.auto_provision.pnp_enable'] = '0';
		$p['static.auto_provision.server.username'] = $ctx['prov']['user'];
		$p['static.auto_provision.server.password'] = $ctx['prov']['pass'];

		// Time
		$tz = $ctx['timezone'];
		$b['local_time.time_zone'] = $tz['offset'];
		$b['local_time.time_zone_name'] = $tz['name'];
		$b['local_time.summer_time'] = '2';

		// Passwords. Yealink repeats the same key for admin and user; the
		// "#suffix" keeps both in the map and is stripped by format().
		if ($ctx['passwords']['admin'] !== '') {
			$p['static.security.user_password#admin'] = 'admin:' . $ctx['passwords']['admin'];
		}
		if ($ctx['passwords']['user'] !== '') {
			$p['static.security.user_password#user'] = 'user:' . $ctx['passwords']['user'];
		}

		// Accounts
		$configured = [];
		foreach ($ctx['accounts'] as $a) {
			$n = (int) $a['account'];
			$configured[$n] = true;
			$b["account.$n.enable"] = '1';
			$b["account.$n.label"] = $a['label'];
			$b["account.$n.display_name"] = $a['display_name'];
			$b["account.$n.sip_server.1.address"] = $a['server'];
			$b["account.$n.sip_server.1.port"] = (string) $a['port'];
			$p["account.$n.auth_name"] = $a['auth'];
			$p["account.$n.user_name"] = $a['user'];
			$p["account.$n.password"] = $a['secret'];
		}
		if ($ctx['manage_keys']) {
			for ($n = 1; $n <= (int) $model['accounts']; $n++) {
				if (!isset($configured[$n])) {
					$b["account.$n.enable"] = '0';
				}
			}
		}

		// Line keys and expansion modules
		$this->compileKeys($b, $ctx['keys']['line'] ?? [], (int) $model['lineKeys'], $ctx['manage_keys'], fn($n) => "linekey.$n");
		$exp = $model['expansion'] ?? null;
		if ($exp) {
			for ($m = 1; $m <= (int) $exp['modules']; $m++) {
				$keys = $ctx['keys']["exp$m"] ?? [];
				if (!$keys && !$ctx['expansion_used']) {
					continue;
				}
				$this->compileKeys($b, $keys, (int) $exp['keysPerModule'], $ctx['manage_keys'] && $ctx['expansion_used'], fn($n) => "expansion_module.$m.key.$n");
			}
		}

		// Wallpaper, ringtone, firmware
		if (!empty($ctx['urls']['wallpaper'])) {
			$b['wallpaper_upload.url'] = $ctx['urls']['wallpaper'];
			$b['phone_setting.backgrounds'] = 'Config:' . $ctx['urls']['wallpaper_name'];
		}
		if (!empty($ctx['urls']['ringtone'])) {
			$b['ringtone.url'] = $ctx['urls']['ringtone'];
			$b['phone_setting.ring_type'] = 'Config:' . $ctx['urls']['ringtone_name'];
		}
		if (!empty($ctx['urls']['firmware'])) {
			$b['static.firmware.url'] = $ctx['urls']['firmware'];
		}

		// VPN
		$b['static.network.vpn_enable'] = !empty($ctx['urls']['vpn']) ? '1' : '0';
		if (!empty($ctx['urls']['vpn'])) {
			$b['openvpn.url'] = $ctx['urls']['vpn'];
		}

		// Multicast paging (Yealink supports 10 listen addresses)
		foreach (array_values($ctx['multicast']) as $i => $mc) {
			if ($i >= 10) {
				break;
			}
			$n = $i + 1;
			$b["multicast.listen_address.$n.ip_address"] = $mc['address'];
			$b["multicast.listen_address.$n.label"] = $mc['label'];
		}

		return ['base' => $b, 'protected' => $p];
	}

	private function compileKeys(array &$b, array $keys, int $count, bool $manage, callable $prefix): void
	{
		$max = $count > 0 ? $count : (empty($keys) ? 0 : max(array_keys($keys)));
		for ($n = 1; $n <= $max; $n++) {
			$pre = $prefix($n);
			$k = $keys[$n] ?? null;
			$code = $k ? $this->code($k['type']) : null;
			if ($k === null || $code === null || $code === 0) {
				if ($manage || $k !== null) {
					$b["$pre.type"] = '0';
					$b["$pre.value"] = '%NULL%';
					$b["$pre.label"] = '%NULL%';
				}
				continue;
			}
			$b["$pre.type"] = (string) $code;
			$b["$pre.value"] = $k['value'] !== '' ? $k['value'] : '%NULL%';
			$b["$pre.label"] = $k['label'] !== '' ? $k['label'] : '%NULL%';
			$b["$pre.line"] = (string) max(1, (int) $k['account']);
		}
	}

	public function format(array $kv, array $ctx): string
	{
		$out = "#!version:1.0.0.1\n";
		$out .= '## Generated by OpenProv for ' . Util::macPretty($ctx['device']['mac'] ?? '') . ' (' . ($ctx['model']['id'] ?? '?') . ")\n";
		foreach ($kv as $k => $v) {
			$key = preg_replace('/#.*$/', '', (string) $k);
			$out .= $key . ' = ' . Util::cfgValue((string) $v) . "\n";
		}
		return $out;
	}

	public function common(array $ctx): string
	{
		// Everything is per-device; the common file only has to exist so the
		// phone continues to <mac>.cfg without logging an error.
		return "#!version:1.0.0.1\n## OpenProv common file (" . ($ctx['model']['id'] ?? 'unknown') . ")\n";
	}
}
