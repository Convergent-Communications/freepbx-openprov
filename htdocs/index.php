<?php
/**
 * OpenProv provisioning front controller, for phones (DESIGN.md D3).
 *
 * Published as /openprov/ on FreePBX's own web server: <webroot>/openprov is
 * a symlink to this directory and .htaccess sends every path here, so
 * https://pbx/openprov/<mac>.cfg reaches ProvisionServer as /<mac>.cfg.
 *
 * The file is also reachable inside the module directory under /admin; answer
 * only when it was requested through the published path.
 */
const OPENPROV_PATH = '/openprov';

if (($_SERVER['SCRIPT_NAME'] ?? '') !== OPENPROV_PATH . '/index.php') {
	http_response_code(404);
	exit;
}
$uriPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if ($uriPath !== OPENPROV_PATH && !str_starts_with($uriPath, OPENPROV_PATH . '/')) {
	http_response_code(404);
	exit;
}
$path = substr($uriPath, strlen(OPENPROV_PATH)) ?: '/';
// Phones only ever GET (or HEAD) configs and files; nothing large comes in.
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
	http_response_code(413);
	exit;
}

// Basic auth: mod_php fills PHP_AUTH_*. Under php-fpm the header arrives via
// the .htaccess rewrite (REDIRECT_ prefix after the internal redirect).
$user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : null;
$pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : null;
if ($user === null) {
	$hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
	if (stripos($hdr, 'basic ') === 0) {
		$dec = base64_decode(substr($hdr, 6), true);
		if ($dec !== false && str_contains($dec, ':')) {
			[$user, $pass] = explode(':', $dec, 2);
		}
	}
}
$secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

$bootstrap_settings = [
	'freepbx_auth' => false,
	'skip_astman' => true,
	'include_compress' => false,
	'cdrdb' => false,
];
$restrict_mods = ['openprov' => true];
include '/etc/freepbx.conf';

try {
	// Loads Openprov.class.php, which registers the autoloader for
	// FreePBX\modules\Openprov\*: nothing below may use those classes before.
	$openprov = \FreePBX::Openprov();
} catch (\Throwable $e) {
	error_log('OpenProv: ' . $e->getMessage());
	http_response_code(500);
	exit;
}

if ($path === \FreePBX\modules\Openprov\Web::PING) {
	// Health check (fwconsole openprov web --check, Settings tab): proves
	// Apache routes the path here and FreePBX bootstraps. Only OpenProv itself
	// knows the token; anyone else gets the same 404 as any unknown path.
	$token = (string) ($_SERVER['HTTP_X_OPENPROV_PING'] ?? '');
	if ($token === '' || !hash_equals(\FreePBX\modules\Openprov\Web::pingToken($openprov->proxySecret()), $token)) {
		http_response_code(404);
		exit;
	}
	header('Content-Type: text/plain');
	echo \FreePBX\modules\Openprov\Web::PING_BODY;
	exit;
}

// Forwarded by OpenProv's HTTPS service (node/tls-service.js): it terminated
// TLS and names the phone. Trusted only from loopback with the shared secret.
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
// The port the request reached Apache on. Apache derives SERVER_PORT from the
// Host header when that names a port, so on a box with several HTTPS ports a
// client could name another one; DESIGN.md D3 ("Internet access") covers it.
$port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
// The local address the connection reached (not the Host header).
$local = (string) ($_SERVER['SERVER_ADDR'] ?? '');
$proxyHeader = (string) ($_SERVER['HTTP_X_OPENPROV_PROXY'] ?? '');
if ($proxyHeader !== '' && in_array($ip, ['127.0.0.1', '::1'], true)) {
	$fwd = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
	if (!hash_equals($openprov->proxySecret(), $proxyHeader) || filter_var($fwd, FILTER_VALIDATE_IP) === false) {
		http_response_code(403);
		exit;
	}
	$ip = $fwd;
	$secure = true;
	// The service's own listening port (0 from an older service that doesn't
	// send it: then internet addresses are refused).
	$port = (int) ($_SERVER['HTTP_X_OPENPROV_PORT'] ?? 0);
	// ...and the address it reached ('' from an older service: then no
	// caller photos).
	$local = (string) ($_SERVER['HTTP_X_OPENPROV_LOCAL'] ?? '');
	$local = filter_var($local, FILTER_VALIDATE_IP) === false ? '' : $local;
}

try {
	$res = $openprov->provisionServer()->handle($path, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $ip, $user, $pass, $secure, $port, $local);
} catch (\Throwable $e) {
	error_log('OpenProv: ' . $e->getMessage());
	$res = ['status' => 500, 'headers' => [], 'body' => "internal error\n"];
}

http_response_code($res['status']);
header_remove('X-Powered-By');
foreach ($res['headers'] as $k => $v) {
	header("$k: $v");
}
if (isset($res['file'])) {
	// Stream large files (firmware is ~40-90 MB) instead of buffering them.
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	@set_time_limit(0);
	readfile($res['file']);
} else {
	echo $res['body'];
}
