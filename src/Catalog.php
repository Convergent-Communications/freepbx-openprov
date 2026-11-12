<?php
namespace FreePBX\modules\Openprov;

/**
 * Phone models and brand settings schemas, loaded from models/<brand>/*.json.
 * Adding a phone model = adding a JSON file (see docs/ADDING-MODELS.md).
 */
class Catalog
{
	private array $models = [];
	private array $schemas = [];

	public function __construct(private string $dir)
	{
		foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $brandDir) {
			$brand = basename($brandDir);
			foreach (glob($brandDir . '/*.json') ?: [] as $file) {
				$data = json_decode((string) file_get_contents($file), true);
				if (!is_array($data)) {
					continue;
				}
				if (basename($file) === 'schema.json') {
					$this->schemas[$brand] = $data['settings'] ?? [];
					continue;
				}
				$data['brand'] = $data['brand'] ?? $brand;
				$this->models[$brand . '/' . $data['id']] = $data + [
					'accounts' => 1, 'lineKeys' => 0, 'expansion' => null, 'wallpaper' => null,
					'commonFile' => null, 'ua' => [], 'defaults' => [], 'verified' => false,
				];
			}
		}
		ksort($this->models, SORT_NATURAL);
	}

	public function models(?string $brand = null): array
	{
		return array_values(array_filter($this->models, fn($m) => $brand === null || $m['brand'] === $brand));
	}

	public function model(string $brand, string $id): ?array
	{
		return $this->models[$brand . '/' . $id] ?? null;
	}

	/** Identify a model from an HTTP User-Agent ("Yealink SIP-T87W 185.87.0.42 c4:fc:..."). */
	public function modelFromUserAgent(string $ua): ?array
	{
		$best = null;
		foreach ($this->models as $m) {
			foreach ($m['ua'] as $token) {
				if (preg_match('/(^|\s)' . preg_quote($token, '/') . '(\s|$)/i', $ua)) {
					// Prefer the longest matching token (T21E2 over T21).
					if ($best === null || strlen($token) > $best[1]) {
						$best = [$m, strlen($token)];
					}
				}
			}
		}
		return $best[0] ?? null;
	}

	public function schema(string $brand): array
	{
		return $this->schemas[$brand] ?? [];
	}

	/** Schema defaults as id => value. */
	public function schemaDefaults(string $brand): array
	{
		$out = [];
		foreach ($this->schema($brand) as $s) {
			$out[$s['id']] = (string) ($s['default'] ?? '');
		}
		return $out;
	}
}
