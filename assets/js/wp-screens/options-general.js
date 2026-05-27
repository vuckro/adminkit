/**
 * AdminKit — options-general.php section grouping + tab navigation.
 *
 * Hosts a FIVE-tab merged page:
 *   1. Site identity     — native WP rows + Site Icon card + Dashboard card
 *                          (brand / accent / tokens / roadmap, mounted by SPA)
 *   2. Account & registration  — native WP rows
 *   3. Language, date & time   — native WP rows
 *   4. Preferences       — AdminKit feature toggles (mounted by SPA)
 *   5. Plugins           — AdminKit per-plugin adapters (mounted by SPA)
 *
 * Native sections: we move the matching `<tr>`s out of WP's one big
 * .form-table into per-section sub-tables and keep them inside the form so
 * submission still posts every field. AdminKit sections: we build empty
 * `<section data-adminkit-panel="…">` placeholders here; `settings.js`
 * finds them and renders content into them.
 *
 * The WP `<p class="submit">` row is hidden on PURE-AdminKit tabs
 * (Preferences / Plugins) where saving goes through REST. It stays visible
 * on Site identity (mixed — native fields still POST to options.php while
 * the AdminKit save bar handles the brand controls separately).
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
		globe:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
		image:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
		users:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0M16 11a3 3 0 0 0 0-6M22 21a6 6 0 0 0-4-5.6"/></svg>',
		clock:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>',
		// AdminKit tabs — sliders for Preferences, plug for Plugins. Dashboard
		// no longer has its own tab (its card lives on Site identity), so no
		// gauge icon here.
		sliders:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
		plug:     '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v4M15 2v4M7 6h10a1 1 0 0 1 1 1v3a6 6 0 0 1-12 0V7a1 1 0 0 1 1-1zM12 16v6"/></svg>'
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
		// ── Native WP General sections — built from existing form rows. ──
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
		},
		// ── AdminKit SPA sections — empty placeholders, filled by settings.js.
		// `adminkit: true` flags them for the build loop (no row scoop, no
		// inner form-table) AND the activate() hook below (hide WP submit when
		// the tab is PURE AdminKit). ──
		//
		// Dashboard rides as a SECONDARY card on the Site identity tab — the
		// brand controls, accent picker and roadmap belong with what the site
		// is called. `showInTabs:false` keeps the strip short; the card's id
		// stays `dashboard` so `options-general.php#dashboard` still works as
		// a deep link (tabFor() resolves a card id → its owning tab below).
		{
			id:    'dashboard',
			tabId: 'site-identity',
			title: S.dashboard || 'Dashboard',
			showInTabs: false,
			adminkit: true
		},
		{
			id:    'settings',
			tabId: 'settings',
			title: S.features || 'Preferences',
			icon:  'sliders',
			showInTabs: true,
			adminkit: true
		},
		{
			id:    'plugins',
			tabId: 'plugins',
			title: S.plugins || 'Plugins',
			icon:  'plug',
			showInTabs: true,
			adminkit: true
		}
	];

	// Set of PURE-AdminKit tab ids — checked by activate() to hide WP's submit
	// row (settings.js owns saving via REST for these panels). Site identity is
	// mixed (native fields + Dashboard card), so it stays out of this set: WP's
	// submit row still saves the native fields, and the AdminKit save bar above
	// the panels saves the brand controls — two complementary saves on one tab.
	var ADMINKIT_TABS = { settings: 1, plugins: 1 };

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
	// `adminkit: true` builds an empty <section> with `data-adminkit-panel`
	// — settings.js finds it by that attribute and renders content into it.
	var made = [];
	var seenTabs = {};
	GROUPS.forEach(function (g) {
		var card = document.createElement('section');
		card.className = 'ak-options-card';
		card.id = g.id;
		card.dataset.tab = g.tabId;
		card.setAttribute('role', 'tabpanel');

		if (g.adminkit) {
			// Empty placeholder for settings.js. No title or inner form-table
			// — the SPA renders its own card chrome inside this container.
			card.dataset.adminkitPanel = g.id;
			panels.appendChild(card);
			if (g.showInTabs && !seenTabs[g.tabId]) {
				seenTabs[g.tabId] = true;
				made.push({ id: g.tabId, title: g.title, icon: g.icon });
			}
			return;
		}

		// Native WP section — scoop matching <tr>s into a per-section table.
		var h = document.createElement('h2');
		h.className = 'ak-options-card__title';
		h.textContent = g.title;
		card.appendChild(h);
		var t = document.createElement('table');
		t.className = 'form-table';
		t.setAttribute('role', 'presentation');
		var tb = document.createElement('tbody');
		t.appendChild(tb);
		(g.rows || []).forEach(function (id) {
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

	// WP's submit row (`<p class="submit">`) — hidden when an AdminKit tab is
	// active (settings.js owns saving for those via REST + its own button);
	// shown when a native tab is active so the form's POST handler is the
	// obvious affordance. Cached once here; toggled by activate() below.
	var submitRow = form.querySelector('.submit');

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
		// Hide WP's submit on AdminKit tabs — settings.js renders its own save
		// affordance inside those panels and saves via REST.
		if (submitRow) {
			submitRow.hidden = !!ADMINKIT_TABS[id];
		}
		// Reflect the active tab in the URL so the page can be deep-linked
		// (e.g. `options-general.php#site-identity`, `#dashboard`, …).
		// `replaceState` keeps the browser history clean — clicking tabs
		// doesn't pile up entries.
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
	// the first tab. `tabFor()` resolves either:
	//   • a tab id directly (e.g. `#site-identity` → 'site-identity'), OR
	//   • a CARD id that lives under a tab (e.g. `#site-icon` or `#dashboard`
	//     → 'site-identity', because both cards carry data-tab="site-identity")
	// so legacy URLs like `?page=adminkit` → `#dashboard` still land on the
	// right tab after Dashboard's merge into Site identity.
	function tabFor(slug) {
		if (!slug) { return null; }
		for (var i = 0; i < made.length; i++) {
			if (made[i].id === slug) { return slug; }
		}
		var card = document.getElementById(slug);
		if (card && card.dataset.tab) { return card.dataset.tab; }
		return null;
	}
	// Scroll a secondary card (one whose id is NOT its own tab) into view after
	// the tab containing it activates. Lets `#dashboard` jump straight to the
	// brand controls instead of stopping at the top of Site identity.
	function scrollCardIfSecondary(slug, tabId) {
		if (!slug || slug === tabId) { return; }
		var card = document.getElementById(slug);
		if (card && !card.hidden) { card.scrollIntoView({ block: 'start' }); }
	}
	var initialHash = location.hash.slice(1);
	var initialTab  = tabFor(initialHash) || made[0].id;
	activate(initialTab);
	scrollCardIfSecondary(initialHash, initialTab);

	// Respond to hash changes — manual URL edits, back/forward, anchor clicks
	// from the same page (e.g. a "jump to Site Icon" link).
	window.addEventListener('hashchange', function () {
		var slug = location.hash.slice(1);
		var id = tabFor(slug);
		if (!id) { return; }
		activate(id);
		scrollCardIfSecondary(slug, id);
	});

	// Build fully done — reveal the rebuilt form (clears the pre-paint hide).
	reveal();
})();
