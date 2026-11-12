<?php
namespace FreePBX\modules\Openprov;

use PDO;

/**
 * Read-only view of the PBX: extensions, display names, SIP secrets, feature
 * codes, hints, time zone. Everything FreePBX-specific that the renderer needs
 * goes through here so tests can substitute a fake.
 */
class Pbx
{
	private ?array $userCache = null;
	private ?array $hintCache = null;

	public function __construct(private PDO $db, private $freepbx = null)
	{
	}

	/** All extensions: ext => ['extension','name','tech']. */
	public function extensions(): array
	{
		if ($this->userCache !== null) {
			return $this->userCache;
		}
		$rows = $this->db->query(
			"SELECT u.extension, u.name, d.tech FROM users u LEFT JOIN devices d ON d.id = u.extension ORDER BY CAST(u.extension AS UNSIGNED), u.extension"
		)->fetchAll(PDO::FETCH_ASSOC);
		$out = [];
		foreach ($rows as $r) {
			$out[(string) $r['extension']] = [
				'extension' => (string) $r['extension'],
				'name' => (string) $r['name'],
				'tech' => (string) ($r['tech'] ?? ''),
			];
		}
		return $this->userCache = $out;
	}

	public function forgetCache(): void
	{
		$this->userCache = null;
		$this->hintCache = null;
	}

	public function displayName(string $ext): ?string
	{
		return $this->extensions()[$ext]['name'] ?? null;
	}

	/** SIP credentials for a device (extension). */
	public function sipAccount(string $ext): ?array
	{
		$st = $this->db->prepare("SELECT keyword, data FROM sip WHERE id = ? AND keyword IN ('secret','account','username','transport')");
		$st->execute([$ext]);
		$kv = [];
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$kv[$r['keyword']] = $r['data'];
		}
		if (!isset($kv['secret'])) {
			return null;
		}
		return [
			'auth' => $ext,
			'user' => $ext,
			'secret' => $kv['secret'],
			'transport' => $kv['transport'] ?? '',
		];
	}

	/** PJSIP UDP port (sipsettings udpport-0.0.0.0, default 5060). */
	public function pjsipPort(): int
	{
		try {
			$st = $this->db->query("SELECT data FROM sipsettings WHERE keyword = 'udpport-0.0.0.0'");
			$v = $st ? $st->fetchColumn() : false;
			return $v ? (int) $v : 5060;
		} catch (\Throwable $e) {
			return 5060;
		}
	}

	/**
	 * PBX time zone: FreePBX's PHPTIMEZONE unless it is blank/UTC (the
	 * install default, often never changed), then the OS zone.
	 */
	public function timezone(): string
	{
		$tz = '';
		try {
			if ($this->freepbx) {
				$tz = (string) $this->freepbx->Config->get('PHPTIMEZONE');
			}
		} catch (\Throwable $e) {
		}
		if ($tz !== '' && !in_array(strtoupper($tz), ['UTC', 'GMT', 'ETC/UTC'], true)) {
			return $tz;
		}
		$sys = self::systemTimezone();
		return $sys ?? ($tz !== '' ? $tz : date_default_timezone_get());
	}

	public static function systemTimezone(): ?string
	{
		if (is_readable('/etc/timezone')) {
			$z = trim((string) file_get_contents('/etc/timezone'));
			if ($z !== '') {
				return $z;
			}
		}
		$link = @readlink('/etc/localtime');
		if ($link && preg_match('#zoneinfo/(.+)$#', $link, $m)) {
			return $m[1];
		}
		return null;
	}

	/** Enabled feature codes: [['module','feature','description','code']]. */
	public function featureCodes(): array
	{
		$rows = $this->db->query(
			"SELECT modulename, featurename, description, defaultcode, customcode FROM featurecodes WHERE enabled = 1 ORDER BY modulename, featurename"
		)->fetchAll(PDO::FETCH_ASSOC);
		$out = [];
		foreach ($rows as $r) {
			$code = trim((string) ($r['customcode'] ?: $r['defaultcode']));
			if ($code === '') {
				continue;
			}
			$out[] = [
				'module' => $r['modulename'],
				'feature' => $r['featurename'],
				'description' => $r['description'],
				'code' => $code,
			];
		}
		return $out;
	}

	/**
	 * Dialplan hints as [pattern-or-exten => true], from AMI ExtensionStateList
	 * when available, else `core show hints`.
	 */
	public function hints(): array
	{
		if ($this->hintCache !== null) {
			return $this->hintCache;
		}
		$out = [];
		$text = '';
		global $astman;
		if (isset($astman) && $astman && method_exists($astman, 'command')) {
			$res = $astman->command('core show hints');
			$text = is_array($res) ? (string) ($res['data'] ?? '') : (string) $res;
		}
		foreach (preg_split('/\n/', $text) as $line) {
			if (preg_match('/^\s*(\S+?)@(\S+)\s+:/', $line, $m)) {
				$out[$m[1]] = $m[2];
			}
		}
		return $this->hintCache = $out;
	}

	/** Does dialling $code have a hint (so a BLF key will show state)? */
	public function hasHint(string $code): bool
	{
		foreach ($this->hints() as $pattern => $ctx) {
			$pattern = (string) $pattern; // numeric extens become int array keys
			if ($pattern === $code) {
				return true;
			}
			if ($pattern !== '' && $pattern[0] === '_' && self::patternMatches(substr($pattern, 1), $code)) {
				return true;
			}
		}
		return false;
	}

	/** Asterisk extension pattern match (X, Z, N, ., !, [..]). */
	public static function patternMatches(string $pattern, string $value): bool
	{
		$re = '';
		$len = strlen($pattern);
		for ($i = 0; $i < $len; $i++) {
			$c = $pattern[$i];
			switch ($c) {
				case 'X': $re .= '[0-9]'; break;
				case 'Z': $re .= '[1-9]'; break;
				case 'N': $re .= '[2-9]'; break;
				case '.': $re .= '.+'; break;
				case '!': $re .= '.*'; break;
				case '[':
					$end = strpos($pattern, ']', $i);
					if ($end === false) {
						return false;
					}
					$re .= '[' . preg_quote(substr($pattern, $i + 1, $end - $i - 1), '/') . ']';
					$i = $end;
					break;
				default: $re .= preg_quote($c, '/');
			}
		}
		return (bool) preg_match('/^' . $re . '$/', $value);
	}

	/**
	 * Catalog of things a key can point at, for the GUI/API pickers and for
	 * permission gating in front ends. Each entry:
	 *   ['id','category','label','type','value','perExtension','hint','module']
	 * 'perExtension' entries contain "{ext}" in value, to be filled with the
	 * extension the key is for (follow-me, DND, call forward toggles).
	 */
	public function keyTargets(): array
	{
		$out = [];
		foreach ($this->extensions() as $e) {
			$out[] = [
				'id' => 'ext:' . $e['extension'], 'category' => 'extension',
				'label' => $e['extension'] . ' ' . $e['name'], 'type' => 'blf',
				'value' => $e['extension'], 'perExtension' => false, 'hint' => true, 'module' => 'core',
			];
		}
		$perExt = [
			'findmefollow/fmf_toggle' => 'Follow-me toggle',
			'donotdisturb/dnd_toggle' => 'DND toggle',
			'callforward/cf_toggle' => 'Call forward toggle',
		];
		// In-call codes and bare prefixes (need digits appended) are useless on a key.
		$skip = [
			'core/atxfer', 'core/automon', 'core/blindxfer', 'core/disconnect', 'core/simu_pstn', 'fax/simu_fax',
			'parking/parkedcall', 'speeddial/callspeeddial', 'paging/intercom-prefix', 'voicemail/directdialvoicemail',
		];
		foreach ($this->featureCodes() as $f) {
			$key = $f['module'] . '/' . $f['feature'];
			if (in_array($key, $skip, true)) {
				continue;
			}
			if (isset($perExt[$key])) {
				$value = $f['code'] . '{ext}';
				$out[] = [
					'id' => 'fc:' . $key, 'category' => 'feature', 'label' => $perExt[$key],
					'type' => 'feature', 'value' => $value, 'perExtension' => true,
					'hint' => $this->hasHint($f['code'] . '0000'), 'module' => $f['module'],
				];
				continue;
			}
			$isToggle = in_array($f['module'], ['timeconditions', 'daynight'], true);
			$category = match ($f['module']) {
				'timeconditions' => 'time_condition',
				'daynight' => 'call_flow',
				'blacklist', 'allowlist' => 'blocklist',
				'parking', 'parkpro' => 'parking',
				'queues' => 'queue',
				default => 'feature',
			};
			$hint = $this->hasHint($f['code']);
			$out[] = [
				'id' => 'fc:' . $key, 'category' => $category,
				'label' => $f['description'] . ' (' . $f['code'] . ')',
				'type' => $hint ? 'feature' : 'feature_dial',
				'value' => $f['code'], 'perExtension' => false, 'hint' => $hint || $isToggle,
				'module' => $f['module'],
			];
		}
		// Park slots with hints
		foreach ($this->hints() as $exten => $ctx) {
			$exten = (string) $exten;
			if ($ctx === 'park-hints' && preg_match('/^\d+$/', $exten)) {
				$out[] = [
					'id' => 'park:' . $exten, 'category' => 'parking', 'label' => 'Park slot ' . $exten,
					'type' => 'park_slot', 'value' => $exten, 'perExtension' => false, 'hint' => true, 'module' => 'parking',
				];
			}
		}
		return $out;
	}

	/** userman user id whose default extension is $ext. */
	public function usermanUid(string $ext): ?int
	{
		$st = $this->db->prepare('SELECT id FROM userman_users WHERE default_extension = ? LIMIT 1');
		$st->execute([$ext]);
		$v = $st->fetchColumn();
		return $v === false ? null : (int) $v;
	}
}
