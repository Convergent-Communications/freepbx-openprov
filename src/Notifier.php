<?php
namespace FreePBX\modules\Openprov;

/**
 * Sends SIP NOTIFYs through AMI PJSIPNotify with the Event header given
 * inline, so no sip_notify*.conf section (and none of its escaping or
 * duplicate-section pitfalls) is involved. See docs/DESIGN.md D8.
 */
class Notifier
{
	/** @var callable|null fn(string $endpoint, string $event): array{ok:bool,message:string} */
	private $sender;

	public function __construct(?callable $sender = null)
	{
		$this->sender = $sender;
	}

	public static function eventFor(string $brand, string $action): string
	{
		return match ($action) {
			'reboot' => 'check-sync;reboot=true',
			default => 'check-sync;reboot=false',
		};
	}

	/** @return array{ok:bool,message:string} */
	public function notify(string $endpoint, string $event): array
	{
		if ($endpoint === '' || !preg_match('/^[A-Za-z0-9_.+\-]+$/', $endpoint)) {
			return ['ok' => false, 'message' => 'invalid endpoint'];
		}
		if ($this->sender) {
			return ($this->sender)($endpoint, $event);
		}
		global $astman;
		if (!isset($astman) || !$astman || !method_exists($astman, 'send_request')) {
			return ['ok' => false, 'message' => 'AMI not connected'];
		}
		$res = $astman->send_request('PJSIPNotify', [
			'Endpoint' => $endpoint,
			'Variable' => 'Event=' . $event,
		]);
		$ok = is_array($res) && strcasecmp((string) ($res['Response'] ?? ''), 'Success') === 0;
		return ['ok' => $ok, 'message' => (string) ($res['Message'] ?? ($ok ? 'sent' : 'failed'))];
	}
}
