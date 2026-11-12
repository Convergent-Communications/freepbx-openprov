<?php

namespace FreePBX\modules\Openprov\Api\Gql;

use FreePBX\modules\Api\Gql\Base;
use FreePBX\modules\Openprov\Api;
use GraphQL\Type\Definition\Type;
use GraphQLRelay\Relay;

/**
 * GraphQL for OpenProv (FreePBX API module).
 *
 * Typed reads for the common objects, plus two generic entry points that
 * accept any fwconsole/AJAX action with JSON arguments, so every operation in
 * docs/API.md is reachable from GraphQL without duplicating the schema:
 *
 *   query { openprovDevices { mac model name inSync lines { account extension } } }
 *   query { openprovQuery(action: "targets") }                     # JSON string
 *   mutation { openprovAction(input: { action: "keys.fill",
 *              args: "{\"scope\":\"device\",\"ref\":\"c4fc221dd637\",\"extensions\":[\"201\"]}" })
 *              { status message result } }
 */
class Openprov extends Base
{
	protected $module = 'openprov';

	/** Actions that only read (allowed with read scope). */
	private const READ_ACTIONS = [
		'info', 'settings.get', 'models', 'schema', 'keytypes', 'targets', 'extensions', 'vpn.clients',
		'templates.list', 'template.get', 'devices.list', 'device.get', 'render', 'devices.showing',
		'assets.list', 'events', 'discovered', 'export.csv', 'web.status', 'web.config',
	];

	private function svc()
	{
		return $this->freepbx->Openprov->service();
	}

	public function queryCallback()
	{
		if (!$this->checkAllReadScope()) {
			return null;
		}
		return fn() => [
			'openprovDevices' => [
				'type' => Type::listOf($this->typeContainer->get('openprovdevice')->getObject()),
				'description' => 'Provisioned phones',
				'resolve' => fn() => $this->svc()->listDevices(),
			],
			'openprovDevice' => [
				'type' => $this->typeContainer->get('openprovdevice')->getObject(),
				'description' => 'One phone by id or MAC (any format)',
				'args' => ['ref' => ['type' => Type::nonNull(Type::string())]],
				'resolve' => fn($root, $args) => $this->svc()->getDevice($args['ref']),
			],
			'openprovTemplates' => [
				'type' => Type::listOf($this->typeContainer->get('openprovtemplate')->getObject()),
				'resolve' => fn() => $this->svc()->listTemplates(),
			],
			'openprovKeyTargets' => [
				'type' => Type::listOf($this->typeContainer->get('openprovkeytarget')->getObject()),
				'description' => 'Extensions and feature codes a key can point at (for pickers and permission gating)',
				'resolve' => fn() => $this->svc()->keyTargets(),
			],
			'openprovQuery' => [
				'type' => Type::string(),
				'description' => 'Run a read-only OpenProv action; returns JSON',
				'args' => [
					'action' => ['type' => Type::nonNull(Type::string())],
					'args' => ['type' => Type::string(), 'description' => 'JSON object'],
				],
				'resolve' => function ($root, $args) {
					if (!in_array($args['action'], self::READ_ACTIONS, true)) {
						throw new \Exception('Not a read-only action; use the openprovAction mutation');
					}
					$a = isset($args['args']) ? json_decode($args['args'], true) : [];
					$svc = $this->svc(); // loads the module (and its autoloader) before Api is referenced
					return json_encode((new Api($svc))->call($args['action'], is_array($a) ? $a : []), JSON_UNESCAPED_SLASHES);
				},
			],
		];
	}

	public function mutationCallback()
	{
		if (!$this->checkAllWriteScope()) {
			return null;
		}
		return fn() => [
			'openprovAction' => Relay::mutationWithClientMutationId([
				'name' => 'openprovAction',
				'description' => 'Run any OpenProv action (see fwconsole openprov actions / docs/API.md)',
				'inputFields' => [
					'action' => ['type' => Type::nonNull(Type::string())],
					'args' => ['type' => Type::string(), 'description' => 'JSON object of arguments'],
				],
				'outputFields' => [
					'status' => ['type' => Type::boolean(), 'resolve' => fn($p) => $p['status']],
					'message' => ['type' => Type::string(), 'resolve' => fn($p) => $p['message']],
					'result' => ['type' => Type::string(), 'description' => 'JSON', 'resolve' => fn($p) => $p['result']],
				],
				'mutateAndGetPayload' => function ($input) {
					$a = isset($input['args']) && $input['args'] !== '' ? json_decode($input['args'], true) : [];
					if (!is_array($a)) {
						return ['status' => false, 'message' => 'args must be a JSON object', 'result' => null];
					}
					try {
						$svc = $this->svc();
						$res = (new Api($svc))->call($input['action'], $a);
						return ['status' => true, 'message' => 'ok', 'result' => json_encode($res, JSON_UNESCAPED_SLASHES)];
					} catch (\Throwable $e) {
						return ['status' => false, 'message' => $e->getMessage(), 'result' => null];
					}
				},
			]),
		];
	}

	public function initializeTypes()
	{
		$line = $this->typeContainer->create('openprovline', 'object');
		$line->addFieldCallback(fn() => [
			'account' => ['type' => Type::int()],
			'extension' => ['type' => Type::string()],
			'label' => ['type' => Type::string()],
		]);

		$key = $this->typeContainer->create('openprovkey', 'object');
		$key->addFieldCallback(fn() => [
			'bank' => ['type' => Type::string()],
			'position' => ['type' => Type::int()],
			'type' => ['type' => Type::string()],
			'value' => ['type' => Type::string()],
			'label' => ['type' => Type::string()],
			'account' => ['type' => Type::int()],
		]);

		$dev = $this->typeContainer->create('openprovdevice', 'object');
		$dev->setDescription('A provisioned phone');
		$dev->addFieldCallback(fn() => [
			'id' => ['type' => Type::int()],
			'mac' => ['type' => Type::string()],
			'brand' => ['type' => Type::string()],
			'model' => ['type' => Type::string()],
			'name' => ['type' => Type::string()],
			'enabled' => ['type' => Type::boolean()],
			'templateId' => ['type' => Type::int(), 'resolve' => fn($d) => $d['template_id']],
			'template' => ['type' => Type::string(), 'resolve' => fn($d) => $d['template'] ?? null],
			'lastSeen' => ['type' => Type::int(), 'resolve' => fn($d) => $d['last_seen']],
			'lastIp' => ['type' => Type::string(), 'resolve' => fn($d) => $d['last_ip']],
			'firmware' => ['type' => Type::string(), 'resolve' => fn($d) => $d['last_fw']],
			'inSync' => ['type' => Type::boolean(), 'resolve' => fn($d) => $d['in_sync'] ?? null],
			'lineKeys' => ['type' => Type::int(), 'resolve' => fn($d) => $d['line_keys'] ?? null],
			'settings' => ['type' => Type::string(), 'description' => 'JSON', 'resolve' => fn($d) => json_encode((object) $d['settings'])],
			'lines' => ['type' => Type::listOf($this->typeContainer->get('openprovline')->getObject()), 'resolve' => fn($d) => $d['lines'] ?? []],
			'keys' => ['type' => Type::listOf($this->typeContainer->get('openprovkey')->getObject()), 'resolve' => fn($d) => $d['keys'] ?? $this->svc()->getDevice($d['id'])['keys']],
		]);

		$tpl = $this->typeContainer->create('openprovtemplate', 'object');
		$tpl->addFieldCallback(fn() => [
			'id' => ['type' => Type::int()],
			'name' => ['type' => Type::string()],
			'parentId' => ['type' => Type::int(), 'resolve' => fn($t) => $t['parent_id']],
			'description' => ['type' => Type::string()],
			'devices' => ['type' => Type::int()],
			'settings' => ['type' => Type::string(), 'description' => 'JSON', 'resolve' => fn($t) => json_encode((object) $t['settings'])],
		]);

		$target = $this->typeContainer->create('openprovkeytarget', 'object');
		$target->addFieldCallback(fn() => [
			'id' => ['type' => Type::string()],
			'category' => ['type' => Type::string(), 'description' => 'extension, feature, time_condition, call_flow, blocklist, parking, queue'],
			'label' => ['type' => Type::string()],
			'type' => ['type' => Type::string(), 'description' => 'Key type to use (blf, feature, feature_dial, park_slot)'],
			'value' => ['type' => Type::string(), 'description' => 'Key value; "{ext}" is replaced by the phone\'s own extension'],
			'perExtension' => ['type' => Type::boolean()],
			'hint' => ['type' => Type::boolean(), 'description' => 'True when the PBX publishes state (BLF light works)'],
			'module' => ['type' => Type::string()],
		]);
	}
}
