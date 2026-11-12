<?php
namespace FreePBX\modules\Openprov;

/**
 * Minimal client for the Yealink T5x/T8x web API (firmware 185.x), used to
 * reboot phones whose SIP check-sync is ignored (VPN enabled; DESIGN.md D8a).
 *
 * Login flow (reverse-engineered from the phone's own web UI):
 *   GET  /                                   seeds cookies
 *   POST /api/common/info {idlist:[rsaN,rsaE]} RSA public key (pre-auth data is under "error")
 *   POST /api/auth/login?p=Login  username=admin&pwd=__WUI_ENC__:<hex RSA-PKCS1(pass)>
 *   POST /api/common/info {idlist:["wui"]}   wui.common.token = CSRF token for later calls
 */
class PhoneClient
{
	private array $cookies = [];
	private ?string $token = null;

	public function __construct(
		private string $host,
		private string $password,
		private string $user = 'admin',
		private int $timeout = 8
	) {
	}

	private function request(string $method, string $path, $body = null, bool $json = false, ?string $page = null): array
	{
		$url = 'https://' . $this->host . $path;
		$sep = str_contains($path, '?') ? '&' : '?';
		if ($page) {
			$url .= $sep . 'p=' . rawurlencode($page);
			$sep = '&';
		}
		$url .= $sep . 't=' . (int) (microtime(true) * 1000);
		$headers = ['Accept: application/json, text/plain, */*', 'Referer: https://' . $this->host . '/'];
		if ($this->cookies) {
			$headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
		}
		if ($this->token) {
			$headers[] = 'X-CSRFToken: ' . $this->token;
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_SSL_VERIFYPEER => false, // phones use self-signed certificates
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT => $this->timeout * 2,
		]);
		if ($body !== null) {
			if ($json) {
				$headers[] = 'Content-Type: application/json;charset=UTF-8';
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
			} else {
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
				curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
			}
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$raw = curl_exec($ch);
		if ($raw === false) {
			$err = curl_error($ch);
			curl_close($ch);
			throw new \RuntimeException("phone {$this->host}: $err");
		}
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);
		foreach (preg_split('/\r\n/', substr($raw, 0, $hsize)) as $h) {
			if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $h, $m)) {
				$this->cookies[trim($m[1])] = trim($m[2]);
			}
		}
		$text = substr($raw, $hsize);
		$data = json_decode($text, true);
		return ['status' => $status, 'json' => is_array($data) ? $data : null, 'text' => $text];
	}

	public function login(): void
	{
		$this->request('GET', '/');
		$info = $this->request('POST', '/api/common/info', ['idlist' => ['wui.common.rsaN', 'wui.common.rsaE']], true)['json'] ?? [];
		$d = array_merge((array) ($info['error'] ?? []), (array) ($info['data'] ?? []));
		$n = $d['rsaN'] ?? $d['wui.common.rsaN'] ?? null;
		$e = $d['rsaE'] ?? $d['wui.common.rsaE'] ?? null;
		if (!$n || !$e) {
			throw new \RuntimeException("phone {$this->host}: no RSA key (not a supported Yealink web UI?)");
		}
		$pem = self::rsaPem((string) $n, (string) $e);
		if (!openssl_public_encrypt($this->password, $enc, $pem, OPENSSL_PKCS1_PADDING)) {
			throw new \RuntimeException('RSA encryption failed');
		}
		$res = $this->request('POST', '/api/auth/login', 'username=' . rawurlencode($this->user) . '&pwd=' . rawurlencode('__WUI_ENC__:' . bin2hex($enc)), false, 'Login');
		if (($res['json']['ret'] ?? '') !== 'ok') {
			throw new \RuntimeException("phone {$this->host}: login failed (" . substr($res['text'], 0, 120) . ')');
		}
		$basic = $this->request('POST', '/api/common/info', ['idlist' => ['wui']], true)['json'] ?? [];
		$flat = array_merge((array) ($basic['error'] ?? []), (array) ($basic['data'] ?? []));
		$this->token = $flat['wui.common.token'] ?? $flat['token'] ?? ($this->cookies['csrftoken'] ?? null);
	}

	public function reboot(): bool
	{
		$res = $this->request('POST', '/api/system/reboot', new \stdClass(), true);
		return ($res['json']['ret'] ?? '') === 'ok';
	}

	public function autopStatus(): ?string
	{
		$res = $this->request('GET', '/api/autop/status?noAutoJump=true');
		return isset($res['json']['data']) ? (string) $res['json']['data'] : null;
	}

	/** PEM for an RSA public key given hex modulus and exponent. */
	public static function rsaPem(string $nHex, string $eHex): string
	{
		$int = function (string $hex): string {
			$hex = ltrim(preg_replace('/^0x/i', '', $hex), '0');
			if (strlen($hex) % 2) {
				$hex = '0' . $hex;
			}
			$bin = hex2bin($hex);
			if (ord($bin[0]) > 0x7f) {
				$bin = "\0" . $bin;
			}
			return "\x02" . self::derLen(strlen($bin)) . $bin;
		};
		$seq = $int($nHex) . $int($eHex);
		$rsaKey = "\x30" . self::derLen(strlen($seq)) . $seq;
		$bitString = "\x03" . self::derLen(strlen($rsaKey) + 1) . "\0" . $rsaKey;
		$algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$spki = "\x30" . self::derLen(strlen($algo . $bitString)) . $algo . $bitString;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	private static function derLen(int $len): string
	{
		if ($len < 0x80) {
			return chr($len);
		}
		$b = '';
		while ($len > 0) {
			$b = chr($len & 0xff) . $b;
			$len >>= 8;
		}
		return chr(0x80 | strlen($b)) . $b;
	}
}
