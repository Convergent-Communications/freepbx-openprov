<?php
namespace FreePBX\modules\Openprov\Brand;

/**
 * A phone vendor. Brands turn a brand-neutral device context (built by
 * ConfigBuilder) into the vendor's config format, and know the vendor's
 * request paths and key-type codes.
 */
interface Brand
{
	public function id(): string;

	/**
	 * Semantic key types this brand supports:
	 * [type => ['label' => ..., 'value' => hint for the value field, 'code' => vendor code]]
	 */
	public function keyTypes(): array;

	/**
	 * Classify a request path. Returns null when the path is not ours, else
	 * ['kind' => 'config'|'common'|'ignore', 'mac' => ?string].
	 */
	public function parsePath(string $path): ?array;

	/** Firmware version from the HTTP User-Agent, if present. */
	public function firmwareFromUserAgent(string $ua): string;

	/** MAC from the HTTP User-Agent, if present. */
	public function macFromUserAgent(string $ua): ?string;

	/**
	 * Vendor keys computed from the device context (accounts, keys, URLs, ...).
	 * Returns ['base' => map, 'protected' => map]; raw keys are layered between
	 * them, so 'protected' keys (secrets, provisioning credentials) always win.
	 */
	public function compile(array $ctx): array;

	/**
	 * The minimal answer to a bootstrap-authenticated request: only the
	 * phone's own provisioning credential and URL (no SIP, VPN or admin
	 * secrets), and an early re-check so it fetches the full config with it.
	 */
	public function bootstrap(array $ctx): array;

	/** Serialise a key/value map into the vendor's file format. */
	public function format(array $kv, array $ctx): string;

	/** Content of the model's common file (shared by all phones of a model). */
	public function common(array $ctx): string;
}
