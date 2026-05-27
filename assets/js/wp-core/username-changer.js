/**
 * AdminKit — Username Changer (profile.php / user-edit.php).
 *
 * Promotes the natively-disabled `#user_login` field to a *locked* state
 * (grayed, readonly) that surfaces a confirmation dialog before becoming
 * editable. The rename rides WordPress's native "Update User" submit — no
 * separate Save button — so saving stays in one place. Server-side
 * validation + the actual rename live in `class-username-changer.php`.
 *
 * State transitions:
 *
 *   disabled (WP default)
 *        │  on boot:
 *        │   - remove [disabled]
 *        │   - add [readonly] + .adminkit-uc-locked
 *        ▼
 *   locked (looks disabled, clickable)
 *        │  on click / focus:
 *        │   - window.confirm() — danger acknowledgement
 *        │   - if confirmed: remove [readonly] + class
 *        ▼
 *   editable
 *        │  user types, clicks WP's native "Update User"
 *        ▼
 *   submitted (server validates + applies; if invalid WP shows the error)
 *
 * If anything we expect is missing (row not present, input gone), we bail
 * silently and the page falls back to WordPress's native read-only state.
 * There is no scenario where our JS quietly *enables* a field on a row we
 * couldn't validate.
 */
(function () {
	function boot() {
		var L = window.AdminKitUsernameChanger || {};

		var row = document.querySelector('tr.user-user-login-wrap');
		if (!row) { return; }
		var input = row.querySelector('input#user_login');
		if (!input) { return; }
		var td = input.closest('td');
		if (!td) { return; }

		// Capture the original value so we have an authoritative baseline; the
		// server reads $_POST['user_login'] but the JS also needs it to drop
		// us back to the locked state if the user changes their mind.
		input.dataset.adminkitOriginal = input.value;

		// disabled → readonly. Why: disabled fields aren't submitted by the
		// browser AND can't receive click events in most browsers — neither
		// works for our flow. readonly inputs are submitted with the form and
		// remain focusable / clickable, which is exactly what we want for the
		// "click to acknowledge" gate below.
		input.removeAttribute('disabled');
		input.setAttribute('readonly', '');
		input.classList.add('adminkit-uc-locked');

		// Drop WP's "Usernames cannot be changed." description — the grayed
		// field with a pointer cursor is enough visual cue in the locked
		// state. We re-create a .description after unlock to surface the
		// "type then save" hint.
		var description = td.querySelector('.description');
		if (description) { description.remove(); }
		description = null;

		input.addEventListener('click', maybeUnlock);
		input.addEventListener('focus', maybeUnlock);
		input.addEventListener('keydown', function (e) {
			// Catch keyboard activation (Enter / Space when focused on the
			// readonly input) and treat it as a click. Edge cases — most
			// keyboards just focus the input on Tab, but be safe.
			if (e.key === 'Enter' || e.key === ' ') {
				maybeUnlock(e);
			}
		});

		function maybeUnlock(e) {
			if (!input.hasAttribute('readonly')) { return; }
			// `confirm()` is a hard modal — the right pattern for a
			// destructive operation. A custom modal would also work but adds
			// markup and DOM management for no real UX gain here.
			if (!window.confirm(L.unlockConfirm || 'Renaming a user invalidates every active sign-in they have. Continue?')) {
				input.blur();
				return;
			}
			input.removeAttribute('readonly');
			input.classList.remove('adminkit-uc-locked');

			// Surface the "type then save" hint only after unlock — the
			// locked state stays silent (grayed field + pointer cursor is
			// enough visual cue).
			description = document.createElement('p');
			description.className = 'description adminkit-uc-active';
			description.textContent = L.unlockedHint || 'Type the new username, then click "Update User" below.';
			td.appendChild(description);

			// Browser focus events on already-focused readonly inputs are
			// finicky — re-focus to put the caret in the input now that it's
			// editable, regardless of which event triggered us.
			input.focus();
			input.setSelectionRange(input.value.length, input.value.length);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
