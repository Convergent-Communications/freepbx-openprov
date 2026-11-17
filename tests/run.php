<?php
/**
 * OpenProv test suite. No Composer, no FreePBX: runs with plain PHP 8.1+
 * (pdo_sqlite) anywhere, including on the PBX:
 *
 *   php tests/run.php            # all tests
 *   php tests/run.php render     # tests whose name contains "render"
 */
declare(strict_types=1);

namespace FreePBX\modules\Openprov\Tests;

use FreePBX\modules\Openprov\{Assets, Catalog, ConfigBuilder, Notifier, Pbx, ProvisionServer, Service, Settings, Store, Tar, TlsService, Util, Vpn, Web};
use FreePBX\modules\Openprov\Brand\Yealink;
use PDO;

$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
	$prefix = 'FreePBX\\modules\\Openprov\\';
	if (str_starts_with($class, $prefix)) {
		$file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});

// ---- tiny assertion framework ------------------------------------------------

final class Fail extends \Exception
{
}

function ok($cond, string $msg = 'assertion failed'): void
{
	if (!$cond) {
		throw new Fail($msg);
	}
}

function eq($expected, $actual, string $msg = ''): void
{
	if ($expected !== $actual) {
		throw new Fail(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function throws(callable $fn, string $contains = ''): void
{
	try {
		$fn();
	} catch (\Throwable $e) {
		if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
			throw new Fail("exception message '{$e->getMessage()}' lacks '$contains'");
		}
		return;
	}
	throw new Fail('expected an exception');
}

// ---- fakes ---------------------------------------------------------------------

class FakePbx extends Pbx
{
	/** User Management users by default extension. */
	public array $userman = [];

	public function userFor(string $ext): ?array
	{
		return $this->userman[$ext] ?? null;
	}

	public function renameExtension(string $ext, string $name): array
	{
		$old = $this->users[$ext] ?? null;
		if ($old === null) {
			throw new \InvalidArgumentException("Extension $ext doesn't exist");
		}
		$this->users[$ext] = $name;
		return ['old' => $old, 'new' => $name, 'updated' => ['users']];
	}

	public function updateUserDetails(string $ext, array $fields): array
	{
		if (!isset($this->userman[$ext])) {
			throw new \InvalidArgumentException("Extension $ext has no User Management user");
		}
		$this->userman[$ext] = array_merge($this->userman[$ext], $fields);
		return ['uid' => $this->userman[$ext]['uid'], 'updated' => array_keys($fields)];
	}

	public array $users = [
		'201' => 'Alice Smith',
		'202' => 'Bob Jones',
		'212' => 'Robert Ellis',
	];

	public function extensions(): array
	{
		$out = [];
		foreach ($this->users as $e => $n) {
			$out[(string) $e] = ['extension' => (string) $e, 'name' => $n, 'tech' => 'pjsip'];
		}
		return $out;
	}

	public function sipAccount(string $ext): ?array
	{
		return isset($this->users[$ext]) ? ['auth' => $ext, 'user' => $ext, 'secret' => "secret$ext", 'transport' => ''] : null;
	}

	/** Set to fake FreePBX's PJSIP transports; null = none configured. */
	public function setTransports(?array $t): void
	{
		$this->transports = $t ?? [];
	}

	public function pjsipTransports(): array
	{
		return $this->transports ?? [];
	}

	public function timezone(): string
	{
		return 'America/New_York';
	}

	public function featureCodes(): array
	{
		return [
			['module' => 'timeconditions', 'feature' => 'toggle-mode-1', 'description' => '1: Main hours (temp)', 'code' => '*271'],
			['module' => 'findmefollow', 'feature' => 'fmf_toggle', 'description' => 'Findme Follow Toggle', 'code' => '*21'],
			['module' => 'core', 'feature' => 'atxfer', 'description' => 'In-Call Attended Transfer', 'code' => '*2'],
		];
	}

	public function hints(): array
	{
		return ['*271' => 'timeconditions-toggles', '_*21X!' => 'ext-findmefollow', '71' => 'park-hints'];
	}

	public function usermanUid(string $ext): ?int
	{
		return $ext === '212' ? 6 : null;
	}
}

class FakeVpn extends Vpn
{
	public ?string $version = 'v1';
	public ?int $expires = null;
	public ?string $ca = null;
	public array $connected = [];

	public function connectedClients(): array
	{
		return $this->connected;
	}

	public function caFingerprint(): ?string
	{
		return $this->ca;
	}

	public function clients(): array
	{
		return [6 => ['id' => 6, 'description' => '212', 'enabled' => true, 'serverremote' => '192.0.2.1', 'remote' => '', 'useserverremote' => true, 'hasFiles' => true,
			'expires' => $this->expires ?? time() + 700 * 86400, 'caExpires' => time() + 720 * 86400]];
	}

	public function profileVersion(int $clientId): ?string
	{
		return $this->version;
	}

	public function clientForUser(int $uid): ?int
	{
		return $uid === 6 ? 6 : null;
	}

	public function serverTunnelAddress(): ?string
	{
		return '10.8.0.1';
	}

	public function tunnelNetwork(): ?array
	{
		return ['10.8.0.0', 24];
	}

	public function port(): int
	{
		return 1194;
	}

	public function serverEnabled(): bool
	{
		return true;
	}

	public function yealinkTar(int $clientId, ?array $remotes = null): ?string
	{
		return Tar::build(['vpn.cnf' => 'remote ' . implode(',', $remotes ?? []) . "\n"]);
	}
}

/** A fresh in-memory world: sqlite schema + all real classes wired with fakes. */
function world(): object
{
	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec('CREATE TABLE openprov_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, brand TEXT DEFAULT "yealink", parent_id INTEGER, description TEXT DEFAULT "", settings TEXT, raw TEXT, updated_at INTEGER DEFAULT 0)');
	$db->exec('CREATE TABLE openprov_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, mac TEXT UNIQUE, brand TEXT, model TEXT, template_id INTEGER, name TEXT DEFAULT "", enabled INTEGER DEFAULT 1, settings TEXT, raw TEXT, prov_user TEXT DEFAULT "", prov_pass TEXT DEFAULT "", claimable INTEGER DEFAULT 1, last_seen INTEGER DEFAULT 0, last_ip TEXT DEFAULT "", last_ua TEXT DEFAULT "", last_fw TEXT DEFAULT "", last_hash TEXT DEFAULT "", notified_hash TEXT DEFAULT "", cert_pin TEXT DEFAULT "", updated_at INTEGER DEFAULT 0)');
	$db->exec('CREATE TABLE openprov_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, account INTEGER, extension TEXT DEFAULT "", label TEXT DEFAULT "", custom TEXT, UNIQUE(device_id, account))');
	$db->exec('CREATE TABLE openprov_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT, scope_id INTEGER, bank TEXT, position INTEGER, type TEXT, value TEXT DEFAULT "", label TEXT DEFAULT "", account INTEGER DEFAULT 1, extra TEXT, UNIQUE(scope, scope_id, bank, position))');
	$db->exec('CREATE TABLE openprov_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, name TEXT, filename TEXT, mime TEXT DEFAULT "", size INTEGER DEFAULT 0, sha256 TEXT DEFAULT "", model TEXT DEFAULT "", version TEXT DEFAULT "", created_at INTEGER DEFAULT 0)');
	$db->exec('CREATE TABLE openprov_events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, mac TEXT DEFAULT "", ip TEXT DEFAULT "", ua TEXT DEFAULT "", path TEXT DEFAULT "", status INTEGER DEFAULT 0, detail TEXT DEFAULT "")');

	$kv = [];
	$settings = new Settings(function ($k) use (&$kv) {
		return $kv[$k] ?? null;
	}, function ($k, $v) use (&$kv) {
		$kv[$k] = $v;
	});
	$settings->set('internal_host', '192.0.2.10');
	$settings->set('bootstrap_user', 'boot');
	$settings->set('bootstrap_pass', 'bootpass');
	$settings->set('admin_password', '24681357');
	$settings->set('user_password', '1357');
	$db->exec('CREATE TABLE openprov_features (id TEXT PRIMARY KEY, slot INTEGER DEFAULT 0, def TEXT, updated_at INTEGER DEFAULT 0)');
	$store = new Store($db);
	$catalog = new Catalog(dirname(__DIR__) . '/models');
	\FreePBX\modules\Openprov\Features::load($store, $catalog);
	$pbx = new FakePbx($db);
	$vpn = new FakeVpn($db);
	$dir = sys_get_temp_dir() . '/openprov-test-' . bin2hex(random_bytes(4));
	$assets = new Assets($store, $dir);
	$builder = new ConfigBuilder($store, $catalog, $pbx, $vpn, $assets, $settings, ['yealink' => new Yealink()]);
	$sent = new \ArrayObject();
	$notifier = new Notifier(function ($ep, $event) use ($sent) {
		$sent[] = [$ep, $event];
		return ['ok' => true, 'message' => 'sent'];
	});
	$svc = new Service($store, $catalog, $pbx, $vpn, $assets, $settings, $builder, $notifier, new Web($settings, '/nonexistent-webroot', dirname(__DIR__) . '/htdocs', ['run' => fn($c) => [1, ''], 'root' => false, 'settle' => 0, 'probe' => fn($u) => false, 'firewall' => fn() => null]));
	$server = new ProvisionServer($store, $catalog, $builder, $settings, $assets, $vpn);
	return (object) compact('db', 'settings', 'store', 'catalog', 'pbx', 'vpn', 'assets', 'builder', 'svc', 'server', 'sent', 'dir');
}

const UA = 'Yealink SIP-T87W 185.87.0.37 c4:fc:22:a1:b2:c3';
const MAC = 'c4fc22a1b2c3';

function phone(object $w, array $extra = []): array
{
	$tpl = $w->svc->saveTemplate(['name' => 'Base', 'settings' => ['label_format' => '{first}'], 'keys' => [['position' => 1, 'type' => 'line', 'account' => 1]]]);
	return $w->svc->saveDevice($extra + ['mac' => 'C4:FC:22:A1:B2:C3', 'model' => 'T87W', 'template' => $tpl['id'], 'lines' => [['account' => 1, 'extension' => '212']]]);
}

function kv(string $cfg): array
{
	$out = [];
	foreach (explode("\n", $cfg) as $l) {
		if ($l !== '' && $l[0] !== '#' && str_contains($l, ' = ')) {
			[$k, $v] = explode(' = ', $l, 2);
			$out[$k][] = $v;
		}
	}
	return array_map(fn($v) => count($v) === 1 ? $v[0] : $v, $out);
}

// ---- tests -----------------------------------------------------------------------

$tests = [];

$tests['util: mac normalisation'] = function () {
	eq('c4fc22a1b2c3', Util::mac('C4:FC:22:A1:B2:C3'));
	eq('c4fc22a1b2c3', Util::mac('c4-fc-22-a1-b2-c3'));
	eq(null, Util::mac('c4fc22a1b2'));
	eq('c4:fc:22:a1:b2:c3', Util::macPretty('C4FC22A1B2C3'));
};

$tests['util: label formats'] = function () {
	eq('Alice', Util::label('{first}', '201', 'Alice Smith'));
	eq('201 Alice Smith', Util::label('{ext} {name}', '201', 'Alice Smith'));
	eq('201', Util::label('{name}', '201', ''));
	eq('Smith', Util::label('{last}', '201', 'Alice Smith'));
};

$tests['util: raw key parsing ignores junk and keeps repeated keys'] = function () {
	$kv = Util::parseKv("# comment\nfoo.bar = 1\n bad line\nx.y=  two words \n;c\nfoo.bar = 2\n");
	eq(['foo.bar' => '1', 'x.y' => 'two words', 'foo.bar#2' => '2'], $kv, 'repeats kept, in order');
	// …and written out as plain repeated lines
	$w = world();
	phone($w, ['raw' => "ringtone.url = http://a/1.wav\nringtone.url = http://a/2.wav"]);
	$text = $w->builder->render($w->store->deviceByMac(MAC))['text'];
	ok(str_contains($text, "ringtone.url = http://a/1.wav\nringtone.url = http://a/2.wav"), 'both lines rendered');
};

$tests['util: CIDR allow-list'] = function () {
	ok(Util::ipAllowed('192.168.1.5', []));
	ok(Util::ipAllowed('192.168.1.5', ['192.168.1.0/24']));
	ok(!Util::ipAllowed('192.168.2.5', ['192.168.1.0/24']));
	ok(Util::ipAllowed('10.8.0.2', ['192.168.1.0/24', '10.8.0.0/16']));
	ok(Util::ipAllowed('10.0.0.1', ['10.0.0.1']));
	ok(!Util::ipAllowed('not-an-ip', ['0.0.0.0/0']));
	ok(Util::ipAllowed('2001:db8::1', ['2001:db8::/32']));
};

$tests['tar: output is readable by tar(1)'] = function () {
	$f = tempnam(sys_get_temp_dir(), 'tar');
	file_put_contents($f, Tar::build(['vpn.cnf' => "client\n", 'keys/ca.crt' => str_repeat('A', 700)]));
	exec('tar -tvf ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
	@unlink($f);
	eq(0, $rc, implode("\n", $out));
	ok(str_contains(implode("\n", $out), 'keys/ca.crt'));
	ok(str_contains(implode("\n", $out), '700'));
};

$tests['pbx: asterisk pattern matching'] = function () {
	ok(Pbx::patternMatches('*21X!', '*21212'));
	ok(Pbx::patternMatches('*76X.', '*76212'));
	ok(!Pbx::patternMatches('*76X.', '*76'));
	ok(Pbx::patternMatches('NXX', '212'));
	ok(!Pbx::patternMatches('NXX', '112'));
	ok(Pbx::patternMatches('[1-3]XX', '201'));
};

$tests['catalog: model from user agent'] = function () {
	$c = new Catalog(dirname(__DIR__) . '/models');
	eq('T87W', $c->modelFromUserAgent(UA)['id']);
	eq('T21E2', $c->modelFromUserAgent('Yealink SIP-T21P_E2 52.84.0.15 00:15:65:aa:bb:cc')['id']);
	eq(null, $c->modelFromUserAgent('Polycom VVX 400'));
	eq(84, $c->model('yealink', 'T87W')['lineKeys']);
	ok(count($c->schema('yealink')) > 30);
};

$tests['settings: reboot window crosses midnight'] = function () {
	$w = world();
	$w->settings->set('reboot_window', '22:00-05:00');
	ok($w->settings->inRebootWindow(strtotime('2026-01-01 23:30')));
	ok($w->settings->inRebootWindow(strtotime('2026-01-01 04:59')));
	ok(!$w->settings->inRebootWindow(strtotime('2026-01-01 12:00')));
	throws(fn() => $w->settings->set('reboot_window', 'noon'), 'reboot_window');
	throws(fn() => $w->settings->set('admin_password', 'admin'), 'admin');
};

$tests['service: device validation'] = function () {
	$w = world();
	throws(fn() => $w->svc->saveDevice(['mac' => 'zz', 'model' => 'T87W']), 'MAC');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'model' => 'T99X']), 'Unknown model');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'lines' => [['account' => 1, 'extension' => '999']]]), 'does not exist');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'lines' => [['account' => 17, 'extension' => '212']]]), 'out of range');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'keys' => [['position' => 85, 'type' => 'blf', 'value' => '201']]]), 'out of range');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'keys' => [['position' => 2, 'type' => 'teleport']]]), 'Unknown key type');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'settings' => ['nope' => '1']]), 'Unknown setting');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'settings' => ['time_format' => '9']]), 'Invalid value');
	$d = phone($w);
	eq('212 Robert Ellis', $d['name']);
	eq('********', $d['prov_pass']);
	$other = $w->svc->saveDevice(['mac' => 'aa:bb:cc:dd:ee:ff', 'model' => 'T87W']);
	throws(fn() => $w->svc->saveDevice(['id' => $other['id'], 'mac' => MAC]), 'already');
};

$tests['render: accounts, labels, keys, %NULL% clearing'] = function () {
	$w = world();
	phone($w);
	$w->svc->setKeys('device', MAC, [
		['position' => 2, 'type' => 'blf', 'value' => '201'],
		['position' => 3, 'type' => 'blf', 'value' => '202', 'label' => 'Bobby'],
		['position' => 4, 'type' => 'feature', 'value' => '*271'],
		['position' => 5, 'type' => 'feature', 'value' => '*21{ext}', 'label' => 'Follow me'],
		['position' => 6, 'type' => 'park_slot', 'value' => '71'],
	]);
	$kv = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('15', $kv['linekey.1.type']);
	eq('212', $kv['linekey.1.label']);
	eq('16', $kv['linekey.2.type']);
	eq('Alice', $kv['linekey.2.label'], 'label_format {first} from the template');
	eq('Bobby', $kv['linekey.3.label']);
	eq('Main hours', $kv['linekey.4.label'], 'feature code name from FreePBX');
	eq('*21212', $kv['linekey.5.value'], '{ext} replaced');
	eq('Park 71', $kv['linekey.6.label']);
	eq('0', $kv['linekey.84.type'], 'unused slot cleared');
	eq('%NULL%', $kv['linekey.84.label']);
	ok(!isset($kv['linekey.85.type']), 'nothing beyond the model');
	eq('secret212', $kv['account.1.password']);
	eq('1', $kv['account.1.enable']);
	eq('0', $kv['account.2.enable'], 'unused accounts disabled');
	eq('-5', $kv['local_time.time_zone']);
	eq('United States-Eastern Time', $kv['local_time.time_zone_name']);
	eq(['admin:24681357', 'user:1357'], $kv['static.security.user_password']);
	eq('https://192.0.2.10/openprov', $kv['static.auto_provision.server.url']);
	eq('c4fc22a1b2c3', $kv['static.auto_provision.server.username']);
	eq('0', $kv['static.auto_provision.custom.protect'], 'server wins over phone-side edits');
};

$tests['render: raw keys override base but not secrets'] = function () {
	$w = world();
	phone($w, ['raw' => "phone_setting.backlight_time = 0\naccount.1.password = hacked\nlinekey.2.label = Raw"]);
	$kv = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('0', $kv['phone_setting.backlight_time']);
	eq('Raw', $kv['linekey.2.label']);
	eq('secret212', $kv['account.1.password'], 'protected keys win');
};

$tests['render: template inheritance and device override'] = function () {
	$w = world();
	$parent = $w->svc->saveTemplate(['name' => 'Company', 'settings' => ['time_format' => '1', 'ntp1' => 'ntp.example'], 'raw' => ['a.b' => 'parent']]);
	$child = $w->svc->saveTemplate(['name' => 'Site', 'parent' => 'Company', 'settings' => ['time_format' => '0'], 'raw' => ['a.b' => 'child', 'c.d' => '1']]);
	$w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'template' => 'Site', 'lines' => [['account' => 1, 'extension' => '212']], 'settings' => ['ntp1' => 'ntp.device']]);
	$kv = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('0', $kv['local_time.time_format']);
	eq('ntp.device', $kv['local_time.ntp_server1']);
	eq('child', $kv['a.b']);
	throws(fn() => $w->svc->saveTemplate(['id' => $parent['id'], 'parent' => 'Site']), 'itself');
};

$tests['server: bootstrap gets only a credential; claim; per-device credentials'] = function () {
	$w = world();
	$d = phone($w, ['settings' => ['vpn' => 'auto']]);
	$old = $w->store->deviceByMac(MAC)['prov_pass'];
	eq(401, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', null, null)['status']);
	eq(401, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'wrong')['status']);
	$r = $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'bootpass');
	eq(200, $r['status'], 'bootstrap works while claimable');
	$pass = $w->store->deviceByMac(MAC)['prov_pass'];
	ok($pass !== $old, 'a fresh device credential per bootstrap answer');
	ok(str_contains($r['body'], "static.auto_provision.server.password = $pass"));
	ok(str_contains($r['body'], 'static.auto_provision.repeat.minutes = 1'), 'comes back within a minute');
	foreach (['account.1.password', 'openvpn.url', 'security.user_password', 'account.1.user_name'] as $secret) {
		ok(!str_contains($r['body'], $secret), "bootstrap answer has no $secret");
	}
	ok(str_contains($r['body'], 'server.url = https://192.0.2.10'), 'direct URL, not the VPN tunnel');
	eq(401, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', MAC, $old)['status'], 'the earlier credential is dead');
	eq(403, $w->server->handle('/c4fc22a1b2c3-vpn.tar', UA, '192.0.2.50', 'boot', 'bootpass')['status'], 'no VPN profile for bootstrap');
	ok($w->store->deviceByMac(MAC)['claimable'], 'still claimable until the phone uses its own creds');
	$full = $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', MAC, $pass);
	eq(200, $full['status']);
	ok(str_contains($full['body'], 'account.1.password'), 'full config with its own credential');
	ok(!$w->store->deviceByMac(MAC)['claimable'], 'claimed');
	eq(404, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status'], 'claimed looks like unknown to bootstrap');
	eq('185.87.0.37', $w->store->deviceByMac(MAC)['last_fw']);
	$w->svc->rearmDevice(MAC, false);
	eq(200, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status'], 're-armed');
};

$tests['security: remote API never reveals secrets or reads server files; settings reject injection'] = function () {
	$w = world();
	phone($w);
	$remote = new \FreePBX\modules\Openprov\Api($w->svc, 'remote');
	$admin = new \FreePBX\modules\Openprov\Api($w->svc, 'admin');
	ok(($remote->call('settings.get', ['reveal' => true])['bootstrap_pass'] ?? '') !== 'bootpass', 'remote: no reveal');
	eq('bootpass', $admin->call('settings.get', ['reveal' => true])['bootstrap_pass']);
	throws(fn() => $remote->call('asset.add', ['kind' => 'ringtone', 'name' => 'x.wav', 'path' => '/etc/passwd']), '');
	throws(fn() => $admin->call('asset.add', ['kind' => 'ringtone', 'name' => 'x.wav', 'path' => '/etc/passwd']), '');
	$f = tempnam(sys_get_temp_dir(), 'x');
	file_put_contents($f, "root:x:0:0\n");
	throws(fn() => (new \FreePBX\modules\Openprov\Api($w->svc, 'cli'))->call('asset.add', ['kind' => 'ringtone', 'name' => 'x.wav', 'path' => $f]), 'WAV');
	@unlink($f);
	throws(fn() => $w->settings->set('external_host', "pbx.example.com\nx"), 'single line');
	throws(fn() => $w->settings->set('vpn_external_remote', 'host 1194 ; x'), 'host port');
	$w->settings->set('allowed_networks', "10.0.0.0/8\n192.168.0.0/16");
	eq('10.0.0.0/8,192.168.0.0/16', $w->settings->get('allowed_networks'));
};

$tests['security: failed requests are logged at most once a minute; Discover needs the bootstrap credential'] = function () {
	$w = world();
	for ($i = 0; $i < 5; $i++) {
		$w->server->handle('/001565000009.cfg', 'curl', '198.51.100.7', 'x', 'y');
	}
	eq(1, count(array_filter($w->store->events(100), fn($e) => $e['ip'] === '198.51.100.7')));
	eq([], array_column($w->svc->discovered(), 'mac'), 'random credentials do not create discovered phones');
};

$tests['server: user-agent MAC, allow-list, unknown devices, paths'] = function () {
	$w = world();
	phone($w);
	eq(403, $w->server->handle('/c4fc22a1b2c3.cfg', 'Yealink SIP-T87W 185.87.0.37 c4:fc:22:a1:b2:c4', '192.0.2.50', 'boot', 'bootpass')['status']);
	eq(404, $w->server->handle('/001565000001.cfg', 'Yealink SIP-T87W 185.87.0.37 00:15:65:00:00:01', '192.0.2.51', 'boot', 'bootpass')['status']);
	$disc = $w->svc->discovered();
	eq('001565000001', $disc[0]['mac']);
	eq('T87W', $disc[0]['model']);
	eq(404, $w->server->handle('/c4fc22a1b2c3.boot', UA, '192.0.2.50', null, null)['status']);
	eq(200, $w->server->handle('/y000000000197.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status']);
	eq(400, $w->server->handle('/a/wallpaper/1/../../etc/passwd', UA, '192.0.2.50', MAC, 'x')['status']);
	$w->settings->set('allowed_networks', '10.0.0.0/8');
	eq(403, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status']);
};

$tests['server: auto-adopt unknown phones when enabled'] = function () {
	$w = world();
	$w->settings->set('auto_adopt', true);
	$r = $w->server->handle('/805ec0aabbcc.cfg', 'Yealink SIP-T54W 96.86.0.100 80:5e:c0:aa:bb:cc', '192.0.2.60', 'boot', 'bootpass');
	eq(200, $r['status']);
	eq('T54W', $w->store->deviceByMac('805ec0aabbcc')['model']);
};

$tests['server: vpn tar only for the device, with remotes'] = function () {
	$w = world();
	phone($w, ['settings' => ['vpn' => 'auto']]);
	$pass = $w->store->deviceByMac(MAC)['prov_pass'];
	$w->settings->set('vpn_external_remote', 'vpn.example.com');
	$cfg = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('1', $cfg['static.network.vpn_enable']);
	ok(str_contains($cfg['openvpn.url'], '/c4fc22a1b2c3-vpn.tar'));
	eq(401, $w->server->handle('/c4fc22a1b2c3-vpn.tar', UA, '192.0.2.50', null, null)['status']);
	$r = $w->server->handle('/c4fc22a1b2c3-vpn.tar', UA, '192.0.2.50', MAC, $pass);
	eq(200, $r['status']);
	ok(str_contains($r['body'], 'remote 192.0.2.1 1194,vpn.example.com 1194'));
};

$tests['sync: changes notify once; vpn phones are not sent check-sync'] = function () {
	$w = world();
	phone($w);
	$pass = $w->store->deviceByMac(MAC)['prov_pass'];
	$w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', MAC, $pass);
	$w->sent->exchangeArray([]);
	eq([], $w->svc->syncChanged(), 'nothing changed yet');
	$w->pbx->users['212'] = 'Rob Ellis';
	$res = $w->svc->syncChanged();
	eq(1, count($res));
	eq(['212', 'check-sync;reboot=false'], $w->sent[0]);
	eq([], $w->svc->syncChanged(), 'not re-notified for the same version');
	// a device showing 201 on a key is found by extension
	$w->svc->setKeys('device', MAC, [['position' => 2, 'type' => 'blf', 'value' => '201']]);
	eq(1, count($w->svc->devicesShowing('201')));
	// VPN phone + method none: reported, no NOTIFY
	$w->settings->set('vpn_update_method', 'none');
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['vpn' => 'auto']]);
	$before = count($w->sent);
	$r = $w->svc->resync([MAC]);
	eq('none', $r[0]['method']);
	eq($before, count($w->sent));
};

$tests['assets: wallpaper fitted to the model screen'] = function () {
	if (!function_exists('imagecreatetruecolor')) {
		return;
	}
	$w = world();
	$img = imagecreatetruecolor(1600, 1000);
	$src = tempnam(sys_get_temp_dir(), 'img') . '.png';
	imagepng($img, $src);
	$a = $w->svc->addAsset('wallpaper', 'My Logo.png', $src);
	eq('My_Logo.png', $a['name'], 'spaces removed');
	$path = $w->assets->wallpaperFor($a, ['width' => 800, 'height' => 480]);
	[$wd, $ht] = getimagesize($path);
	eq([800, 480], [$wd, $ht]);
	throws(fn() => $w->svc->addAsset('wallpaper', 'x.png', __FILE__), 'Not an image');
	throws(fn() => $w->svc->addAsset('firmware', 'x.txt', __FILE__), 'Firmware');
	$fw = tempnam(sys_get_temp_dir(), 'fw');
	file_put_contents($fw, 'rom');
	eq('185.87.0.37', $w->svc->addAsset('firmware', 'Yealink-T7X-185.87.0.37.rom', $fw)['version']);
	@unlink($src);
	@unlink($fw);
	exec('rm -rf ' . escapeshellarg($w->dir));
};

$tests['import: devices CSV round trip'] = function () {
	$w = world();
	$w->svc->saveTemplate(['name' => 'Base']);
	$r = $w->svc->importDevicesCsv("mac,model,template,name,extension\nc4:fc:22:a1:b2:c3,T87W,Base,Desk,212\nzz,T87W,,Bad,212\n");
	eq(['c4fc22a1b2c3'], $r['imported']);
	eq(3, $r['errors'][0]['row']);
	ok(str_contains($w->svc->exportDevicesCsv(), 'c4fc22a1b2c3,T87W,Base,Desk,212'));
};

$tests['phone client: RSA key encoding round-trips through OpenSSL'] = function () {
	$k = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
	$d = openssl_pkey_get_details($k)['rsa'];
	$pem = \FreePBX\modules\Openprov\PhoneClient::rsaPem(bin2hex($d['n']), bin2hex($d['e']));
	ok(openssl_public_encrypt('Admin123', $enc, $pem, OPENSSL_PKCS1_PADDING), 'PEM accepted');
	openssl_private_decrypt($enc, $plain, $k, OPENSSL_PKCS1_PADDING);
	eq('Admin123', $plain);
};

$tests['render: template-only view and per-model VPN update method'] = function () {
	$w = world();
	phone($w, ['keys' => [['position' => 3, 'type' => 'blf', 'value' => '202', 'label' => 'Override']]]);
	$w->svc->setKeys('template', 'Base', [['position' => 1, 'type' => 'line', 'account' => 1], ['position' => 3, 'type' => 'blf', 'value' => '201']]);
	$d = $w->svc->getDevice(MAC);
	eq('Alice', $d['template_view']['keys']['line'][3]['label'], 'template column shows the template key');
	eq('Override', $d['effective']['keys']['line'][3]['label'], 'phone gets its override');
	eq('Base', $d['template_view']['name']);
	// auto = check-sync for every model now that tunnel-bound phones provision through the tunnel
	$w->settings->set('vpn_update_method', 'auto');
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['vpn' => 'auto']]);
	eq('notify', $w->svc->resync([MAC])[0]['method']);
	$w->svc->saveDevice(['mac' => '80:5e:c0:a1:b2:c4', 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '201']], 'settings' => ['vpn' => '6']]);
	eq('notify', $w->svc->resync(['805ec0a1b2c4'])[0]['method']);
	eq(true, $w->settings->get('resync_on_apply_config'));
	throws(fn() => $w->settings->set('vpn_update_method', 'sometimes'), 'vpn_update_method');
};

$tests['render: tunnel-bound VPN phones provision through the tunnel'] = function () {
	$w = world();
	phone($w, ['settings' => ['vpn' => 'auto']]);
	$d = $w->store->deviceByMac(MAC);
	$pass = $d['prov_pass'];
	// First contact from the LAN: server URL and SIP go to the tunnel, but the
	// VPN profile must come from a direct address (no tunnel yet).
	$cfg = kv($w->builder->render($d, '192.168.1.246')['text']);
	ok(str_contains($cfg['static.auto_provision.server.url'], '://10.8.0.1/openprov'), $cfg['static.auto_provision.server.url']);
	eq('10.8.0.1', $cfg['account.1.sip_server.1.address']);
	ok(!str_contains($cfg['openvpn.url'], '10.8.0.1'), 'vpn tar not via the tunnel on first contact');
	// Request through the tunnel: everything through the tunnel.
	$cfg = kv($w->builder->render($d, '10.8.0.2')['text']);
	ok(str_contains($cfg['openvpn.url'], '@10.8.0.1/openprov/'), $cfg['openvpn.url']);
	// Server records the request IP, and sync renders reuse it (same hash as the phone got).
	$r = $w->server->handle('/c4fc22a1b2c3.cfg', UA, '10.8.0.2', MAC, $pass);
	eq(200, $r['status']);
	$d = $w->store->deviceByMac(MAC);
	eq($d['last_hash'], $w->builder->render($d)['hash'], 'sync render matches what the phone fetched');
	// A VPN provisioning host name overrides the tunnel address.
	$w->settings->set('vpn_provisioning_host', 'prov-vpn.example.com');
	$cfg = kv($w->builder->render($d)['text']);
	ok(str_contains($cfg['static.auto_provision.server.url'], 'prov-vpn.example.com'));
	// A model without the quirk keeps the template's network (internal).
	$w->svc->saveDevice(['mac' => '80:5e:c0:a1:b2:c4', 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '212']], 'settings' => ['vpn' => 'auto']]);
	$cfg = kv($w->builder->render($w->store->deviceByMac('805ec0a1b2c4'), '192.168.1.126')['text']);
	ok(!str_contains($cfg['static.auto_provision.server.url'], 'prov-vpn'), $cfg['static.auto_provision.server.url']);
};

$tests['template: parent_view shows what a child template inherits'] = function () {
	$w = world();
	$w->svc->saveTemplate(['name' => 'Parent', 'settings' => ['backlight_time' => '60']]);
	$w->svc->setKeys('template', 'Parent', [['position' => 2, 'type' => 'blf', 'value' => '201']]);
	$w->svc->saveTemplate(['name' => 'Child', 'parent' => 'Parent']);
	$c = $w->svc->getTemplate('Child');
	eq('Parent', $c['parent_view']['name']);
	eq('60', $c['parent_view']['settings']['backlight_time']);
	eq('201', $c['parent_view']['keys']['line'][2]['value']);
	$p = $w->svc->getTemplate('Parent');
	eq(null, $p['parent_view']['name']);
	ok(array_key_exists('backlight_time', $p['parent_view']['settings']), 'defaults for a root template');
	eq('Parent', $c['parent_view']['sources']['backlight_time'], 'set by the parent');
	eq('default', $c['parent_view']['sources']['vpn'], 'built-in default');
	eq('', $p['parent_view']['sources']['backlight_time'], 'nothing set: not managed');
};

$tests['render: screenshots allowed from the PBX addresses only'] = function () {
	$w = world();
	phone($w);
	$w->settings->set('internal_host', '192.0.2.1');
	$cfg = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('1', $cfg['features.action_uri.enable']);
	eq('192.0.2.1,10.8.0.1', $cfg['features.action_uri_limit_ip']);
	eq('0', $cfg['features.show_action_uri_option']);
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['remote_screenshot' => '0']]);
	$cfg = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	ok(!isset($cfg['features.action_uri_limit_ip']), 'off = untouched');
	throws(fn() => $w->svc->screenshot('001565000099'), '');
};

$tests['render: ringtones by name, distinctive ring, expanded wallpaper, new settings'] = function () {
	$w = world();
	$wav = tempnam(sys_get_temp_dir(), 'rt');
	file_put_contents($wav, 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 0));
	$cg = $w->svc->addAsset('ringtone', 'caregiver.wav', $wav);
	file_put_contents($wav, "\0", FILE_APPEND); // a different file (identical uploads are one asset)
	$cl = $w->svc->addAsset('ringtone', 'client.wav', $wav);
	phone($w, ['settings' => [
		'ringtone' => 'Resource:Ring3.wav',
		'distinctive_ring' => "internal=Resource:Ring6.wav\ncg=asset:{$cg['id']}\ncl=asset:{$cl['id']}",
		'opus' => '6', 'rport' => '1', 'dss_transfer' => '1',
	]]);
	$cfg = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('Resource:Ring3.wav', $cfg['phone_setting.ring_type']);
	eq(2, count($cfg['ringtone.url']), 'both uploaded ringtones download in one pass');
	eq('Resource:Ring6.wav', $cfg['distinctive_ring_tones.alert_info.1.ringer']);
	eq('cg', $cfg['distinctive_ring_tones.alert_info.2.text']);
	ok(str_starts_with($cfg['distinctive_ring_tones.alert_info.2.ringer'], 'Config:op'), 'uploaded ringer by file name');
	eq('%NULL%', $cfg['distinctive_ring_tones.alert_info.10.text'], 'unused slots cleared');
	eq('1', $cfg['account.1.codec.opus.enable']);
	eq('6', $cfg['account.1.codec.opus.priority']);
	eq('1', $cfg['account.1.nat.rport']);
	eq('1', $cfg['transfer.dsskey_deal_type']);
	ok(!isset($cfg['voice.handfree_send']), 'volumes unmanaged unless set');
	// defaults are always sent, so clearing a setting reverts the phone
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['ringtone' => 'Resource:Ring3.wav']]);
	$cfg = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('2', $cfg['transfer.dsskey_deal_type']);
	eq('0', $cfg['account.1.codec.opus.enable']);
	ok(!isset($cfg['distinctive_ring_tones.alert_info.1.text']), 'distinctive unmanaged when blank');
	// "none" clears the phone's rules
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['distinctive_ring' => 'none']]);
	eq('%NULL%', kv($w->builder->render($w->store->deviceByMac(MAC))['text'])['distinctive_ring_tones.alert_info.1.text']);
	// validation
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'settings' => ['handsfree_send' => '60']]), 'between');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'settings' => ['distinctive_ring' => 'a&b=Resource:Ring1.wav']]), '32 characters');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'settings' => ['distinctive_ring' => 'x=asset:999']]), 'no uploaded');
	throws(fn() => $w->svc->saveDevice(['mac' => MAC, 'settings' => ['ringtone' => 'Ring1']]), 'Resource');
	// expanded wallpaper defaults to the wallpaper; a different one is a second download
	if (function_exists('imagecreatetruecolor')) {
		$img = imagecreatetruecolor(100, 60);
		$png = tempnam(sys_get_temp_dir(), 'wp') . '.png';
		imagepng($img, $png);
		$a = $w->svc->addAsset('wallpaper', 'a.png', $png);
		imagesetpixel($img, 1, 1, imagecolorallocate($img, 255, 0, 0));
		imagepng($img, $png);
		$b = $w->svc->addAsset('wallpaper', 'b.png', $png);
		$w->svc->saveDevice(['mac' => MAC, 'settings' => ['wallpaper' => (string) $a['id']]]);
		$cfg = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
		ok(is_string($cfg['wallpaper_upload.url']), 'one wallpaper download');
		eq($cfg['phone_setting.backgrounds'], $cfg['phone_setting.backgrounds_with_dsskey_unfold']);
		$w->svc->saveDevice(['mac' => MAC, 'settings' => ['wallpaper' => (string) $a['id'], 'wallpaper_expanded' => (string) $b['id']]]);
		$cfg = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
		eq(2, count($cfg['wallpaper_upload.url']));
		ok($cfg['phone_setting.backgrounds'] !== $cfg['phone_setting.backgrounds_with_dsskey_unfold']);
		@unlink($png);
	}
	@unlink($wav);
};

$tests['audio: mu-law WAV becomes 16-bit PCM'] = function () {
	$ulaw = 'RIFF' . pack('V', 40) . 'WAVEfmt ' . pack('VvvVVvv', 16, 7, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 4) . "\xff\x7f\x00\x80";
	$pcm = \FreePBX\modules\Openprov\Audio::toPcmWav($ulaw);
	eq(1, unpack('v', substr($pcm, 20, 2))[1], 'format PCM');
	eq(16, unpack('v', substr($pcm, 34, 2))[1], '16-bit');
	$samples = array_values(unpack('s*', substr($pcm, 44)));
	eq(4, count($samples));
	eq(0, $samples[0], 'mu-law 0xFF is silence');
	ok($samples[2] < -30000 && $samples[3] > 30000, 'extremes');
};

$tests['assets: chunked upload assembles in order'] = function () {
	$w = world();
	$id = str_repeat('ab', 16);
	$rom = random_bytes(3000);
	eq(1000, $w->svc->uploadChunk($id, 0, substr($rom, 0, 1000))['received']);
	throws(fn() => $w->svc->uploadChunk($id, 0 + 500, 'x'), 'out of order');
	$w->svc->uploadChunk($id, 1000, substr($rom, 1000, 1000));
	$w->svc->uploadChunk($id, 2000, substr($rom, 2000));
	$a = $w->svc->addAsset('firmware', 'Yealink-T7X-185.87.0.37.rom', ['upload' => $id], []);
	eq(hash('sha256', $rom), $a['sha256']);
	throws(fn() => $w->svc->addAsset('firmware', 'x.rom', ['upload' => $id], []), 'Upload not found');
	throws(fn() => $w->svc->uploadChunk('../../etc', 0, 'x'), 'Bad upload id');
	// the same file again is the existing asset, not a duplicate
	$id2 = str_repeat('cd', 16);
	$w->svc->uploadChunk($id2, 0, $rom);
	$again = $w->svc->addAsset('firmware', 'Yealink-T7X-185.87.0.37.rom', ['upload' => $id2], []);
	eq($a['id'], $again['id']);
	ok(!empty($again['duplicate']));
	eq(1, count($w->store->assets('firmware')));
};

$tests['assets: deleting one of two assets sharing a file keeps the file'] = function () {
	$w = world();
	$f = tempnam(sys_get_temp_dir(), 'fw');
	file_put_contents($f, 'rom');
	$a = $w->svc->addAsset('firmware', 'same.rom', $f);
	// a second row pointing at the same stored file (as older versions could create)
	$id = $w->store->addAsset(['kind' => 'firmware', 'name' => 'same.rom', 'filename' => $a['filename'], 'mime' => 'x', 'size' => 3, 'sha256' => $a['sha256'], 'model' => '', 'version' => '']);
	$w->svc->deleteAsset($id);
	ok(is_file($w->assets->path($a)), 'file kept for the other asset');
	$w->svc->deleteAsset($a['id']);
	ok(!is_file($w->assets->path($a)), 'file removed with the last asset');
	@unlink($f);
};

$tests['service: device.sync reports state and requests since a time'] = function () {
	$w = world();
	phone($w);
	$d = $w->store->deviceByMac(MAC);
	eq('never', $w->svc->deviceSync(MAC)['sync']);
	$t = time();
	$w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', MAC, $d['prov_pass']);
	$st = $w->svc->deviceSync(MAC, $t);
	eq('current', $st['sync']);
	eq('/c4fc22a1b2c3.cfg', end($st['events'])['path']);
	eq([], $w->svc->deviceSync(MAC, $t + 3600)['events'], 'nothing after a later time');
	$w->pbx->users['212'] = 'Renamed';
	ok(in_array($w->svc->deviceSync(MAC)['sync'], ['stale', 'pending'], true), 'a change shows as not current');
};

$tests['keys: overrides that match the template are dropped, so the phone follows it again'] = function () {
	$w = world();
	phone($w, ['keys' => [['position' => 2, 'type' => 'blf', 'value' => '202'], ['position' => 3, 'type' => 'blf', 'value' => '201', 'label' => 'Front desk'], ['position' => 9, 'type' => 'none']]]);
	eq([2, 3], array_column($w->store->keys('device', $w->store->deviceByMac(MAC)['id']), 'position'), 'a blank over nothing is dropped on save');
	// template now has 202 on key 2 (same as the phone) and 201 on 3 (phone has its own label)
	$w->svc->setKeys('template', 'Base', [['position' => 1, 'type' => 'line', 'account' => 1], ['position' => 2, 'type' => 'blf', 'value' => '202'], ['position' => 3, 'type' => 'blf', 'value' => '201']]);
	$own = $w->store->keys('device', $w->store->deviceByMac(MAC)['id']);
	eq([3], array_column($own, 'position'), 'key 2 follows the template; key 3 keeps its own label');
	// later template changes now reach key 2
	$w->svc->setKeys('template', 'Base', [['position' => 2, 'type' => 'blf', 'value' => '205']], 'line', false);
	eq('205', $w->svc->getDevice(MAC)['effective']['keys']['line'][2]['value'] ?? null);
	// child templates too
	$w->svc->saveTemplate(['name' => 'Kid', 'parent' => 'Base', 'keys' => [['position' => 2, 'type' => 'blf', 'value' => '205']]]);
	eq([], $w->store->keys('template', $w->svc->getTemplate('Kid')['id']));
};

$tests['features: captions on/off/unmanaged, slots, globals, models'] = function () {
	$w = world();
	$w->svc->saveFeature(['id' => 'captions', 'name' => 'Live captions', 'kind' => 'softkey_url', 'button_label' => 'Captions', 'url' => 'http://{pbx}:8080/captions/{ext}', 'states' => ['on_talk']]);
	ok(isset($w->catalog->features('yealink')['captions']), 'captions feature defined');
	phone($w);
	$cfg = fn() => kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	ok(!isset($cfg()['softkey.1.enable']), 'blank = not managed: nothing written');
	$w->settings->set('internal_host', '192.0.2.1');
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['feature.captions' => '1']]);
	$c = $cfg();
	eq('1', $c['features.enhanced_dss_keys.enable']);
	eq('27', $c['linekey.84.type'], 'hidden key = the T87W\'s last key');
	eq('http://192.0.2.1:8080/captions/212', $c['linekey.84.value']);
	eq('Captions', $c['softkey.1.label']);
	eq('$KLineKey84$', $c['softkey.1.action']);
	eq('1', $c['softkey.1.use.on_talk']);
	eq(['captions' => ['lineKey' => 84, 'softkey' => 1]], $w->svc->getDevice(MAC)['effective']['features']);
	// a real key on 84 pushes the hidden key down to 83
	$w->svc->setKeys('device', MAC, [['position' => 84, 'type' => 'speeddial', 'value' => '100']]);
	$c = $cfg();
	eq('13', $c['linekey.84.type']);
	eq('27', $c['linekey.83.type']);
	eq('$KLineKey83$', $c['softkey.1.action']);
	// custom url / label with {ext}
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['feature.captions' => '1', 'feature.captions.url' => 'http://agent:9/c/{ext}?k=t', 'feature.captions.label' => 'CC']]);
	$c = $cfg();
	eq('http://agent:9/c/212?k=t', $c['linekey.83.value']);
	eq('CC', $c['softkey.1.label']);
	// Off writes the removal values at the same slots, and leaves the global switch alone
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['feature.captions' => '0']]);
	$c = $cfg();
	eq('0', $c['softkey.1.enable']);
	eq('0', $c['linekey.83.type']);
	eq('%NULL%', $c['linekey.83.value']);
	ok(!isset($c['features.enhanced_dss_keys.enable']), 'global switch never turned off');
	// a model without the capabilities: warning, nothing written
	$w->svc->saveDevice(['mac' => '00:15:65:00:00:02', 'model' => 'T46S', 'lines' => [['account' => 1, 'extension' => '201']], 'settings' => ['feature.captions' => '1']]);
	$d = $w->svc->getDevice('001565000002');
	ok(str_contains(implode(' ', $d['warnings']), 'not available on the T46S'));
	ok(!isset(kv($w->builder->render($w->store->deviceByMac('001565000002'))['text'])['softkey.1.enable']));
};

$tests['features: custom softkeys from the GUI keep permanent slots'] = function () {
	$w = world();
	$w->svc->saveFeature(['id' => 'captions', 'name' => 'Live captions', 'kind' => 'softkey_url', 'button_label' => 'Captions', 'url' => 'http://{pbx}:8080/captions/{ext}', 'states' => ['on_talk']]);
	$cam = $w->svc->saveFeature(['id' => 'door_cam', 'name' => 'Door camera', 'kind' => 'softkey_url', 'button_label' => 'Door', 'url' => 'http://{pbx}:8080/cam/{ext}', 'states' => ['idle', 'on_talk'], 'create' => true]);
	eq(2, $cam['slot'], 'captions has slot 1');
	eq(1, $w->catalog->features('yealink')['captions']['slot']);
	$park = $w->svc->saveFeature(['id' => 'park_view', 'name' => 'Parked calls', 'kind' => 'softkey_url', 'button_label' => 'Parked', 'url' => 'http://x/p', 'states' => ['idle'], 'create' => true]);
	eq(3, $park['slot']);
	throws(fn() => $w->svc->saveFeature(['id' => 'captions', 'name' => 'x', 'kind' => 'softkey_url', 'button_label' => 'x', 'url' => 'http://x', 'states' => ['idle'], 'create' => true]), 'already exists');
	throws(fn() => $w->svc->saveFeature(['id' => 'bad', 'name' => 'x', 'kind' => 'softkey_url', 'button_label' => 'x', 'url' => 'ftp://x', 'states' => ['idle']]), 'http');
	phone($w, ['settings' => ['feature.captions' => '1', 'feature.door_cam' => '1', 'feature.park_view' => '1']]);
	$w->settings->set('internal_host', '192.0.2.1');
	$c = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('$KLineKey84$', $c['softkey.1.action']);
	eq('$KLineKey83$', $c['softkey.2.action']);
	eq('http://192.0.2.1:8080/cam/212', $c['linekey.83.value']);
	eq('1', $c['softkey.2.use.idle']);
	eq('0', $c['softkey.3.use.on_talk']);
	eq('$KLineKey82$', $c['softkey.3.action']);
	// in use: delete refused
	throws(fn() => $w->svc->deleteFeature('door_cam'), 'Still set on');
	// cleared everywhere -> deleted; park_view keeps slot 3 (no shift into door_cam's slot)
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['feature.captions' => '1', 'feature.park_view' => '1']]);
	$w->svc->deleteFeature('door_cam');
	$c = kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('$KLineKey82$', $c['softkey.3.action'], 'unchanged after another feature was deleted');
	ok(!isset($c['softkey.2.enable']));
	// a new feature reuses the free slot 2
	eq(2, $w->svc->saveFeature(['id' => 'new_one', 'name' => 'N', 'kind' => 'softkey_url', 'button_label' => 'N', 'url' => 'http://n', 'states' => ['idle'], 'create' => true])['slot']);
	// advanced
	$adv = $w->svc->saveFeature(['id' => 'dnd_note', 'name' => 'Note', 'kind' => 'advanced', 'requires' => ['edk'], 'claims' => ['softkey' => 1],
		'variables' => [['id' => 'text', 'label' => 'Text', 'default' => 'Hi {ext}']],
		'lines' => "softkey.{softkey}.enable = 1\nsoftkey.{softkey}.label = {text}", 'remove' => "softkey.{softkey}.enable = 0", 'create' => true]);
	eq(4, $adv['slot']);
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['feature.dnd_note' => '1']]);
	eq('Hi 212', kv($w->builder->render($w->store->deviceByMac(MAC))['text'])['softkey.4.label']);
	throws(fn() => $w->svc->saveFeature(['id' => 'bad2', 'name' => 'B', 'kind' => 'advanced', 'lines' => "bad key! = 1"]), 'Invalid config key');
	ok(count(array_filter($w->catalog->schema('yealink'), fn($s) => $s['id'] === 'feature.dnd_note.text')) === 1, 'variables become settings');
};

$tests['labels: names from the extension or from User Management'] = function () {
	$w = world();
	$w->pbx->userman['201'] = ['uid' => 2, 'username' => 'alice', 'fname' => 'Alicia', 'lname' => 'Smythe', 'displayname' => 'Dr. Alicia Smythe', 'email' => ''];
	phone($w, ['keys' => [['position' => 2, 'type' => 'blf', 'value' => '201'], ['position' => 3, 'type' => 'blf', 'value' => '202']]]);
	$cfg = fn() => kv($w->builder->render($w->store->deviceByMac(MAC))['text']);
	eq('Alice', $cfg()['linekey.2.label'], 'default: extension display name ({first} from the Base template)');
	$w->svc->saveTemplate(['id' => $w->svc->getTemplate('Base')['id'], 'settings' => ['label_format' => '{first}', 'name_source' => 'user']]);
	eq('Alicia', $cfg()['linekey.2.label'], 'User Management first name');
	eq('Bob', $cfg()['linekey.3.label'], 'no User Management user: falls back to the extension');
	$w->svc->saveTemplate(['id' => $w->svc->getTemplate('Base')['id'], 'settings' => ['label_format' => '{name}', 'name_source' => 'user']]);
	eq('Dr. Alicia Smythe', $cfg()['linekey.2.label']);
	$w->pbx->userman['201']['displayname'] = '';
	eq('Alicia Smythe', $cfg()['linekey.2.label'], 'no display name: first + last');
};

$tests['admin password: shared by default, per-device when enabled, overrides win'] = function () {
	$w = world();
	$w->settings->set('device_secret', 'test-secret');
	phone($w);
	$pw = fn($mac = MAC) => kv($w->builder->render($w->store->deviceByMac($mac))['text'])['static.security.user_password'][0] ?? '';
	eq('admin:24681357', $pw(), 'shared by default');
	$w->settings->set('per_device_admin_password', true);
	$a = $pw();
	ok(preg_match('/^admin:\d{10}$/', $a) && $a !== 'admin:24681357', "per-device: $a");
	eq($a, $pw(), 'stable');
	$w->svc->saveDevice(['mac' => '80:5e:c0:a1:b2:c4', 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '201']]]);
	ok($pw('805ec0a1b2c4') !== $a, 'different per phone');
	eq(substr($a, 6), $w->svc->getDevice(MAC, true)['admin_password'], 'shown on the phone page (reveal)');
	eq('********', $w->svc->getDevice(MAC)['admin_password']);
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['admin_password' => '13572468']]);
	eq('admin:13572468', $pw(), 'phone override wins');
	eq('********', $w->svc->getSettings(true)['device_secret'], 'secret never revealed');
};

$tests['dashboard policy: remote callers change only what OpenProv allows'] = function () {
	$w = world();
	phone($w, ['settings' => ['vpn' => 'off'], 'keys' => [['position' => 9, 'type' => 'xml', 'value' => 'http://x/app', 'label' => 'App']]]);
	$r = new \FreePBX\modules\Openprov\Api($w->svc, 'remote');
	$p = $r->call('dashboard.policy', []);
	ok($p['changes_allowed'] && in_array('blf', $p['key_types'], true) && !in_array('xml', $p['key_types'], true));
	eq([], $p['settings'], 'no phone settings by default');
	// own keys with allowed types: fine; the PBX-managed XML key must stay
	$keys = [['position' => 2, 'type' => 'blf', 'value' => '201'], ['position' => 9, 'type' => 'xml', 'value' => 'http://x/app', 'label' => 'App']];
	throws(fn() => $r->call('keys.set', ['scope' => 'device', 'ref' => MAC, 'keys' => [['position' => 2, 'type' => 'blf', 'value' => '201']]]), 'managed on the PBX');
	throws(fn() => $r->call('keys.set', ['scope' => 'device', 'ref' => MAC, 'keys' => [['position' => 3, 'type' => 'url', 'value' => 'http://evil']] + $keys]), "key type 'url'");
	$r->call('keys.set', ['scope' => 'device', 'ref' => MAC, 'keys' => $keys]); // managed key sent back unchanged
	eq(['blf', 'xml'], array_column($w->store->keys('device', $w->store->deviceByMac(MAC)['id']), 'type'));
	throws(fn() => $r->call('keys.set', ['scope' => 'device', 'ref' => MAC, 'keys' => [['position' => 2, 'type' => 'blf', 'value' => '201'], ['position' => 9, 'type' => 'xml', 'value' => 'http://evil', 'label' => 'App']]]), "key type 'xml'");
	throws(fn() => $r->call('keys.set', ['scope' => 'template', 'ref' => 'Base', 'keys' => []]), 'templates');
	// a managed key's line (account) counts as part of it: sending it back on another line is a change
	$w->svc->setKeys('device', MAC, [['position' => 9, 'type' => 'xml', 'value' => 'http://x/app', 'label' => 'App', 'account' => 2]], null, false);
	throws(fn() => $r->call('keys.set', ['scope' => 'device', 'ref' => MAC, 'keys' => $keys]), "key type 'xml'");
	$keys[1]['account'] = 2;
	$r->call('keys.set', ['scope' => 'device', 'ref' => MAC, 'keys' => $keys]);
	eq(2, $w->store->keys('device', $w->store->deviceByMac(MAC)['id'])[1]['account']);
	// settings: none allowed, then one
	throws(fn() => $r->call('device.save', ['mac' => MAC, 'settings' => ['backlight_time' => '60']]), "setting 'backlight_time'");
	throws(fn() => $r->call('device.save', ['mac' => MAC, 'raw' => 'x = 1']), 'only phone settings');
	$w->settings->set('dashboard_settings', 'backlight_time,label_length');
	$r->call('device.save', ['mac' => MAC, 'settings' => ['backlight_time' => '60']]);
	$d = $w->store->deviceByMac(MAC);
	eq('60', $d['settings']['backlight_time']);
	eq('off', $d['settings']['vpn'], 'other settings untouched by a dashboard save');
	eq(['backlight_time', 'label_length'], array_column($r->call('dashboard.policy', [])['settings'], 'id'));
	// email never, even with user details on (Sangoma app login, password resets)
	throws(fn() => $r->call('extension.update', ['extension' => '212', 'email' => 'x@example.com']), 'email');
	throws(fn() => $r->call('extension.update', ['extension' => '212', 'fname' => 'Rob', 'email' => '']), 'email');
	// renames / user details follow their switches; master switch stops everything
	$w->settings->set('dashboard_user_details', false);
	throws(fn() => $r->call('extension.update', ['extension' => '212', 'fname' => 'Rob']), 'User Management');
	$w->settings->set('dashboard_access', false);
	throws(fn() => $r->call('resync', ['devices' => [MAC]]), 'turned off');
	throws(fn() => $r->call('template.delete', ['template' => 'Base']), 'turned off');
	$w->settings->set('dashboard_access', true);
	throws(fn() => $r->call('template.delete', ['template' => 'Base']), "action 'template.delete'");
};

$tests['extension.update: rename + User Management in one call; phones update'] = function () {
	$w = world();
	$w->pbx->userman['201'] = ['uid' => 2, 'username' => '201', 'fname' => 'Alice', 'lname' => 'Smith', 'displayname' => '', 'email' => ''];
	phone($w, ['keys' => [['position' => 2, 'type' => 'blf', 'value' => '201']]]);
	$d = $w->store->deviceByMac(MAC);
	$w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', MAC, $d['prov_pass']);
	$w->sent->exchangeArray([]);
	$r = $w->svc->updateExtension(['extension' => '201', 'name' => 'Alicia Smythe', 'fname' => 'Alicia', 'lname' => 'Smythe', 'email' => 'a@example.com']);
	eq('Alice Smith', $r['rename']['old']);
	eq(['fname', 'lname', 'email'], $r['user']['updated']);
	ok($r['needs_reload']);
	eq(1, count($r['phones']), 'the phone showing 201 was told to update');
	eq('Alicia', kv($w->builder->render($w->store->deviceByMac(MAC))['text'])['linekey.2.label']);
	eq('Alicia', $w->svc->extensionInfo('201')['user']['fname']);
	throws(fn() => $w->svc->updateExtension(['extension' => '201', 'name' => 'Bad <name>']), 'without');
	throws(fn() => $w->svc->updateExtension(['extension' => '201', 'email' => 'nope']), 'valid address');
	throws(fn() => $w->svc->updateExtension(['extension' => '202', 'fname' => 'Bob']), 'no User Management user');
	throws(fn() => $w->svc->updateExtension(['extension' => '999', 'name' => 'X']), "doesn't exist");
};

$tests['service: null template and parent are accepted'] = function () {
	$w = world();
	$d = $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'template' => null]);
	eq(null, $d['template_id']);
	$t = $w->svc->saveTemplate(['name' => 'Solo', 'parent' => null]);
	eq(null, $t['parent_id']);
};

$tests['backup: restore puts JSON back byte for byte (FreePBX importTables would mangle \n and \")'] = function () {
	if (!class_exists('FreePBX\modules\Backup\RestoreBase')) {
		eval('namespace FreePBX\modules\Backup; class RestoreBase {}');
	}
	require_once dirname(__DIR__) . '/Restore.php';
	$w = world();
	$t = $w->svc->saveTemplate(['name' => 'Rings', 'description' => 'say "hi"', 'settings' => ['distinctive_ring' => "cl=Resource:Ring2.wav\ncp=Resource:Ring3.wav", 'label_format' => '{first}']]);
	$rows = $w->db->query('SELECT * FROM openprov_templates')->fetchAll(PDO::FETCH_ASSOC);
	$backup = json_decode(json_encode($rows), true);   // what the backup file holds
	$w->db->exec('DELETE FROM openprov_templates');
	\FreePBX\modules\Openprov\Restore::importTable($w->db, 'openprov_templates', array_map(fn($r) => $r + ['gone_column' => 'x'], $backup));
	eq($rows, $w->db->query('SELECT * FROM openprov_templates')->fetchAll(PDO::FETCH_ASSOC));
	eq("cl=Resource:Ring2.wav\ncp=Resource:Ring3.wav", $w->svc->getTemplate($t['id'])['settings']['distinctive_ring']);
	\FreePBX\modules\Openprov\Restore::importTable($w->db, 'users', []);   // never touches other modules' tables
};

$tests['render: unreadable stored settings refuse to render instead of sending defaults'] = function () {
	$w = world();
	$t = $w->svc->saveTemplate(['name' => 'Broken', 'settings' => ['label_format' => '{first}']]);
	phone($w, ['template' => $t['id']]);
	$w->db->prepare('UPDATE openprov_templates SET settings=? WHERE id=?')->execute(["{\"distinctive_ring\":\"a=1\nb=2\"}", $t['id']]);
	throws(fn() => $w->builder->render($w->store->deviceByMac(MAC)), 'unreadable');
	throws(fn() => $w->svc->getTemplate($t['id']), 'unreadable');
};

$tests['dashboard: only simple settings can be allowed; remote reads never show stored passwords'] = function () {
	$w = world();
	$t = $w->svc->saveTemplate(['name' => 'Secrets', 'settings' => ['admin_password' => '13572468', 'backlight_time' => '30'], 'raw' => "static.security.user_password = admin:4321"]);
	phone($w, ['template' => $t['id'], 'settings' => ['admin_password' => '99887766']]);
	$w->settings->set('dashboard_settings', 'backlight_time,wallpaper,ringtone,admin_password,prov_network,handset_send');
	$r = new \FreePBX\modules\Openprov\Api($w->svc, 'remote');
	eq(['backlight_time', 'handset_send'], array_column($r->call('dashboard.policy', [])['settings'], 'id'), 'asset, ringtone, password and computed settings are never offered');
	throws(fn() => $r->call('device.save', ['mac' => MAC, 'settings' => ['wallpaper' => '1']]), "setting 'wallpaper'");
	foreach ([$r->call('device.get', ['device' => MAC]), $r->call('template.get', ['template' => $t['id']]), $r->call('templates.list', [])] as $out) {
		$json = json_encode($out);
		ok(!str_contains($json, '13572468') && !str_contains($json, '99887766') && !str_contains($json, '4321'), 'a password leaked: ' . substr($json, 0, 200));
	}
	ok(str_contains(json_encode($r->call('device.get', ['device' => MAC])), '"backlight_time":"30"'), 'other settings still readable');
	// the FreePBX GUI (admin) still sees the stored values it edits
	eq('99887766', (new \FreePBX\modules\Openprov\Api($w->svc, 'admin'))->call('device.get', ['device' => MAC])['settings']['admin_password']);
};

$tests['signing: only the pinned release key is trusted; trust and untrust round-trip'] = function () {
	$fpr = \FreePBX\modules\Openprov\Signing::FINGERPRINT;
	// a scratch keyring, never the system's
	$home = sys_get_temp_dir() . '/openprov-gpg-' . getmypid();
	@mkdir($home, 0700);
	$run = function (string $params, $stdin = null) use ($home) {
		$p = proc_open('gpg --batch --no-tty --homedir ' . escapeshellarg($home) . " $params", [0 => $stdin ?: ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		return ['stdout' => $out, 'stderr' => $err, 'status' => [], 'exitcode' => proc_close($p)];
	};
	try {
		$s = new \FreePBX\modules\Openprov\Signing($run);
		$k = $s->shippedKey();
		eq($fpr, $k['fingerprint'], 'keys/release-signing-key.asc is the pinned key');
		ok(str_contains($k['uid'], 'FreePBX module releases'));
		eq(['fingerprint' => $fpr, 'imported' => false, 'trusted' => false], $s->status());
		$r = $s->trust();
		ok($r['imported'] && $r['trusted'], 'trusted after trust()');
		ok(!$s->untrust()['imported'], 'gone after untrust()');
		// a different key in the shipped file is refused before anything is imported
		$fake = new \FreePBX\modules\Openprov\Signing(fn() => ['stdout' => "pub:-:4096:1:AAAA:1:::-:::cSC:\nfpr:::::::::0123456789ABCDEF0123456789ABCDEF01234567:\n", 'exitcode' => 0, 'status' => []]);
		throws(fn() => $fake->trust(), 'pinned fingerprint');
		$two = "pub:-:4096:1:AAAA:1:::-:::cSC:\nfpr:::::::::$fpr:\npub:-:4096:1:BBBB:1:::-:::cSC:\nfpr:::::::::0123456789ABCDEF0123456789ABCDEF01234567:\n";
		eq(2, count(\FreePBX\modules\Openprov\Signing::primaryKeys($two)), 'a second key smuggled into the file is seen');
		throws(fn() => (new \FreePBX\modules\Openprov\Signing(fn() => ['stdout' => $two, 'exitcode' => 0, 'status' => []]))->shippedKey(), 'pinned fingerprint');
	} finally {
		exec('rm -rf ' . escapeshellarg($home));
	}
};

// ---- web: /openprov path, no Apache config (incident 2026-11-15) ---------------

function webFixture(bool $root, array $rcs = []): object
{
	$tmp = sys_get_temp_dir() . '/openprov-web-' . bin2hex(random_bytes(4));
	mkdir("$tmp/www", 0777, true);
	mkdir("$tmp/apache/sites-available", 0777, true);
	mkdir("$tmp/apache/sites-enabled", 0777, true);
	$cmds = new \ArrayObject();
	// $rcs: command prefix => list of return codes, consumed in order (last one sticks)
	$run = function (string $cmd) use ($cmds, &$rcs) {
		$cmds[] = $cmd;
		foreach ($rcs as $prefix => &$list) {
			if (str_starts_with($cmd, $prefix)) {
				return [count($list) > 1 ? array_shift($list) : $list[0], ''];
			}
		}
		return [0, ''];
	};
	$kv = [];
	$settings = new Settings(function ($k) use (&$kv) { return $kv[$k] ?? null; }, function ($k, $v) use (&$kv) { $kv[$k] = $v; });
	$web = new Web($settings, "$tmp/www", dirname(__DIR__) . '/htdocs', ['apache_dir' => "$tmp/apache", 'run' => $run, 'root' => $root, 'settle' => 0, 'firewall' => fn() => null, 'probe' => fn($u) => false]);
	return (object) compact('tmp', 'cmds', 'web', 'settings');
}

function legacyVhost(object $f): void
{
	file_put_contents($f->web->legacyConf(), "Listen 8443\n<VirtualHost *:8443>\n</VirtualHost>\n");
	symlink($f->web->legacyConf(), $f->web->legacyLink());
}

$tests['web: /openprov is a symlink in the web root; a foreign entry is left alone'] = function () {
	$f = webFixture(false);
	ok(!$f->web->published());
	[$ok] = $f->web->publish();
	ok($ok && $f->web->published(), 'published');
	eq(realpath(dirname(__DIR__) . '/htdocs'), realpath("$f->tmp/www/openprov"));
	[$ok] = $f->web->publish();
	ok($ok, 'idempotent');
	$f->web->unpublish();
	ok(!file_exists("$f->tmp/www/openprov"), 'unpublished');
	mkdir("$f->tmp/www/openprov");
	[$ok, $msg] = $f->web->publish();
	ok(!$ok && str_contains($msg, "isn't OpenProv's"), $msg);
	$f->web->unpublish();
	ok(is_dir("$f->tmp/www/openprov"), 'never deletes what it did not create');
	eq([], $f->cmds->getArrayCopy(), 'publishing runs no commands');
	exec('rm -rf ' . escapeshellarg($f->tmp));
};

$tests['web: the 0.1 vhost is removed only as root, and Apache is brought back up'] = function () {
	// Not root (GUI): nothing touched.
	$f = webFixture(false);
	legacyVhost($f);
	eq(['present' => true, 'enabled' => true, 'ports' => [8443]], $f->web->legacy());
	[$ok, $msg] = $f->web->removeLegacy();
	ok(!$ok && str_contains($msg, 'as root'), $msg);
	ok(is_file($f->web->legacyConf()) && is_link($f->web->legacyLink()), 'files untouched');
	eq([], $f->cmds->getArrayCopy(), 'no commands as non-root');

	// Root, Apache running: delete, configtest, reload, verify.
	$f = webFixture(true);
	legacyVhost($f);
	[$ok, $msg] = $f->web->removeLegacy();
	ok($ok, $msg);
	ok(!$f->web->legacy()['present'], 'gone');
	eq(['apache2ctl configtest', 'systemctl is-active --quiet apache2', 'systemctl reload apache2', 'systemctl is-active --quiet apache2'], $f->cmds->getArrayCopy(), 'reload, then check it stayed up');

	// Root, Apache crash-looping on the bind error (the incident): restart it.
	$f = webFixture(true, ['systemctl is-active' => [3, 0]]);
	legacyVhost($f);
	[$ok, $msg] = $f->web->removeLegacy();
	ok($ok, $msg);
	ok(in_array('systemctl restart apache2', $f->cmds->getArrayCopy(), true), 'restarted');
	ok(!in_array('systemctl reload apache2', $f->cmds->getArrayCopy(), true), 'no reload of a dead Apache');

	// Root, the remaining config is broken (someone else's): never reload.
	$f = webFixture(true, ['apache2ctl configtest' => [1]]);
	legacyVhost($f);
	[$ok, $msg] = $f->web->removeLegacy();
	ok(!$ok && str_contains($msg, 'configtest'), $msg);
	ok(!$f->web->legacy()['present'], 'our file is still removed');
	eq(['apache2ctl configtest'], $f->cmds->getArrayCopy(), 'no reload or restart after a failed configtest');

	// Root, Apache dies after the reload: one restart, and a failure is reported.
	$f = webFixture(true, ['systemctl is-active' => [0, 3, 3]]);
	legacyVhost($f);
	[$ok, $msg] = $f->web->removeLegacy();
	ok(!$ok && str_contains($msg, 'not running'), $msg);
	eq(1, count(array_filter($f->cmds->getArrayCopy(), fn($c) => $c === 'systemctl restart apache2')));

	// Nothing to remove: no commands at all.
	$f = webFixture(true);
	[$ok] = $f->web->removeLegacy();
	ok($ok);
	eq([], $f->cmds->getArrayCopy());
};

$tests['web: no code writes Apache config, adds Listen or enables sites'] = function () {
	$root = dirname(__DIR__);
	$files = array_merge(glob("$root/*.php"), glob("$root/src/*.php"), glob("$root/src/*/*.php"), glob("$root/Console/*.php"), glob("$root/Api/Gql/*.php"), glob("$root/htdocs/*.php"));
	foreach ($files as $file) {
		$src = (string) file_get_contents($file);
		foreach (['/a2ensite/', '/a2enmod/', '/apache2?ctl\s+(-k\s+)?(graceful|restart|start)/', '/"Listen\s/', "/'Listen\\s/", '/<VirtualHost/', '/file_put_contents\([^;]*(apache|sites-)/i', '/symlink\([^;]*(apache|sites-)/i'] as $re) {
			ok(!preg_match($re, $src), basename($file) . " matches $re");
		}
	}
	ok(!is_dir("$root/web"), 'the 0.1 vhost document root is gone');
};

$tests['server: plain HTTP is refused unless phone_scheme is http; URLs carry /openprov'] = function () {
	$w = world();
	phone($w);
	eq(403, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'bootpass', false)['status'], 'HTTP refused with https scheme');
	eq(200, $w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'bootpass', true)['status']);
	$w->settings->set('https_port', 8444);
	eq('https://192.0.2.10:8444/openprov', $w->builder->baseUrl('internal'));
	$w->settings->set('phone_scheme', 'http');
	eq('http://192.0.2.10/openprov', $w->builder->baseUrl('internal'), 'port 80 is implicit');
	ok($w->server->handle('/c4fc22a1b2c3.cfg', UA, '192.0.2.50', 'boot', 'bootpass', false)['status'] !== 403, 'HTTP allowed with http scheme');
	throws(fn() => $w->settings->set('http_port', 0), 'TCP port');
	throws(fn() => $w->settings->set('service_port', 443), '1025');
	throws(fn() => $w->settings->set('https_mode', 'apache'), 'auto, freepbx or service');
	$w->settings->set('tls_cert', '/x');
	ok(!array_key_exists('tls_cert', $w->settings->all()), 'retired settings are ignored');
};

// ---- VPN: profile version, certificate expiry, firmware per model ---------------

$tests['vpn: a rebuilt certificate changes the profile URL (phone gets pushed the new profile); expiry is tracked'] = function () {
	$dir = sys_get_temp_dir() . '/openprov-vpn-' . bin2hex(random_bytes(4));
	mkdir("$dir/clients", 0777, true);
	$mk = function (int $days, string $cn) {
		$k = openssl_pkey_new(['private_key_bits' => 2048]);
		$x = openssl_csr_sign(openssl_csr_new(['commonName' => $cn], $k), null, $k, $days);
		openssl_x509_export($x, $crt);
		openssl_pkey_export($k, $key);
		return [$crt, $key];
	};
	[$ca] = $mk(730, 'FreePBX');
	file_put_contents("$dir/sysadmin_ca.crt", $ca);
	[$crt, $key] = $mk(730, 'client6');
	file_put_contents("$dir/clients/sysadmin_client6.crt", $crt);
	file_put_contents("$dir/clients/sysadmin_client6.key", $key);
	$db = new PDO('sqlite::memory:');
	$db->exec('CREATE TABLE sysadmin_options (`key` TEXT, `value` TEXT)');
	$db->exec("INSERT INTO sysadmin_options VALUES ('vpnclients', '{\"6\":{\"enabled\":\"1\",\"description\":\"212\"}}'), ('vpnserver_serverremote', '192.0.2.1')");
	$vpn = new Vpn($db, $dir);
	$c = $vpn->clients()[6];
	ok(abs($c['expires'] - (time() + 730 * 86400)) < 86400, 'client expiry read from the certificate');
	ok(abs($c['caExpires'] - (time() + 730 * 86400)) < 86400, 'CA expiry');
	$v1 = $vpn->profileVersion(6);
	eq($v1, $vpn->profileVersion(6), 'stable');
	[$crt2, $key2] = $mk(730, 'client6'); // System Admin regenerated the client
	file_put_contents("$dir/clients/sysadmin_client6.crt", $crt2);
	file_put_contents("$dir/clients/sysadmin_client6.key", $key2);
	ok($vpn->profileVersion(6) !== $v1, 'new certificate, new profile version');
	eq(null, $vpn->profileVersion(9), 'no files, no version');
	exec('rm -rf ' . escapeshellarg($dir));

	// In the phone config: the profile URL carries the version, and a change re-notifies.
	$w = world();
	phone($w, ['settings' => ['vpn' => 'auto']]);
	$d = $w->store->deviceByMac(MAC);
	$w->vpn->version = 'aaa';
	$url1 = kv($w->builder->render($d)['text'])['openvpn.url'] ?? '';
	ok(preg_match('#-vpn\.tar\?v=[0-9a-f]{12}$#', $url1) === 1, $url1);
	$h1 = $w->builder->render($d)['hash'];
	$w->vpn->version = 'bbb';
	ok($w->builder->render($d)['hash'] !== $h1, 'rebuild changes the config hash, so sync.changed pushes it');
	// The server still serves the tar with the query string on.
	$w->vpn->expires = time() + 10 * 86400;
	$exp = $w->svc->vpnExpiring(30);
	eq(1, count($exp));
	eq([MAC, 6, 10], [$exp[0]['mac'], $exp[0]['vpn_client'], $exp[0]['days_left']]);
	eq([], $w->svc->vpnExpiring(5), 'outside the window');

	// Mid-rebuild (files unreadable): the change is held, not pushed.
	$w->store->touchDevice($d['id'], ['last_hash' => 'old', 'notified_hash' => 'old']);
	$sent = count($w->sent);
	$w->vpn->version = null;
	$r = $w->svc->syncChanged();
	ok(str_contains($r[0]['message'] ?? '', 'held'), json_encode($r));
	eq($sent, count($w->sent), 'no notify while the VPN profile is unavailable');
	$w->vpn->version = 'ccc';
	$r = $w->svc->syncChanged();
	ok(count($w->sent) > $sent, 'pushed once the profile is back: ' . json_encode($r));
};

$tests['vpn: a full rebuild is warned about beforehand and detected afterwards'] = function () {
	$w = world();
	phone($w, ['settings' => ['vpn' => 'auto']]); // T87W: tunnel-bound
	$w->svc->saveDevice(['mac' => '80:5e:c0:a1:b2:c4', 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '212']], 'settings' => ['vpn' => 'auto']]);
	$r = $w->svc->vpnRebuildCheck();
	eq([MAC], array_column($r['stranded'], 'mac'), 'only the tunnel-bound phone');
	eq(1, $r['pushed'], 'the T57W is pushed automatically');
	eq('https://192.0.2.10/openprov', $r['stranded'][0]['repoint_url']);
	eq(null, $r['rebuilt_at']);
	// First run records the CA; nothing to report.
	$w->vpn->ca = 'AA';
	eq([], $w->svc->vpnRebuildWatch(1000));
	eq([], $w->svc->vpnRebuildWatch(2000), 'same CA: nothing');
	// Rebuild: the T87W hasn't fetched since.
	$w->vpn->ca = 'BB';
	eq([MAC], array_column($w->svc->vpnRebuildWatch(3000), 'mac'));
	eq(3000, $w->svc->vpnRebuildCheck()['rebuilt_at']);
	eq([MAC], array_column($w->svc->vpnRebuildWatch(3300), 'mac'), 'still waiting');
	// It comes back (repointed): cleared.
	$w->store->touchDevice($w->store->deviceByMac(MAC)['id'], ['last_seen' => 3500]);
	$w->vpn->connected = [6 => ['address' => '10.8.0.2', 'real' => '192.168.1.246:1', 'since' => 3500]];
	eq([], $w->svc->vpnRebuildWatch(3700), 'fetched and reconnected');
	eq(null, $w->svc->vpnRebuildCheck()['rebuilt_at'], 'state cleared');
	$w->vpn->ca = null;
	eq([], $w->svc->vpnRebuildWatch(4000), 'unreadable CA (mid-rebuild): no false alarm');
};

$tests['vpn: prepare for a rebuild holds tunnel-bound phones off the VPN, and gives it back on the new CA'] = function () {
	$w = world();
	phone($w, ['settings' => ['vpn' => 'auto']]); // T87W, tunnel-bound
	$w->svc->saveDevice(['mac' => '80:5e:c0:a1:b2:c4', 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '212']], 'settings' => ['vpn' => 'auto']]);
	$d = fn() => $w->store->deviceByMac(MAC);
	$cfg = fn() => kv($w->builder->render($d(), '10.8.0.2')['text']);
	eq('1', $cfg()['static.network.vpn_enable']);
	ok(str_contains($cfg()['static.auto_provision.server.url'], '10.8.0.1'), 'normally through the tunnel');
	$w->vpn->ca = 'AA';
	$w->vpn->connected = [6 => ['address' => '10.8.0.2', 'real' => '192.168.1.246:49203', 'since' => 1]];
	$st = $w->svc->vpnRebuildPrepare();
	eq(true, $st['active']);
	eq(false, $st['ready'], 'not until the phone checked in directly');
	eq('0', $cfg()['static.network.vpn_enable'], 'VPN held off');
	eq('https://192.0.2.10/openprov', $cfg()['static.auto_provision.server.url'], 'LAN phone: internal address');
	eq('auto', $d()['settings']['vpn'], 'the phone\'s own setting is untouched');
	eq('0', kv($w->builder->render($w->store->deviceByMac('805ec0a1b2c4'))['text'])['static.network.vpn_enable'], 'the T57W is held too (fail2ban)');
	$w->svc->vpnRebuildFinish();
	$w->svc->vpnRebuildPrepare(true);
	eq('1', kv($w->builder->render($w->store->deviceByMac('805ec0a1b2c4'))['text'])['static.network.vpn_enable'], 'tunnelBoundOnly: the T57W is not held');
	// It fetches the VPN-off config through the tunnel: not ready while the tunnel is up...
	$w->server->handle('/c4fc22a1b2c3.cfg', UA, '10.8.0.2', MAC, $d()['prov_pass']);
	eq(false, $w->svc->vpnRebuildPrepareStatus()['ready'], 'tunnel still up');
	// ...ready once the tunnel is gone.
	$w->vpn->connected = [];
	ok($w->svc->vpnRebuildPrepareStatus()['ready'], json_encode($w->svc->vpnRebuildPrepareStatus()));
	// Rebuild: new CA. The job gives the VPN back and the phone gets a grace period.
	$w->vpn->ca = 'BB';
	$w->vpn->connected = [6 => ['address' => '10.8.0.2', 'real' => '192.168.1.246:1', 'since' => 1]];
	eq([], $w->svc->vpnRebuildWatch(time() + 10), 'no alarm for a handed-back phone in its grace period');
	eq(false, $w->svc->vpnRebuildPrepareStatus()['active']);
	eq('1', $cfg()['static.network.vpn_enable'], 'VPN back');
	ok(count($w->sent) > 0, 'pushed');
	// Past the grace period without fetching: it's reported, and so is its tunnel being down.
	$late0 = $w->svc->vpnRebuildWatch(time() + 1000); eq([MAC], array_column($late0, 'mac'), json_encode($late0));
	$w->vpn->connected = []; // tunnels not back (e.g. fail2ban)
	$late = $w->svc->vpnRebuildWatch(time() + 1000);
	ok(count(array_filter($late, fn($r) => !empty($r['not_reconnected']))) >= 1, 'tunnel not back is reported: ' . json_encode($late));
	// A public VPN address without external_host is refused.
	$w->svc->vpnRebuildFinish();
	$w->vpn->connected = [6 => ['address' => '10.8.0.2', 'real' => '203.0.113.9:1194', 'since' => 1]];
	throws(fn() => $w->svc->vpnRebuildPrepare(), 'external_host');
	$w->settings->set('external_host', 'pbx.example.com');
	$w->svc->vpnRebuildPrepare();
	ok(str_contains($cfg()['static.auto_provision.server.url'], 'pbx.example.com'), 'remote phone: external address');
	eq(['c4fc22a1b2c3', '805ec0a1b2c4'], $w->svc->vpnRebuildFinish()['released'], 'every VPN phone was held');
	eq('1', $cfg()['static.network.vpn_enable']);
};

$tests['render: firmware goes only to the models it is for'] = function () {
	eq(true, ConfigBuilder::firmwareFits(['name' => 'T77_T73_T74_T73U_T74U_T77U_T85W_T87W_-185.87.0.42.rom'], 'T87W'));
	eq(false, ConfigBuilder::firmwareFits(['name' => 'T77_T73_T74_T73U_T74U_T77U_T85W_T87W_-185.87.0.42.rom'], 'T57W'));
	eq(true, ConfigBuilder::firmwareFits(['name' => 'T57W(T57W)-96.87.0.20.rom'], 'T57W'));
	eq(true, ConfigBuilder::firmwareFits(['name' => 'firmware.rom'], 'T57W'), 'no model in the name: as before');
	eq(false, ConfigBuilder::firmwareFits(['name' => 'firmware.rom', 'model' => 'T87W'], 'T57W'), 'the asset model wins');
};

$tests['render: phones follow the public DNS name by default (auto); VPN phones register through the tunnel'] = function () {
	$w = world();
	phone($w); // template "Base": prov_network not set = auto
	$cfg = fn($mac = MAC) => kv($w->builder->render($w->store->deviceByMac($mac))['text']);
	eq('https://192.0.2.10/openprov', $cfg()['static.auto_provision.server.url'], 'no external_host: internal address');
	$w->settings->set('external_host', 'pbx.example.com');
	eq('https://pbx.example.com/openprov', $cfg()['static.auto_provision.server.url'], 'auto: the DNS name');
	eq('pbx.example.com', $cfg()['account.1.sip_server.1.address'], 'SIP follows it too');
	eq('https://pbx.example.com/openprov', $w->svc->info()['bootstrap_url']);
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['prov_network' => 'internal']]);
	eq('https://192.0.2.10/openprov', $cfg()['static.auto_provision.server.url'], 'an explicit internal still wins');
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['prov_network' => '', 'vpn' => 'auto']]);
	ok(str_contains($cfg()['static.auto_provision.server.url'], '10.8.0.1'), 'tunnel-bound T87W with VPN: the tunnel');
	// A T57W (not tunnel-bound) with a VPN: provisions from the DNS name, registers through the tunnel.
	$t57 = '805ec0a1b2c4';
	$w->svc->saveDevice(['mac' => $t57, 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '212']], 'settings' => ['vpn' => 'auto']]);
	eq('https://pbx.example.com/openprov', $cfg($t57)['static.auto_provision.server.url'], 'VPN phone: still provisions from the DNS name');
	eq('10.8.0.1', $cfg($t57)['account.1.sip_server.1.address'], 'VPN phone: SIP through the tunnel');
	eq('1', $cfg($t57)['static.network.vpn_enable']);
	$w->svc->saveDevice(['mac' => $t57, 'settings' => ['prov_network' => 'external', 'vpn' => 'auto']]);
	eq('pbx.example.com', $cfg($t57)['account.1.sip_server.1.address'], 'explicit external: SIP stays on the DNS name');
	$w->svc->saveDevice(['mac' => $t57, 'settings' => ['prov_network' => '', 'vpn' => 'off']]);
	eq('pbx.example.com', $cfg($t57)['account.1.sip_server.1.address'], 'no VPN: SIP on the DNS name');
	eq('auto', json_decode(file_get_contents(dirname(__DIR__) . '/seeds/default-template.json'), true)['settings']['prov_network'], 'new installs default to auto');
};

// ---- SIP port from FreePBX 17's PJSIP transports -------------------------------

$tests['render: the SIP port comes from FreePBX\'s PJSIP transports (non-standard 5160, TLS, extension transport, NAT)'] = function () {
	$w = world();
	phone($w);
	$port = fn() => kv($w->builder->render($w->store->deviceByMac(MAC))['text'])['account.1.sip_server.1.port'];
	eq('5060', $port(), 'nothing configured: 5060');
	$t = fn($proto, $ip, $p, $ext = 0) => ["$ip-$proto" => ['proto' => $proto, 'ip' => $ip, 'port' => $p, 'extport' => $ext]];
	// The cloud box: PJSIP UDP on 5160, bound to its own address.
	$w->pbx->setTransports($t('udp', '203.0.113.5', 5160));
	eq('5160', $port(), 'non-0.0.0.0 bind on 5160');
	$w->pbx->setTransports($t('udp', '0.0.0.0', 5160) + $t('tls', '0.0.0.0', 5161) + $t('tcp', '0.0.0.0', 5162));
	eq('5160', $port());
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['sip_transport' => '2']]);
	eq('5161', $port(), 'TLS transport picks the TLS port');
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['sip_transport' => '1']]);
	eq('5162', $port(), 'TCP');
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['sip_transport' => '0', 'sip_port' => '6000']]);
	eq('6000', $port(), 'an explicit sip_port still wins');
	// External phones use the transport's external signaling port.
	$w->pbx->setTransports($t('udp', '0.0.0.0', 5160, 15160));
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['sip_port' => '', 'prov_network' => 'external']]);
	eq('15160', $port(), 'external port for external phones');
	// Transport TLS asked for but only UDP exists: UDP's port.
	$w->pbx->setTransports($t('udp', '0.0.0.0', 5160));
	$w->svc->saveDevice(['mac' => MAC, 'settings' => ['prov_network' => 'internal', 'sip_transport' => '2']]);
	eq('5160', $port());
};

$tests['pbx: PJSIP transports are read from Sipsettings (kvstore), not chan_sip\'s sipsettings table'] = function () {
	$db = new PDO('sqlite::memory:');
	$db->exec('CREATE TABLE sipsettings (keyword TEXT, data TEXT)');
	$db->exec("INSERT INTO sipsettings VALUES ('bindport', '5060'), ('udpport-0.0.0.0', '9999')"); // chan_sip / stale: must be ignored
	$db->exec('CREATE TABLE kvstore_Sipsettings (`key` TEXT, val TEXT, type TEXT, id TEXT)');
	$db->exec("INSERT INTO kvstore_Sipsettings VALUES ('binds', '{\"udp\":{\"198.51.100.7\":\"on\"},\"tls\":{\"0.0.0.0\":\"on\"}}', 'json-arr', 'noid'), ('udpport-198.51.100.7', '5160', NULL, 'noid'), ('udpextport-198.51.100.7', ' 25160 ', NULL, 'noid')");
	$pbx = new Pbx($db, null);
	eq(['198.51.100.7-udp' => ['proto' => 'udp', 'ip' => '198.51.100.7', 'port' => 5160, 'extport' => 25160], '0.0.0.0-tls' => ['proto' => 'tls', 'ip' => '0.0.0.0', 'port' => 5061, 'extport' => 0]], $pbx->pjsipTransports());
	eq(5160, $pbx->pjsipPort());
	eq(25160, $pbx->pjsipPort('udp', '', true));
	eq(5061, $pbx->pjsipPort('tls'));
	eq(5160, $pbx->pjsipPort('tls', '198.51.100.7-udp'), 'the extension\'s own transport wins');
	eq(5060, (new Pbx(new PDO('sqlite::memory:'), null))->pjsipPort(), 'no FreePBX data: 5060');
};

// ---- HTTPS: FreePBX's own, else OpenProv's service ------------------------------

class FakePm2
{
	public array $calls = [];
	public bool $online = false;
	public function __construct(private ?string $statusFile = null, private bool $binds = true) {}
	public function getStatus($n) { return $this->online ? ['pm2_env' => ['status' => 'online']] : false; }
	public function start($n, $proc, $env = [], $force = false)
	{
		$this->calls[] = ['start', $n, $env];
		$this->online = true;
		// what node/tls-service.js reports once it tried to bind
		file_put_contents($this->statusFile, json_encode($this->binds ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'port 8449 is already in use by another process']));
		return [];
	}
	public function delete($n) { $this->calls[] = ['delete', $n]; $this->online = false; }
}

class FakeFirewall
{
	public array $svc = [];
	public array $zones = [];
	public function getAllCustomServices() { return $this->svc; }
	public function addCustomService($name, $proto, $port) { $this->svc['id1'] = ['name' => $name, 'custid' => 'id1', 'custfw' => ['port' => $port]]; }
	public function editCustomService($id, $name, $proto, $port) { $this->svc[$id]['custfw']['port'] = $port; }
	public function setCustomServiceZones($id, $z) { $this->zones[$id] = $z; }
	public function deleteCustomService($id) { unset($this->svc[$id], $this->zones[$id]); }
}

/** $answers: URLs (scheme://127.0.0.1:port) whose /openprov/_ping answers. */
function httpsFixture(array $answers, array $sysadmin = [], array $opts = []): object
{
	$dir = sys_get_temp_dir() . '/openprov-tls-' . bin2hex(random_bytes(4));
	mkdir($dir);
	file_put_contents("$dir/c.crt", 'cert');
	file_put_contents("$dir/c.key", 'key');
	$pm2 = array_key_exists('pm2', $opts) ? $opts['pm2'] : new FakePm2("$dir/tls-service.status.json", $opts['binds'] ?? true);
	$inUse = $opts['inUse'] ?? [];
	$tls = new TlsService($pm2, '/x/tls-service.js', $dir, fn() => ['cert' => "$dir/c.crt", 'key' => "$dir/c.key"],
		fn($cmd) => [0, array_filter($inUse, fn($p) => str_contains($cmd, ":$p'")) ? "LISTEN 0 511 *:x" : ''], 0);
	$kv = [];
	$settings = new Settings(function ($k) use (&$kv) { return $kv[$k] ?? null; }, function ($k, $v) use (&$kv) { $kv[$k] = $v; });
	$fw = new FakeFirewall();
	$probed = new \ArrayObject();
	$web = new Web($settings, $dir, dirname(__DIR__) . '/htdocs', [
		'root' => false, 'settle' => 0, 'run' => fn($c) => [0, ''],
		'sysadmin' => fn() => $sysadmin, 'service' => $tls, 'secret' => fn() => 's3cret', 'firewall' => fn() => $fw,
		'probe' => function ($url) use ($answers, $probed) {
			$probed[] = $url;
			foreach ($answers as $a) {
				if (str_starts_with($url, "$a/")) {
					return true;
				}
			}
			return false;
		},
	]);
	return (object) compact('dir', 'pm2', 'tls', 'settings', 'web', 'fw', 'probed');
}

$tests['https: FreePBX\'s own HTTPS is used when it serves /openprov (System Admin port, then 443)'] = function () {
	$f = httpsFixture(['https://127.0.0.1:1443', 'http://127.0.0.1:80'], ['acp' => '80', 'sslacp' => '1443', 'fail2ban_ban_time' => '86400']);
	$s = $f->web->resolve();
	eq(['freepbx', 1443, true], [$s['mode'], $s['port'], $s['ok']]);
	eq(1443, $f->settings->phonePort());
	eq([], $f->pm2->calls, 'no service');
	eq('https://127.0.0.1:1443/openprov/_ping', $f->probed[0], 'System Admin\'s HTTPS port first');

	$f = httpsFixture(['https://127.0.0.1:443']);
	eq(['freepbx', 443], array_slice(array_values($f->web->resolve()), 0, 2));
};

$tests['https: no HTTPS on FreePBX (the lab) → OpenProv\'s service on service_port, firewall service added'] = function () {
	$f = httpsFixture(['http://127.0.0.1:80', 'https://127.0.0.1:8449'], ['acp' => '80', 'ucp' => '81', 'hpro' => '84']);
	$s = $f->web->resolve();
	ok($s['ok'], $s['message']);
	eq(['service', 8449, 80], [$s['mode'], $s['port'], $s['upstream']]);
	eq(8449, $f->settings->phonePort());
	eq('start', $f->pm2->calls[0][0]);
	$cfg = json_decode(file_get_contents($f->tls->configFile()), true);
	eq([8449, 80, 's3cret'], [$cfg['port'], $cfg['upstreamPort'], $cfg['secret']]);
	eq('600', substr(sprintf('%o', fileperms($f->tls->configFile())), -3), 'secret file is private');
	eq('8449', $f->fw->svc['id1']['custfw']['port']);
	eq(['internal', 'other'], $f->fw->zones['id1']);
	// Unchanged: not restarted.
	$f->web->resolve();
	eq(1, count($f->pm2->calls));
	// FreePBX gains HTTPS later: the service and its firewall service go away.
	$f2 = httpsFixture(['https://127.0.0.1:443']);
	$f2->pm2->online = true;
	$f2->fw->addCustomService('OpenProv provisioning', 'tcp', '8449');
	eq('freepbx', $f2->web->resolve()['mode']);
	eq([['delete', 'openprov-tls']], $f2->pm2->calls);
	eq([], $f2->fw->svc);
};

$tests['https: a taken service port is reported and never fought over; Apache untouched'] = function () {
	$f = httpsFixture(['http://127.0.0.1:80'], [], ['inUse' => [8449]]);
	$s = $f->web->resolve();
	ok(!$s['ok'] && str_contains($s['message'], 'Port 8449 is already in use'), $s['message']);
	eq([], $f->pm2->calls, 'not started into a conflict');
	// Taken after the check (race): the service reports it.
	$f = httpsFixture(['http://127.0.0.1:80'], [], ['binds' => false]);
	$s = $f->web->resolve();
	ok(!$s['ok'] && str_contains($s['message'], 'already in use'), $s['message']);
	// No pm2 module.
	$f = httpsFixture(['http://127.0.0.1:80'], [], ['pm2' => null]);
	ok(str_contains($f->web->resolve()['message'], 'pm2 module'));
};

$tests['https: freepbx mode without HTTPS says how to fix it; http scheme uses http_port'] = function () {
	$f = httpsFixture(['http://127.0.0.1:80']);
	$f->settings->set('https_mode', 'freepbx');
	$s = $f->web->resolve();
	ok(!$s['ok'] && str_contains($s['message'], 'Port Management'), $s['message']);
	eq([], $f->pm2->calls);
	$f->settings->set('phone_scheme', 'http');
	eq(['http', 80, true], array_slice(array_values($f->web->resolve()), 0, 3));
	$f = httpsFixture(['https://127.0.0.1:443', 'http://127.0.0.1:80', 'https://127.0.0.1:8449']);
	$f->settings->set('https_mode', 'service');
	eq('service', $f->web->resolve()['mode'], 'forced service even though 443 exists');
};

// ---- runner --------------------------------------------------------------------

$filter = $argv[1] ?? '';
$pass = $fail = 0;
foreach ($tests as $name => $fn) {
	if ($filter !== '' && !str_contains($name, $filter)) {
		continue;
	}
	try {
		$fn();
		$pass++;
		echo "  ok    $name\n";
	} catch (\Throwable $e) {
		$fail++;
		echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
	}
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
