/**
 * AdminKit — list-table chrome polish.
 *
 * Wraps each list table in a horizontal-scroll container, sizes Quick/Bulk Edit
 * to the visible width, and strips the "(12)" parentheses + literal " |"
 * separators from the `.subsubsub` filter row so wp-core/chrome.css can style
 * clean links + numeric pills. No-op wherever those elements are absent.
 * Loaded as a footer script on admin pages.
 */
(function () {
	// Wrap each list table in a horizontal-scroll container (.ak-table-scroll):
	// the table stays width:100% so it fills the area + Quick Edit spans full
	// width, while a too-wide table slides inside the wrapper instead of
	// squashing or stacking. The tablenav stays outside (only the table is wrapped).
	Array.prototype.forEach.call(document.querySelectorAll('.wp-list-table'), function (table) {
		var parent = table.parentNode;
		if (!parent || (parent.classList && parent.classList.contains('ak-table-scroll'))) return;
		var wrap = document.createElement('div');
		wrap.className = 'ak-table-scroll';
		parent.insertBefore(wrap, table);
		wrap.appendChild(table);
	});

	// Quick / Bulk Edit: keep the inline-edit form within the visible area on a
	// wide (horizontally scrollable) table. The edit cell spans every column, so
	// (a) publish each wrapper's visible width as --ak-qe-w for the form grid to
	// size against, and (b) wrap the form columns in .ak-qe-grid so CSS can lay
	// them out as a responsive grid instead of letting them ride the wide cell.
	var scrolls = document.querySelectorAll('.ak-table-scroll');
	function setQEWidth(el) { el.style.setProperty('--ak-qe-w', el.clientWidth + 'px'); }
	if (window.ResizeObserver) {
		var ro = new ResizeObserver(function (entries) {
			entries.forEach(function (e) { setQEWidth(e.target); });
		});
		Array.prototype.forEach.call(scrolls, function (el) { ro.observe(el); });
	} else {
		Array.prototype.forEach.call(scrolls, setQEWidth);
		window.addEventListener('resize', function () {
			Array.prototype.forEach.call(scrolls, setQEWidth);
		});
	}
	// Wrap the hidden Quick/Bulk Edit templates once. WP clones them into rows on
	// demand, so the clones inherit both the .ak-qe-grid wrapper and --ak-qe-w
	// (inherited from the .ak-table-scroll the clone lands in).
	['inline-edit', 'bulk-edit'].forEach(function (id) {
		var tmpl = document.getElementById(id);
		var cell = tmpl && tmpl.querySelector('td.colspanchange');
		if (!cell) return;
		var first = cell.firstElementChild;
		if (first && first.classList.contains('ak-qe-grid')) return;
		var grid = document.createElement('div');
		grid.className = 'ak-qe-grid';
		while (cell.firstChild) { grid.appendChild(cell.firstChild); }
		cell.appendChild(grid);
	});

	var lists = document.querySelectorAll('.subsubsub');
	if (!lists.length) return;

	// Status filter icons — small line glyphs prepended to each .subsubsub link,
	// keyed by the WP <li> class. Lucide-style strokes (currentColor, 1.8px) so
	// they inherit the link colour (muted by default → heading on hover/active)
	// without an extra rule per state. role-* (users.php) collapses to the user
	// glyph; everything else not in the map falls back to the "all" list glyph.
	var SUBSUB_ICONS = {
		all:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3.5" y1="6" x2="3.51" y2="6"/><line x1="3.5" y1="12" x2="3.51" y2="12"/><line x1="3.5" y1="18" x2="3.51" y2="18"/></svg>',
		mine:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		publish:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
		approved:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
		draft:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
		moderated: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>',
		pending:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>',
		future:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>',
		'private': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 1 1 8 0v4"/></svg>',
		trash:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>',
		active:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
		inactive:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
		spam:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
		upgrade:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 11 12 5 18 11"/><line x1="12" y1="19" x2="12" y2="5"/></svg>',
		mustuse:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		dropins:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 L 2 7 L 12 12 L 22 7 Z"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
		paused:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
	};

	function classifySubsubLi(li, ul) {
		// users.php role context — if ANY <li> in the strip is a `role-*`
		// filter, every entry in the strip (including the leading "All", which
		// carries no class) reads as a user-role link. Stamp them all with the
		// user glyph so the strip is coherent end-to-end.
		if (ul && ul.dataset.akSubsubRoles === '1') { return 'mine'; }
		var cls = (li.className || '').split(/\s+/);
		for (var i = 0; i < cls.length; i++) {
			if (SUBSUB_ICONS[cls[i]]) { return cls[i]; }
			if (cls[i].indexOf('role-') === 0) { return 'mine'; }
		}
		return 'all';
	}

	function clean(ul) {
		// Detect users.php role context once per strip. If any <li> carries
		// role-* the whole strip is role filters — flag the <ul> so
		// classifySubsubLi() short-circuits to the user glyph for every entry.
		if (!ul.dataset.akSubsubRoles) {
			var hasRole = '0';
			var items = ul.querySelectorAll('li');
			for (var k = 0; k < items.length; k++) {
				if (/\brole-/.test(items[k].className || '')) { hasRole = '1'; break; }
			}
			ul.dataset.akSubsubRoles = hasRole;
		}
		// "(12)" -> "12"
		Array.prototype.forEach.call(ul.querySelectorAll('.count'), function (count) {
			var n = count.textContent.replace(/[()]/g, '').trim();
			if (n !== '' && n !== count.textContent) { count.textContent = n; }
		});
		// Drop the literal " |" separators (direct text-node children of each <li>).
		Array.prototype.forEach.call(ul.querySelectorAll('li'), function (li) {
			Array.prototype.slice.call(li.childNodes).forEach(function (node) {
				if (node.nodeType === 3) { li.removeChild(node); }
			});
			// Prepend the status icon to the link, once. Re-runs (mutation observer
			// fires whenever WP refreshes the counts via updates.js) are no-ops.
			var a = li.querySelector('a');
			if (!a || a.querySelector('.ak-subsubsub-ic')) { return; }
			var ic = document.createElement('span');
			ic.className = 'ak-subsubsub-ic';
			ic.setAttribute('aria-hidden', 'true');
			ic.innerHTML = SUBSUB_ICONS[classifySubsubLi(li, ul)];
			a.insertBefore(ic, a.firstChild);
		});
	}
	Array.prototype.forEach.call(lists, function (ul) {
		clean(ul);
		// WP's updates.js re-renders the counts (with parens) after its async
		// update check, overwriting our pass. Re-clean whenever the subtree changes.
		var obs = new MutationObserver(function () {
			obs.disconnect();
			clean(ul);
			obs.observe(ul, { childList: true, subtree: true, characterData: true });
		});
		obs.observe(ul, { childList: true, subtree: true, characterData: true });
	});

	// Plugin-settings nav-tab strips (Bricks, ACF, …) — inject icons mapped by
	// the `data-tab-id` attribute Bricks ships on each `<a class="nav-tab">`.
	// Unknown ids degrade gracefully (no icon, just the label). We don't try
	// to invent icons for tabs we don't know about — every glyph here is a
	// considered match, not a guess.
	var NAV_TAB_ICONS = {
		'tab-general':        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>',
		'tab-builder-access': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'tab-templates':      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 L 2 7 L 12 12 L 22 7 Z"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
		'tab-builder':        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9.06 11.9l8.07-8.06a2.85 2.85 0 1 1 4.03 4.03l-8.06 8.08"/><path d="M7.07 14.94c-1.66 0-3 1.35-3 3.02 0 1.33-2.5 1.52-2 2.02 1.08 1.1 2.49 2.02 4 2.02 2.2 0 4-1.8 4-4.04a3.01 3.01 0 0 0-3-3.02z"/></svg>',
		'tab-performance':    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
		'tab-maintenance':    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
		'tab-api-keys':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="3.5"/><path d="M10 13l11-11"/><path d="M16 7l3 3"/><path d="M19 4l3 3"/></svg>',
		'tab-custom-code':    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>'
	};
	function injectNavTabIcons() {
		Array.prototype.forEach.call(document.querySelectorAll('.nav-tab[data-tab-id]'), function (a) {
			if (a.querySelector('.ak-nav-tab-ic')) { return; }
			var icon = NAV_TAB_ICONS[a.dataset.tabId];
			if (!icon) { return; }
			var ic = document.createElement('span');
			ic.className = 'ak-nav-tab-ic';
			ic.setAttribute('aria-hidden', 'true');
			ic.innerHTML = icon;
			a.insertBefore(ic, a.firstChild);
		});
	}
	// Run now AND on the next tick. Some plugin / theme admin scripts (Bricks'
	// `bricks-admin.js` in particular) initialise their settings tabs AFTER
	// list-table-chrome.js runs, and re-set the link inner HTML — wiping our
	// freshly-injected `.ak-nav-tab-ic` span. The second pass re-injects after
	// that init completes; the `if (already has icon) return` guard makes both
	// passes idempotent (no doubled icons when nobody mutates anything).
	injectNavTabIcons();
	setTimeout(injectNavTabIcons, 0);
})();
