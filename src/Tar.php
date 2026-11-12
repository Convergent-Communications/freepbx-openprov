<?php
namespace FreePBX\modules\Openprov;

/** Minimal in-memory ustar writer (regular files only), deterministic output. */
final class Tar
{
	/** @param array<string,string> $files path => contents */
	public static function build(array $files, int $mtime = 0): string
	{
		$out = '';
		foreach ($files as $name => $data) {
			if (strlen($name) > 99) {
				throw new \InvalidArgumentException("tar path too long: $name");
			}
			$h = str_pad($name, 100, "\0");
			$h .= sprintf('%07o', 0644) . "\0";
			$h .= sprintf('%07o', 0) . "\0";
			$h .= sprintf('%07o', 0) . "\0";
			$h .= sprintf('%011o', strlen($data)) . "\0";
			$h .= sprintf('%011o', $mtime) . "\0";
			$h .= '        '; // checksum placeholder
			$h .= '0';        // regular file
			$h .= str_repeat("\0", 100); // link name
			$h .= "ustar\0" . '00';
			$h .= str_pad('root', 32, "\0") . str_pad('root', 32, "\0");
			$h .= str_repeat("\0", 8) . str_repeat("\0", 8); // dev major/minor
			$h .= str_repeat("\0", 155); // prefix
			$h = str_pad($h, 512, "\0");
			$sum = 0;
			for ($i = 0; $i < 512; $i++) {
				$sum += ord($h[$i]);
			}
			$h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
			$out .= $h . $data;
			$pad = (512 - strlen($data) % 512) % 512;
			$out .= str_repeat("\0", $pad);
		}
		return $out . str_repeat("\0", 1024);
	}
}
