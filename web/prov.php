<?php
/**
 * OpenProv provisioning front controller. Only answers on the OpenProv vhost
 * (OPENPROV_VHOST is set there); the same file is reachable under /admin on
 * port 80 because it lives in the module directory, so refuse that.
 */
if (getenv('OPENPROV_VHOST') !== '1' && ($_SERVER['OPENPROV_VHOST'] ?? '') !== '1') {
	http_response_code(404);
	exit;
}

$bootstrap_settings = [
	'freepbx_auth' => false,
	'skip_astman' => true,
	'include_compress' => false,
	'cdrdb' => false,
];
$restrict_mods = ['openprov' => true];
include '/etc/freepbx.conf';

try {
	$server = \FreePBX::Openprov()->provisionServer();
	$res = $server->handle(
		(string) ($_SERVER['REQUEST_URI'] ?? '/'),
		(string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
		(string) ($_SERVER['REMOTE_ADDR'] ?? ''),
		isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : null,
		isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : null
	);
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
