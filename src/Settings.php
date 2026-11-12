<?php
namespace FreePBX\modules\Openprov;

/**
 * Global settings, persisted through FreePBX's kvstore (BMO getConfig/setConfig)
 * or any get/set callables (tests).
 */
class Settings
{
	public const DEFAULTS = [
		'internal_host' => '',          // blank = auto-detect primary IPv4
		'external_host' => '',          // public hostname/IP for remote phones
		'phone_scheme' => 'https',      // scheme written into phone configs
		'https_port' => 8443,
		'http_port' => 0,               // 0 = no plain-HTTP listener
		'tls_cert' => '/etc/asterisk/keys/default.crt',
		'tls_key' => '/etc/asterisk/keys/default.key',
		'tls_chain' => '',
		'bootstrap_user' => '',         // generated on install
		'bootstrap_pass' => '',
		'allowed_networks' => '',       // newline/comma separated CIDRs; blank = any
		'enforce_ua_mac' => true,       // UA MAC must match the requested MAC
		'auto_adopt' => false,          // create unknown phones on first bootstrap contact
		'auto_adopt_template' => 0,
		'admin_password' => '',         // generated on install
		'user_password' => '',
		'auto_resync' => true,          // notify phones whose config changed
		'event_retention_days' => 30,
		'vpn_external_remote' => '',    // "host port" added after the LAN remote in vpn.cnf
		'default_template_id' => 0,
	];

	/** @var callable */
	private $get;
	/** @var callable */
	private $set;

	public function __construct(callable $get, callable $set)
	{
		$this->get = $get;
		$this->set = $set;
	}

	public function get(string $key)
	{
		if (!array_key_exists($key, self::DEFAULTS)) {
			throw new \InvalidArgumentException("Unknown setting '$key'");
		}
		$v = ($this->get)($key);
		if ($v === null || $v === false) {
			return self::DEFAULTS[$key];
		}
		return match (gettype(self::DEFAULTS[$key])) {
			'integer' => (int) $v,
			'boolean' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
			default => (string) $v,
		};
	}

	public function all(): array
	{
		$out = [];
		foreach (array_keys(self::DEFAULTS) as $k) {
			$out[$k] = $this->get($k);
		}
		return $out;
	}

	public function set(string $key, $value): void
	{
		if (!array_key_exists($key, self::DEFAULTS)) {
			throw new \InvalidArgumentException("Unknown setting '$key'");
		}
		$type = gettype(self::DEFAULTS[$key]);
		if ($type === 'integer') {
			if (!is_numeric($value)) {
				throw new \InvalidArgumentException("Setting '$key' must be a number");
			}
			$value = (int) $value;
		} elseif ($type === 'boolean') {
			$value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
		} else {
			$value = trim((string) $value);
		}
		if ($key === 'phone_scheme' && !in_array($value, ['http', 'https'], true)) {
			throw new \InvalidArgumentException('phone_scheme must be http or https');
		}
		if (in_array($key, ['admin_password', 'user_password'], true) && strtolower($value) === 'admin') {
			throw new \InvalidArgumentException("Do not use 'admin': recent Yealink firmware forces a change-password screen");
		}
		($this->set)($key, $value);
	}

	/** Fill generated secrets that are still empty. */
	public function ensureSecrets(): void
	{
		if ($this->get('bootstrap_user') === '') {
			$this->set('bootstrap_user', 'prov' . Util::digits(4));
		}
		if ($this->get('bootstrap_pass') === '') {
			$this->set('bootstrap_pass', Util::secret(18));
		}
		if ($this->get('admin_password') === '') {
			$this->set('admin_password', Util::digits(10));
		}
		if ($this->get('user_password') === '') {
			$this->set('user_password', Util::digits(6));
		}
	}

	public function allowedNetworks(): array
	{
		return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->get('allowed_networks')))));
	}

	public function internalHost(): string
	{
		$h = (string) $this->get('internal_host');
		return $h !== '' ? $h : self::detectIp();
	}

	public static function detectIp(): string
	{
		$out = @shell_exec("ip -4 route get 1.1.1.1 2>/dev/null");
		if ($out && preg_match('/\bsrc\s+(\d+\.\d+\.\d+\.\d+)/', $out, $m)) {
			return $m[1];
		}
		return gethostbyname(gethostname()) ?: '127.0.0.1';
	}
}
