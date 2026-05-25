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
 * Discussion is special-cased: it doesn't use a real `<h2>` per section but packs
 * seven titled groups into the `<tr>` rows of one `<table class="form-table
 * indent-children">`. That `indent-children` table (unique to this screen among
 * the six) is "exploded" — each `<th>`-titled row is moved into its own single-
 * row `.form-table` and becomes its own tab — so the screen tabs cleanly instead
 * of collapsing every group into one giant panel.
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
	// `settings_fields()` opens every options form with bare `<input type="hidden">`
	// (option_page, action, the nonce). They carry no UI, so they must never count
	// as a section's "content" — a leading run of only these would otherwise become
	// an empty "General" tab (this is why Media showed a content-less first tab).
	// They still have to stay inside the form, so callers re-home them rather than
	// drop them.
	function isHiddenField(node) {
		return node.nodeType === 1 && node.tagName === 'INPUT' &&
			(node.getAttribute('type') || '').toLowerCase() === 'hidden';
	}

	// The Discussion screen is the odd one out: instead of a real `<h2>` per
	// section it crams SEVEN distinct titled groups ("Default post settings",
	// "Other comment settings", "Comment Pagination", "Email me whenever", …)
	// into the `<tr>` rows of a SINGLE `<table class="form-table indent-children">`,
	// each row carrying its title as a `<th scope="row">`. Splitting only on
	// `<h2>` would therefore collapse all seven into one giant unusable tab. WP
	// uses the `indent-children` class on exactly this table (and on no other core
	// options table), so it's a reliable, self-documenting signal: treat each such
	// table as a mini section list, one tab per `<th>`-titled row.
	function isExplodableTable(node) {
		return node.nodeType === 1 && node.tagName === 'TABLE' &&
			node.classList.contains('form-table') &&
			node.classList.contains('indent-children');
	}
	// Pull the row's section title from its `<th>` (the `screen-reader-text`
	// `<legend>` inside the fieldset mirrors it, but the `<th>` is the visible
	// one). Falls back to empty so the caller can drop title-less rows in safely.
	function rowLabel(tr) {
		var th = tr.querySelector(':scope > th');
		return th ? (th.textContent || '').trim() : '';
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

	// Leading tables (no heading) become a synthesized "General" section, first —
	// BUT only if the leading run holds something visible. On screens whose first
	// content is an `<h2>` (Media), the only leading nodes are the `settings_fields`
	// hidden inputs; those must stay in the form yet must NOT spawn an empty
	// "General" tab, so fold them into the first real section instead. If there's no
	// section to fold into (a heading-less screen), keep the General section so the
	// fields (and any stray markup) are never dropped.
	if (leading.length) {
		var leadingHasContent = leading.some(function (n) { return !isHiddenField(n); });
		if (leadingHasContent || !sections.length) {
			sections.unshift({ header: null, label: GENERAL_LABEL, body: leading });
		} else {
			sections[0].body = leading.concat(sections[0].body);
		}
	}

	// Explode any `indent-children` table (the Discussion comment-settings table)
	// into one section per `<th>`-titled row, so each group gets its own tab. Each
	// row is re-homed into a fresh single-row `.form-table` that copies the source
	// table's classes (minus `indent-children`) so all the card + row-grid CSS in
	// options.css keeps matching unchanged. Rows are MOVED, never cloned, so every
	// field keeps its `name` and the one form still posts them all on Save.
	//
	// The table is NOT necessarily the section's only body node: `settings_fields()`
	// prints the option_page / action / nonce `<input type="hidden">` at the top of
	// the form, so on Discussion the synthesized "General" section is actually
	// `[…hidden inputs…, indent-children table]`. We therefore SCAN the body for the
	// explodable table rather than requiring it to be the lone element (the old
	// `body.length === 1` guard silently disabled the explode on the live screen).
	// Any non-table body siblings (those hidden inputs, plus, defensively, anything
	// else) ride with the FIRST resulting section so they stay in the form and
	// nothing is dropped. Tables without ≥2 title-bearing `<th>` rows, and sections
	// with no explodable table at all, pass straight through unchanged.
	sections = sections.reduce(function (acc, sect) {
		var table = null;
		var others = [];
		sect.body.forEach(function (node) {
			if (!table && isExplodableTable(node)) { table = node; }
			else { others.push(node); }
		});
		var rows = table ? Array.prototype.slice.call(table.querySelectorAll(':scope > tbody > tr, :scope > tr')) : [];
		var titled = rows.filter(function (tr) { return rowLabel(tr); });
		// Need a real heading-per-row split to be worthwhile: ≥2 titled rows.
		if (!table || titled.length < 2) {
			acc.push(sect);
			return acc;
		}
		// Carry the non-table siblings (hidden inputs) into the first section so
		// they remain inside the form; `prepend` tracks whether that's still owed.
		var prepend = others;
		rows.forEach(function (tr) {
			var label = rowLabel(tr);
			var holder = document.createElement('table');
			// Mirror the source table's look minus the explode marker; the
			// per-screen card/grid CSS keys off `.ak-options-panel > .form-table`.
			// `ak-options-exploded` flags it so options.css can drop the now-
			// redundant row label (the tab already names the section) and give the
			// controls the full card width.
			holder.className = (table.className.replace(/\bindent-children\b/, '').trim() +
				' ak-options-exploded').trim();
			holder.setAttribute('role', 'presentation');
			var tbody = document.createElement('tbody');
			tbody.appendChild(tr); // MOVE the row (keeps every field + its name)
			holder.appendChild(tbody);
			if (label) {
				acc.push({ header: null, label: label, body: prepend.concat([holder]) });
				prepend = []; // hidden inputs placed once, on the first real section
			} else if (acc.length) {
				// Untitled stray row — keep it visible by folding it into the
				// previous section rather than orphaning it in a nameless tab.
				acc[acc.length - 1].body.push(holder);
			} else {
				acc.push({ header: null, label: sect.label, body: prepend.concat([holder]) });
				prepend = [];
			}
		});
		// If every row was untitled (no section ever opened) the leftover hidden
		// inputs would be lost — re-home them into the last section as a safety net.
		if (prepend.length && acc.length) {
			Array.prototype.push.apply(acc[acc.length - 1].body, prepend);
		}
		// All rows were MOVED out, so the source table is now an empty shell still
		// sitting in the form — drop it, or options.css would paint it as a blank
		// card (and the no-JS fallback rule also targets `.wrap > form > .form-table`).
		if (table.parentNode) { table.parentNode.removeChild(table); }
		return acc;
	}, []);

	// Drop empty sections (a heading with no body — nothing to show in a panel).
	sections = sections.filter(function (s) { return s.body.length; });
	if (!sections.length) { return; }

	var used = {};
	function uid(base) {
		var id = base, i = 2;
		while (used[id]) { id = base + '-' + (i++); }
		used[id] = 1;
		return id;
	}

	// Build the `.ak-options-panel` card for a section: its header `<h2>` (if any)
	// rides along as the panel heading and the body nodes are MOVED in (every field
	// keeps its `name`, so the one form still posts them all). options.css keys its
	// whole card/grid system off `.ak-options-panel > …`, so the panel wrapper is
	// what makes a section render as a styled card — with OR without a tab strip.
	function buildPanel(sect, i) {
		var slug = (sect.label || '').toLowerCase()
			.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		var id = uid('ak-options-' + (slug || 'sect-' + i));
		var panel = document.createElement('div');
		panel.className = 'ak-options-panel';
		panel.id = id;
		if (sect.header) { panel.appendChild(sect.header); }
		sect.body.forEach(function (node) { panel.appendChild(node); });
		return { id: id, label: sect.label, header: sect.header, panel: panel };
	}

	// --- single section: NO tab strip ------------------------------------------
	// A one-tab strip is meaningless — a lone pill button floating under the page
	// title reads as a stray, raw control, not navigation. This is the steady
	// state of Reading (its whole form is ONE `.form-table`, no `<h2>` sections)
	// and of Writing whenever its optional `<h2>` blocks ("Post via email",
	// "Update Services") are filtered off. When there's only one section, drop its
	// card straight into the form as a plain, always-visible panel: still a styled
	// card (options.css `.ak-options-panel > …` matches), no tablist, no roving
	// tabindex, nothing to switch. The native submit row stays below it.
	if (sections.length === 1) {
		var only = buildPanel(sections[0], 0);
		form.insertBefore(only.panel, form.firstChild);
		return;
	}

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

	var built = sections.map(function (sect, i) {
		// Panel: a real tabpanel element holding the section's moved nodes. The
		// header <h2> rides along (re-used as the panel heading) so card CSS keeps
		// matching; a synthesized section gets no header element (the tab names it).
		var info = buildPanel(sect, i);
		var id = info.id;
		var panel = info.panel;
		panel.setAttribute('role', 'tabpanel');
		panel.setAttribute('aria-labelledby', 'tab-' + id);
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
