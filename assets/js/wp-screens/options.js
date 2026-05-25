/**
 * AdminKit — collapsible sections for the core Settings screens.
 *
 * The six built-in options pages (general, writing, reading, discussion, media,
 * permalink) render as a flat list inside `.wrap > form`: a run of optional
 * leading `.form-table`s, then repeating `<h2 class="title">` headings each
 * followed by that section's body (`<p>` notes, a `.form-table`, the Writing
 * "Update Services" `<textarea>`, …). options.css paints those as cards; this
 * brick makes each card COLLAPSIBLE.
 *
 * It keeps the markup FLAT (no wrapper element) so options.css's `.wrap > form >
 * .form-table` rules keep matching: each heading becomes a real `<button>`
 * toggle (chevron + aria-expanded → keyboard-operable for free), and toggling a
 * section simply shows/hides the sibling body nodes that follow it. Leading
 * tables with no heading get a synthesized header so they collapse too.
 *
 * Sections default to OPEN — the toggle only lets users FOLD groups they don't
 * need; settings are never hidden on load. Open state is remembered per section
 * in localStorage (best-effort). The submit row is left untouched, always shown.
 *
 * i18n labels arrive via `window.AdminKitOptions` (inline bootstrap). Vanilla,
 * no jQuery; degrades to plain open sections if JS is off. Footer script,
 * enqueued only on the six options screens.
 */
(function () {
	var form = document.querySelector('.wrap form');
	if (!form) { return; }

	var DATA = window.AdminKitOptions || {};
	var COLLAPSE_LABEL = DATA.collapse || 'Collapse section';
	var EXPAND_LABEL = DATA.expand || 'Expand section';
	// A synthesized title for the leading table(s) with no own heading (the
	// first table on general / reading / discussion).
	var GENERAL_LABEL = DATA.general || 'General';
	// Per-page key prefix for the localStorage open/closed memory.
	var PAGE = (document.body.className.match(/\b(options-[a-z]+)-php\b/) || [])[1] || 'page';
	var STORE_PREFIX = 'adminkit:options:' + PAGE + ':';

	// Only `<h2 class="title">` starts a new section; any other element is body
	// content belonging to the current section.
	function isHeading(node) {
		return node.nodeType === 1 && node.tagName === 'H2' && node.classList.contains('title');
	}
	// The submit row + trailing hidden fields must never fold — collecting stops
	// at the submit paragraph.
	function isSubmit(node) {
		return node.nodeType === 1 && node.tagName === 'P' && node.classList.contains('submit');
	}

	// localStorage is best-effort (private mode / disabled storage throws).
	function readOpen(key, fallback) {
		try {
			var v = window.localStorage.getItem(key);
			return v === null ? fallback : v === '1';
		} catch (e) { return fallback; }
	}
	function writeOpen(key, open) {
		try { window.localStorage.setItem(key, open ? '1' : '0'); } catch (e) {}
	}

	var sections = [];      // { header: <h2>, body: [el, …] }
	var leading = [];       // body elements before the first heading
	var current = null;
	var child = form.firstChild;

	// Walk the form's direct children once, splitting the flat stream into
	// { header, body[] } sections. Stop at the submit row.
	while (child) {
		var next = child.nextSibling;
		if (child.nodeType === 1 && isSubmit(child)) { break; }
		if (isHeading(child)) {
			current = { header: child, body: [] };
			sections.push(current);
		} else if (child.nodeType === 1) {
			(current ? current.body : leading).push(child);
		}
		child = next; // whitespace text nodes are ignored, left in place
	}

	if (!sections.length && !leading.length) { return; }

	var idSeq = 0;

	// Turn one heading + its body nodes into a collapsible group. `headerEl` is
	// the existing <h2> (re-used as the toggle host) or null → we synthesize one
	// with `synthLabel`, inserted before the first body node.
	function buildGroup(headerEl, bodyNodes, synthLabel, storeKey) {
		if (!bodyNodes.length) { return; } // header with no body — leave as-is

		var open = readOpen(storeKey, true); // default OPEN
		var ids = [];

		// Tag every body node so the toggle can target the exact set (avoids any
		// reliance on sibling order) and aria-controls can reference them.
		bodyNodes.forEach(function (n) {
			if (!n.id) { n.id = 'ak-options-body-' + (idSeq++); }
			n.classList.add('ak-options-section__body');
			ids.push(n.id);
		});

		var host = headerEl;
		if (!host) {
			host = document.createElement('h2');
			host.className = 'title ak-options-section__synth';
			bodyNodes[0].parentNode.insertBefore(host, bodyNodes[0]);
		}
		host.classList.add('ak-options-section__header');

		// A real <button> → focusable + Enter/Space for free.
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'ak-options-section__toggle';
		btn.setAttribute('aria-controls', ids.join(' '));

		// Move the heading's existing text/markup into the label so the whole
		// header is the clickable target; synthesized headers get `synthLabel`.
		var label = document.createElement('span');
		label.className = 'ak-options-section__label';
		if (headerEl) {
			while (host.firstChild) { label.appendChild(host.firstChild); }
		} else {
			label.textContent = synthLabel;
		}

		// Chevron — a CSS-drawn rotated caret (decorative; the button text names it).
		var chevron = document.createElement('span');
		chevron.className = 'ak-options-section__chevron';
		chevron.setAttribute('aria-hidden', 'true');

		btn.appendChild(label);
		btn.appendChild(chevron);
		host.appendChild(btn);

		function apply(isOpen) {
			btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			btn.setAttribute('aria-label', isOpen ? COLLAPSE_LABEL : EXPAND_LABEL);
			host.classList.toggle('is-collapsed', !isOpen);
			bodyNodes.forEach(function (n) { n.hidden = !isOpen; });
		}
		apply(open);

		btn.addEventListener('click', function () {
			open = !open;
			apply(open);
			writeOpen(storeKey, open);
		});
	}

	// Leading tables (no heading) → a synthesized "General" group first.
	if (leading.length) {
		buildGroup(null, leading, GENERAL_LABEL, STORE_PREFIX + 'lead');
	}

	// Each real heading → its own group, keyed by a slug of its text so the
	// memory survives section reorders across WP versions.
	sections.forEach(function (sect, i) {
		var slug = (sect.header.textContent || '').trim().toLowerCase()
			.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		buildGroup(sect.header, sect.body, '', STORE_PREFIX + (slug || 'sect-' + i));
	});
})();
