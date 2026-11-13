<?php
namespace FreePBX\modules\Openprov;

use FreePBX\modules\Backup as Base;

class Restore extends Base\RestoreBase
{
	public function runRestore()
	{
		$configs = $this->getConfigs();
		if (!empty($configs['tables']) && is_array($configs['tables'])) {
			foreach ($configs['tables'] as $table => $rows) {
				self::importTable($this->FreePBX->Database, (string) $table, is_array($rows) ? $rows : []);
			}
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

	/**
	 * Replace a table's rows with the backed-up ones, byte for byte.
	 *
	 * Not RestoreBase::importTables(): its addDataToTableFromArray() rewrites
	 * the two-character sequences \n, \" and \' in every value. That breaks
	 * our JSON columns: an escaped newline in a string becomes a raw newline,
	 * json_decode fails, and the template restores as if it had no settings.
	 */
	public static function importTable(\PDO $db, string $table, array $rows): void
	{
		if (!preg_match('/^openprov_[a-z_]+$/', $table)) {
			return;
		}
		$probe = $db->query("SELECT * FROM `$table` LIMIT 0");
		$columns = [];
		for ($i = 0; $i < $probe->columnCount(); $i++) {
			$columns[] = $probe->getColumnMeta($i)['name'];
		}
		$db->beginTransaction();
		try {
			$db->exec("DELETE FROM `$table`");
			foreach ($rows as $row) {
				$row = array_intersect_key((array) $row, array_flip($columns));
				if (!$row) {
					continue;
				}
				$cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
				$marks = implode(',', array_fill(0, count($row), '?'));
				$db->prepare("INSERT INTO `$table` ($cols) VALUES ($marks)")->execute(array_values($row));
			}
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
	}
}
