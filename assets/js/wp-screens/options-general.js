/**
 * AdminKit — options-general.php section grouping + tab navigation.
 *
 * WP ships one long .form-table that mixes site identity, account/registration,
 * and date/time settings. This footer script:
 *
 *   1. Splits those rows into three labelled section cards (Identity / Account
 *      / Language, date & time) by MOVING the matching <tr>s into per-section
 *      sub-tables. Site Icon (its own settings-section TR on WP 6.5+) gets
 *      folded into Identity so it isn't a stranded card at the bottom.
 *   2. Builds an `.ak-tabs` strip above the cards (one tab per section, same
 *      bordered-container + pill shape used everywhere else in AdminKit).
 *      Active tab → visible card; the others are `hidden`. All cards remain
 *      inside the form, so submission still posts every field on every tab.
 *
 * Strings ride along via `window.AdminKitOptionsGeneral` (inline bootstrap
 * printed by AdminKit_Core_Options_General::enqueue).
 */
(function () {
	// Anti-FOUC hatch — PHP added `ak-options-pending` to <html> pre-paint so
	// the raw form is hidden by options.css until we clear it here. EVERY exit
	// path (bail, mid-build error, success) must run reveal() so the form is
	// never trapped invisible. Backstops in the PHP bootstrap force-reveal on
	// `load` + a 3s timer if we throw.
	function reveal() {
		document.documentElement.classList.remove('ak-options-pending');
	}

	var form = document.querySelector('.wrap > form[action="options.php"]');
	if (!form) { reveal(); return; }
	var table = form.querySelector('.form-table');
	if (!table || table.dataset.akGrouped) { reveal(); return; }
	table.dataset.akGrouped = '1';

	var S = window.AdminKitOptionsGeneral || {};

	// Inline stroke icons (currentColor) — same Lucide-style set used by the
	// profile tab strip. Sized via the `.ak-tabs button .ic` rule in chrome.css.
	var I = {
		globe:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
		image:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
		users:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0M16 11a3 3 0 0 0 0-6M22 21a6 6 0 0 0-4-5.6"/></svg>',
		clock:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>'
	};

	// Field ids per section, in display order. Each section title + icon comes
	// alongside. Site Icon is its own card (between Identity and Account) so it
	// sits in the natural Settings → General reading order, not buried at the
	// bottom. Anything that isn't on the page (e.g. WPLANG only when
	// translations are installed, users_can_register only on single-site) is
	// silently skipped — the card just renders shorter, or doesn't render at all.
	// IDs are clean fragment-friendly slugs so the URL serves as a deep link.
	// Example: a support reply can point at `options-general.php#site-identity`
	// and the user lands directly on that tab.
	//
	// Site Icon is a SECONDARY card living on the Site identity tab (same
	// `tabId`), not its own tab — keeps the strip short and lets the visitor
	// see the icon next to the title/tagline it belongs with.
	var GROUPS = [
		{
			id:    'site-identity',
			tabId: 'site-identity',
			title: S.identity || 'Site identity',
			icon:  'globe',
			showInTabs: true,
			rows:  ['blogname', 'blogdescription', 'siteurl', 'home']
		},
		{
			id:    'site-icon',
			tabId: 'site-identity',
			title: S.siteIcon || 'Site Icon',
			showInTabs: false,
			rows:  ['site_icon']
		},
		{
			id:    'account-registration',
			tabId: 'account-registration',
			title: S.account || 'Account & registration',
			icon:  'users',
			showInTabs: true,
			rows:  ['admin_email', 'new_admin_email', 'users_can_register', 'default_role']
		},
		{
			id:    'language-date-time',
			tabId: 'language-date-time',
			title: S.locale || 'Language, date & time',
			icon:  'clock',
			showInTabs: true,
			rows:  ['WPLANG', 'timezone_string', 'date_format', 'time_format', 'start_of_week']
		}
	];

	// Page-level wrapper: holds the tabs above and the per-section cards below.
	// Inserted before the original .form-table so existing nonce + submit rows
	// after the table keep working unchanged.
	var box = document.createElement('div');
	box.className = 'ak-options-grouped';
	var tabs = document.createElement('div');
	tabs.className = 'ak-tabs';
	tabs.setAttribute('role', 'tablist');
	var panels = document.createElement('div');
	panels.className = 'ak-options-panels';
	box.appendChild(tabs);
	box.appendChild(panels);
	table.parentNode.insertBefore(box, table);

	// Site Icon is rendered by WP via a Settings API section, not as a row
	// inside the same .form-table. Its actual <tr> sits in a sibling
	// .form-table (or under an h2 heading). When we sweep rows by input id
	// we'd miss it — try to find it by name on the *form* (not the table)
	// and pre-move its parent <tr> into the original table so the row-scoop
	// below picks it up. Best-effort: silently skipped if absent.
	(function moveSiteIconRow() {
		var siteIcon = form.querySelector('#site_icon-hidden') ||
		               form.querySelector('input[name="site_icon"]') ||
		               form.querySelector('#site-icon-img-input') ||
		               form.querySelector('.site-icon-section');
		if (!siteIcon) { return; }
		var tr = siteIcon.closest('tr');
		if (!tr || tr.closest('.form-table') === table) { return; }
		// Append to the original table's tbody so the matching loop below moves
		// it into Identity.
		var tbody = table.querySelector('tbody') || table;
		tbody.appendChild(tr);
	})();

	// Build each card. The card's `data-tab` attribute names its owning tab —
	// multiple cards can share a tabId so they show together (Site identity +
	// Site Icon). `showInTabs` controls whether the group contributes a tab
	// BUTTON; secondary cards (Site Icon) ride along with their primary tab.
	var made = [];
	var seenTabs = {};
	GROUPS.forEach(function (g) {
		var card = document.createElement('section');
		card.className = 'ak-options-card';
		card.id = g.id;
		card.dataset.tab = g.tabId;
		card.setAttribute('role', 'tabpanel');
		var h = document.createElement('h2');
		h.className = 'ak-options-card__title';
		h.textContent = g.title;
		card.appendChild(h);
		var t = document.createElement('table');
		t.className = 'form-table';
		t.setAttribute('role', 'presentation');
		var tb = document.createElement('tbody');
		t.appendChild(tb);
		g.rows.forEach(function (id) {
			var input = form.querySelector('#' + id) ||
			            form.querySelector('[name="' + id + '"]');
			var tr = input && input.closest('tr');
			if (tr) { tb.appendChild(tr); }
		});
		if (tb.children.length) {
			card.appendChild(t);
			panels.appendChild(card);
			if (g.showInTabs && !seenTabs[g.tabId]) {
				seenTabs[g.tabId] = true;
				made.push({ id: g.tabId, title: g.title, icon: g.icon });
			}
		}
	});

	// Drop the original table if everything was claimed.
	var tbody = table.querySelector('tbody');
	if (tbody && !tbody.querySelector('tr')) { table.remove(); }

	// Nothing built? leave the page alone (no tab strip with zero tabs).
	if (!made.length) {
		box.parentNode.removeChild(box);
		reveal();
		return;
	}

	// Build the tab strip + wire show/hide. One panel visible at a time;
	// hidden panels still submit because they stay inside the form.
	made.forEach(function (m, i) {
		var b = document.createElement('button');
		b.type = 'button';
		b.dataset.target = m.id;
		b.setAttribute('role', 'tab');
		var ic = document.createElement('span');
		ic.className = 'ic';
		ic.innerHTML = I[m.icon] || '';
		var tx = document.createElement('span');
		tx.className = 'tx';
		tx.textContent = m.title;
		b.appendChild(ic);
		b.appendChild(tx);
		tabs.appendChild(b);
	});

	function activate(id) {
		Array.prototype.forEach.call(tabs.children, function (b) {
			var on = b.dataset.target === id;
			b.classList.toggle('on', on);
			b.setAttribute('aria-selected', on ? 'true' : 'false');
			b.tabIndex = on ? 0 : -1;
		});
		// Toggle by `data-tab` so secondary cards (e.g. Site Icon under Site
		// identity) show with their primary, not on their own.
		Array.prototype.forEach.call(panels.children, function (p) {
			p.hidden = (p.dataset.tab !== id);
		});
		// Reflect the active tab in the URL so the page can be deep-linked
		// (e.g. `options-general.php#site-identity`). `replaceState` keeps the
		// browser history clean — clicking tabs doesn't pile up entries.
		if (location.hash.slice(1) !== id) {
			if (history.replaceState) {
				history.replaceState(null, '', '#' + id);
			} else {
				location.hash = id;
			}
		}
	}

	tabs.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		if (b && b.dataset.target) { activate(b.dataset.target); }
	});

	// Initial activation: URL hash wins (deep link from support / docs), else
	// the first tab.
	function tabFor(slug) {
		for (var i = 0; i < made.length; i++) {
			if (made[i].id === slug) { return slug; }
		}
		return null;
	}
	activate(tabFor(location.hash.slice(1)) || made[0].id);

	// Respond to hash changes — manual URL edits, back/forward, anchor clicks
	// from the same page (e.g. a "jump to Site Icon" link).
	window.addEventListener('hashchange', function () {
		var id = tabFor(location.hash.slice(1));
		if (id) { activate(id); }
	});

	// Build fully done — reveal the rebuilt form (clears the pre-paint hide).
	reveal();
})();
