/**
 * AdminKit — options-discussion.php two-tab regrouping.
 *
 * Wraps every top-level `<table class="form-table">` in a `.ak-options-card`
 * (with the preceding `<h2>`, if any, as the card title) and routes each card
 * into one of two tabs:
 *
 *   • Avatars          — the avatars form-table on its own
 *   • Comment settings — every other form-table (default post settings, other
 *                        comment settings, pagination, email, moderation,
 *                        disallowed keys — WP renders all of those as <tr>s
 *                        in ONE giant form-table on this page)
 *
 * Routing is locale-proof: we don't match heading text, we check whether a
 * form-table contains the avatar-block inputs (`show_avatars` /
 * `avatar_rating` / `avatar_default`).
 *
 * Active tab → panel visible; the others are `[hidden]` but stay inside the
 * form so submit still posts every field on every tab.
 *
 * Strings ride along via `window.AdminKitOptionsDiscussion` (inline bootstrap
 * printed by AdminKit_Core_Options_Discussion::enqueue).
 */
(function () {
	// Anti-FOUC hatch — PHP added `ak-options-pending` to <html> pre-paint so
	// the raw form is hidden by options.css until we clear it. EVERY exit
	// path must run reveal() so the form is never trapped invisible.
	function reveal() {
		document.documentElement.classList.remove('ak-options-pending');
	}

	var form = document.querySelector('.wrap > form[action="options.php"]');
	if (!form || form.dataset.akGrouped) { reveal(); return; }
	form.dataset.akGrouped = '1';

	var S = window.AdminKitOptionsDiscussion || {};

	// Avatar-content detector. WP renders options-discussion.php as ONE giant
	// .form-table holding every non-avatar setting (Default post settings,
	// Other comment settings, Pagination, Email-me, Before-comment-appears,
	// Comment Moderation, Disallowed Keys — all as <tr> rows in the same table),
	// then a SINGLE <h2>Avatars</h2> followed by a second .form-table of avatar
	// settings. Walking h2s misses everything except Avatars.
	//
	// So we work at .form-table granularity, not h2 granularity: a table is
	// "avatar-related" if it contains the avatar-block inputs (matched by name
	// so detection survives translation). Everything else is Comment settings.
	function tableHasAvatarInputs(el) {
		return !!(el.querySelector && (
			el.querySelector('input[name="show_avatars"]') ||
			el.querySelector('input[name="avatar_rating"]') ||
			el.querySelector('input[name="avatar_default"]')
		));
	}

	// Inline stroke icons (currentColor) — same set as the other options pages.
	var I = {
		message: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
		user:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
	};

	// Clean fragment-friendly ids so `options-discussion.php#avatars` deep-links
	// straight into the Avatars tab. Avatars is listed FIRST — it's a focused,
	// single-purpose tab that opens directly to one card; Comment settings is
	// the long, multi-section tab that benefits from being click-to-reveal.
	var TABS = [
		{ id: 'avatars',  title: S.avatars  || 'Avatars',          icon: 'user' },
		{ id: 'comments', title: S.comments || 'Comment settings', icon: 'message' }
	];

	// Walk every top-level `<table class="form-table">` in the form. Each table
	// becomes a `.ak-options-card`; the immediately-preceding `<h2>` (if any)
	// rides along as the card title. Routing is by content (input names, not
	// heading text) so translation doesn't break detection.
	var tablesInForm = form.querySelectorAll(':scope > table.form-table');
	if (!tablesInForm.length) { reveal(); return; }
	var cards = []; // [{ card: <section>, target: 'avatars'|'comments' }]
	Array.prototype.forEach.call(tablesInForm, function (table) {
		var target = tableHasAvatarInputs(table) ? 'avatars' : 'comments';

		// Optional title — the closest preceding <h2> sibling, if there's nothing
		// table-shaped in between. WP only ships one (`<h2>Avatars</h2>` before
		// the avatars table); the rest of the form-tables have no preceding h2.
		var prev = table.previousElementSibling;
		var h2 = null;
		while (prev) {
			if (prev.tagName === 'H2') { h2 = prev; break; }
			if (prev.tagName === 'TABLE' || prev.tagName === 'INPUT' ||
				(prev.classList && prev.classList.contains('submit'))) { break; }
			prev = prev.previousElementSibling;
		}

		var card = document.createElement('section');
		card.className = 'ak-options-card';
		if (h2) {
			h2.classList.add('ak-options-card__title');
			card.appendChild(h2);
		}
		card.appendChild(table);
		cards.push({ card: card, target: target });
	});

	// Build the strip + panels above the first thing we're about to move (so
	// the tab strip sits where the first section used to be — anything WP
	// printed before that, e.g. hidden settings_fields inputs, stays in place).
	var anchor = cards[0].card.querySelector('.ak-options-card__title') || tablesInForm[0];
	var box = document.createElement('div');
	box.className = 'ak-options-grouped';
	var tabs = document.createElement('div');
	tabs.className = 'ak-tabs';
	tabs.setAttribute('role', 'tablist');
	var panelsEl = document.createElement('div');
	panelsEl.className = 'ak-options-panels';
	box.appendChild(tabs);
	box.appendChild(panelsEl);
	anchor.parentNode.insertBefore(box, anchor);

	TABS.forEach(function (t) {
		var panel = document.createElement('div');
		panel.id = t.id;
		panel.className = 'ak-options-panel';
		panel.setAttribute('role', 'tabpanel');
		panelsEl.appendChild(panel);
		t.panel = panel;
	});

	var TAB_INDEX = { avatars: 0, comments: 1 };
	cards.forEach(function (c) {
		TABS[ TAB_INDEX[ c.target ] ].panel.appendChild(c.card);
	});

	// Drop empty panels (e.g. a site whose theme/plugins removed every Avatars
	// input → Avatars panel ends up empty).
	var made = TABS.filter(function (t) { return t.panel.children.length > 0; });
	if (!made.length) {
		box.parentNode.removeChild(box);
		reveal();
		return;
	}

	made.forEach(function (t) {
		var b = document.createElement('button');
		b.type = 'button';
		b.dataset.target = t.id;
		b.setAttribute('role', 'tab');
		var ic = document.createElement('span');
		ic.className = 'ic';
		ic.innerHTML = I[t.icon] || '';
		var tx = document.createElement('span');
		tx.className = 'tx';
		tx.textContent = t.title;
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
		Array.prototype.forEach.call(panelsEl.children, function (p) {
			p.hidden = (p.id !== id);
		});
		// Reflect in URL — supports deep links like `options-discussion.php#avatars`.
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

	function tabFor(slug) {
		for (var i = 0; i < made.length; i++) {
			if (made[i].id === slug) { return slug; }
		}
		return null;
	}
	activate(tabFor(location.hash.slice(1)) || made[0].id);

	window.addEventListener('hashchange', function () {
		var id = tabFor(location.hash.slice(1));
		if (id) { activate(id); }
	});

	// Build fully done — reveal the rebuilt form (clears the pre-paint hide).
	reveal();
})();
