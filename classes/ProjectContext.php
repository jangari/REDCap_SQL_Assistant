<?php
namespace Intersect\SqlAssistant;

/**
 * Describes one project's STRUCTURE for writing Dynamic SQL queries: the numeric IDs that queries join on
 * (project_id, arm_id, event_id, survey_id, role_id, group_id), plus instruments, repeating setup and fields.
 *
 * Only project configuration is included - never record data. Roles are read with a narrow SELECT
 * (role_id, role_name, unique_role_name) rather than UserRights::getRoles(), which has side effects.
 *
 * Size: the description is kept within a character limit. The IDs that queries join on (arms, events,
 * instruments, roles, DAGs) are always included in full; when the whole project doesn't fit, only the
 * instruments the query needs get full field detail, and detail for the rest is reduced in stages.
 */
class ProjectContext
{
	const MAX_LABEL_CHARS = 100;
	const DEFAULT_MAX_CHARS = 40000; // ~10,000 tokens

	// Field detail levels, per instrument
	const FIELDS_FULL = 'full';    // type, label and choices (code=label)
	const FIELDS_CODES = 'codes';  // type, label and choice codes only
	const FIELDS_NAMES = 'names';  // field names only
	const FIELDS_NONE = 'none';    // not listed

	private $module;
	private $projectId;
	private $project;
	private $draftMode;
	private $metadata;
	private $forms;
	private $structure = null; // rendered once: everything except the fields

	public function __construct($module, int $projectId)
	{
		$this->module = $module;
		$this->projectId = $projectId;
		$this->project = new \Project($projectId);
		$this->draftMode = $this->project->project['status'] > 0 && $this->project->project['draft_mode'] > 0;
		if ($this->draftMode && $this->project->metadata_temp === null) {
			$this->project->loadMetadataTemp(); // not loaded by the Project constructor
		}
		$this->metadata = $this->draftMode ? $this->project->metadata_temp : $this->project->metadata;
		$this->forms = $this->draftMode ? $this->project->forms_temp : $this->project->forms;
	}

	/** The whole project at full detail, or null if it doesn't fit within $maxChars. */
	public function buildWhole(int $maxChars = self::DEFAULT_MAX_CHARS): ?string
	{
		$text = $this->render(array_fill_keys(array_keys($this->forms), self::FIELDS_FULL));
		return mb_strlen($text) <= $maxChars ? $text : null;
	}

	/**
	 * The project with full field detail for the given instruments, reducing detail for the rest (and then
	 * for these) in stages until it fits. With no instruments given, every instrument's field names are listed.
	 */
	public function buildForForms(array $forms, int $maxChars = self::DEFAULT_MAX_CHARS): string
	{
		$focus = array_values(array_intersect(array_keys($this->forms), $forms)); // valid names, in project order
		$plans = empty($focus)
			? [[null, self::FIELDS_NAMES], [null, self::FIELDS_NONE]]
			: [[self::FIELDS_FULL, self::FIELDS_NAMES], [self::FIELDS_FULL, self::FIELDS_NONE], [self::FIELDS_CODES, self::FIELDS_NONE]];
		$text = '';
		foreach ($plans as [$focusLevel, $otherLevel]) {
			$levels = [];
			foreach (array_keys($this->forms) as $form) {
				$levels[$form] = in_array($form, $focus, true) ? $focusLevel : $otherLevel;
			}
			$text = $this->render($levels);
			if (mb_strlen($text) <= $maxChars) return $text;
		}
		return $this->truncate($text, $maxChars);
	}

	/** @return string[] the instrument names in this project */
	public function formNames(): array
	{
		return array_keys($this->forms);
	}

	/**
	 * Instruments whose form name, label or field names appear in the text (e.g. the request, or the query
	 * already in the editor). Names are matched as whole words, so a short form name like "bp" doesn't
	 * match inside other words.
	 * @return string[]
	 */
	public function formsNamedIn(string $text): array
	{
		$forms = [];
		preg_match_all('/[a-z][a-z0-9_]*/i', $text, $m);
		foreach (array_unique(array_map('strtolower', $m[0])) as $word) {
			if (isset($this->forms[$word])) $forms[$word] = true;
			$form = $this->metadata[$word]['form_name'] ?? null;
			if ($form !== null && isset($this->forms[$form])) $forms[$form] = true;
		}
		foreach ($this->forms as $form => $formInfo) {
			$label = $this->clean($formInfo['menu'] ?? '');
			if (mb_strlen($label) >= 4 && stripos($text, $label) !== false) $forms[$form] = true;
		}
		return array_keys($forms);
	}

	/**
	 * A short list of the instruments, for the AI to choose from: name, label and fields.
	 * Detail is dropped in stages (field labels, then field names) to fit within $maxChars.
	 */
	public function instrumentIndex(int $maxChars): string
	{
		$text = '';
		foreach (['labels', 'names', 'forms_only'] as $level) {
			$out = ['## Instruments in this project (form_name | label | fields)'];
			foreach ($this->forms as $form => $formInfo) {
				$fields = array_keys($formInfo['fields'] ?? []);
				$line = "- $form | " . $this->clean($formInfo['menu'] ?? $form) . ' | ' . count($fields) . ' fields';
				if ($level === 'labels') {
					$line .= ': ' . implode('; ', array_map(function ($f) {
						return $f . ' (' . $this->clean($this->metadata[$f]['element_label'] ?? '', 40) . ')';
					}, $fields));
				} elseif ($level === 'names') {
					$line .= ': ' . implode(', ', $fields);
				}
				$out[] = $line;
			}
			$text = implode("\n", $out) . "\n";
			if (mb_strlen($text) <= $maxChars) return $text;
		}
		return $this->truncate($text, $maxChars);
	}

	private function truncate(string $text, int $maxChars): string
	{
		// A note so the model knows the description is incomplete
		return mb_substr($text, 0, $maxChars) . "\n[... project description cut short to fit the request size limit ...]\n";
	}

	/** @param array $levels form => one of the FIELDS_* levels */
	private function render(array $levels): string
	{
		$out = [$this->structure()];
		$reduced = in_array(self::FIELDS_NAMES, $levels, true) || in_array(self::FIELDS_NONE, $levels, true)
			|| in_array(self::FIELDS_CODES, $levels, true);
		$out[] = '## Fields by instrument (field_name | type[, validation] | label | choices as code=label)';
		if ($reduced) {
			$out[] = '(To keep the request small, some instruments are shown with less detail: field names only, choice codes without labels, or not at all. Every instrument is listed under Instruments above.)';
		}
		foreach ($this->forms as $form => $formInfo) {
			$level = $levels[$form] ?? self::FIELDS_NONE;
			if ($level === self::FIELDS_NONE) continue;
			$fields = array_keys($formInfo['fields'] ?? []);
			if ($level === self::FIELDS_NAMES) {
				$out[] = "### $form (field names only)";
				$out[] = implode(', ', $fields);
				continue;
			}
			$out[] = "### $form" . ($level === self::FIELDS_CODES ? ' (choice codes only)' : '');
			foreach ($fields as $field) {
				$attr = $this->metadata[$field] ?? null;
				if ($attr !== null) $out[] = $this->renderField($field, $attr, $level === self::FIELDS_FULL);
			}
		}
		return implode("\n", $out) . "\n";
	}

	/** Everything except the fields: IDs and names that queries join on. Rendered once per request. */
	private function structure(): string
	{
		if ($this->structure !== null) return $this->structure;
		$p = $this->project;
		$out = [];
		$out[] = '## This project';
		$out[] = 'project_id: ' . $this->projectId . ' (in a Dynamic SQL query, write [project-id] rather than the literal number)';
		$out[] = 'Record data table: ' . $this->dataTable() . ' (write redcap_data in Dynamic SQL; REDCap substitutes the right table automatically)';
		$out[] = 'Record ID field: ' . $p->table_pk;
		$out[] = 'Longitudinal: ' . ($p->longitudinal ? 'yes' : 'no');
		if ($this->draftMode) $out[] = 'The project is in Draft Mode; the fields below are the draft versions.';

		// Arms and events, with IDs
		$repeating = $p->getRepeatingFormsEvents(); // [event_id => 'WHOLE' | [form => label]]
		$uniqueNames = $p->getUniqueEventNames();
		$out[] = '';
		$out[] = '## Arms (arm_num | arm_id | name)';
		foreach ($p->events as $armNum => $arm) {
			$out[] = "- $armNum | " . $arm['id'] . ' | ' . $this->clean($arm['name']);
		}
		$out[] = '';
		$out[] = '## Events (event_id | unique event name | label | arm_num | instruments designated | repeating?)';
		foreach ($p->eventInfo as $eventId => $info) {
			$isRepeatingEvent = isset($repeating[$eventId]) && !is_array($repeating[$eventId]);
			$out[] = "- $eventId | " . ($uniqueNames[$eventId] ?? '') . ' | ' . $this->clean($info['name'])
				. ' | ' . $info['arm_num']
				. ' | ' . implode(', ', $p->eventsForms[$eventId] ?? [])
				. ' | ' . ($isRepeatingEvent ? 'REPEATING EVENT' : 'no');
		}

		// Instruments, with survey IDs and repeating status
		$surveyIds = [];
		foreach ((array)$p->surveys as $surveyId => $survey) {
			$surveyIds[$survey['form_name']] = $surveyId;
		}
		$out[] = '';
		$out[] = '## Instruments (form_name | label | survey_id if a survey | repeating in event_ids)';
		foreach ($this->forms as $form => $formInfo) {
			$repeatingIn = [];
			foreach ($repeating as $eventId => $value) {
				if (is_array($value) && array_key_exists($form, $value)) $repeatingIn[] = $eventId;
			}
			$out[] = "- $form | " . $this->clean($formInfo['menu'] ?? $form)
				. ' | ' . ($surveyIds[$form] ?? '-')
				. ' | ' . (empty($repeatingIn) ? 'not repeating' : 'REPEATING in ' . implode(', ', $repeatingIn));
		}

		// User roles and DAGs (names and IDs only)
		$out[] = '';
		$out[] = '## User roles (role_id | unique_role_name | role_name)';
		$roles = $this->module->query(
			'SELECT role_id, unique_role_name, role_name FROM redcap_user_roles WHERE project_id = ? ORDER BY role_name',
			[$this->projectId]
		);
		$any = false;
		while ($row = $roles->fetch_assoc()) {
			$any = true;
			$out[] = '- ' . $row['role_id'] . ' | ' . $row['unique_role_name'] . ' | ' . $this->clean($row['role_name']);
		}
		if (!$any) $out[] = '(none)';

		$out[] = '';
		$out[] = '## Data Access Groups (group_id | unique group name | group name)';
		$groups = $p->getGroups();
		$uniqueGroups = $p->getUniqueGroupNames();
		if (empty($groups)) $out[] = '(none)';
		foreach ($groups as $groupId => $name) {
			$out[] = "- $groupId | " . ($uniqueGroups[$groupId] ?? '') . ' | ' . $this->clean($name);
		}
		$out[] = '';
		return $this->structure = implode("\n", $out) . "\n";
	}

	private function dataTable(): string
	{
		if (class_exists('\Records') && method_exists('\Records', 'getDataTable')) {
			try { return \Records::getDataTable($this->projectId); } catch (\Throwable $e) {}
		}
		return 'redcap_data';
	}

	private function renderField(string $field, array $attr, bool $choiceLabels = true): string
	{
		$type = $attr['element_type'];
		$typeNames = ['select' => 'dropdown', 'textarea' => 'notes', 'advcheckbox' => 'checkbox', 'sql' => 'dynamic sql'];
		$typeName = $typeNames[$type] ?? $type;
		if ($type === 'text' && ($attr['element_validation_type'] ?? '') !== '') $typeName .= ', ' . $attr['element_validation_type'];
		$line = "- $field | $typeName | " . $this->clean($attr['element_label']);
		if (in_array($type, ['select', 'radio', 'checkbox', 'advcheckbox', 'yesno', 'truefalse'], true)) {
			$parts = [];
			foreach (parseEnum((string)$attr['element_enum']) as $code => $label) {
				$parts[] = $choiceLabels ? $code . '=' . $this->clean($label, 40) : (string)$code;
			}
			$line .= ' | ' . implode($choiceLabels ? '; ' : ', ', $parts);
		}
		return $line;
	}

	private function clean($text, int $max = self::MAX_LABEL_CHARS): string
	{
		$text = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string)$text, ENT_QUOTES))));
		return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
	}
}
