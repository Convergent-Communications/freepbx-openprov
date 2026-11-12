<?php
namespace FreePBX\modules\Openprov;

/**
 * Handles phone requests on the provisioning vhost. Pure request → response
 * logic (no globals), so it can be unit tested.
 *
 * Response: ['status' => int, 'headers' => [..], 'body' => string] or
 *           ['status' => 200, 'headers' => [..], 'file' => path]
 */
class ProvisionServer
{
	public function __construct(
		private Store $store,
		private Catalog $catalog,
		private ConfigBuilder $builder,
		private Settings $settings,
		private Assets $assets,
		private Vpn $vpn
	) {
	}

	public function handle(string $path, string $ua, string $ip, ?string $user, ?string $pass): array
	{
		$path = '/' . ltrim(rawurldecode(parse_url($path, PHP_URL_PATH) ?? '/'), '/');
		$log = ['ip' => $ip, 'ua' => $ua, 'path' => $path];

		if (!Util::ipAllowed($ip, $this->settings->allowedNetworks())) {
			return $this->deny(403, 'source address not allowed', $log);
		}
		if (str_contains($path, '..') || str_contains($path, "\0")) {
			return $this->deny(400, 'bad path', $log);
		}

		// Assets: /a/<kind>/<id>/...
		if (preg_match('#^/a/(wallpaper|ringtone|firmware)/(\d+)/(.+)$#', $path, $m)) {
			$device = $this->authAny($user, $pass);
			if (!$device) {
				return $this->challenge($log, 'asset auth failed');
			}
			$log['mac'] = $device['mac'];
			return $this->asset($m[1], (int) $m[2], $m[3], $log);
		}

		// VPN profile: /<mac>-vpn.tar
		if (preg_match('#^/([0-9a-f]{12})-vpn\.tar$#i', $path, $m)) {
			$mac = strtolower($m[1]);
			$log['mac'] = $mac;
			$device = $this->store->deviceByMac($mac);
			if (!$device || !$this->authFor($device, $user, $pass)) {
				return $user === null ? $this->challenge($log, 'vpn auth required') : $this->deny(403, 'vpn auth failed', $log);
			}
			return $this->vpnTar($device, $log);
		}

		$brandRoute = null;
		foreach (['yealink'] as $brandId) {
			$r = $this->builder->brand($brandId)->parsePath($path);
			if ($r) {
				$brandRoute = $r + ['brand' => $brandId];
				break;
			}
		}
		if (!$brandRoute) {
			return $this->deny(404, 'unknown path', $log);
		}
		$brand = $this->builder->brand($brandRoute['brand']);
		$uaMac = $brand->macFromUserAgent($ua);
		$log['mac'] = $brandRoute['mac'] ?? ($uaMac ?? '');

		if ($brandRoute['kind'] === 'ignore') {
			return $this->deny(404, 'not served', $log, false);
		}

		if ($user === null) {
			return $this->challenge($log, 'no credentials');
		}

		if ($brandRoute['kind'] === 'common') {
			if (!$this->authAny($user, $pass) && !$this->isBootstrap($user, $pass)) {
				return $this->deny(401, 'common auth failed', $log, true);
			}
			$model = $this->catalog->modelFromUserAgent($ua);
			$body = $brand->common(['model' => $model ?? []]);
			$this->store->logEvent($log + ['status' => 200, 'detail' => 'common ' . ($model['id'] ?? '?')]);
			return ['status' => 200, 'headers' => ['Content-Type' => 'text/plain'], 'body' => $body];
		}

		// Device config
		$mac = $brandRoute['mac'];
		if ($this->settings->get('enforce_ua_mac') && $uaMac !== null && $uaMac !== $mac) {
			return $this->deny(403, 'user-agent MAC mismatch', $log);
		}
		$device = $this->store->deviceByMac($mac);
		if (!$device) {
			$device = $this->maybeAdopt($mac, $ua, $user, $pass, $log);
			if (!$device) {
				return $this->deny(404, $this->isBootstrap($user, $pass) ? 'unknown device (discovered)' : 'unknown device', $log, false);
			}
		}
		$usedDevice = Util::eq((string) $user, $device['prov_user']) && Util::eq((string) $pass, $device['prov_pass']);
		$usedBootstrap = !$usedDevice && $device['claimable'] && $this->isBootstrap($user, $pass);
		if (!$usedDevice && !$usedBootstrap) {
			return $this->deny(401, $this->isBootstrap($user, $pass) ? 'bootstrap refused: device already claimed' : 'bad credentials', $log, true);
		}
		if (!$device['enabled']) {
			return $this->deny(403, 'device disabled', $log);
		}
		try {
			$out = $this->builder->render($device);
		} catch (\Throwable $e) {
			return $this->deny(500, 'render failed: ' . $e->getMessage(), $log);
		}
		$touch = [
			'last_seen' => time(), 'last_ip' => $ip, 'last_ua' => $ua,
			'last_fw' => $brand->firmwareFromUserAgent($ua), 'last_hash' => $out['hash'],
		];
		if ($usedDevice && $device['claimable']) {
			$touch['claimable'] = false; // phone proved it adopted its own credentials
		}
		$this->store->touchDevice($device['id'], $touch);
		$this->store->logEvent($log + ['status' => 200, 'detail' => ($usedBootstrap ? 'config (bootstrap)' : 'config') . ' ' . substr($out['hash'], 0, 8)]);
		return ['status' => 200, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'body' => $out['text']];
	}

	private function maybeAdopt(string $mac, string $ua, ?string $user, ?string $pass, array $log): ?array
	{
		if (!$this->settings->get('auto_adopt') || !$this->isBootstrap($user, $pass)) {
			return null;
		}
		$model = $this->catalog->modelFromUserAgent($ua);
		if (!$model) {
			return null;
		}
		$tpl = (int) $this->settings->get('auto_adopt_template') ?: null;
		$id = $this->store->saveDevice([
			'mac' => $mac, 'brand' => $model['brand'], 'model' => $model['id'], 'template_id' => $tpl,
			'name' => 'Adopted ' . Util::macPretty($mac), 'enabled' => true, 'claimable' => true,
			'prov_user' => $mac, 'prov_pass' => Util::secret(18),
		]);
		$this->store->logEvent($log + ['mac' => $mac, 'status' => 201, 'detail' => 'auto-adopted as ' . $model['id']]);
		return $this->store->device($id);
	}

	private function isBootstrap(?string $user, ?string $pass): bool
	{
		return Util::eq((string) $user, (string) $this->settings->get('bootstrap_user'))
			&& Util::eq((string) $pass, (string) $this->settings->get('bootstrap_pass'));
	}

	private function authFor(array $device, ?string $user, ?string $pass): bool
	{
		if (Util::eq((string) $user, $device['prov_user']) && Util::eq((string) $pass, $device['prov_pass'])) {
			return true;
		}
		return $device['claimable'] && $this->isBootstrap($user, $pass);
	}

	/** Device whose credentials these are (username = MAC). */
	private function authAny(?string $user, ?string $pass): ?array
	{
		$mac = Util::mac((string) $user);
		if ($mac === null) {
			return null;
		}
		$d = $this->store->deviceByMac($mac);
		return $d && $this->authFor($d, $user, $pass) ? $d : null;
	}

	private function asset(string $kind, int $id, string $rest, array $log): array
	{
		$a = $this->store->asset($id);
		if (!$a || $a['kind'] !== $kind) {
			return $this->deny(404, 'no such asset', $log);
		}
		$path = $this->assets->path($a);
		$type = $a['mime'] ?: 'application/octet-stream';
		if ($kind === 'wallpaper') {
			$modelId = explode('/', $rest)[0] ?? '';
			$device = $this->store->deviceByMac($log['mac'] ?? '');
			$model = $this->catalog->model($device['brand'] ?? 'yealink', rawurldecode($modelId));
			$path = $this->assets->wallpaperFor($a, $model['wallpaper'] ?? null);
			if (str_ends_with($path, '.jpg') && str_contains($path, '/cache/')) {
				$type = 'image/jpeg';
			}
		}
		if (!is_file($path)) {
			return $this->deny(404, 'asset file missing', $log);
		}
		$this->store->logEvent($log + ['status' => 200, 'detail' => "$kind {$a['id']}"]);
		return ['status' => 200, 'headers' => ['Content-Type' => $type, 'Content-Length' => (string) filesize($path)], 'file' => $path];
	}

	private function vpnTar(array $device, array $log): array
	{
		$ctx = $this->builder->context($device);
		if ($ctx['vpn_client'] === null) {
			return $this->deny(404, 'no vpn client for device', $log);
		}
		$remotes = [];
		$clients = $this->vpn->clients();
		$c = $clients[$ctx['vpn_client']] ?? null;
		if (!$c) {
			return $this->deny(404, 'vpn client ' . $ctx['vpn_client'] . ' not found', $log);
		}
		$port = $this->vpn->port();
		if ($c['serverremote'] !== '') {
			$remotes[] = $c['serverremote'] . ' ' . $port;
		}
		$ext = trim((string) $this->settings->get('vpn_external_remote'));
		if ($ext !== '') {
			$remotes[] = str_contains($ext, ' ') ? $ext : "$ext $port";
		}
		$tar = $this->vpn->yealinkTar($ctx['vpn_client'], $remotes ?: null);
		if ($tar === null) {
			return $this->deny(500, 'vpn client files unreadable', $log);
		}
		$this->store->logEvent($log + ['status' => 200, 'detail' => 'vpn client ' . $ctx['vpn_client']]);
		return ['status' => 200, 'headers' => ['Content-Type' => 'application/x-tar', 'Cache-Control' => 'no-store'], 'body' => $tar];
	}

	private function challenge(array $log, string $why): array
	{
		// First request from a Yealink is unauthenticated; not worth logging each one.
		return ['status' => 401, 'headers' => ['WWW-Authenticate' => 'Basic realm="OpenProv"'], 'body' => "authentication required\n", 'reason' => $why];
	}

	private function deny(int $status, string $why, array $log, bool $challenge = false): array
	{
		$this->store->logEvent($log + ['status' => $status, 'detail' => $why]);
		$headers = $challenge ? ['WWW-Authenticate' => 'Basic realm="OpenProv"'] : [];
		return ['status' => $status, 'headers' => $headers, 'body' => "$why\n", 'reason' => $why];
	}
}
