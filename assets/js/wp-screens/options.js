/**
 * AdminKit — tab navigation for the core Settings screens.
 *
 * The six built-in options pages (general, writing, reading, discussion, media,
 * permalink) render as a flat list inside `.wrap > form`: a run of optional
 * leading `.form-table`s, then repeating section headings (`<h2 class="title">`,
 * plus plain `<h2>` for any section a plugin registers via do_settings_sections)
 * each followed by that section's body (`<p>` notes, a `.form-table`, the Writing
 * "Update Services" `<textarea>`, …), and finally the single `<p class="submit">`
 * Save row. options.css paints those bodies as cards; this brick turns the screen
 * into a TAB strip + one visible panel, mirroring the accounts page
 * (assets/js/wp-core/profile-account.js): each section becomes a tab panel, a tab
 * bar under the page title switches between them, first tab active by default.
 *
 * Fields are never removed — each section's nodes are MOVED into its panel
 * `<div>` (every `<input>` keeps its `name`), and hidden panels are toggled with
 * the `hidden` attribute, so the screen's ONE `<form>` still posts every field on
 * Save regardless of which tab is showing. The submit row is left in place at the
 * form foot, outside the panels, always visible across tabs.
 *
 * Accessibility mirrors the accounts page: `role="tablist"`/`tab`/`tabpanel`,
 * `aria-selected`, `aria-controls`/`aria-labelledby`, roving tabindex and
 * Arrow-Left/Right navigation. The active tab is remembered per-screen in
 * localStorage (best-effort try/catch). With JS off there is no tab bar and every
 * panel is just an open card — no settings are ever hidden.
 *
 * i18n labels arrive via `window.AdminKitOptions` (inline bootstrap). Vanilla, no
 * jQuery. Footer script, enqueued only on the six options screens.
 */
(function () {
	var form = document.querySelector('.wrap form');
	if (!form || form.dataset.akOptions) { return; }
	form.dataset.akOptions = '1';

	var DATA = window.AdminKitOptions || {};
	// A synthesized title for the leading table(s) with no own heading (the
	// first table(s) on general / reading / discussion).
	var GENERAL_LABEL = DATA.general || 'General';
	var NAV_LABEL = DATA.nav || 'Settings sections';
	// Per-page key prefix for the localStorage active-tab memory.
	var PAGE = (document.body.className.match(/\b(options-[a-z]+)-php\b/) || [])[1] || 'page';
	var STORE_KEY = 'adminkit:options:' + PAGE + ':tab';

	// A section starts at a top-level heading: WP prints curated sections as
	// `<h2 class="title">` and plugin-registered ones (do_settings_sections) as a
	// plain `<h2>`; treat both as boundaries. Anything else is body content.
	function isHeading(node) {
		return node.nodeType === 1 && node.tagName === 'H2';
	}
	// The single submit row (+ any trailing hidden fields) must stay at the form
	// foot, outside the panels — collecting stops at the submit paragraph.
	function isSubmit(node) {
		return node.nodeType === 1 && node.tagName === 'P' && node.classList.contains('submit');
	}

	// localStorage is best-effort (private mode / disabled storage throws).
	function readActive() {
		try { return window.localStorage.getItem(STORE_KEY); } catch (e) { return null; }
	}
	function writeActive(id) {
		try { window.localStorage.setItem(STORE_KEY, id); } catch (e) {}
	}

	var sections = [];      // { header: <h2>|null, label: string, body: [el, …] }
	var leading = [];       // body elements before the first heading
	var current = null;
	var child = form.firstChild;

	// Walk the form's direct children once, splitting the flat stream into
	// sections. Stop at the submit row (it and everything after stay put).
	while (child) {
		var next = child.nextSibling;
		if (isSubmit(child)) { break; }
		if (isHeading(child)) {
			current = { header: child, label: (child.textContent || '').trim(), body: [] };
			sections.push(current);
		} else if (child.nodeType === 1) {
			(current ? current.body : leading).push(child);
		}
		child = next; // whitespace text nodes are ignored, left in place
	}

	// Leading tables (no heading) become a synthesized "General" section, first.
	if (leading.length) {
		sections.unshift({ header: null, label: GENERAL_LABEL, body: leading });
	}

	// Drop empty sections (a heading with no body — nothing to show in a panel).
	sections = sections.filter(function (s) { return s.body.length; });
	if (!sections.length) { return; }

	// --- layout shell: tab strip + panels, inserted at the top of the form -----
	// (the native submit row stays below, outside this block).
	var tabs = document.createElement('div');
	tabs.className = 'ak-tabs ak-options-tabs';
	tabs.setAttribute('role', 'tablist');
	tabs.setAttribute('aria-label', NAV_LABEL);

	var panels = document.createElement('div');
	panels.className = 'ak-options-panels';

	form.insertBefore(tabs, form.firstChild);
	form.insertBefore(panels, tabs.nextSibling);

	var used = {};
	function uid(base) {
		var id = base, i = 2;
		while (used[id]) { id = base + '-' + (i++); }
		used[id] = 1;
		return id;
	}

	var built = sections.map(function (sect, i) {
		var slug = (sect.label || '').toLowerCase()
			.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		var id = uid('ak-options-' + (slug || 'sect-' + i));

		// Panel: a real tabpanel element holding the section's moved nodes. The
		// header <h2> rides along (re-used as the panel heading) so card CSS keeps
		// matching; a synthesized section gets no header element (the tab names it).
		var panel = document.createElement('div');
		panel.className = 'ak-options-panel';
		panel.id = id;
		panel.setAttribute('role', 'tabpanel');
		panel.setAttribute('aria-labelledby', 'tab-' + id);
		if (sect.header) { panel.appendChild(sect.header); }
		sect.body.forEach(function (node) { panel.appendChild(node); });
		panels.appendChild(panel);

		// Tab: a real <button> → focusable + Enter/Space for free.
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.id = 'tab-' + id;
		btn.className = 'ak-options-tab';
		btn.dataset.target = id;
		btn.setAttribute('role', 'tab');
		btn.setAttribute('aria-controls', id);
		var tx = document.createElement('span');
		tx.className = 'tx';
		tx.textContent = sect.label;
		btn.appendChild(tx);
		tabs.appendChild(btn);

		return { id: id, btn: btn, panel: panel };
	});

	// --- tabs: show one panel at a time ---------------------------------------
	var buttons = built.map(function (b) { return b.btn; });

	function activate(id, remember) {
		built.forEach(function (b) {
			var on = b.id === id;
			b.btn.classList.toggle('on', on);
			b.btn.setAttribute('aria-selected', on ? 'true' : 'false');
			b.btn.tabIndex = on ? 0 : -1;
			b.panel.hidden = !on;
		});
		if (remember) { writeActive(id); }
	}

	tabs.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		if (b && b.dataset.target) { activate(b.dataset.target, true); }
	});

	// Roving Arrow-Left/Right navigation across the tab strip (mirrors the
	// accounts page): move focus, activate, and remember the new tab.
	tabs.addEventListener('keydown', function (e) {
		if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
		var i = buttons.indexOf(document.activeElement);
		if (i === -1) { return; }
		var next = buttons[(i + (e.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length];
		next.focus();
		activate(next.dataset.target, true);
		e.preventDefault();
	});

	// Restore the remembered tab if it still exists, else default to the first.
	var saved = readActive();
	var initial = built.some(function (b) { return b.id === saved; }) ? saved : built[0].id;
	activate(initial, false);
})();
