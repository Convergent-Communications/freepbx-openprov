<?php
namespace FreePBX\modules\Openprov;

/**
 * Named actions with JSON arguments, shared by the GUI (AJAX) and fwconsole.
 * docs/API.md lists every action with examples.
 */
class Api
{
	public function __construct(private Service $svc)
	{
	}

	public static function actions(): array
	{
		return [
			'info' => 'Module version, web server status and counts',
			'settings.get' => '{reveal?: bool}',
			'settings.set' => '{<setting>: value, ...}',
			'models' => '{brand?}',
			'schema' => '{brand?}',
			'keytypes' => '{brand?}',
			'targets' => 'Catalog of extensions and feature codes keys can point at',
			'extensions' => 'PBX extensions with display names',
			'vpn.clients' => 'System Admin OpenVPN clients',
			'templates.list' => '',
			'template.get' => '{template: id|name}',
			'template.save' => '{id?, name, parent?, description?, settings?, raw?, keys?}',
			'template.copy' => '{template, name}',
			'template.delete' => '{template}',
			'devices.list' => '',
			'device.get' => '{device: id|mac, reveal?}',
			'device.save' => '{id?|mac, model, template?, name?, enabled?, settings?, raw?, lines?, keys?}',
			'device.delete' => '{device}',
			'device.rearm' => '{device, rotate?: bool}',
			'ringtones.builtin' => ' — cached built-in ringtones per model: {model: [names]}',
			'ringtones.fetch' => '{device} — fetch that phone\'s built-in ringtones into the cache',
			'ringtone.audio' => '{ref: asset:<id> | Resource:<name>.wav, model?} — {mime, data (base64)} for listening',
			'device.screenshot' => '{device} — the phone\'s current screen as {mime, data (base64), host}',
			'keys.set' => '{scope: template|device, ref, keys: [...], bank?, replace?: bool}',
			'keys.fill' => '{scope, ref, extensions: [..]|"all", start?, bank?, type?}',
			'render' => '{device, reveal?: bool}',
			'resync' => '{devices?: [..], extension?, all?: bool, reboot?: bool}',
			'sync.changed' => '{dryRun?: bool}',
			'devices.showing' => '{extension}',
			'devices.forExtension' => '{extension} — phones with this extension on a line (a user\'s own phones)',
			'assets.list' => '{kind?}',
			'asset.add' => '{kind, name, path | base64}',
			'asset.delete' => '{id}',
			'events' => '{limit?, mac?}',
			'discovered' => '{days?}',
			'adopt' => '{mac, model, extension?, template?}',
			'export.csv' => '',
			'import.csv' => '{csv}',
			'import.epm' => '{dryRun?: bool (default true), template?, modelMap?: {"SIP-T57W": "T87W"}}',
			'web.status' => '',
			'web.config' => '',
		];
	}

	public function call(string $action, array $a, array $files = [])
	{
		$s = $this->svc;
		$need = function (string $k) use ($a) {
			if (!isset($a[$k]) || $a[$k] === '') {
				throw new \InvalidArgumentException("Missing argument '$k'");
			}
			return $a[$k];
		};
		$bool = fn(string $k, bool $d = false) => isset($a[$k]) ? filter_var($a[$k], FILTER_VALIDATE_BOOLEAN) : $d;

		switch ($action) {
			case 'info': return $s->info();
			case 'settings.get': return $s->getSettings($bool('reveal'));
			case 'settings.set': return $s->setSettings($a);
			case 'models': return $s->models($a['brand'] ?? null);
			case 'schema': return $s->schema($a['brand'] ?? 'yealink');
			case 'keytypes': return $s->keyTypes($a['brand'] ?? 'yealink');
			case 'targets': return $s->keyTargets();
			case 'extensions': return $s->extensions();
			case 'vpn.clients': return $s->vpnClients();
			case 'templates.list': return $s->listTemplates();
			case 'template.get': return $s->getTemplate($need('template'));
			case 'template.save': return $s->saveTemplate($a);
			case 'template.copy': return $s->copyTemplate($need('template'), (string) $need('name'));
			case 'template.delete': return $s->deleteTemplate($need('template'));
			case 'devices.list': return $s->listDevices();
			case 'device.get': return $s->getDevice($need('device'), $bool('reveal'));
			case 'device.save': return $s->saveDevice($a);
			case 'device.delete': return $s->deleteDevice($need('device'));
			case 'device.rearm': return $s->rearmDevice($need('device'), $bool('rotate', true));
			case 'device.screenshot': return $s->screenshot($need('device'));
			case 'ringtones.builtin': return (object) $s->builtinRingtones();
			case 'ringtones.fetch': return $s->fetchBuiltinRingtones($need('device'));
			case 'ringtone.audio': return $s->ringtoneAudio((string) $need('ref'), isset($a['model']) ? (string) $a['model'] : null);
			case 'keys.set':
				return $s->setKeys((string) $need('scope'), $need('ref'), (array) ($a['keys'] ?? []), $a['bank'] ?? null, $bool('replace', true));
			case 'keys.fill':
				return $s->fillBlf((string) $need('scope'), $need('ref'), $a['extensions'] ?? 'all', (int) ($a['start'] ?? 1), (string) ($a['bank'] ?? 'line'), (string) ($a['type'] ?? 'blf'));
			case 'render': return $s->render($need('device'), !$bool('reveal'));
			case 'resync':
				if ($bool('all')) {
					return $s->resync('all', $bool('reboot'));
				}
				if (!empty($a['extension'])) {
					return $s->resync(['extension' => (string) $a['extension']], $bool('reboot'));
				}
				return $s->resync((array) $need('devices'), $bool('reboot'));
			case 'sync.changed': return $s->syncChanged($bool('dryRun'));
			case 'devices.showing': return array_map(fn($d) => ['id' => $d['id'], 'mac' => $d['mac'], 'name' => $d['name']], $s->devicesShowing((string) $need('extension')));
			case 'devices.forExtension': return $s->devicesForExtension((string) $need('extension'));
			case 'assets.list': return $s->listAssets($a['kind'] ?? null);
			case 'asset.add':
				if (isset($files['file']['tmp_name']) && is_uploaded_file($files['file']['tmp_name'])) {
					return $s->addAsset((string) $need('kind'), (string) ($a['name'] ?? $files['file']['name']), $files['file']['tmp_name'], $a);
				}
				if (isset($a['base64'])) {
					return $s->addAsset((string) $need('kind'), (string) $need('name'), ['base64' => $a['base64']], $a);
				}
				return $s->addAsset((string) $need('kind'), (string) ($a['name'] ?? basename((string) $need('path'))), (string) $need('path'), $a);
			case 'asset.delete': return $s->deleteAsset((int) $need('id'));
			case 'events': return $s->events((int) ($a['limit'] ?? 200), $a['mac'] ?? null);
			case 'discovered': return $s->discovered((int) ($a['days'] ?? 7));
			case 'adopt': return $s->adopt((string) $need('mac'), (string) $need('model'), $a['extension'] ?? null, $a['template'] ?? null);
			case 'export.csv': return $s->exportDevicesCsv();
			case 'import.csv': return $s->importDevicesCsv((string) $need('csv'));
			case 'import.epm': return $s->importEpm($bool('dryRun', true), $a['template'] ?? null, (array) ($a['modelMap'] ?? []));
			case 'web.status': return $s->webStatus();
			case 'web.config': return $s->webConfig();
		}
		throw new \InvalidArgumentException("Unknown action '$action'");
	}
}
