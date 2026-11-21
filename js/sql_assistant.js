/**
 * SQL Assistant - adds a "Generate query" panel for super users:
 *  - dsql: in the editor dialog core opens for a Dynamic SQL field's query (openLogicEditor on #element_enum
 *          while the field type is "sql"), in the Online Designer;
 *  - dqt:  above the query box (#query) on the Control Center's Database Query Tool.
 *
 * Generated SQL is only ever inserted as text into the editor when the user clicks Replace or Insert.
 * Nothing is run.
 */
window.IntersectSqlAssistant = (function () {
	'use strict';

	let jsmo = null;
	let mode = null;
	let currentOpener = null;

	function init(moduleObject, config, isRetry) {
		jsmo = moduleObject;
		mode = config && config.mode;
		username = (config && config.username) || '';

		if (mode === 'dqt') {
			$(function () { injectDqtPanel(); });
			return;
		}
		if (mode !== 'dsql') return;
		if (typeof window.openLogicEditor !== 'function') {
			// Project.js may not have loaded yet when the hook output runs; try once more when the DOM is ready
			if (!isRetry) $(function () { init(moduleObject, config, true); });
			return;
		}
		if (window.openLogicEditor.__sqlAssistantWrapped) return;
		const original = window.openLogicEditor;
		const wrapped = function (opener) {
			const result = original.apply(this, arguments);
			currentOpener = (opener instanceof HTMLElement) ? $(opener) : opener;
			setTimeout(injectDsqlPanel, 50);
			return result;
		};
		wrapped.__sqlAssistantWrapped = true;
		window.openLogicEditor = wrapped;
	}

	function isDynamicSqlEditor() {
		return currentOpener && currentOpener.attr && currentOpener.attr('id') === 'element_enum'
			&& $('#field_type').val() === 'sql';
	}

	/* ---------- Placement ---------- */

	function injectDsqlPanel() {
		const parent = $('#rc-ace-editor-parent');
		if (!parent.length || parent.find('.isa-panel').length || !isDynamicSqlEditor()) return;
		parent.prepend(buildPanel(
			'e.g. "List the records in this project as value, with first and last name as the label, sorted by last name"',
			function () {
				const editor = getAceEditor();
				return editor ? editor.getSession().getValue() : '';
			},
			function (sql, how) {
				const editor = getAceEditor();
				if (!editor) return;
				if (how === 'replace') editor.getSession().setValue(sql); else editor.insert(sql);
				editor.focus();
			}
		));
	}

	function injectDqtPanel() {
		const $query = $('#query');
		if (!$query.length || $('.isa-panel').length) return;
		const $panel = buildPanel(
			'e.g. "Projects in production with no user rights changes in the last 12 months, with their PI and last logged event"',
			function () { return $query.val(); },
			function (sql, how) {
				const el = $query.get(0);
				if (how === 'replace') {
					$query.val(sql);
				} else {
					const start = el.selectionStart ?? el.value.length;
					const end = el.selectionEnd ?? el.value.length;
					el.value = el.value.slice(0, start) + sql + el.value.slice(end);
					el.selectionStart = el.selectionEnd = start + sql.length;
				}
				$query.trigger('input').trigger('change').trigger('focus');
			}
		);
		// Place above the "Query" label that precedes the textarea
		const $label = $query.prev('div');
		($label.length ? $label : $query).before($panel);
	}

	function getAceEditor() {
		try { return ace.edit('rc-ace-editor'); } catch (e) { return null; } // returns the existing instance
	}

	/* ---------- Panel ---------- */

	function buildPanel(placeholder, getExisting, apply) {
		const $panel = $(
			'<div class="isa-panel">' +
				'<button type="button" class="btn btn-xs btn-primaryrc isa-toggle"><i class="fas fa-wand-magic-sparkles"></i> Generate query with AI</button>' +
				'<div class="isa-body" style="display:none;">' +
					'<textarea class="form-control isa-request" rows="3" maxlength="2000"></textarea>' +
					'<div class="isa-row">' +
						'<button type="button" class="btn btn-xs btn-rcgreen isa-submit"><i class="fas fa-paper-plane"></i> Generate</button>' +
						'<span class="isa-status"></span>' +
					'</div>' +
					'<div class="isa-result" style="display:none;"></div>' +
					'<div class="isa-note"><i class="fas fa-shield-halved"></i> Only the database <b>schema</b> (table and column definitions)' +
						(mode === 'dsql' ? ' and this project\'s structure (fields, events, arms, instruments, roles and DAGs)' : '') +
						' is sent to the AI service, never data from the tables. Generated queries are not run.</div>' +
				'</div>' +
			'</div>'
		);
		$panel.find('.isa-request').attr('placeholder', placeholder);
		$panel.find('.isa-toggle').on('click', function () {
			withDisclaimerAccepted(function () {
				$panel.find('.isa-body').slideToggle(150, function () {
					if ($(this).is(':visible')) $panel.find('.isa-request').trigger('focus');
				});
			});
		});
		const submit = function () { generate($panel, getExisting, apply); };
		$panel.find('.isa-submit').on('click', submit);
		$panel.find('.isa-request').on('keydown', function (e) {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); submit(); }
		});
		return $panel;
	}

	/* ---------- Disclaimer (accepted once per browser, remembered for a year) ---------- */

	// Bump the version to ask everyone to accept again after the disclaimer text changes
	const DISCLAIMER_COOKIE_BASE = 'intersect_sql_assistant_disclaimer_v1';
	let username = '';

	// One cookie per REDCap user, so each user on a shared browser accepts for themselves.
	// The username is URI-encoded, which keeps the cookie name valid and unambiguous.
	function disclaimerCookieName() {
		return DISCLAIMER_COOKIE_BASE + '_' + encodeURIComponent(username);
	}
	const DISCLAIMER_HTML =
		'<p><b>Before using the SQL Assistant, please read and accept the following.</b></p>' +
		'<ul>' +
			'<li>Queries are <b>generated by AI and may be incorrect</b>, even when they pass the module\'s checks. ' +
				'You are responsible for reviewing, verifying and testing any query before running or saving it.</li>' +
			'<li>Generated queries are never run by the assistant. Running a query against the REDCap database, ' +
				'and any Dynamic SQL field that runs every time a form is opened, is your responsibility.</li>' +
			'<li>Your request, any query already in the editor, and the REDCap database <b>schema</b> (table and column definitions) ' +
				'are sent to the GenAI service configured by your REDCap administrators. For Dynamic SQL fields, the project\'s structure ' +
				'(fields, events, arms, instruments, roles and Data Access Groups) is also sent. <b>No data from the tables is sent.</b></li>' +
			'<li><b>Do not include identifying or sensitive information in your request.</b></li>' +
			'<li>Use of the assistant is subject to your institution\'s policies on the use of AI.</li>' +
		'</ul>';

	function disclaimerAccepted() {
		return document.cookie.split('; ').some(function (c) { return c === disclaimerCookieName() + '=1'; });
	}

	function rememberDisclaimer() {
		const secure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = disclaimerCookieName() + '=1; Max-Age=' + (365 * 24 * 60 * 60) + '; Path=/; SameSite=Lax' + secure;
	}

	function withDisclaimerAccepted(callback) {
		if (disclaimerAccepted()) { callback(); return; }
		const $dlg = $('<div class="isa-disclaimer">').html(DISCLAIMER_HTML);
		$dlg.dialog({
			title: 'SQL Assistant: AI disclaimer',
			modal: true,
			width: 580,
			close: function () { $dlg.dialog('destroy').remove(); },
			buttons: [
				{ text: 'Cancel', click: function () { $dlg.dialog('close'); } },
				{ text: 'I accept', class: 'font-weight-bold', click: function () {
					rememberDisclaimer();
					$dlg.dialog('close');
					callback();
				} }
			]
		});
	}

	function generate($panel, getExisting, apply) {
		const request = $.trim($panel.find('.isa-request').val());
		if (!request) { $panel.find('.isa-request').trigger('focus'); return; }

		const $btn = $panel.find('.isa-submit').prop('disabled', true);
		$panel.find('.isa-status').html('<i class="fas fa-spinner fa-spin"></i> Generating&hellip; (this can take a little while)');
		$panel.find('.isa-result').hide().empty();

		jsmo.ajax('generate', { mode: mode, request: request, existing_sql: getExisting() || '' }).then(function (resp) {
			$panel.find('.isa-status').empty();
			if (!resp || resp.error) {
				showError($panel, (resp && resp.error) || 'Something went wrong. Please try again.');
				return;
			}
			showResult($panel, resp, apply);
		}).catch(function (err) {
			$panel.find('.isa-status').empty();
			showError($panel, 'The request failed: ' + (err && err.message ? err.message : err));
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	}

	function showError($panel, message) {
		$panel.find('.isa-result').empty().append($('<div class="isa-error">').text(message)).show();
	}

	function showResult($panel, resp, apply) {
		const $r = $panel.find('.isa-result').empty();
		if (!resp.sql) {
			$r.append($('<div class="isa-clarify">').text(resp.clarification || resp.explanation || 'The assistant could not generate a query for that request. Try adding more detail.'));
			$r.show();
			return;
		}

		$r.append(
			'<div class="isa-ai-notice" role="note"><i class="fas fa-robot"></i> <b>AI-generated query.</b> ' +
			'This SQL was generated by AI and may be incorrect. Review it, verify that it matches your intent, ' +
			'and test it before relying on it' + (mode === 'dsql' ? ', including on a record' : '') + '.</div>'
		);
		$r.append($('<pre class="isa-sql">').text(resp.sql));
		if (resp.explanation) $r.append($('<div class="isa-explanation">').text(resp.explanation));
		appendList($r, 'Assumptions', resp.assumptions, 'isa-assumptions');
		appendList($r, 'Check before using', resp.errors, 'isa-errors');
		appendList($r, 'Note', resp.warnings, 'isa-warnings');
		if (resp.clarification) $r.append($('<div class="isa-clarify">').text(resp.clarification));

		const $actions = $('<div class="isa-row">');
		$('<button type="button" class="btn btn-xs btn-defaultrc"><i class="fas fa-right-left"></i> Replace query</button>')
			.on('click', function () { apply(resp.sql, 'replace'); }).appendTo($actions);
		$('<button type="button" class="btn btn-xs btn-defaultrc"><i class="fas fa-i-cursor"></i> Insert at cursor</button>')
			.on('click', function () { apply(resp.sql, 'insert'); }).appendTo($actions);
		$('<button type="button" class="btn btn-xs btn-defaultrc"><i class="fas fa-copy"></i> Copy</button>')
			.on('click', function () { if (navigator.clipboard) navigator.clipboard.writeText(resp.sql); }).appendTo($actions);
		$r.append($actions).show();
	}

	function appendList($container, title, items, cls) {
		if (!items || !items.length) return;
		const $ul = $('<ul>');
		items.forEach(function (item) { $ul.append($('<li>').text(item)); });
		$container.append($('<div>').addClass(cls).append($('<b>').text(title + ':')).append($ul));
	}

	return { init: init };
})();
