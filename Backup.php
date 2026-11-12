<?php
namespace FreePBX\modules\Openprov;

use FreePBX\modules\Backup as Base;

/** FreePBX Backup & Restore: tables (not the request log), settings, uploaded files. */
class Backup extends Base\BackupBase
{
	public function runBackup($id, $transaction)
	{
		$dir = $this->FreePBX->Openprov->assetDir() . '/files';
		if (is_dir($dir)) {
			foreach (glob($dir . '/*') ?: [] as $f) {
				if (is_file($f)) {
					$this->addFile(basename($f), $dir, '', 'openprov-asset');
				}
			}
			$this->addDirectories([$dir]);
		}
		$this->addConfigs([
			'tables' => $this->dumpTables(false, ['openprov_events']),
			'kvstore' => $this->dumpKVStore(),
		]);
	}
}
