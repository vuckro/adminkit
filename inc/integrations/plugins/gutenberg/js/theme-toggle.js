/**
 * AdminKit — light/dark toggle inside the block editor.
 *
 * The admin-bar theme toggle is hidden in the fullscreen editor, so this injects
 * a matching sun/moon button into the editor header. It flips the SAME
 * `data-adminkit-theme` attribute + localStorage key as the admin-bar toggle
 * (passed in via window.AdminKitEditorToggle), so the editor chrome re-cascades
 * through the --ak-* tokens and the choice is shared everywhere. Vanilla DOM, no
 * build step; polls for the header because the editor mounts its React app late.
 */
(function () {
	var D = window.AdminKitEditorToggle || {};
	var ATTR = D.attr || 'data-adminkit-theme';
	var KEY = D.key || 'adminkit-theme';
	var LABEL = D.label || 'Toggle light / dark mode';
	var root = document.documentElement;

	var SUN = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.25a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-1.5 0V3a.75.75 0 0 1 .75-.75ZM7.5 12a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0Zm11.394-5.834a.75.75 0 0 0-1.06-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM21.75 12a.75.75 0 0 1-.75.75h-2.25a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 .75.75Zm-3.916 6.894a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 1 0-1.061 1.06l1.59 1.591ZM12 18a.75.75 0 0 1 .75.75V21a.75.75 0 0 1-1.5 0v-2.25A.75.75 0 0 1 12 18Zm-4.242-.697a.75.75 0 0 0-1.061-1.06l-1.591 1.59a.75.75 0 0 0 1.06 1.061l1.591-1.59ZM6 12a.75.75 0 0 1-.75.75H3a.75.75 0 0 1 0-1.5h2.25A.75.75 0 0 1 6 12Zm.697-4.243a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 0 0-1.061 1.06l1.59 1.591Z"/></svg>';
	var MOON = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd"/></svg>';

	function isDark() { return root.getAttribute(ATTR) === 'dark'; }

	function render(btn) {
		btn.innerHTML = isDark() ? MOON : SUN;
		btn.setAttribute('aria-pressed', isDark() ? 'true' : 'false');
	}

	function makeButton() {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'ak-editor-theme-toggle components-button has-icon';
		b.setAttribute('aria-label', LABEL);
		b.title = LABEL;
		render(b);
		b.addEventListener('click', function () {
			var next = isDark() ? 'light' : 'dark';
			root.setAttribute(ATTR, next);
			try { localStorage.setItem(KEY, next); } catch (e) {}
			render(b);
		});
		// Keep the icon in sync if the mode flips elsewhere (another tab, the admin
		// bar when visible). Reads the attribute, never writes it — no loop.
		new MutationObserver(function () { render(b); })
			.observe(root, { attributes: true, attributeFilter: [ATTR] });
		return b;
	}

	// The header (post + site editor) mounts after first paint — poll briefly.
	var tries = 0;
	var timer = setInterval(function () {
		var bar = document.querySelector('.editor-header__settings, .edit-post-header__settings');
		if (bar) {
			clearInterval(timer);
			if (!bar.querySelector('.ak-editor-theme-toggle')) {
				bar.insertBefore(makeButton(), bar.firstChild);
			}
		} else if (++tries > 60) {
			clearInterval(timer);
		}
	}, 250);
})();
