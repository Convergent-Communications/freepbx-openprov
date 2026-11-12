<?php
namespace FreePBX\modules\Openprov;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs every 5 minutes (fwconsole job): retries deferred phone updates
 * (busy lines, reboot window, unreachable VPN phones) and catches config
 * changes made outside OpenProv without an Apply Config.
 */
class Job implements \FreePBX\Job\TaskInterface
{
	public static function run(InputInterface $input, OutputInterface $output)
	{
		$svc = \FreePBX::Openprov()->service();
		foreach ($svc->syncChanged() as $r) {
			$output->writeln(sprintf('%s %s %s', $r['mac'], $r['ok'] ? 'ok' : ($r['deferred'] ? 'deferred' : 'failed'), $r['message']));
		}
		return true;
	}
}
