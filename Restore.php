<?php
namespace FreePBX\modules\Openprov;

use FreePBX\modules\Backup as Base;

class Restore extends Base\RestoreBase
{
	public function runRestore()
	{
		$configs = $this->getConfigs();
		if (!empty($configs['tables']) && is_array($configs['tables'])) {
			$this->importTables($configs['tables']);
		}
		if (!empty($configs['kvstore']) && is_array($configs['kvstore'])) {
			$this->importKVStore($configs['kvstore']);
		}
		foreach ($this->getFiles() as $file) {
			$source = $this->tmpdir . '/files' . $file->getPathTo() . '/' . $file->getFilename();
			if (!is_file($source)) {
				continue;
			}
			if (!is_dir($file->getPathTo())) {
				mkdir($file->getPathTo(), 0750, true);
			}
			copy($source, $file->getPathTo() . '/' . $file->getFilename());
			@chown($file->getPathTo() . '/' . $file->getFilename(), 'asterisk');
		}
	}
}
