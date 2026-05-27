/**
 * AdminKit — options-discussion.php two-tab regrouping.
 *
 * Splits WP's seven `<h2>` settings sections (Default post settings, Other
 * comment settings, Email me whenever, Before a comment appears, Comment
 * Moderation, Disallowed Comment Keys, Avatars) into two coherent tabs:
 *
 *   • Comment settings — everything that governs comments + moderation
 *   • Avatars          — the Avatars block on its own
 *
 * Avatar detection is locale-proof: we don't match the heading text (which is
 * translated), we look at the inputs inside (`show_avatars` / `avatar_rating`
 * / `avatar_default`). Everything else falls into the Comment settings tab.
 *
 * Each section becomes its own `.ak-options-card` (heading + form-table), so a
 * tab with multiple sections stacks them as separate cards — the existing
 * card chrome from options.css carries over. Active tab → panel visible; the
 * others are `[hidden]` but stay inside the form so submit still posts every
 * field on every tab.
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
	// straight into the Avatars tab.
	var TABS = [
		{ id: 'comments', title: S.comments || 'Comment settings', icon: 'message' },
		{ id: 'avatars',  title: S.avatars  || 'Avatars',          icon: 'user' }
	];

	// Identify the routing target for each top-level form-table + h2 + leading
	// note before we touch the DOM. We need a reference to the FIRST node we'll
	// move so we know where to insert the tab box. Children list is captured up
	// front so the live re-parenting later doesn't trip us up.
	var nodes = Array.prototype.slice.call(form.children);
	var routes = []; // [{ node, target: 'comments'|'avatars' }]
	var seenAvatar = false;
	nodes.forEach(function (node) {
		if (!node.classList) { return; }
		// Submit row + WP-printed nonces stay in the form, outside any panel,
		// so they keep working as the form's footer.
		if (node.classList.contains('submit')) { return; }
		if (node.tagName === 'INPUT' || node.tagName === 'SCRIPT') { return; }
		// .form-table — route by content.
		if (node.classList.contains('form-table')) {
			var to = tableHasAvatarInputs(node) ? 'avatars' : 'comments';
			if (to === 'avatars') { seenAvatar = true; }
			routes.push({ node: node, target: to });
			return;
		}
		// <h2>Avatars</h2> — route to Avatars panel. Any prior h2 (rare,
		// plugin-injected) routes to Comments.
		if (node.tagName === 'H2') {
			routes.push({ node: node, target: seenAvatar ? 'avatars' : 'comments' });
			return;
		}
		// Anything else (intro <p>, etc.) routes with the LAST decided target,
		// falling back to comments. Treats stray nodes as content of the
		// nearest section.
		routes.push({ node: node, target: seenAvatar ? 'avatars' : 'comments' });
	});

	if (!routes.length) { reveal(); return; }

	// Build the strip + panels and insert ABOVE the first node we're about to
	// move (so the tab strip sits where the first section used to be).
	var box = document.createElement('div');
	box.className = 'ak-options-grouped';
	var tabs = document.createElement('div');
	tabs.className = 'ak-tabs';
	tabs.setAttribute('role', 'tablist');
	var panelsEl = document.createElement('div');
	panelsEl.className = 'ak-options-panels';
	box.appendChild(tabs);
	box.appendChild(panelsEl);
	routes[0].node.parentNode.insertBefore(box, routes[0].node);

	TABS.forEach(function (t) {
		var panel = document.createElement('div');
		panel.id = t.id;
		panel.className = 'ak-options-panel';
		panel.setAttribute('role', 'tabpanel');
		panelsEl.appendChild(panel);
		t.panel = panel;
	});

	var TAB_INDEX = { comments: 0, avatars: 1 };
	routes.forEach(function (r) {
		// h2 gets the card-title class so options.css can style it as a card
		// heading (matches the General-page card titles).
		if (r.node.tagName === 'H2') {
			r.node.classList.add('ak-options-card__title');
		}
		TABS[ TAB_INDEX[ r.target ] ].panel.appendChild(r.node);
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
