/**
 * AdminKit — user Quick Edit on users.php.
 *
 * Wires each row's "Quick Edit" button to a hidden <template> form that opens
 * inline, mirroring the post Quick Edit pattern. Saves via admin-ajax.php; on
 * success the JS repaints the row's visible cells (name / email / role)
 * without a full page reload.
 *
 * Implementation notes:
 *   - Event delegation on document. Robust against any order in which the
 *     buttons and template land in the DOM, and survives dynamic row
 *     replacement (the row's button is the same element across edits).
 *   - DOMContentLoaded gate. The script ships as a footer asset so the DOM is
 *     ready in practice, but the gate keeps it safe under unusual loaders.
 *   - One editor open at a time; opening a second one closes the first. Escape
 *     closes the editor.
 *   - Server-supplied data-* attrs on the trigger button mean opening the form
 *     needs no extra request — current values are already on the page.
 *
 * Disable the whole feature via AdminKit → Features → Users quick edit; the
 * PHP side then never enqueues this script and never renders the template.
 */
(function () {
	function boot() {
		var L = window.AdminKitUserQuickEdit || {};
		if (!L.ajaxUrl) { return; }

		var template = document.getElementById('adminkit-quick-edit-template');
		if (!template) {
			if (window.console && console.warn) {
				console.warn('AdminKit Quick Edit: template element missing.');
			}
			return;
		}

		var openRow = null;       // Original <tr> we hid.
		var openEditor = null;    // Inline editor <tr> we inserted.
		var openButton = null;    // The Quick Edit button that opened it.

		// Delegated click handler — works regardless of when buttons land in the
		// DOM (e.g. after we replace a row's HTML, the new button stays wired).
		document.addEventListener('click', function (e) {
			var btn = e.target && e.target.closest ? e.target.closest('.adminkit-qe-open') : null;
			if (!btn) { return; }
			e.preventDefault();
			openEditorFor(btn);
		});

		function onEscape(e) {
			if (e.key === 'Escape') { e.preventDefault(); closeEditor(); }
		}

		function closeEditor() {
			if (openEditor) { openEditor.remove(); openEditor = null; }
			if (openRow) { openRow.style.display = ''; openRow = null; }
			if (openButton) {
				openButton.setAttribute('aria-expanded', 'false');
				openButton.focus();
				openButton = null;
			}
			document.removeEventListener('keydown', onEscape);
		}

		function openEditorFor(btn) {
			closeEditor();
			var row = btn.closest('tr');
			if (!row) { return; }

			var editor = template.content.cloneNode(true).firstElementChild;
			if (!editor) { return; }

			fill(editor, '.adminkit-qe-first-name', btn.dataset.firstName || '');
			fill(editor, '.adminkit-qe-last-name', btn.dataset.lastName || '');
			fill(editor, '.adminkit-qe-email', btn.dataset.email || '');
			fill(editor, '.adminkit-qe-role', btn.dataset.role || '');

			var cancel = editor.querySelector('.adminkit-qe-cancel');
			if (cancel) { cancel.addEventListener('click', closeEditor); }

			var save = editor.querySelector('.adminkit-qe-save');
			if (save) { save.addEventListener('click', function () { saveEditor(btn, editor, row); }); }

			row.parentNode.insertBefore(editor, row.nextSibling);
			row.style.display = 'none';
			btn.setAttribute('aria-expanded', 'true');

			openRow = row;
			openEditor = editor;
			openButton = btn;

			var first = editor.querySelector('input, select');
			if (first) { first.focus(); }
			document.addEventListener('keydown', onEscape);
		}

		function fill(scope, sel, value) {
			var el = scope.querySelector(sel);
			if (el) { el.value = value; }
		}

		function saveEditor(btn, editor, row) {
			var saveBtn = editor.querySelector('.adminkit-qe-save');
			var cancel = editor.querySelector('.adminkit-qe-cancel');
			var spinner = editor.querySelector('.spinner');
			var errEl = editor.querySelector('.adminkit-qe-error');
			if (errEl) { errEl.textContent = ''; }

			var fd = new FormData();
			fd.append('action', 'adminkit_user_quick_edit');
			fd.append('user_id', btn.dataset.userId);
			fd.append('_wpnonce', btn.dataset.nonce);
			fd.append('first_name', editor.querySelector('.adminkit-qe-first-name').value);
			fd.append('last_name', editor.querySelector('.adminkit-qe-last-name').value);
			fd.append('user_email', editor.querySelector('.adminkit-qe-email').value);
			var roleEl = editor.querySelector('.adminkit-qe-role');
			if (roleEl) { fd.append('role', roleEl.value); }

			if (saveBtn) { saveBtn.disabled = true; }
			if (cancel) { cancel.disabled = true; }
			if (spinner) { spinner.classList.add('is-active'); }

			fetch(L.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json().catch(function () { return null; }); })
				.then(function (json) {
					if (spinner) { spinner.classList.remove('is-active'); }
					if (!json || !json.success) {
						if (saveBtn) { saveBtn.disabled = false; }
						if (cancel) { cancel.disabled = false; }
						var msg = (json && json.data && json.data.message) || L.genericErr || 'Error';
						if (errEl) { errEl.textContent = msg; }
						return;
					}
					applyToRow(row, btn, json.data || {});
					closeEditor();
				})
				.catch(function () {
					if (spinner) { spinner.classList.remove('is-active'); }
					if (saveBtn) { saveBtn.disabled = false; }
					if (cancel) { cancel.disabled = false; }
					if (errEl) { errEl.textContent = L.genericErr || 'Error'; }
				});
		}

		// Repaint the row's visible cells + refresh data-* on the trigger button
		// so the next Quick Edit opens with the fresh values. Best-effort — if a
		// plugin renamed a WP-list-table column class, the cell stays as-is.
		function applyToRow(row, btn, data) {
			btn.dataset.firstName = data.first_name || '';
			btn.dataset.lastName = data.last_name || '';
			btn.dataset.email = data.user_email || '';
			btn.dataset.role = data.role || '';

			setCellText(row, '.column-name', data.name_display || '');
			setEmailCell(row, data.user_email || '');
			setCellText(row, '.column-role', data.role_display || '');
		}

		function setCellText(row, selector, text) {
			var cell = row.querySelector(selector);
			if (cell) { cell.textContent = text; }
		}

		function setEmailCell(row, email) {
			var cell = row.querySelector('.column-email');
			if (!cell) { return; }
			var a = cell.querySelector('a[href^="mailto:"]');
			if (a) {
				a.href = 'mailto:' + email;
				a.textContent = email;
			} else {
				cell.textContent = email;
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
