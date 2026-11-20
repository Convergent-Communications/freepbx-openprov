<?php
namespace FreePBX\modules\Openprov;

/**
 * Caller photos on ringing phones (DESIGN.md D10e).
 *
 * FreePBX adds `Call-Info: <url>;purpose=icon` to each INVITE it sends a
 * phone; a Yealink with account.N.picture_info_enable = 1 downloads the image
 * while it rings (T87W 185.87.0.42: about 1 s, shown round). The image is the
 * caller's Contact Manager photo: the user's for an extension, else a
 * contact's from a shared group (never a private one).
 *
 * VPN only: the URL is the VPN server's tunnel address, and /openprov/photo/
 * answers only connections from a tunnel address to a tunnel address, so photos are never served to the LAN or
 * the internet. The header is shared by every phone a call rings, so it can't
 * carry a phone's own credential: the path carries the number and a token
 * (SHA1 of a key derived from device_secret and the number, which the
 * dialplan computes too), so a VPN client can't walk the directory.
 */
class CallerPhotos
{
	/** Pixels per side of the square image phones get. */
	public const SIZE = 200;

	/** Dialplan contexts this module writes. */
	public const CONTEXT = 'openprov-caller-photo';
	public const TRUNK_CONTEXT = 'openprov-caller-photo-trunk';

	public function __construct(private Settings $settings, private Vpn $vpn, private ConfigBuilder $builder, private Pbx $pbx)
	{
	}

	/** On, and there's a VPN tunnel to serve photos over. */
	public function enabled(): bool
	{
		return (bool) $this->settings->get('caller_photos') && $this->vpn->serverEnabled() && $this->vpn->serverTunnelAddress() !== null;
	}

	public function key(): string
	{
		return hash_hmac('sha256', 'openprov caller photo', (string) $this->settings->get('device_secret'));
	}

	/** Same value as the dialplan's ${SHA1(key:number)}, first 16 hex digits. */
	public function token(string $digits): string
	{
		return substr(sha1($this->key() . ':' . $digits), 0, 16);
	}

	/** Base URL of photos: the VPN provisioning host (tunnel address), /openprov/photo/. */
	public function baseUrl(): string
	{
		return $this->builder->baseUrl('vpn') . '/photo/';
	}

	public function url(string $digits): string
	{
		return $this->baseUrl() . $digits . '-' . $this->token($digits) . '.jpg';
	}

	/**
	 * Add the dialplan (FreePBX dialplan hook, after core). Calls that ring
	 * an extension (macro-dial-one) or a ring group / follow-me list
	 * (macro-dial) get the header, unless something else already set a
	 * Call-Info (intercom's auto-answer). Trunk calls (macro-dialout-trunk)
	 * drop it again: core applies SIPHEADERS to trunk legs as well, and a
	 * call forwarded off the PBX would otherwise send the URL to the carrier.
	 *
	 * @param object $ext FreePBX's extensions object
	 */
	public function dialplan(object $ext): bool
	{
		if (!$this->enabled() || !class_exists('ext_gosub', false)) {
			return false;
		}
		$c = self::CONTEXT;
		$have = '${HASH(__SIPHEADERS,Call-Info)}${HASH(SIPHEADERS,Call-Info)}';
		$ext->add($c, 's', '', new \ext_gotoif('$["' . $have . '" != ""]', 'done'));
		$ext->add($c, 's', '', new \ext_set('OPENPROV_PHOTO_N', '${FILTER(0123456789,${CALLERID(num)})}'));
		$ext->add($c, 's', '', new \ext_gotoif('$[${LEN(${OPENPROV_PHOTO_N})} < 2 | ${LEN(${OPENPROV_PHOTO_N})} > 20]', 'done'));
		$ext->add($c, 's', '', new \ext_set('OPENPROV_PHOTO_T', '${SHA1(' . $this->key() . ':${OPENPROV_PHOTO_N})}'));
		$ext->add($c, 's', '', new \ext_set('HASH(__SIPHEADERS,Call-Info)', '<' . $this->baseUrl() . '${OPENPROV_PHOTO_N}-${OPENPROV_PHOTO_T:0:16}.jpg>\;purpose=icon'));
		$ext->add($c, 's', 'done', new \ext_return());

		$t = self::TRUNK_CONTEXT;
		$ext->add($t, 's', '', new \ext_execif('$[${REGEX("' . Web::PATH . '/photo/" ${HASH(__SIPHEADERS,Call-Info)})}]', 'Set', 'HASH(__SIPHEADERS,Call-Info)=unset'));
		$ext->add($t, 's', '', new \ext_return());

		$this->spliceBefore($ext, 'macro-dial-one', ['dial'], new \ext_gosub('1', 's', $c));
		$this->spliceBefore($ext, 'macro-dial', ['nddialapp', 'hsdialapp'], new \ext_gosub('1', 's', $c));
		$this->spliceBefore($ext, 'macro-dialout-trunk', [], new \ext_gosub('1', 's', $t));
		return true;
	}

	/**
	 * Splice $cmd into $section's "s" before each label, or after its first
	 * line when $labels is empty. A missing context or label is skipped (FreePBX
	 * would otherwise append the line at the end, after the Dial).
	 */
	private function spliceBefore(object $ext, string $section, array $labels, object $cmd): void
	{
		$lines = $ext->_exts[$section][' s '] ?? null;
		if (!is_array($lines) || !$lines) {
			return;
		}
		if (!$labels) {
			$ext->splice($section, 's', 1, $cmd);
			return;
		}
		$tags = array_column($lines, 'tag');
		foreach ($labels as $label) {
			if (in_array($label, $tags, true)) {
				$ext->splice($section, 's', $label, clone $cmd);
			}
		}
	}

	/**
	 * The photo for /photo/<number>-<token>.jpg, requested from $ip to the
	 * PBX's $local address:
	 * ['status', 'body', 'why'] (status 200 = body is a JPEG).
	 */
	public function serve(string $digits, string $token, string $ip, string $local): array
	{
		if (!$this->enabled()) {
			return ['status' => 404, 'why' => 'caller photos off'];
		}
		if (!$this->vpn->isTunnelAddress($ip)) {
			return ['status' => 404, 'why' => 'caller photo: not a VPN address'];
		}
		// Second check: the connection reached the PBX's own tunnel address,
		// so a LAN that overlaps the VPN subnet can't pass as the VPN.
		if (!$this->vpn->isTunnelAddress($local)) {
			return ['status' => 404, 'why' => 'caller photo: not to the VPN address' . ($local === '' ? ' (unknown)' : " ($local)")];
		}
		if (!hash_equals($this->token($digits), $token)) {
			return ['status' => 404, 'why' => 'caller photo: bad token'];
		}
		$raw = $this->pbx->callerPhoto($digits);
		$img = $raw === null ? null : self::square($raw, self::SIZE);
		return $img === null ? ['status' => 404, 'why' => "no photo for $digits"] : ['status' => 200, 'body' => $img];
	}

	/** Center-cropped square JPEG, or null when $data isn't an image GD reads. */
	public static function square(string $data, int $size): ?string
	{
		if (!function_exists('imagecreatefromstring')) {
			return null;
		}
		$src = @imagecreatefromstring($data);
		if (!$src) {
			return null;
		}
		$w = imagesx($src);
		$h = imagesy($src);
		$side = min($w, $h);
		$out = imagecreatetruecolor($size, $size);
		imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNGs: white, not black
		imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
		ob_start();
		imagejpeg($out, null, 85);
		return (string) ob_get_clean();
	}
}
