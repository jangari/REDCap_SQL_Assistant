<?php
namespace Intersect\SqlAssistant;

use ExternalModules\AbstractExternalModule;

require_once __DIR__ . '/classes/AIClient.php';
require_once __DIR__ . '/classes/SchemaContext.php';
require_once __DIR__ . '/classes/ProjectContext.php';
require_once __DIR__ . '/classes/SqlValidator.php';
require_once __DIR__ . '/classes/PromptBuilder.php';

class SqlAssistant extends AbstractExternalModule
{
	const MAX_REQUEST_CHARS = 2000;
	const MAX_EXISTING_SQL_CHARS = 10000;
	const MAX_ATTEMPTS = 3; // first attempt + up to 2 corrections after validation failures

	/* ===================== Hooks ===================== */

	public function redcap_every_page_top($project_id)
	{
		if (!$this->isAuthenticated() || !$this->isSuperUser()) return;

		$mode = $this->pageMode($project_id);
		if ($mode === null) return;
		if (!(new AIClient())->isAvailable()) return;

		$this->initializeJavascriptModuleObject();
		$jsmo = $this->getJavascriptModuleObjectName();
		?>
		<link rel="stylesheet" href="<?= $this->getUrl('css/sql_assistant.css') ?>">
		<script src="<?= $this->getUrl('js/sql_assistant.js') ?>"></script>
		<script>
			(function () {
				if (window.IntersectSqlAssistant) {
					window.IntersectSqlAssistant.init(<?= $jsmo ?>, <?= json_encode(['mode' => $mode, 'username' => defined('USERID') ? USERID : ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
				}
			})();
		</script>
		<?php
	}

	public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
									   $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
	{
		if ($action !== 'generate') return ['error' => 'Unknown action.'];
		if (!$this->isSuperUser()) return ['error' => 'The SQL Assistant is only available to super users.'];

		$payload = is_array($payload) ? $payload : [];
		$mode = PromptBuilder::normaliseMode($payload['mode'] ?? null);

		// The request must come from the matching context
		if ($mode === 'dsql') {
			if (empty($project_id)) return ['error' => 'Dynamic SQL queries can only be generated within a project.'];
			// Without PROJECT_ID, core would use the system-level GenAI configuration instead of the project's
			if (!defined('PROJECT_ID') || (int)PROJECT_ID !== (int)$project_id) {
				return ['error' => 'Project context is not available, so the project\'s GenAI setting cannot be applied.'];
			}
		} else {
			if (!empty($project_id)) return ['error' => 'Database Query Tool queries can only be generated from the Control Center.'];
			if (($GLOBALS['database_query_tool_enabled'] ?? '') != '1') return ['error' => 'The Database Query Tool is not enabled.'];
		}

		try {
			return $this->generate($mode, empty($project_id) ? null : (int)$project_id, $payload);
		} catch (\Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/* ===================== Core flow ===================== */

	private function generate(string $mode, ?int $projectId, array $payload): array
	{
		$request = trim((string)($payload['request'] ?? ''));
		if ($request === '') return ['error' => 'Please describe the query you need.'];
		if (mb_strlen($request) > self::MAX_REQUEST_CHARS) {
			return ['error' => 'Please shorten your description to ' . self::MAX_REQUEST_CHARS . ' characters or fewer.'];
		}
		$existingSql = mb_substr((string)($payload['existing_sql'] ?? ''), 0, self::MAX_EXISTING_SQL_CHARS);

		// Daily token limit: refuse outright if the project (or the Database Query Tool) is already at it
		$limit = $this->dailyTokenLimit($mode, $projectId);
		$usedBefore = $limit > 0 ? $this->tokensUsedToday($projectId) : 0;
		if ($limit > 0 && $usedBefore >= $limit) {
			return ['error' => ($mode === 'dsql' ? 'This project has' : 'The Database Query Tool assistant has')
				. ' reached its daily AI usage limit (' . number_format($usedBefore) . ' of ' . number_format($limit)
				. ' tokens). The limit resets at midnight server time.'];
		}

		$client = new AIClient();
		$tables = [];
		try {
			$schema = new SchemaContext($this);

			// Stage 1: choose relevant tables from the table list (names and comments only)
			$selection = $this->parseModelJson($client->complete(
				PromptBuilder::tableSelectionSystemPrompt(),
				PromptBuilder::tableSelectionUserMessage($schema->renderTableList(), $mode, $request)
			));
			$chosen = array_filter(array_map(function ($t) use ($schema) {
				return is_scalar($t) ? $schema->canonicalTableName((string)$t) : null;
			}, (array)($selection['tables'] ?? [])));
			$core = $mode === 'dsql' ? SchemaContext::DSQL_CORE_TABLES : SchemaContext::DQT_CORE_TABLES;
			$tables = array_values(array_unique(array_merge($core, $chosen)));

			// Stage 2: generate the query from the selected tables' definitions (+ project structure for Dynamic SQL)
			$projectContext = $mode === 'dsql' ? (new ProjectContext($this, $projectId))->render() : '';
			$systemPrompt = PromptBuilder::generationSystemPrompt($mode);
			$userMessage = PromptBuilder::generationUserMessage(
				$schema->renderTableDetail($tables),
				$projectContext,
				(string)$this->getSystemSetting('site-guidance'),
				$existingSql,
				$request
			);

			$validator = new SqlValidator($schema);
			$message = $userMessage;
			$result = null;
			$check = ['errors' => [], 'warnings' => []];
			$attempts = 0;
			$limitReached = false;
			while ($attempts < self::MAX_ATTEMPTS) {
				// Always make the first attempt; only stop making corrections once the limit is reached
				if ($attempts > 0 && $limit > 0 && $usedBefore + $client->getTokensUsed() >= $limit) {
					$limitReached = true;
					break;
				}
				$attempts++;
				$result = $this->normaliseResult($this->parseModelJson($client->complete($systemPrompt, $message)));
				if ($result['sql'] === '') { // clarifying question instead of a query
					$check = ['errors' => [], 'warnings' => []];
					break;
				}
				$check = $validator->validate($result['sql'], $mode);
				if (empty($check['errors'])) break;
				$message = PromptBuilder::retryMessage($userMessage, $result['sql'], $check['errors']);
			}
		} catch (\Throwable $e) {
			// Tokens spent before a failure still count toward the daily limit
			if ($client->getTokensUsed() > 0) {
				$this->log('SQL query failed', [
					'mode' => $mode,
					'tokens' => $client->getTokensUsed(),
					'tables_described' => count($tables),
					'ai_service' => $client->getServiceName(),
				]);
			}
			throw $e;
		}

		$warnings = $check['warnings'];
		if ($limitReached) {
			$warnings[] = ($mode === 'dsql' ? 'This project has' : 'The Database Query Tool assistant has')
				. ' reached its daily AI usage limit, so the assistant stopped correcting this query. Check the problems listed above before using it.';
		}

		// Record usage (no request text or SQL is logged)
		$clarification = $result['sql'] === '';
		$this->log('SQL query generated', [
			'mode' => $mode,
			'attempts' => $attempts,
			'valid' => (!$clarification && empty($check['errors'])) ? 1 : 0,
			'clarification' => $clarification ? 1 : 0,
			'limit_reached' => $limitReached ? 1 : 0,
			'tokens' => $client->getTokensUsed(),
			'tables_described' => count($tables),
			'ai_service' => $client->getServiceName(),
		]);

		return [
			'sql' => $result['sql'],
			'explanation' => $result['explanation'],
			'assumptions' => $result['assumptions'],
			'clarification' => $result['clarification'],
			'errors' => $check['errors'],
			'warnings' => $warnings,
		];
	}

	/* ===================== Daily token limit ===================== */

	/**
	 * The daily token limit that applies to this request; 0 means no limit.
	 *  - Dynamic SQL: the project's setting (super users only) if it is a whole number (0 = no limit for this
	 *    project); otherwise (blank, or not a whole number) the system "per project" limit.
	 *  - Database Query Tool: the system limit for the Database Query Tool.
	 * Blank or invalid system values mean no limit.
	 */
	private function dailyTokenLimit(string $mode, ?int $projectId): int
	{
		if ($mode !== 'dsql' || $projectId === null) {
			return self::parseTokenLimit($this->getSystemSetting('dqt-daily-token-limit')) ?? 0;
		}
		$project = self::parseTokenLimit($this->getProjectSetting('project-daily-token-limit', $projectId));
		return $project ?? (self::parseTokenLimit($this->getSystemSetting('daily-token-limit')) ?? 0);
	}

	/** A whole number of tokens, or null if the value is blank or not a whole number. */
	public static function parseTokenLimit($value): ?int
	{
		$value = is_scalar($value) ? trim((string)$value) : '';
		return preg_match('/^\d{1,12}$/', $value) ? (int)$value : null;
	}

	/**
	 * Tokens used since midnight (server time) by the project, or by the Database Query Tool when $projectId
	 * is null. Log entries from before token counting was added have no count, so they count as 0.
	 */
	private function tokensUsedToday(?int $projectId): int
	{
		$params = ['SQL query generated', 'SQL query failed', date('Y-m-d 00:00:00')];
		$where = 'message in (?, ?) and timestamp >= ? and ';
		if ($projectId === null) {
			$where .= 'project_id is null';
		} else {
			$where .= 'project_id = ?';
			$params[] = $projectId;
		}
		$row = $this->queryLogs("select sum(tokens) as total where $where", $params)->fetch_assoc();
		return (int)($row['total'] ?? 0);
	}

	/** Which assistant, if any, belongs on the current page. */
	private function pageMode($projectId): ?string
	{
		if (!empty($projectId) && $this->isREDCapPage('Design/online_designer.php')) {
			return 'dsql';
		}
		if (empty($projectId) && $this->isREDCapPage('ControlCenter/database_query_tool.php')
			&& ($GLOBALS['database_query_tool_enabled'] ?? '') == '1') {
			return 'dqt';
		}
		return null;
	}

	/** Models occasionally wrap JSON in code fences or add stray text; extract the outermost object. */
	private function parseModelJson(string $text): array
	{
		$text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		$decoded = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
		if (!is_array($decoded)) {
			throw new \Exception('The AI service returned a response that could not be read. Please try again.');
		}
		return $decoded;
	}

	private function normaliseResult(array $decoded): array
	{
		$assumptions = $decoded['assumptions'] ?? [];
		if (!is_array($assumptions)) $assumptions = [$assumptions];
		return [
			'sql' => SqlValidator::normalise((string)($decoded['sql'] ?? '')),
			'explanation' => trim((string)($decoded['explanation'] ?? '')),
			'assumptions' => array_values(array_filter(array_map('strval', $assumptions), 'strlen')),
			'clarification' => trim((string)($decoded['clarification'] ?? '')),
		];
	}
}
