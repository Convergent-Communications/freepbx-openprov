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
	private array $features = [];
	private array $customFeatures = [];
	private array $featureSlots = [];

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
				$data['capabilities'] = $data['capabilities'] ?? [];
				$this->models[$brand . '/' . $data['id']] = $data + [
					'accounts' => 1, 'lineKeys' => 0, 'expansion' => null, 'wallpaper' => null,
					'commonFile' => null, 'ua' => [], 'defaults' => [], 'verified' => false, 'quirks' => [],
				];
			}
		}
		ksort($this->models, SORT_NATURAL);
		// Add-on features: models/<brand>/features/*.json (docs/FEATURES.md)
		foreach (glob($dir . '/*/features/*.json') ?: [] as $file) {
			$f = json_decode((string) file_get_contents($file), true);
			if (is_array($f) && isset($f['id']) && preg_match('/^[a-z0-9_]+$/', $f['id'])) {
				$this->features[basename(dirname($file, 2))][$f['id']] = $f + ['requires' => [], 'claims' => [], 'variables' => [], 'globals' => [], 'lines' => [], 'remove' => []];
			}
		}
		foreach ($this->features as &$list) {
			ksort($list); // fixed order = stable slot numbers
		}
	}

	/** Add-on features for a brand (built-in + custom), id => definition with 'slot', in slot order. */
	public function features(string $brand): array
	{
		$all = ($this->features[$brand] ?? []) + ($this->customFeatures[$brand] ?? []);
		foreach ($all as $id => &$f) {
			$f += ['requires' => [], 'claims' => [], 'variables' => [], 'globals' => [], 'lines' => [], 'remove' => [], 'custom' => false];
			$f['slot'] = $this->featureSlots[$id] ?? 0;
		}
		unset($f);
		uasort($all, fn($a, $b) => [$a['slot'] ?: 99, $a['id'] ?? ''] <=> [$b['slot'] ?: 99, $b['id'] ?? '']);
		return $all;
	}

	public function isBuiltinFeature(string $brand, string $id): bool
	{
		return isset($this->features[$brand][$id]);
	}

	/** Custom features (from the GUI); built-in ids win. */
	public function setCustomFeatures(string $brand, array $defs): void
	{
		$this->customFeatures[$brand] = array_diff_key($defs, $this->features[$brand] ?? []);
	}

	public function setFeatureSlots(array $slots): void
	{
		$this->featureSlots = $slots;
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
		$out = $this->schemas[$brand] ?? [];
		// Each feature becomes ordinary settings, so templates, phone
		// overrides and inheritance work as for everything else.
		foreach ($this->features($brand) as $id => $f) {
			$out[] = [
				'id' => "feature.$id", 'label' => $f['label'] ?? $id, 'group' => 'Add-on features', 'type' => 'select',
				'options' => [['value' => '1', 'label' => 'On'], ['value' => '0', 'label' => 'Off (removes it from phones)']],
				'default' => '', 'computed' => true, 'feature' => $id,
				'help' => trim(($f['description'] ?? '') . ' Needs: ' . implode(', ', $f['requires']) . '. Blank = not managed; Off writes the removal values.'),
			];
			foreach ($f['variables'] as $v) {
				$out[] = [
					'id' => "feature.$id.{$v['id']}", 'label' => $v['label'] ?? $v['id'], 'group' => 'Add-on features', 'type' => 'text',
					'default' => (string) ($v['default'] ?? ''), 'computed' => true, 'feature' => $id, 'help' => $v['help'] ?? '',
				];
			}
		}
		return $out;
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
