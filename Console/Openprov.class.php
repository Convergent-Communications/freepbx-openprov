<?php
namespace FreePBX\Console\Command;

use FreePBX\modules\Openprov\Api;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * fwconsole openprov <action> [key=value ...] [--data JSON] [--compact]
 *
 * Every action takes JSON arguments and prints JSON, so asterisk-server (or
 * any script on the box) can drive OpenProv. `fwconsole openprov actions`
 * lists them; docs/API.md has examples.
 */
class Openprov extends Command
{
	protected function configure()
	{
		$this->setName('openprov')
			->setDescription('OpenProv phone provisioning')
			->addArgument('action', InputArgument::REQUIRED, 'Action name (fwconsole openprov actions)')
			->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'key=value arguments (values may be JSON)')
			->addOption('data', 'd', InputOption::VALUE_REQUIRED, 'JSON object of arguments ("-" reads stdin)')
			->addOption('compact', 'c', InputOption::VALUE_NONE, 'Single-line JSON output')
			->addOption('install', null, InputOption::VALUE_NONE, 'With "web": install/refresh the provisioning vhost (root)')
			->addOption('uninstall', null, InputOption::VALUE_NONE, 'With "web": remove the provisioning vhost (root)');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$bmo = \FreePBX::create()->Openprov;
		$svc = $bmo->service();
		$action = (string) $input->getArgument('action');
		$flags = $input->getOption('compact') ? JSON_UNESCAPED_SLASHES : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

		if ($action === 'actions') {
			foreach (Api::actions() as $name => $help) {
				$output->writeln(str_pad($name, 18) . ' ' . $help);
			}
			return 0;
		}
		if ($action === 'web') {
			if ($input->getOption('install')) {
				$r = $svc->webInstall();
				$output->writeln(($r['ok'] ? '<info>' : '<error>') . $r['message'] . ($r['ok'] ? '</info>' : '</error>'));
				return $r['ok'] ? 0 : 1;
			}
			if ($input->getOption('uninstall')) {
				$r = $svc->webUninstall();
				$output->writeln($r['message']);
				return $r['ok'] ? 0 : 1;
			}
			$output->writeln(json_encode($svc->webStatus(), $flags));
			return 0;
		}

		$args = [];
		$data = $input->getOption('data');
		if ($data !== null) {
			$json = $data === '-' ? stream_get_contents(STDIN) : $data;
			$args = json_decode((string) $json, true);
			if (!is_array($args)) {
				$output->writeln('<error>--data must be a JSON object</error>');
				return 2;
			}
		}
		foreach ((array) $input->getArgument('args') as $pair) {
			if (!str_contains($pair, '=')) {
				$output->writeln("<error>Expected key=value, got '$pair'</error>");
				return 2;
			}
			[$k, $v] = explode('=', $pair, 2);
			$decoded = json_decode($v, true);
			$args[$k] = ($decoded !== null && (is_array($decoded) || is_bool($decoded))) ? $decoded : $v;
		}

		try {
			$result = (new Api($svc))->call($action, $args);
		} catch (\InvalidArgumentException $e) {
			$output->writeln(json_encode(['ok' => false, 'error' => $e->getMessage()], $flags));
			return 1;
		} catch (\Throwable $e) {
			$output->writeln(json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()], $flags));
			return 1;
		}
		if ($action === 'render' && isset($result['text']) && !$input->getOption('compact')) {
			$output->write($result['text'], false, OutputInterface::OUTPUT_RAW);
			return 0;
		}
		if (is_string($result)) {
			$output->write($result, false, OutputInterface::OUTPUT_RAW);
			return 0;
		}
		$output->writeln(json_encode($result, $flags), OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
