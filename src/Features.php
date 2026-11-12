<?php
namespace FreePBX\modules\Openprov;

/**
 * Add-on feature registry: built-in features (models/<brand>/features/*.json)
 * plus custom ones defined in the GUI (openprov_features.def), each with a
 * permanent slot.
 *
 * Slots: a feature keeps its slot for as long as it exists, and its softkey
 * number and hidden line key come from that slot alone (softkey = slot,
 * hidden key = the slot-th free key from the top). Adding or deleting other
 * features therefore never moves it or makes two features collide.
 */
class Features
{
	public const MAX_SLOTS = 10;
	public const CAPABILITIES = ['edk' => 'Enhanced DSS keys (custom softkeys)', 'xml_browser' => 'XML browser'];
	/** Yealink EDK softkey states (softkey.N.use.<state>). on_talk and idle verified on the T87W. */
	public const STATES = [
		'on_talk' => 'During a call', 'idle' => 'Idle screen', 'ringing' => 'Ringing (incoming call)', 'calling' => 'Calling (outgoing)',
		'dialing' => 'Dialing', 'held' => 'Call on hold', 'conferenced' => 'In a conference',
	];

	/** Load custom features into the catalog and make sure every feature has a slot. */
	public static function load(Store $store, Catalog $catalog, string $brand = 'yealink'): void
	{
		try {
			$rows = $store->featureRows();
		} catch (\Throwable $e) {
			return; // table not created yet (before the module upgrade)
		}
		$custom = [];
		foreach ($rows as $id => $r) {
			if ($r['def'] !== null && is_array($d = json_decode($r['def'], true))) {
				$custom[$id] = self::compile($id, $d);
			}
		}
		$catalog->setCustomFeatures($brand, $custom);
		$slots = array_map(fn($r) => (int) $r['slot'], $rows);
		foreach (array_keys($catalog->features($brand)) as $id) {
			if (!isset($slots[$id])) {
				$slot = self::freeSlot($slots);
				if ($slot === null) {
					continue;
				}
				$store->saveFeatureRow($id, $slot, null);
				$slots[$id] = $slot;
			}
		}
		$catalog->setFeatureSlots($slots);
	}

	public static function freeSlot(array $slots): ?int
	{
		for ($n = 1; $n <= self::MAX_SLOTS; $n++) {
			if (!in_array($n, $slots, true)) {
				return $n;
			}
		}
		return null;
	}

	/**
	 * Turn what the GUI stores into an engine definition.
	 * kind "softkey_url": a softkey that opens a URL in the XML browser
	 * (through a hidden XML-browser line key, like captions).
	 * kind "advanced": the definition as written (lines/remove/globals…).
	 */
	public static function compile(string $id, array $d): array
	{
		$base = ['id' => $id, 'label' => (string) ($d['name'] ?? $id), 'description' => (string) ($d['description'] ?? ''), 'custom' => true, 'source' => $d];
		if (($d['kind'] ?? '') === 'softkey_url') {
			$states = array_values(array_intersect(array_keys(self::STATES), (array) ($d['states'] ?? ['on_talk'])));
			$lines = [
				'linekey.{lineKey}.type' => '27',
				'linekey.{lineKey}.label' => '{label}',
				'linekey.{lineKey}.value' => '{url}',
				'softkey.{softkey}.enable' => '1',
				'softkey.{softkey}.label' => '{label}',
				'softkey.{softkey}.position' => '0',
				'softkey.{softkey}.action' => '$KLineKey{lineKey}$',
			];
			$remove = [
				'linekey.{lineKey}.type' => '0', 'linekey.{lineKey}.label' => '%NULL%', 'linekey.{lineKey}.value' => '%NULL%',
				'softkey.{softkey}.enable' => '0', 'softkey.{softkey}.label' => '%NULL%', 'softkey.{softkey}.action' => '%NULL%',
			];
			foreach (array_keys(self::STATES) as $st) {
				$lines["softkey.{softkey}.use.$st"] = in_array($st, $states, true) ? '1' : '0';
				$remove["softkey.{softkey}.use.$st"] = '0';
			}
			return $base + [
				'description' => $base['description'] !== '' ? $base['description'] : 'Softkey "' . ($d['button_label'] ?? '') . '" that opens ' . ($d['url'] ?? '') . ' (' . implode(', ', array_map(fn($s) => self::STATES[$s], $states)) . ').',
				'requires' => ['edk', 'xml_browser'],
				'claims' => ['lineKey' => 1, 'softkey' => 1],
				'variables' => [
					['id' => 'url', 'label' => ($d['name'] ?? $id) . ': URL', 'default' => (string) ($d['url'] ?? ''), 'help' => '{ext} = the phone\'s extension, {pbx} = the PBX address it provisions from, {mac} = its MAC.'],
					['id' => 'label', 'label' => ($d['name'] ?? $id) . ': softkey label', 'default' => (string) ($d['button_label'] ?? '')],
				],
				'globals' => ['features.enhanced_dss_keys.enable' => '1'],
				'lines' => $lines,
				'remove' => $remove,
			];
		}
		return $base + [
			'requires' => array_values(array_intersect(array_keys(self::CAPABILITIES), (array) ($d['requires'] ?? []))),
			'claims' => ['lineKey' => min(1, (int) ($d['claims']['lineKey'] ?? 0)), 'softkey' => min(1, (int) ($d['claims']['softkey'] ?? 0))],
			'variables' => array_values(array_filter((array) ($d['variables'] ?? []), fn($v) => isset($v['id']))),
			'globals' => self::parseLines((string) ($d['globals'] ?? '')),
			'lines' => self::parseLines((string) ($d['lines'] ?? '')),
			'remove' => self::parseLines((string) ($d['remove'] ?? '')),
		];
	}

	/** "key = value" lines; keys may contain {placeholders}. */
	public static function parseLines(string $text): array
	{
		$out = [];
		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
				continue;
			}
			[$k, $v] = array_map('trim', explode('=', $line, 2));
			if (!preg_match('/^[A-Za-z0-9_.\-\[\]{}]+$/', $k)) {
				throw new \InvalidArgumentException("Invalid config key '$k'");
			}
			$out[$k] = $v;
		}
		return $out;
	}
}
