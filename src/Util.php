<?php
namespace FreePBX\modules\Openprov;

/** Small helpers with no FreePBX dependencies (unit-testable). */
final class Util
{
	/** Normalise a MAC to 12 lowercase hex chars, or null if invalid. */
	public static function mac(?string $mac): ?string
	{
		if ($mac === null) {
			return null;
		}
		$m = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
		return strlen($m) === 12 ? $m : null;
	}

	/** Pretty MAC (aa:bb:cc:dd:ee:ff). */
	public static function macPretty(string $mac): string
	{
		return implode(':', str_split(self::mac($mac) ?? $mac, 2));
	}

	public static function jsonDecode(?string $json, array $default = []): array
	{
		if ($json === null || $json === '') {
			return $default;
		}
		$v = json_decode($json, true);
		return is_array($v) ? $v : $default;
	}

	public static function jsonEncode($v): string
	{
		return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/** Random URL-safe secret. */
	public static function secret(int $bytes = 18): string
	{
		return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'AZ'), '=');
	}

	/** Random numeric password (many phones only accept digits in some fields). */
	public static function digits(int $len = 10): string
	{
		$s = '';
		for ($i = 0; $i < $len; $i++) {
			$s .= (string) random_int(0, 9);
		}
		return $s;
	}

	/**
	 * Parse "key = value" lines (Yealink cfg / raw-key editor) into an ordered map.
	 * Lines starting with # or ; are comments. Later duplicates win.
	 */
	public static function parseKv(string $text): array
	{
		$out = [];
		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || $line[0] === ';') {
				continue;
			}
			$pos = strpos($line, '=');
			if ($pos === false) {
				continue;
			}
			$k = trim(substr($line, 0, $pos));
			if ($k === '' || !preg_match('/^[A-Za-z0-9_.\-\[\]]+$/', $k)) {
				continue;
			}
			// A repeated key is kept as "key#2", "key#3"…: Yealink acts on every
			// line (e.g. several ringtone.url downloads); format() strips the suffix.
			$key = $k;
			for ($n = 2; array_key_exists($key, $out); $n++) {
				$key = "$k#$n";
			}
			$out[$key] = trim(substr($line, $pos + 1));
		}
		return $out;
	}

	/** Render a map back to "key = value" lines. */
	public static function formatKv(array $kv): string
	{
		$s = '';
		foreach ($kv as $k => $v) {
			$s .= $k . ' = ' . self::cfgValue((string) $v) . "\n";
		}
		return $s;
	}

	/** Values cannot contain newlines in a cfg file. */
	public static function cfgValue(string $v): string
	{
		return str_replace(["\r", "\n"], ' ', $v);
	}

	/** Expand a label format: {name} {first} {last} {ext}. */
	public static function label(string $format, string $ext, string $name, ?string $first = null, ?string $last = null): string
	{
		$name = trim($name);
		$parts = preg_split('/\s+/', $name) ?: [];
		$first = $first ?? ($parts[0] ?? '');
		$last = $last ?? (count($parts) > 1 ? end($parts) : '');
		$out = strtr($format, [
			'{name}' => $name !== '' ? $name : $ext,
			'{first}' => $first !== '' ? $first : $ext,
			'{last}' => $last !== '' ? $last : ($first !== '' ? $first : $ext),
			'{ext}' => $ext,
		]);
		return trim(preg_replace('/\s+/', ' ', $out));
	}

	/** Constant-time string compare that tolerates empty strings. */
	public static function eq(string $a, string $b): bool
	{
		return $a !== '' && $b !== '' && hash_equals($a, $b);
	}

	/** Is $ip inside any CIDR in $list (IPv4 and IPv6)? Empty list = allow. */
	public static function ipAllowed(string $ip, array $list): bool
	{
		$list = array_values(array_filter(array_map('trim', $list)));
		if (!$list) {
			return true;
		}
		$bin = @inet_pton($ip);
		if ($bin === false) {
			return false;
		}
		foreach ($list as $cidr) {
			[$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
			$nb = @inet_pton($net);
			if ($nb === false || strlen($nb) !== strlen($bin)) {
				continue;
			}
			$bits = $bits === null ? strlen($nb) * 8 : (int) $bits;
			$bytes = intdiv($bits, 8);
			$rem = $bits % 8;
			if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
				continue;
			}
			if ($rem === 0) {
				return true;
			}
			$mask = chr((0xFF << (8 - $rem)) & 0xFF);
			if ((ord($bin[$bytes]) & ord($mask)) === (ord($nb[$bytes]) & ord($mask))) {
				return true;
			}
		}
		return false;
	}
}
