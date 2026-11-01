/**
 * AdminKit — Username Changer (profile.php / user-edit.php).
 *
 * Promotes the natively-disabled `#user_login` field to a *locked* state
 * (grayed, readonly) paired with an explicit "Change username" button and an
 * inline accessible confirmation group. Focusing or Tab-navigating past
 * `#user_login` never interrupts keyboard or screen-reader users with a
 * blocking browser alert.
 */
(function () {
	function boot() {
		var L = window.AdminKitUsernameChanger;
		if (!L || !L.unlockConfirm || !L.unlockedHint || !L.unlockLabel || !L.confirmAction || !L.cancelAction) { return; }

		var row = document.querySelector('tr.user-user-login-wrap');
		if (!row) { return; }
		var input = row.querySelector('input#user_login');
		if (!input) { return; }
		var td = input.closest('td');
		if (!td) { return; }

		input.dataset.adminkitOriginal = input.value;
		input.removeAttribute('disabled');
		input.setAttribute('readonly', '');
		input.classList.add('adminkit-uc-locked');

		var description = td.querySelector('.description');
		if (description) { description.remove(); }
		description = null;

		var trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = 'button button-secondary adminkit-uc-trigger';
		trigger.setAttribute('aria-expanded', 'false');
		trigger.textContent = L.unlockLabel;

		var confirmBox = document.createElement('div');
		confirmBox.className = 'adminkit-uc-confirm';
		confirmBox.setAttribute('role', 'group');
		confirmBox.setAttribute('aria-label', L.unlockLabel);
		confirmBox.hidden = true;

		var confirmText = document.createElement('p');
		confirmText.className = 'adminkit-uc-confirm-text';
		confirmText.textContent = L.unlockConfirm;

		var actions = document.createElement('div');
		actions.className = 'adminkit-uc-confirm-actions';

		var acceptBtn = document.createElement('button');
		acceptBtn.type = 'button';
		acceptBtn.className = 'button button-primary adminkit-uc-confirm-accept';
		acceptBtn.textContent = L.confirmAction;

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'button button-secondary adminkit-uc-confirm-cancel';
		cancelBtn.textContent = L.cancelAction;

		actions.appendChild(acceptBtn);
		actions.appendChild(cancelBtn);
		confirmBox.appendChild(confirmText);
		confirmBox.appendChild(actions);

		td.appendChild(trigger);
		td.appendChild(confirmBox);

		function openConfirm() {
			trigger.hidden = true;
			trigger.setAttribute('aria-expanded', 'true');
			confirmBox.hidden = false;
			acceptBtn.focus();
		}

		function closeConfirm() {
			confirmBox.hidden = true;
			trigger.hidden = false;
			trigger.setAttribute('aria-expanded', 'false');
			trigger.focus();
		}

		trigger.addEventListener('click', openConfirm);
		cancelBtn.addEventListener('click', closeConfirm);
		confirmBox.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				e.preventDefault();
				closeConfirm();
			}
		});

		acceptBtn.addEventListener('click', function () {
			trigger.remove();
			confirmBox.remove();
			input.removeAttribute('readonly');
			input.classList.remove('adminkit-uc-locked');

			description = document.createElement('p');
			description.className = 'description adminkit-uc-active';
			description.textContent = L.unlockedHint;
			td.appendChild(description);

			input.focus();
			input.setSelectionRange(input.value.length, input.value.length);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
