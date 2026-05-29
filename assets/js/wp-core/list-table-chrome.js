/**
 * AdminKit — list-table chrome polish.
 *
 * Wraps each list table in a horizontal-scroll container and sizes Quick/Bulk
 * Edit to the visible width. Status-filter counts are cleaned server-side; the
 * small client fallback below only protects plugin screens that bypass WP's
 * `views_*` filters. Loaded as a footer script on admin pages.
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

	function clean(ul) {
		// "(12)" -> "12". Core list tables are handled before first paint in PHP;
		// this remains for plugin screens that print their own filter strips.
		Array.prototype.forEach.call(ul.querySelectorAll('.count'), function (count) {
			var n = count.textContent.replace(/[()]/g, '').trim();
			if (n !== '' && n !== count.textContent) { count.textContent = n; }
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
