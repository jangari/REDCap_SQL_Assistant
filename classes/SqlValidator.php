<?php
namespace Intersect\SqlAssistant;

/**
 * Static checks on a generated query. The query is NEVER executed (not even with EXPLAIN): nothing here
 * touches table data, and problems are reported as plain-language messages that can be sent back to the
 * model for a corrected attempt.
 *
 * Checks:
 *  - one statement only;
 *  - read-only, using REDCap's own Database Query Tool rule (DBQueryTool::isSafeQuery) where available;
 *  - Dynamic SQL: must start with SELECT, as REDCap only runs Dynamic SQL that does;
 *  - every table referenced exists, and every qualified column (alias.column) exists in its table,
 *    using REDCap's bundled PHPSQLParser. Unqualified columns that can't be found are warnings only,
 *    since the parser cannot always tell columns from keywords.
 */
class SqlValidator
{
	private $schema;

	public function __construct(SchemaContext $schema)
	{
		$this->schema = $schema;
	}

	/** Normalise model output into a single query string (no trailing semicolon). */
	public static function normalise(string $sql): string
	{
		$sql = trim($sql);
		$sql = preg_replace('/^```(?:sql)?\s*|\s*```$/i', '', $sql);
		return rtrim(trim($sql), "; \t\n\r");
	}

	/**
	 * @param string $mode 'dsql' or 'dqt'
	 * @return array ['errors' => string[], 'warnings' => string[]]
	 */
	public function validate(string $sql, string $mode): array
	{
		$errors = [];
		$warnings = [];
		if ($sql === '') return ['errors' => ['The query is empty.'], 'warnings' => []];

		if ($this->hasMultipleStatements($sql)) {
			$errors[] = 'The response contains more than one SQL statement. Return a single query.';
		}

		if ($mode === 'dsql' && !preg_match('/^select\s/i', $sql)) {
			$errors[] = 'Dynamic SQL queries must begin with SELECT (REDCap only runs Dynamic SQL that starts with SELECT, so a WITH clause cannot be used).';
		}

		if (class_exists('\DBQueryTool') && method_exists('\DBQueryTool', 'isSafeQuery')) {
			if (!\DBQueryTool::isSafeQuery($sql)) {
				$errors[] = 'The query is not read-only by REDCap\'s rules: it must start with SELECT, SHOW or EXPLAIN and must not use INTO OUTFILE/DUMPFILE, LOAD_FILE, SLEEP, BENCHMARK, EXPLAIN ANALYZE, FOR UPDATE or LOCK IN SHARE MODE.';
			}
		} elseif (!preg_match('/^(select|show|explain)\s/i', $sql)) {
			$errors[] = 'The query must be read-only (SELECT, SHOW or EXPLAIN).';
		}

		if (preg_match('/^select\s/i', $sql)) {
			$this->checkReferences($sql, $mode, $errors, $warnings);
		}

		return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
	}

	private function hasMultipleStatements(string $sql): bool
	{
		// Remove quoted strings and comments, then look for a semicolon
		$stripped = preg_replace(["/'(?:[^'\\\\]|\\\\.)*'/s", '/"(?:[^"\\\\]|\\\\.)*"/s', '/`[^`]*`/', '/\/\*.*?\*\//s', '/(--\s|#)[^\n]*/'], '', $sql);
		return strpos($stripped, ';') !== false;
	}

	private function checkReferences(string $sql, string $mode, array &$errors, array &$warnings): void
	{
		$parserClass = '\PHPSQLParser\PHPSQLParser';
		if (!class_exists($parserClass)) {
			$warnings[] = 'Table and column names could not be checked automatically on this REDCap version.';
			return;
		}
		// Smart Variables / DQT placeholders such as [project-id] are replaced with quoted values at run time
		$parseable = preg_replace('/\[[a-z0-9_:\-]+\]/i', "'0'", $sql);
		try {
			$parsed = (new $parserClass())->parse($parseable);
		} catch (\Throwable $e) {
			$warnings[] = 'The query could not be fully parsed, so its table and column names were not checked.';
			return;
		}
		if (!is_array($parsed) || empty($parsed)) {
			$warnings[] = 'The query could not be fully parsed, so its table and column names were not checked.';
			return;
		}

		$tables = [];         // real table names referenced
		$aliases = [];        // alias => real table
		$derived = [];        // aliases of derived tables / subqueries
		$selectAliases = [];  // column aliases defined in SELECT lists
		$colrefs = [];
		$this->walk($parsed, $tables, $aliases, $derived, $selectAliases, $colrefs);

		foreach (array_unique($tables) as $table) {
			if (!$this->schema->tableExists($table)) {
				$errors[] = "Table `$table` does not exist in this REDCap database.";
			}
		}

		$realTables = array_values(array_unique(array_filter(array_map([$this->schema, 'canonicalTableName'], $tables))));
		foreach (array_unique($colrefs) as $ref) {
			$parts = array_map(function ($p) { return trim($p, '` '); }, explode('.', $ref));
			$column = end($parts);
			if ($column === '*' || $column === '' || $column[0] === '@' || is_numeric($column)) continue;

			if (count($parts) >= 2) {
				$qualifier = $parts[count($parts) - 2];
				if (isset($derived[$qualifier])) continue;
				$table = $aliases[$qualifier] ?? ($this->schema->tableExists($qualifier) ? $qualifier : null);
				if ($table === null) {
					$errors[] = "`$qualifier` in `$ref` is not a table or alias used in the query.";
				} elseif ($this->schema->tableExists($table) && !$this->columnExists($table, $column)) {
					$errors[] = "Column `$column` does not exist in table `$table`.";
				}
				continue;
			}

			// Unqualified column
			if (isset($selectAliases[strtolower($column)]) || !empty($derived)) continue;
			$found = false;
			foreach ($realTables as $table) {
				if ($this->columnExists($table, $column)) { $found = true; break; }
			}
			if (!$found && !empty($realTables)) {
				$warnings[] = "`$column` was not found as a column in " . implode(', ', $realTables) . '.';
			}
		}

		// Dynamic SQL uses only the first two columns: value, then label
		if ($mode === 'dsql' && isset($parsed['SELECT']) && is_array($parsed['SELECT'])) {
			$columns = array_filter($parsed['SELECT'], function ($e) { return ($e['expr_type'] ?? '') !== 'reserved'; });
			if (count($columns) > 2) {
				$warnings[] = 'Dynamic SQL uses only the first two columns (value, then label); the query returns ' . count($columns) . '.';
			}
		}
	}

	private function walk($node, array &$tables, array &$aliases, array &$derived, array &$selectAliases, array &$colrefs): void
	{
		if (!is_array($node)) return;
		$type = $node['expr_type'] ?? null;
		$alias = isset($node['alias']['name']) ? trim($node['alias']['name'], '` ') : null;

		if ($type === 'table' && isset($node['table'])) {
			$nameParts = explode('.', $node['table']);
			$name = trim(end($nameParts), '` ');
			$tables[] = $name;
			$aliases[$alias ?: $name] = $name;
		} elseif (($type === 'subquery' || $type === 'table_expression') && $alias) {
			$derived[$alias] = true;
		} elseif ($type === 'colref' && isset($node['base_expr'])) {
			$colrefs[] = $node['base_expr'];
		}
		if ($alias && $type !== 'table' && $type !== 'subquery' && $type !== 'table_expression') {
			$selectAliases[strtolower($alias)] = true;
		}
		foreach ($node as $key => $child) {
			if (is_array($child)) $this->walk($child, $tables, $aliases, $derived, $selectAliases, $colrefs);
		}
	}

	private $columnCache = [];

	private function columnExists(string $table, string $column): bool
	{
		// information_schema lookups need the stored name, whatever case the query used
		$table = $this->schema->canonicalTableName($table) ?? $table;
		if (!isset($this->columnCache[$table])) {
			$this->columnCache[$table] = array_change_key_case(array_flip(array_keys($this->schema->getColumns($table))));
		}
		return isset($this->columnCache[$table][strtolower($column)]);
	}
}
