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
	private ?array $usermanCache = null;
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

	/**
	 * Rename an extension in place: exactly what FreePBX's extension page
	 * changes for a new Display Name (probed 2026-11-12), and nothing else:
	 * users.name, devices.description, the SIP callerid ("Name <ext>"), AstDB
	 * AMPUSER/<ext>/cidname and the voicemail box name. Apply Config is still
	 * needed for Asterisk's PJSIP caller ID (the caller schedules it).
	 *
	 * Not core's GraphQL updateExtension: that deletes and recreates the
	 * extension and its User Management user (losing groups, password, UCP
	 * and other modules' per-user data).
	 */
	public function renameExtension(string $ext, string $name): array
	{
		$old = $this->displayName($ext);
		if ($old === null) {
			throw new \InvalidArgumentException("Extension $ext doesn't exist");
		}
		$this->db->prepare('UPDATE users SET name = ? WHERE extension = ?')->execute([$name, $ext]);
		$this->db->prepare('UPDATE devices SET description = ? WHERE id = ? AND user = ?')->execute([$name, $ext, $ext]);
		$this->db->prepare("UPDATE sip SET data = ? WHERE id = ? AND keyword = 'callerid'")->execute(["$name <$ext>", $ext]);
		$done = ['users', 'devices', 'sip'];
		try {
			$astman = $this->freepbx->astman ?? null;
			if ($astman && $astman->connected()) {
				$astman->database_put('AMPUSER', "$ext/cidname", $name);
				$done[] = 'astdb';
			}
		} catch (\Throwable $e) {
		}
		try {
			$vm = $this->freepbx->Voicemail;
			$box = $vm->getMailbox($ext, false);
			if ($box) {
				$box['name'] = $name;
				$vm->updateMailbox($ext, $box, false);
				$done[] = 'voicemail';
			}
		} catch (\Throwable $e) {
		}
		$this->forgetCache();
		return ['old' => $old, 'new' => $name, 'updated' => $done];
	}

	/**
	 * Update the User Management user whose default extension is $ext.
	 * $fields: any of fname, lname, displayname, email (others untouched).
	 */
	public function updateUserDetails(string $ext, array $fields): array
	{
		$um = $this->freepbx->Userman;
		$u = $um->getUserByDefaultExtension($ext);
		if (empty($u['id'])) {
			throw new \InvalidArgumentException("Extension $ext has no User Management user");
		}
		$fields = array_intersect_key($fields, array_flip(['fname', 'lname', 'displayname', 'email']));
		if (!$fields) {
			return ['uid' => (int) $u['id'], 'updated' => []];
		}
		if (!$um->updateUserExtraData($u['id'], $fields)) {
			throw new \RuntimeException('User Management refused the update');
		}
		$this->forgetCache();
		return ['uid' => (int) $u['id'], 'updated' => array_keys($fields)];
	}

	public function forgetCache(): void
	{
		$this->userCache = null;
		$this->usermanCache = null;
		$this->hintCache = null;
		$this->groupCache = null;
		$this->bookCache = [];
	}

	public function displayName(string $ext): ?string
	{
		return $this->extensions()[$ext]['name'] ?? null;
	}

	/**
	 * The User Management user linked to an extension (its default
	 * extension): ['uid', 'username', 'fname', 'lname', 'displayname', 'email'],
	 * or null.
	 */
	public function userFor(string $ext): ?array
	{
		if ($this->usermanCache === null) {
			$this->usermanCache = [];
			try {
				$rows = $this->db->query("SELECT id, username, default_extension, fname, lname, displayname, email FROM userman_users WHERE default_extension IS NOT NULL AND default_extension NOT IN ('', 'none')")->fetchAll(PDO::FETCH_ASSOC);
			} catch (\Throwable $e) {
				$rows = []; // no User Management
			}
			foreach ($rows as $r) {
				$this->usermanCache[(string) $r['default_extension']] = [
					'uid' => (int) $r['id'], 'username' => (string) $r['username'],
					'fname' => trim((string) $r['fname']), 'lname' => trim((string) $r['lname']),
					'displayname' => trim((string) $r['displayname']), 'email' => trim((string) $r['email']),
				];
			}
		}
		return $this->usermanCache[$ext] ?? null;
	}

	/**
	 * Name parts for labels: ['name', 'first', 'last'] from the extension's
	 * display name ('extension') or from User Management ('user': display
	 * name / first / last name, each falling back to the extension's).
	 * Null when the extension doesn't exist.
	 */
	public function nameParts(string $ext, string $source = 'extension'): ?array
	{
		$name = $this->displayName($ext);
		if ($name === null) {
			return null;
		}
		$words = preg_split('/\s+/', trim($name)) ?: [];
		$parts = ['name' => trim($name), 'first' => $words[0] ?? '', 'last' => count($words) > 1 ? end($words) : ''];
		if ($source === 'user' && ($u = $this->userFor($ext))) {
			$full = trim($u['fname'] . ' ' . $u['lname']);
			$parts = [
				'name' => $u['displayname'] !== '' ? $u['displayname'] : ($full !== '' ? $full : $parts['name']),
				'first' => $u['fname'] !== '' ? $u['fname'] : $parts['first'],
				'last' => $u['lname'] !== '' ? $u['lname'] : $parts['last'],
			];
		}
		return $parts;
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

	protected ?array $transports = null;

	/**
	 * PJSIP transports as FreePBX 17 configures them (Settings → Asterisk SIP
	 * Settings → PJSIP): Sipsettings' "binds" ({proto: {ip: "on"}}) and its
	 * "<proto>port-<ip>" / "<proto>extport-<ip>" keys, the same data core's
	 * PJSip driver builds transports from. (The old `sipsettings` table holds
	 * chan_sip's settings, e.g. bindport, and never these.)
	 * Returns ["<ip>-<proto>" => {proto, ip, port, extport}].
	 */
	public function pjsipTransports(): array
	{
		if ($this->transports !== null) {
			return $this->transports;
		}
		$get = null;
		try {
			if ($this->freepbx) {
				$ss = $this->freepbx->Sipsettings;
				$get = fn(string $k) => $ss->getConfig($k);
			}
		} catch (\Throwable $e) {
		}
		if (!$get) {
			$get = function (string $k) {
				try {
					$st = $this->db->prepare('SELECT val FROM kvstore_Sipsettings WHERE `key` = ? AND id = \'noid\'');
					$st->execute([$k]);
					$v = $st->fetchColumn();
				} catch (\Throwable $e) {
					return null;
				}
				if ($v === false) {
					return null;
				}
				$j = json_decode((string) $v, true);
				return is_array($j) ? $j : $v;
			};
		}
		$out = [];
		$binds = $get('binds');
		foreach (is_array($binds) ? $binds : [] as $proto => $ips) {
			foreach ((array) $ips as $ip => $on) {
				if ($on !== 'on') {
					continue;
				}
				$port = (int) $get("{$proto}port-$ip");
				$out["$ip-$proto"] = [
					'proto' => (string) $proto,
					'ip' => (string) $ip,
					'port' => $port ?: ($proto === 'tls' ? 5061 : 5060),
					'extport' => (int) trim((string) $get("{$proto}extport-$ip")),
				];
			}
		}
		return $this->transports = $out;
	}

	/**
	 * Port a phone registers to. $extTransport: the extension's own transport
	 * ("0.0.0.0-udp", from sipAccount()); else the first enabled transport of
	 * $proto (udp/tcp/tls; 0.0.0.0 preferred), else UDP's. $external: the
	 * transport's external signaling port when FreePBX has one (NAT).
	 */
	public function pjsipPort(string $proto = 'udp', string $extTransport = '', bool $external = false): int
	{
		$all = $this->pjsipTransports();
		$t = $all[$extTransport] ?? null;
		foreach ([$proto, 'udp'] as $p) {
			if ($t) {
				break;
			}
			$t = $all["0.0.0.0-$p"] ?? null;
			foreach ($all as $c) {
				if (!$t && $c['proto'] === $p) {
					$t = $c;
				}
			}
		}
		if (!$t) {
			return $proto === 'tls' ? 5061 : 5060;
		}
		return $external && $t['extport'] > 0 ? $t['extport'] : $t['port'];
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
					// Character class: keep ranges (1-3), escape only regex-special chars.
					$re .= '[' . addcslashes(substr($pattern, $i + 1, $end - $i - 1), '\\]^/') . ']';
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
		// Ring groups: dialled like an extension, so a key calls the group and,
		// with dss_transfer, hands a call to it. FreePBX gives ring groups no
		// hint, so these are plain speed dials with no status light.
		foreach ($this->ringGroups() as $g) {
			$out[] = [
				'id' => 'rg:' . $g['number'], 'category' => 'ring_group',
				'label' => $g['number'] . ' ' . $g['name'],
				'type' => 'speeddial', 'value' => $g['number'], 'perExtension' => false, 'hint' => false,
				'module' => 'ringgroups',
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

	/**
	 * FreePBX ring groups: [['number','name','members']], by number. Empty when
	 * the ringgroups module isn't installed (no table). `members` is FreePBX's
	 * grplist split on '-', outside numbers keeping their trailing '#'.
	 */
	public function ringGroups(): array
	{
		try {
			$rows = $this->db->query(
				"SELECT grpnum, description, grplist FROM ringgroups ORDER BY CAST(grpnum AS UNSIGNED), grpnum"
			)->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [];
		}
		$out = [];
		foreach ($rows as $r) {
			$number = trim((string) $r['grpnum']);
			if (!preg_match('/^\d+$/', $number)) {
				continue;
			}
			$name = trim((string) ($r['description'] ?? ''));
			$out[] = [
				'number' => $number,
				'name' => $name !== '' ? $name : 'Ring group ' . $number,
				'members' => array_values(array_filter(
					preg_split('/[-\s]+/', (string) ($r['grplist'] ?? '')),
					fn($m) => preg_match('/^\d+#?$/', $m) === 1
				)),
			];
		}
		return $out;
	}

	/**
	 * Contact Manager groups: [['id', 'name', 'type', 'owner']], by name.
	 * type is Contact Manager's: internal (User Management users), external
	 * (shared contacts) or private (one user's own); owner is the owning
	 * user's username for private groups, else ''. Empty without the module.
	 */
	public function contactGroups(): array
	{
		if ($this->groupCache !== null) {
			return $this->groupCache;
		}
		$cm = $this->contactManager();
		if (!$cm) {
			return $this->groupCache = [];
		}
		try {
			$groups = (array) $cm->getGroups();
		} catch (\Throwable $e) {
			return $this->groupCache = [];
		}
		$owners = [];
		try {
			foreach ($this->db->query('SELECT id, username FROM userman_users')->fetchAll(PDO::FETCH_ASSOC) as $u) {
				$owners[(int) $u['id']] = (string) $u['username'];
			}
		} catch (\Throwable $e) {
		}
		$out = [];
		foreach ($groups as $g) {
			$owner = (int) ($g['owner'] ?? -1);
			$out[] = [
				'id' => (int) $g['id'],
				'name' => (string) $g['name'],
				'type' => (string) ($g['type'] ?? ''),
				'owner' => $owner > 0 ? ($owners[$owner] ?? (string) $owner) : '',
			];
		}
		usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);
		return $this->groupCache = $out;
	}

	/** Contact Manager reads for this request (every phone's render uses them). */
	private ?array $groupCache = null;
	private array $bookCache = [];

	/**
	 * A Contact Manager group's entries as a phone directory:
	 * [['name', 'numbers' => [['number', 'type']]]], sorted by name. Entries
	 * without a dialable number are left out. Names: display name, else first
	 * and last name, else company, else the FreePBX name of an extension
	 * number, else the first number (User Management entries often have no
	 * name of their own). Numbers: Contact Manager's digits-only form when it
	 * has one, so incoming calls match. null = no such group.
	 */
	public function contactBook(int $groupId): ?array
	{
		if (array_key_exists($groupId, $this->bookCache)) {
			return $this->bookCache[$groupId];
		}
		$cm = $this->contactManager();
		if (!$cm || !in_array($groupId, array_column($this->contactGroups(), 'id'), true)) {
			return $this->bookCache[$groupId] = null;
		}
		try {
			$entries = (array) $cm->getEntriesByGroupID($groupId);
		} catch (\Throwable $e) {
			return $this->bookCache[$groupId] = null;
		}
		$exts = $this->extensions();
		$out = [];
		foreach ($entries as $e) {
			$numbers = [];
			foreach ((array) ($e['numbers'] ?? []) as $n) {
				$num = trim((string) (($n['stripped'] ?? '') !== '' ? $n['stripped'] : ($n['number'] ?? '')));
				if ($num === '' || !preg_match('/^[+*#0-9A-Za-z@._-]+$/', $num)) {
					continue;
				}
				$numbers[$num] ??= ['number' => $num, 'type' => strtolower(trim((string) ($n['type'] ?? '')))];
			}
			if (!$numbers) {
				continue;
			}
			$name = trim((string) ($e['displayname'] ?? ''));
			if ($name === '') {
				$name = trim(trim((string) ($e['fname'] ?? '')) . ' ' . trim((string) ($e['lname'] ?? '')));
			}
			if ($name === '') {
				$name = trim((string) ($e['company'] ?? ''));
			}
			if ($name === '') {
				foreach ($numbers as $n) {
					if (($exts[$n['number']]['name'] ?? '') !== '') {
						$name = $exts[$n['number']]['name'];
						break;
					}
				}
			}
			if ($name === '') {
				$name = reset($numbers)['number']; // not the key: PHP makes "7775550000" an int
			}
			$out[] = ['name' => $name, 'numbers' => array_values($numbers)];
		}
		usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
		return $this->bookCache[$groupId] = $out;
	}

	/**
	 * The caller's Contact Manager photo, as stored (PNG/JPEG bytes), or null.
	 * Uses Contact Manager's own global lookup (its number matching, the
	 * user's photo for an extension, contacts in shared groups); private
	 * groups are never searched.
	 */
	public function callerPhoto(string $number): ?string
	{
		$cm = $this->contactManager();
		if (!$cm || !method_exists($cm, 'lookupNumberByUserID')) {
			return null;
		}
		try {
			$e = $cm->lookupNumberByUserID(-1, $number);
			if (!is_array($e)) {
				return null;
			}
			$img = $e['image']['image'] ?? null;
			if (!is_string($img) || $img === '') {
				// Matched through its fuzzy pass, which returns the entry without its image.
				$id = $e['id'] ?? null;
				$full = $id !== null && method_exists($cm, 'getEntryByID') ? $cm->getEntryByID($id) : null;
				$img = is_array($full) ? ($full['image']['image'] ?? null) : null;
			}
			return is_string($img) && $img !== '' ? $img : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** FreePBX's Contact Manager module, or null when it isn't installed. */
	private function contactManager(): ?object
	{
		try {
			$cm = $this->freepbx?->Contactmanager;
			return is_object($cm) && method_exists($cm, 'getGroups') ? $cm : null;
		} catch (\Throwable $e) {
			return null;
		}
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
