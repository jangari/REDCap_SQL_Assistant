<?php
namespace Intersect\SqlAssistant;

/**
 * Describes one project's STRUCTURE for writing Dynamic SQL queries: the numeric IDs that queries join on
 * (project_id, arm_id, event_id, survey_id, role_id, group_id), plus instruments, repeating setup and fields.
 *
 * Only project configuration is included - never record data. Roles are read with a narrow SELECT
 * (role_id, role_name, unique_role_name) rather than UserRights::getRoles(), which has side effects.
 */
class ProjectContext
{
	const MAX_LABEL_CHARS = 100;

	private $module;
	private $projectId;
	private $project;
	private $draftMode;
	private $metadata;
	private $forms;

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

	public function render(): string
	{
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

		// Fields
		$out[] = '';
		$out[] = '## Fields by instrument (field_name | type[, validation] | label | choices as code=label)';
		foreach ($this->forms as $form => $formInfo) {
			$out[] = "### $form";
			foreach (array_keys($formInfo['fields'] ?? []) as $field) {
				$attr = $this->metadata[$field] ?? null;
				if ($attr !== null) $out[] = $this->renderField($field, $attr);
			}
		}
		return implode("\n", $out) . "\n";
	}

	private function dataTable(): string
	{
		if (class_exists('\Records') && method_exists('\Records', 'getDataTable')) {
			try { return \Records::getDataTable($this->projectId); } catch (\Throwable $e) {}
		}
		return 'redcap_data';
	}

	private function renderField(string $field, array $attr): string
	{
		$type = $attr['element_type'];
		$typeNames = ['select' => 'dropdown', 'textarea' => 'notes', 'advcheckbox' => 'checkbox', 'sql' => 'dynamic sql'];
		$typeName = $typeNames[$type] ?? $type;
		if ($type === 'text' && ($attr['element_validation_type'] ?? '') !== '') $typeName .= ', ' . $attr['element_validation_type'];
		$line = "- $field | $typeName | " . $this->clean($attr['element_label']);
		if (in_array($type, ['select', 'radio', 'checkbox', 'advcheckbox', 'yesno', 'truefalse'], true)) {
			$parts = [];
			foreach (parseEnum((string)$attr['element_enum']) as $code => $label) $parts[] = $code . '=' . $this->clean($label, 40);
			$line .= ' | ' . implode('; ', $parts);
		}
		return $line;
	}

	private function clean($text, int $max = self::MAX_LABEL_CHARS): string
	{
		$text = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string)$text, ENT_QUOTES))));
		return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
	}
}
