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
	var LABEL = D.label;
	var root = document.documentElement;

	var SUN = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.75\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><circle cx=\"12\" cy=\"12\" r=\"4\" /> <path d=\"M12 2v2\" /> <path d=\"M12 20v2\" /> <path d=\"m4.93 4.93 1.41 1.41\" /> <path d=\"m17.66 17.66 1.41 1.41\" /> <path d=\"M2 12h2\" /> <path d=\"M20 12h2\" /> <path d=\"m6.34 17.66-1.41 1.41\" /> <path d=\"m19.07 4.93-1.41 1.41\" /></svg>";
	var MOON = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.75\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401\" /></svg>";

	function isDark() { return root.getAttribute(ATTR) === 'dark'; }

	function render(btn) {
		// eslint-disable-next-line no-unsanitized/property -- Static inline SUN/MOON SVG constants.
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
	// In WP 7.1+, #wpadminbar remains visible even in .is-fullscreen-mode, so the
	// admin-bar theme toggle is already on screen; only inject into the editor
	// header when the skeleton covers or hides #wpadminbar (top <= 0).
	var tries = 0;
	var timer = setInterval(function () {
		var bar = document.querySelector('.editor-header__settings, .edit-post-header__settings');
		if (bar) {
			clearInterval(timer);
			var skeleton = document.querySelector('.interface-interface-skeleton');
			var ab = document.getElementById('wpadminbar');
			if (ab && ab.offsetHeight > 0 && skeleton && skeleton.getBoundingClientRect().top > 0) {
				return;
			}
			if (!bar.querySelector('.ak-editor-theme-toggle')) {
				bar.insertBefore(makeButton(), bar.firstChild);
			}
		} else if (++tries > 60) {
			clearInterval(timer);
		}
	}, 250);
})();
