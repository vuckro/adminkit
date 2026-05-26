/**
 * AdminKit — user Quick Edit on users.php.
 *
 * Wires each row's "Quick Edit" button to a hidden <template> form that opens
 * inline, mirroring the post Quick Edit pattern. Saves via admin-ajax.php;
 * server returns the re-rendered <tr> HTML so the row updates in place
 * without a full page reload.
 *
 * Only one editor open at a time — opening a second one closes the first.
 * Escape closes the editor. Errors render inline next to Save.
 */
(function () {
	var L = window.AdminKitUserQuickEdit || {};
	var template = document.getElementById('adminkit-quick-edit-template');
	if (!template || !L.ajaxUrl) { return; }

	var openButtons = document.querySelectorAll('.adminkit-qe-open');
	if (!openButtons.length) { return; }

	var openRow = null;       // The original <tr> we hid
	var openEditor = null;    // The inline <tr> editor we inserted
	var openButton = null;    // The Quick Edit button we came from (carries data + nonce)

	function close() {
		if (openEditor) { openEditor.remove(); openEditor = null; }
		if (openRow) { openRow.style.display = ''; openRow = null; }
		if (openButton) { openButton.setAttribute('aria-expanded', 'false'); openButton.focus(); openButton = null; }
		document.removeEventListener('keydown', onEscape);
	}

	function onEscape(e) {
		if (e.key === 'Escape') { e.preventDefault(); close(); }
	}

	function open(btn) {
		close();
		var row = btn.closest('tr');
		if (!row) { return; }

		var editor = template.content.cloneNode(true).firstElementChild;
		if (!editor) { return; }

		// Prefill from data-* on the button (server emits the current values).
		fill(editor, '.adminkit-qe-first-name', btn.dataset.firstName || '');
		fill(editor, '.adminkit-qe-last-name', btn.dataset.lastName || '');
		fill(editor, '.adminkit-qe-email', btn.dataset.email || '');
		fill(editor, '.adminkit-qe-role', btn.dataset.role || '');

		editor.querySelector('.adminkit-qe-cancel').addEventListener('click', close);
		editor.querySelector('.adminkit-qe-save').addEventListener('click', function () { save(btn, editor, row); });

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

	function save(btn, editor, row) {
		var saveBtn = editor.querySelector('.adminkit-qe-save');
		var cancel = editor.querySelector('.adminkit-qe-cancel');
		var spinner = editor.querySelector('.spinner');
		var errEl = editor.querySelector('.adminkit-qe-error');
		errEl.textContent = '';

		var fd = new FormData();
		fd.append('action', 'adminkit_user_quick_edit');
		fd.append('user_id', btn.dataset.userId);
		fd.append('_wpnonce', btn.dataset.nonce);
		fd.append('first_name', editor.querySelector('.adminkit-qe-first-name').value);
		fd.append('last_name', editor.querySelector('.adminkit-qe-last-name').value);
		fd.append('user_email', editor.querySelector('.adminkit-qe-email').value);
		var roleEl = editor.querySelector('.adminkit-qe-role');
		if (roleEl) { fd.append('role', roleEl.value); }

		saveBtn.disabled = true;
		cancel.disabled = true;
		if (spinner) { spinner.classList.add('is-active'); }

		fetch(L.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json().catch(function () { return null; }); })
			.then(function (json) {
				if (spinner) { spinner.classList.remove('is-active'); }
				if (!json || !json.success) {
					saveBtn.disabled = false;
					cancel.disabled = false;
					var msg = (json && json.data && json.data.message) || L.genericErr || 'Error';
					errEl.textContent = msg;
					return;
				}
				// Repaint the visible cells + refresh the data-* on the button so
				// the next Quick Edit opens with the new values.
				applyToRow(row, btn, json.data || {});
				close();
			})
			.catch(function () {
				if (spinner) { spinner.classList.remove('is-active'); }
				saveBtn.disabled = false;
				cancel.disabled = false;
				errEl.textContent = L.genericErr || 'Error';
			});
	}

	// Update the row's visible cells + the trigger button's data-* attrs from
	// the server's response. Best-effort — if a plugin renamed a column class
	// the cell is left alone (the next page reload will catch up).
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

	openButtons.forEach(function (btn) {
		btn.addEventListener('click', function () { open(btn); });
	});
})();
