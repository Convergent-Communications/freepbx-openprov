<?php
namespace FreePBX\modules\Openprov;

/**
 * WAV helpers. Yealink's built-in ringtones are G.711 (µ-law) WAV files,
 * which most browsers can't play; toPcmWav() turns them into 16-bit PCM.
 */
class Audio
{
	/** Return a 16-bit PCM WAV for a PCM, µ-law or A-law WAV. Other input is returned unchanged. */
	public static function toPcmWav(string $wav): string
	{
		if (substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
			return $wav;
		}
		$fmt = null;
		$data = null;
		for ($p = 12; $p + 8 <= strlen($wav);) {
			$id = substr($wav, $p, 4);
			$len = unpack('V', substr($wav, $p + 4, 4))[1];
			$body = substr($wav, $p + 8, $len);
			if ($id === 'fmt ') {
				$fmt = unpack('vformat/vchannels/Vrate', $body);
			} elseif ($id === 'data') {
				$data = $body;
			}
			$p += 8 + $len + ($len & 1);
		}
		if (!$fmt || $data === null || !in_array($fmt['format'], [6, 7], true)) {
			return $wav; // already PCM (1) or something we leave alone
		}
		$decode = $fmt['format'] === 7 ? [self::class, 'ulaw'] : [self::class, 'alaw'];
		$pcm = '';
		for ($i = 0, $n = strlen($data); $i < $n; $i++) {
			$pcm .= pack('v', $decode(ord($data[$i])) & 0xffff);
		}
		$ch = $fmt['channels'];
		$rate = $fmt['rate'];
		return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
			. 'fmt ' . pack('VvvVVvv', 16, 1, $ch, $rate, $rate * $ch * 2, $ch * 2, 16)
			. 'data' . pack('V', strlen($pcm)) . $pcm;
	}

	private static function ulaw(int $u): int
	{
		$u = ~$u & 0xff;
		$t = (($u & 0x0f) << 3) + 0x84;
		$t <<= ($u & 0x70) >> 4;
		return ($u & 0x80) ? (0x84 - $t) : ($t - 0x84);
	}

	private static function alaw(int $a): int
	{
		$a ^= 0x55;
		$t = ($a & 0x0f) << 4;
		$seg = ($a & 0x70) >> 4;
		$t += $seg === 0 ? 8 : 0x108;
		if ($seg > 1) {
			$t <<= $seg - 1;
		}
		return ($a & 0x80) ? $t : -$t;
	}
}
