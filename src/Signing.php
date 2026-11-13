<?php
namespace FreePBX\modules\Openprov;

/**
 * Trust (or stop trusting) the release key this module is signed with, so
 * FreePBX shows it as signed instead of "unsigned" / "tampered".
 *
 * FreePBX verifies module.sig with gpg as the web user (asterisk), in that
 * user's keyring. A signature from a key that isn't in the keyring, or is
 * in it but not trusted, fails ("Signed by unknown, untrusted key"). The
 * key does not need Sangoma's signature: verifyModule() only asks gpg
 * whether the signer is trusted. So trusting is: import the public key
 * shipped in keys/ and mark it ultimately trusted (like FreePBX does for
 * its own key in GPG::trustFreePBX()).
 *
 * The shipped key is accepted only if its fingerprint matches FINGERPRINT,
 * which is also pinned in bin/module-sign. Trusting it applies system-wide:
 * any module signed with this key passes, not only OpenProv.
 *
 * Root only (fwconsole openprov trust-key); gpg runs through FreePBX's GPG
 * class, so the right keyring is used, and ownership is fixed afterwards.
 */
class Signing
{
	public const FINGERPRINT = 'E40FA5E8A108D1B826408E0DB63E9EEEE8CDA76F';

	/** @var callable(string, mixed=): array{stdout:string, status:array, exitcode:int} */
	private $gpg;

	public function __construct(?callable $gpg = null, private ?string $keyFile = null)
	{
		$this->gpg = $gpg ?? fn(string $params, $stdin = null) => \FreePBX::GPG()->runGPG($params, $stdin);
		$this->keyFile ??= dirname(__DIR__) . '/keys/release-signing-key.asc';
	}

	/**
	 * The primary key(s) in gpg --with-colons output: [[fingerprint, uid], ...].
	 */
	public static function primaryKeys(string $colons): array
	{
		$keys = [];
		$expect = null; // the next fpr line belongs to a pub line
		foreach (explode("\n", $colons) as $line) {
			$f = explode(':', $line);
			if ($f[0] === 'pub') {
				$keys[] = ['fingerprint' => '', 'uid' => ''];
				$expect = 'pub';
			} elseif ($f[0] === 'sub') {
				$expect = 'sub';
			} elseif ($f[0] === 'fpr' && $expect === 'pub') {
				$keys[count($keys) - 1]['fingerprint'] = strtoupper($f[9] ?? '');
				$expect = null;
			} elseif ($f[0] === 'uid' && $keys && $keys[count($keys) - 1]['uid'] === '') {
				$keys[count($keys) - 1]['uid'] = $f[9] ?? '';
			}
		}
		return $keys;
	}

	/** The shipped public key, checked against FINGERPRINT. */
	public function shippedKey(): array
	{
		if (!is_file($this->keyFile)) {
			throw new \RuntimeException("The release key is missing ({$this->keyFile})");
		}
		$r = ($this->gpg)('--with-colons --import-options show-only --import ' . escapeshellarg($this->keyFile));
		$keys = self::primaryKeys($r['stdout']);
		if (count($keys) !== 1 || $keys[0]['fingerprint'] !== self::FINGERPRINT) {
			throw new \RuntimeException('The shipped release key does not match the pinned fingerprint ' . self::FINGERPRINT . '; not trusting it');
		}
		return $keys[0];
	}

	/** Is the key in the web user's keyring, and trusted? */
	public function status(): array
	{
		$imported = ($this->gpg)('--with-colons --list-keys ' . self::FINGERPRINT)['exitcode'] === 0;
		$trust = ($this->gpg)('--export-ownertrust')['stdout'];
		return [
			'fingerprint' => self::FINGERPRINT,
			'imported' => $imported,
			'trusted' => $imported && (bool) preg_match('/^' . self::FINGERPRINT . ':6:/m', $trust),
		];
	}

	public function trust(): array
	{
		$key = $this->shippedKey();
		$r = ($this->gpg)('--batch --import ' . escapeshellarg($this->keyFile));
		if ($r['exitcode'] !== 0) {
			throw new \RuntimeException('gpg could not import the release key: ' . trim($r['stderr'] ?? ''));
		}
		$fd = tmpfile(); // a real file: proc_open can't hand php://temp to gpg
		fwrite($fd, self::FINGERPRINT . ":6:\n");
		rewind($fd);
		$r = ($this->gpg)('--import-ownertrust', $fd);
		fclose($fd);
		if ($r['exitcode'] !== 0) {
			throw new \RuntimeException('gpg could not mark the release key trusted: ' . trim($r['stderr'] ?? ''));
		}
		return ['key' => $key['uid']] + $this->status();
	}

	/** Stop trusting the key (removes it from the web user's keyring). */
	public function untrust(): array
	{
		($this->gpg)('--batch --yes --delete-keys ' . self::FINGERPRINT);
		return $this->status();
	}

	/**
	 * After gpg ran as root: give the keyring back to the web user (FreePBX
	 * does the same in GPG::checkPermissions), then re-check every module's
	 * signature so the "unsigned modules" notice updates now.
	 */
	public static function afterChange(): array
	{
		$dir = \FreePBX::GPG()->getGpgLocation();
		$pw = posix_getpwnam((string) \FreePBX::Config()->get('AMPASTERISKWEBUSER'));
		if ($pw && posix_geteuid() === 0) {
			$paths = [$dir];
			$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
			foreach ($it as $f) {
				$paths[] = $f->getPathname();
			}
			foreach ($paths as $p) {
				@lchown($p, $pw['uid']);
				@lchgrp($p, $pw['gid']);
			}
		}
		$sigs = \module_functions::create()->getAllSignatures(false);
		$out = [];
		foreach (($sigs['modules'] ?? []) as $name => $m) {
			if (is_file(\FreePBX::Config()->get('AMPWEBROOT') . "/admin/modules/$name/keys/release-signing-key.asc")) {
				$s = (int) ($m['signature']['status'] ?? 0);
				$out[$name] = ($s & \FreePBX\GPG::STATE_GOOD) && ($s & \FreePBX\GPG::STATE_TRUSTED) ? 'signed' : 'not verified (' . implode('; ', $m['signature']['details'] ?? []) . ')';
			}
		}
		return $out;
	}
}
