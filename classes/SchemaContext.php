<?php
namespace Intersect\SqlAssistant;

/**
 * Describes the REDCap database SCHEMA for the model.
 *
 * Strict rule: this class only ever reads information_schema (table names, table comments, column
 * definitions and foreign keys). It never selects rows from any REDCap table, and it deliberately does
 * not read information_schema statistics such as TABLE_ROWS, AUTO_INCREMENT or index cardinality.
 *
 * The full schema is large (~270 tables), so requests are made in two stages:
 *  1. the model sees only the list of table names (and table comments) and picks the relevant tables;
 *  2. the model sees full column/foreign-key detail for those tables only.
 */
class SchemaContext
{
	const MAX_TABLES_DETAILED = 25;

	/** Tables always described in detail for Dynamic SQL fields, as most project queries need them. */
	const DSQL_CORE_TABLES = [
		'redcap_data', 'redcap_metadata', 'redcap_events_metadata', 'redcap_events_arms', 'redcap_events_forms',
		'redcap_record_list', 'redcap_user_rights', 'redcap_user_roles', 'redcap_data_access_groups', 'redcap_surveys',
	];

	/** Tables always described in detail on the Database Query Tool. */
	const DQT_CORE_TABLES = ['redcap_projects', 'redcap_user_information'];

	private $module;
	private $tables = null;
	private $canonicalNames = null; // lowercase name => name as stored

	public function __construct($module)
	{
		$this->module = $module;
	}

	/** @return array table name => table comment, for REDCap base tables in the current database */
	public function getTables(): array
	{
		if ($this->tables !== null) return $this->tables;
		$this->tables = [];
		$result = $this->module->query(
			"SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ?
			 ORDER BY TABLE_NAME",
			['redcap\_%']
		);
		while ($row = $result->fetch_assoc()) {
			$this->tables[$row['TABLE_NAME']] = (string)$row['TABLE_COMMENT'];
		}
		return $this->tables;
	}

	public function tableExists(string $table): bool
	{
		return $this->canonicalTableName($table) !== null;
	}

	/**
	 * The table's name as stored in the database, matched case-insensitively (the model or a query may
	 * write REDCAP_PROJECTS for redcap_projects), or null if there is no such table.
	 */
	public function canonicalTableName(string $table): ?string
	{
		if ($this->canonicalNames === null) {
			$this->canonicalNames = [];
			foreach (array_keys($this->getTables()) as $name) {
				$this->canonicalNames[strtolower($name)] = $name;
			}
		}
		return $this->canonicalNames[strtolower($table)] ?? null;
	}

	/**
	 * Tables named in the text (e.g. the request, or the query already in the editor), as stored names.
	 * @return string[]
	 */
	public function tablesNamedIn(string $text): array
	{
		preg_match_all('/\bredcap_[a-z0-9_]+\b/i', $text, $m);
		return array_values(array_unique(array_filter(array_map([$this, 'canonicalTableName'], $m[0]))));
	}

	/** Stage 1: compact list of table names. Numbered record-data tables are collapsed into one line. */
	public function renderTableList(): string
	{
		$out = ['## REDCap database tables (name | comment)'];
		$dataTables = [];
		foreach ($this->getTables() as $name => $comment) {
			if (preg_match('/^redcap_data\d+$/', $name)) { $dataTables[] = $name; continue; }
			$out[] = '- ' . $name . ($comment !== '' ? ' | ' . $this->clean($comment) : '');
		}
		if (!empty($dataTables)) {
			$out[] = '- (' . implode(', ', $dataTables) . ': identical in structure to redcap_data; each project stores its record data in one of these, given by redcap_projects.data_table)';
		}
		return implode("\n", $out) . "\n";
	}

	/** @return array column name => definition, for one table */
	public function getColumns(string $table): array
	{
		$columns = [];
		$result = $this->module->query(
			"SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_COMMENT FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
			[$table]
		);
		while ($row = $result->fetch_assoc()) {
			$columns[$row['COLUMN_NAME']] = $row;
		}
		return $columns;
	}

	/** Stage 2: full column and foreign-key detail for the given tables. */
	public function renderTableDetail(array $tables): string
	{
		$known = $this->getTables();
		$tables = array_slice(array_values(array_unique(array_filter(
			array_map(function ($t) { return $this->canonicalTableName((string)$t); }, $tables)
		))), 0, self::MAX_TABLES_DETAILED);
		if (empty($tables)) return '';

		// Foreign keys for the selected tables, in one query
		$fks = [];
		$placeholders = implode(',', array_fill(0, count($tables), '?'));
		$result = $this->module->query(
			"SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
			 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ($placeholders)",
			$tables
		);
		while ($row = $result->fetch_assoc()) {
			$fks[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'];
		}

		$out = ['## Table definitions (column | type | key | nullable | references | comment)'];
		foreach ($tables as $table) {
			$out[] = "### $table" . ($known[$table] !== '' ? ' - ' . $this->clean($known[$table]) : '');
			foreach ($this->getColumns($table) as $col => $def) {
				$parts = [$col, $def['COLUMN_TYPE']];
				$parts[] = $def['COLUMN_KEY'] !== '' ? $def['COLUMN_KEY'] : '-';
				$parts[] = $def['IS_NULLABLE'] === 'YES' ? 'null' : 'not null';
				$parts[] = isset($fks[$table][$col]) ? '-> ' . $fks[$table][$col] : '-';
				if ($def['COLUMN_COMMENT'] !== '') $parts[] = $this->clean($def['COLUMN_COMMENT']);
				$out[] = '- ' . implode(' | ', $parts);
			}
		}
		return implode("\n", $out) . "\n";
	}

	private function clean($text, int $max = 150): string
	{
		$text = trim(preg_replace('/\s+/', ' ', (string)$text));
		return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
	}
}
