<?php
namespace Intersect\SqlAssistant;

/**
 * Prompts for the two-stage request:
 *  1. table selection (sees table names only);
 *  2. query generation (sees full definitions of the selected tables, plus project structure for Dynamic SQL).
 */
class PromptBuilder
{
	const MODES = ['dsql', 'dqt'];

	const REDCAP_DATA_MODEL = <<<'TXT'
How REDCap stores data:
- Record data is stored one value per row (entity-attribute-value) in redcap_data (project_id, event_id, record, field_name, value, instance). Very large sites split projects across redcap_data, redcap_data2, redcap_data3, ...; the table a project uses is redcap_projects.data_table.
- Blank values are not stored at all, so "is blank" means "no row exists".
- A checkbox field has one row per ticked option, with value = the choice code.
- instance is NULL for the first (or only) instance and 2, 3, ... for later instances of repeating instruments/events; use coalesce(instance, 1) when comparing instances.
- The record ID field has its own rows in redcap_data (field_name = the record ID field).
- To put several fields on one row, pivot, e.g. max(if(field_name = 'dob', value, null)) AS dob ... GROUP BY record.
- Field definitions are in redcap_metadata (project_id, field_name, form_name, element_type, element_enum = choices, ...); events in redcap_events_metadata (event_id, arm_id, descrip) and arms in redcap_events_arms (arm_id, project_id, arm_num, arm_name).
TXT;

	const RULES = [
		'dsql' => <<<'TXT'
You are writing the query for a REDCap DYNAMIC SQL field (a dropdown whose choices come from a query). Rules:
- The query MUST start with SELECT (REDCap will not run anything else, so a WITH clause cannot be used). It must be read-only.
- The first column is the stored value (code) and the optional second column is the label shown to the user. Return no more than two columns. Rows are shown in the order returned, so include ORDER BY.
- Return each value only once (use DISTINCT or GROUP BY where needed).
- Refer to the current project as [project-id], never as a literal number, so the field still works if the project is copied. Always restrict project tables with project_id = [project-id], unless the user explicitly asks to read another project, which they will identify by its project_id.
- Smart Variables such as [project-id], [record-name], [event-id], [current-instance], [instrument-name], [user-name] and [record-dag-id] are replaced with quoted, SQL-escaped values before the query runs. Do NOT put your own quotes around them.
- Write redcap_data for record data; REDCap automatically substitutes the project's own data table.
- The query runs every time the form is opened, so keep it efficient.
TXT,
		'dqt' => <<<'TXT'
You are writing a query for an administrator to run in REDCap's DATABASE QUERY TOOL (Control Center). Rules:
- The query must be a single read-only SELECT, SHOW or EXPLAIN statement. The tool adds "LIMIT 0,<n>" automatically if no LIMIT is given.
- The page has context boxes that fill these placeholders with quoted values: [project-id], [record-name], [event-id], [instrument-name], [current-instance], [user-name], plus [survey-id] and [user-id] which the tool looks up. Use them where the user's request is about "a project", "a record", "a user" etc. without giving a specific value, and do not put your own quotes around them.
- If the query reads record data for a specific project, note in the explanation that redcap_data must be replaced with that project's data table (redcap_projects.data_table) if it is not redcap_data.
- Use clear column aliases and ORDER BY so the results are easy to read.
TXT,
	];

	public static function normaliseMode(?string $mode): string
	{
		return in_array($mode, self::MODES, true) ? $mode : 'dqt';
	}

	/* ---------- Stage 1: table selection ---------- */

	/** @param bool $withInstruments also choose the project's instruments (when the whole project is too large to send) */
	public static function tableSelectionSystemPrompt(bool $withInstruments = false): string
	{
		if (!$withInstruments) {
			return <<<'TXT'
You are an expert in the REDCap database schema. Given a list of REDCap database tables and an administrator's request for a SQL query, choose the tables whose column definitions are needed to write the query. Include tables needed for joins. Choose at most 15 tables, using exact names from the list.

Respond with ONLY a JSON object, with no markdown code fences and no other text: {"tables": ["redcap_...", ...]}
TXT;
		}
		return <<<'TXT'
You are an expert in the REDCap database schema. Given a list of REDCap database tables, a list of the project's instruments, and an administrator's request for a SQL query, choose:
- "tables": the tables whose column definitions are needed to write the query, including tables needed for joins. At most 15, using exact names from the list.
- "instruments": the project's instruments whose fields the query needs (e.g. fields it reads, filters on or shows as the label). At most 10, using exact form names from the list. Use [] if the query needs no project fields.

Respond with ONLY a JSON object, with no markdown code fences and no other text: {"tables": ["redcap_...", ...], "instruments": ["form_name", ...]}
TXT;
	}

	public static function tableSelectionUserMessage(string $tableList, string $mode, string $request, string $instrumentIndex = ''): string
	{
		$purpose = $mode === 'dsql'
			? 'The query is for a Dynamic SQL field in a REDCap project.'
			: 'The query is for the Database Query Tool in the Control Center.';
		return $tableList . ($instrumentIndex !== '' ? "\n" . $instrumentIndex : '')
			. "\n## Purpose\n$purpose\n\n## Request\n" . trim($request);
	}

	/* ---------- Stage 2: query generation ---------- */

	public static function generationSystemPrompt(string $mode): string
	{
		$rules = self::RULES[self::normaliseMode($mode)];
		$model = self::REDCAP_DATA_MODEL;
		return <<<TXT
You are an expert REDCap administrator and MySQL developer.

$rules

$model

Use only the tables and columns given in the table definitions. Never invent table or column names. If you need a REDCap table whose definition was not given, leave "sql" empty and list its exact name in "need_tables"; its definition will be added and you will be asked again. If the request is ambiguous, give your best query and list the ambiguity in "assumptions"; if no sensible query is possible, leave "sql" empty and ask a short question in "clarification".

Respond with ONLY a JSON object, with no markdown code fences and no other text, in exactly this shape:
{"sql": "<the query, or empty string>", "explanation": "<1-4 sentences in plain English>", "assumptions": ["<assumption>", ...], "clarification": "<question for the user, or empty string>", "need_tables": ["<table name>", ...]}
TXT;
	}

	public static function generationUserMessage(string $tableDetail, string $projectContext, string $siteGuidance, string $existingSql, string $request): string
	{
		$parts = [$tableDetail];
		if ($projectContext !== '') $parts[] = $projectContext;
		if (trim($siteGuidance) !== '') $parts[] = "## Site-specific guidance from administrators\n" . trim($siteGuidance);
		if (trim($existingSql) !== '') $parts[] = "## Query currently in the editor (the user may want this modified)\n" . $existingSql;
		$parts[] = "## Request\n" . trim($request);
		return implode("\n\n", $parts);
	}

	/** Definitions of tables added after the first attempt (asked for by the model, or used by its query without having been given). */
	public static function addedTablesSection(string $tableDetail): string
	{
		return $tableDetail === '' ? '' : "\n\n## Further table definitions (added since your previous response)\n" . $tableDetail;
	}

	public static function retryMessage(string $originalMessage, string $previousSql, array $errors): string
	{
		return $originalMessage
			. "\n\n## Your previous query was invalid\nPrevious query: $previousSql\nProblems:\n- "
			. implode("\n- ", $errors)
			. "\nReturn a corrected JSON response.";
	}

	public static function tablesAddedMessage(string $originalMessage): string
	{
		return $originalMessage . "\n\nThe table definitions you asked for have been added above. Return your JSON response.";
	}
}
