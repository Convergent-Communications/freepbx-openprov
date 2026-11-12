<?php
namespace FreePBX\modules\Openprov;

/**
 * Wallpapers, ringtones and firmware files. Files live outside the web root
 * (default /var/lib/asterisk/openprov) and are only served through prov.php
 * to authenticated phones.
 */
class Assets
{
	public const KINDS = [
		'wallpaper' => ['image/jpeg', 'image/png', 'image/gif', 'image/bmp'],
		'ringtone' => ['audio/x-wav', 'audio/wav', 'audio/wave', 'audio/vnd.wave'],
		'firmware' => ['application/octet-stream', 'application/x-tar', 'application/zip'],
		'screensaver' => ['image/jpeg', 'image/png'],
	];

	public function __construct(private Store $store, private string $dir)
	{
	}

	public function dir(): string
	{
		return $this->dir;
	}

	public function ensureDir(): void
	{
		foreach (['', '/files', '/cache'] as $sub) {
			if (!is_dir($this->dir . $sub)) {
				@mkdir($this->dir . $sub, 0750, true);
				self::own($this->dir . $sub);
			}
		}
	}

	/** Files written by root (fwconsole) must stay readable by the web server user. */
	public static function own(string $path): void
	{
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($path, 'asterisk');
			@chgrp($path, 'asterisk');
		}
	}

	/** Store an uploaded file. Returns the asset row. */
	public function add(string $kind, string $name, string $sourcePath, array $meta = []): array
	{
		if (!isset(self::KINDS[$kind])) {
			throw new \InvalidArgumentException("Unknown asset kind '$kind'");
		}
		if (!is_file($sourcePath)) {
			throw new \InvalidArgumentException('Upload not found');
		}
		$this->ensureDir();
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: 'application/octet-stream';
		$name = trim($name) !== '' ? trim($name) : 'file';
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($kind === 'wallpaper' || $kind === 'screensaver') {
			if (!str_starts_with($mime, 'image/') || @getimagesize($sourcePath) === false) {
				throw new \InvalidArgumentException('Not an image');
			}
		}
		if ($kind === 'ringtone' && !in_array($mime, self::KINDS['ringtone'], true) && $ext !== 'wav') {
			throw new \InvalidArgumentException('Ringtones must be WAV files (8 kHz/16 kHz, 16-bit, mono is safest)');
		}
		if ($kind === 'firmware' && !in_array($ext, ['rom', 'zip', 'bin'], true)) {
			throw new \InvalidArgumentException('Firmware must be a .rom/.zip/.bin file');
		}
		$sha = hash_file('sha256', $sourcePath);
		// Phones choke on spaces and odd characters in file names.
		$safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
		$stored = substr($sha, 0, 12) . '-' . $safe;
		$dest = $this->dir . '/files/' . $stored;
		if (!@copy($sourcePath, $dest)) {
			throw new \RuntimeException("Could not write $dest");
		}
		@chmod($dest, 0640);
		self::own($dest);
		$version = $meta['version'] ?? '';
		if ($kind === 'firmware' && $version === '' && preg_match('/(\d+\.\d+\.\d+\.\d+)/', $name, $m)) {
			$version = $m[1];
		}
		$id = $this->store->addAsset([
			'kind' => $kind,
			'name' => $safe,
			'filename' => $stored,
			'mime' => $mime,
			'size' => filesize($dest),
			'sha256' => $sha,
			'model' => $meta['model'] ?? '',
			'version' => $version,
		]);
		return $this->store->asset($id);
	}

	public function delete(int $id): void
	{
		$a = $this->store->asset($id);
		if (!$a) {
			return;
		}
		@unlink($this->path($a));
		foreach (glob($this->dir . '/cache/' . $id . '-*') ?: [] as $f) {
			@unlink($f);
		}
		$this->store->deleteAsset($id);
	}

	public function path(array $asset): string
	{
		return $this->dir . '/files/' . basename($asset['filename']);
	}

	/**
	 * Path of a wallpaper fitted to the model's screen (cover-crop, JPEG).
	 * Returns the original when the model has no screen size or GD is missing.
	 */
	public function wallpaperFor(array $asset, ?array $screen): string
	{
		$src = $this->path($asset);
		if (!$screen || !function_exists('imagecreatetruecolor')) {
			return $src;
		}
		$w = (int) $screen['width'];
		$h = (int) $screen['height'];
		$cache = $this->dir . "/cache/{$asset['id']}-{$w}x{$h}.jpg";
		if (is_file($cache) && filemtime($cache) >= filemtime($src)) {
			return $cache;
		}
		$data = @file_get_contents($src);
		$img = $data !== false ? @imagecreatefromstring($data) : false;
		if (!$img) {
			return $src;
		}
		$sw = imagesx($img);
		$sh = imagesy($img);
		$scale = max($w / $sw, $h / $sh);
		$cw = (int) round($w / $scale);
		$ch = (int) round($h / $scale);
		$sx = (int) floor(($sw - $cw) / 2);
		$sy = (int) floor(($sh - $ch) / 2);
		$out = imagecreatetruecolor($w, $h);
		imagecopyresampled($out, $img, 0, 0, $sx, $sy, $w, $h, $cw, $ch);
		$this->ensureDir();
		imagejpeg($out, $cache, 90);
		self::own($cache);
		return is_file($cache) ? $cache : $src;
	}

	/** Name the phone stores the wallpaper under (must not contain spaces). */
	public static function phoneFileName(array $asset, string $suffix = ''): string
	{
		$base = pathinfo($asset['name'], PATHINFO_FILENAME);
		$ext = $suffix !== '' ? $suffix : strtolower(pathinfo($asset['name'], PATHINFO_EXTENSION));
		return 'op' . $asset['id'] . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $base) . ($ext !== '' ? '.' . $ext : '');
	}
}
