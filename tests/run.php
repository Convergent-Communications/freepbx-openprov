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

use FreePBX\modules\Openprov\{Assets, Catalog, ConfigBuilder, Notifier, Pbx, ProvisionServer, Service, Settings, Store, Tar, Util, Vpn, WebServer};
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

	public function pjsipPort(): int
	{
		return 5060;
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
	public function clients(): array
	{
		return [6 => ['id' => 6, 'description' => '212', 'enabled' => true, 'serverremote' => '192.0.2.1', 'remote' => '', 'useserverremote' => true, 'hasFiles' => true]];
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

	public function connectedClients(): array
	{
		return [];
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
	$db->exec('CREATE TABLE openprov_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, mac TEXT UNIQUE, brand TEXT, model TEXT, template_id INTEGER, name TEXT DEFAULT "", enabled INTEGER DEFAULT 1, settings TEXT, raw TEXT, prov_user TEXT DEFAULT "", prov_pass TEXT DEFAULT "", claimable INTEGER DEFAULT 1, last_seen INTEGER DEFAULT 0, last_ip TEXT DEFAULT "", last_ua TEXT DEFAULT "", last_fw TEXT DEFAULT "", last_hash TEXT DEFAULT "", notified_hash TEXT DEFAULT "", updated_at INTEGER DEFAULT 0)');
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
	$store = new Store($db);
	$catalog = new Catalog(dirname(__DIR__) . '/models');
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
	$svc = new Service($store, $catalog, $pbx, $vpn, $assets, $settings, $builder, $notifier, new WebServer($settings, '/tmp'));
	$server = new ProvisionServer($store, $catalog, $builder, $settings, $assets, $vpn);
	return (object) compact('db', 'settings', 'store', 'catalog', 'pbx', 'vpn', 'assets', 'builder', 'svc', 'server', 'sent', 'dir');
}

const UA = 'Yealink SIP-T87W 185.87.0.37 c4:fc:22:1d:d6:37';
const MAC = 'c4fc221dd637';

function phone(object $w, array $extra = []): array
{
	$tpl = $w->svc->saveTemplate(['name' => 'Base', 'settings' => ['label_format' => '{first}'], 'keys' => [['position' => 1, 'type' => 'line', 'account' => 1]]]);
	return $w->svc->saveDevice($extra + ['mac' => 'C4:FC:22:1D:D6:37', 'model' => 'T87W', 'template' => $tpl['id'], 'lines' => [['account' => 1, 'extension' => '212']]]);
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
	eq('c4fc221dd637', Util::mac('C4:FC:22:1D:D6:37'));
	eq('c4fc221dd637', Util::mac('c4-fc-22-1d-d6-37'));
	eq(null, Util::mac('c4fc221dd6'));
	eq('c4:fc:22:1d:d6:37', Util::macPretty('C4FC221DD637'));
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
	eq('https://192.0.2.10:8443', $kv['static.auto_provision.server.url']);
	eq('c4fc221dd637', $kv['static.auto_provision.server.username']);
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

$tests['server: bootstrap, claim, and per-device credentials'] = function () {
	$w = world();
	$d = phone($w);
	$pass = $w->store->deviceByMac(MAC)['prov_pass'];
	eq(401, $w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', null, null)['status']);
	eq(401, $w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', 'boot', 'wrong')['status']);
	$r = $w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', 'boot', 'bootpass');
	eq(200, $r['status'], 'bootstrap works while claimable');
	ok(str_contains($r['body'], "static.auto_provision.server.password = $pass"));
	ok($w->store->deviceByMac(MAC)['claimable'], 'still claimable until the phone uses its own creds');
	eq(200, $w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', MAC, $pass)['status']);
	ok(!$w->store->deviceByMac(MAC)['claimable'], 'claimed');
	eq(401, $w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status'], 'bootstrap refused after claim');
	eq('185.87.0.37', $w->store->deviceByMac(MAC)['last_fw']);
	$w->svc->rearmDevice(MAC, false);
	eq(200, $w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status'], 're-armed');
};

$tests['server: user-agent MAC, allow-list, unknown devices, paths'] = function () {
	$w = world();
	phone($w);
	eq(403, $w->server->handle('/c4fc221dd637.cfg', 'Yealink SIP-T87W 185.87.0.37 c4:fc:22:1d:d6:38', '192.0.2.50', 'boot', 'bootpass')['status']);
	eq(404, $w->server->handle('/001565000001.cfg', 'Yealink SIP-T87W 185.87.0.37 00:15:65:00:00:01', '192.0.2.51', 'boot', 'bootpass')['status']);
	$disc = $w->svc->discovered();
	eq('001565000001', $disc[0]['mac']);
	eq('T87W', $disc[0]['model']);
	eq(404, $w->server->handle('/c4fc221dd637.boot', UA, '192.0.2.50', null, null)['status']);
	eq(200, $w->server->handle('/y000000000197.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status']);
	eq(400, $w->server->handle('/a/wallpaper/1/../../etc/passwd', UA, '192.0.2.50', MAC, 'x')['status']);
	$w->settings->set('allowed_networks', '10.0.0.0/8');
	eq(403, $w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', 'boot', 'bootpass')['status']);
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
	ok(str_contains($cfg['openvpn.url'], '/c4fc221dd637-vpn.tar'));
	eq(401, $w->server->handle('/c4fc221dd637-vpn.tar', UA, '192.0.2.50', null, null)['status']);
	$r = $w->server->handle('/c4fc221dd637-vpn.tar', UA, '192.0.2.50', MAC, $pass);
	eq(200, $r['status']);
	ok(str_contains($r['body'], 'remote 192.0.2.1 1194,vpn.example.com 1194'));
};

$tests['sync: changes notify once; vpn phones are not sent check-sync'] = function () {
	$w = world();
	phone($w);
	$pass = $w->store->deviceByMac(MAC)['prov_pass'];
	$w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', MAC, $pass);
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
	$r = $w->svc->importDevicesCsv("mac,model,template,name,extension\nc4:fc:22:1d:d6:37,T87W,Base,Desk,212\nzz,T87W,,Bad,212\n");
	eq(['c4fc221dd637'], $r['imported']);
	eq(3, $r['errors'][0]['row']);
	ok(str_contains($w->svc->exportDevicesCsv(), 'c4fc221dd637,T87W,Base,Desk,212'));
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
	$w->svc->saveDevice(['mac' => '80:5e:c0:d6:7c:5f', 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '201']], 'settings' => ['vpn' => '6']]);
	eq('notify', $w->svc->resync(['805ec0d67c5f'])[0]['method']);
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
	ok(str_contains($cfg['static.auto_provision.server.url'], '://10.8.0.1:'), $cfg['static.auto_provision.server.url']);
	eq('10.8.0.1', $cfg['account.1.sip_server.1.address']);
	ok(!str_contains($cfg['openvpn.url'], '10.8.0.1'), 'vpn tar not via the tunnel on first contact');
	// Request through the tunnel: everything through the tunnel.
	$cfg = kv($w->builder->render($d, '10.8.0.2')['text']);
	ok(str_contains($cfg['openvpn.url'], '@10.8.0.1:'), $cfg['openvpn.url']);
	// Server records the request IP, and sync renders reuse it (same hash as the phone got).
	$r = $w->server->handle('/c4fc221dd637.cfg', UA, '10.8.0.2', MAC, $pass);
	eq(200, $r['status']);
	$d = $w->store->deviceByMac(MAC);
	eq($d['last_hash'], $w->builder->render($d)['hash'], 'sync render matches what the phone fetched');
	// A VPN provisioning host name overrides the tunnel address.
	$w->settings->set('vpn_provisioning_host', 'prov-vpn.example.com');
	$cfg = kv($w->builder->render($d)['text']);
	ok(str_contains($cfg['static.auto_provision.server.url'], 'prov-vpn.example.com'));
	// A model without the quirk keeps the template's network (internal).
	$w->svc->saveDevice(['mac' => '80:5e:c0:d6:7c:5f', 'model' => 'T57W', 'lines' => [['account' => 1, 'extension' => '212']], 'settings' => ['vpn' => 'auto']]);
	$cfg = kv($w->builder->render($w->store->deviceByMac('805ec0d67c5f'), '192.168.1.126')['text']);
	ok(!str_contains($cfg['static.auto_provision.server.url'], 'prov-vpn'), $cfg['static.auto_provision.server.url']);
	throws(fn() => $w->settings->set('firewall_zones', 'internal,dmz'), 'dmz');
	$w->settings->set('firewall_zones', 'Internal, other');
	eq('internal,other', $w->settings->get('firewall_zones'));
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
	$w->server->handle('/c4fc221dd637.cfg', UA, '192.0.2.50', MAC, $d['prov_pass']);
	$st = $w->svc->deviceSync(MAC, $t);
	eq('current', $st['sync']);
	eq('/c4fc221dd637.cfg', end($st['events'])['path']);
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

$tests['service: null template and parent are accepted'] = function () {
	$w = world();
	$d = $w->svc->saveDevice(['mac' => MAC, 'model' => 'T87W', 'template' => null]);
	eq(null, $d['template_id']);
	$t = $w->svc->saveTemplate(['name' => 'Solo', 'parent' => null]);
	eq(null, $t['parent_id']);
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
