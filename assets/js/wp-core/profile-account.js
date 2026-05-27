/**
 * AdminKit — account screen tabs.
 *
 * Rebuilds profile.php / user-edit.php / user-new.php (one long form of <h2> +
 * .form-table sections) as a tab strip + one visible panel, by MOVING the native
 * <tr>s (never cloning — every input keeps its name, so the form posts exactly as
 * WP expects). Curated tabs (Informations / Réglages) are filled first; unmapped
 * plugin sections (WooCommerce billing/shipping, ACF, …) are swept into their own
 * icon-tagged tabs. Localized strings + AdminKit copy arrive via
 * `window.AdminKitProfileAccount` (set by an inline bootstrap). Footer script,
 * loaded only on the account screens. Layout: assets/css/wp-screens/profile.css.
 */
(function () {
	var S = window.AdminKitProfileAccount || {};

	// Anti-FOUC reveal. An inline <head> bootstrap (class-profile-account.php)
	// tags <html> with the pending class pre-paint so profile.css hides the raw,
	// untabbed form until the layout below is built; reveal() swaps in the ready
	// class to show it. Call it on EVERY exit path (including the early returns
	// and the "nothing to group" bail) so the form is never left hidden — and the
	// PHP side also force-reveals on `load` as a backstop if this script throws.
	var docEl = document.documentElement;
	function reveal() {
		if (S.pendingClass) { docEl.classList.remove(S.pendingClass); }
		if (S.readyClass) { docEl.classList.add(S.readyClass); }
	}

	var form = document.querySelector('#your-profile, #createuser');
	if (!form || form.dataset.akAccount) { reveal(); return; }
	form.dataset.akAccount = '1';

	// Inline stroke icons (currentColor). One per curated tab + a set keyed to
	// the kind of fields a plugin section carries.
	var I = {
		users:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0M16 11a3 3 0 0 0 0-6M22 21a6 6 0 0 0-4-5.6"/></svg>',
		sliders:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>',
		billing:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
		shipping: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-1"/><circle cx="7" cy="18" r="2"/><path d="M9 18h6"/><circle cx="18" cy="18" r="2"/></svg>',
		address:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>',
		camera:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l1.2-2h3.6L15 6h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3z"/><circle cx="12" cy="13" r="3.2"/></svg>',
		acf:      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',
		plugin:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3v4M14 3v4M6 7h12v5a6 6 0 0 1-12 0zM12 17v4"/></svg>'
	};

	// --- helpers ----------------------------------------------------------

	// First existing node matching one selector or a selector list.
	function firstMatch(root, selectorOrList) {
		var selectors = [].concat(selectorOrList);
		for (var i = 0; i < selectors.length; i++) {
			var node = root.querySelector(selectors[i]);
			if (node) return node;
		}
		return null;
	}
	// A top-level section heading matched by its (localized) text.
	function topH2(titles) {
		titles = [].concat(titles);
		var h2s = form.querySelectorAll(':scope > h2');
		for (var i = 0; i < h2s.length; i++) {
			if (titles.indexOf(h2s[i].textContent.trim()) !== -1) return h2s[i];
		}
		return null;
	}
	// Every node between a heading and the next section boundary.
	function sectionNodes(h2) {
		var nodes = [], n = h2.nextSibling;
		while (n) {
			if (n.nodeType === 1 && (n.tagName === 'H2' || (n.classList && n.classList.contains('submit')))) break;
			nodes.push(n);
			n = n.nextSibling;
		}
		return nodes;
	}
	// A top-level section heading whose body carries a field matching `sel`
	// (used to find WooCommerce billing/shipping sections by their field names).
	function sectionByField(sel) {
		var h2s = form.querySelectorAll(':scope > h2');
		for (var i = 0; i < h2s.length; i++) {
			var n = h2s[i].nextElementSibling;
			while (n && n.tagName !== 'H2') {
				if (n.classList && n.classList.contains('submit')) break;
				if ((n.matches && n.matches(sel)) || (n.querySelector && n.querySelector(sel))) return h2s[i];
				n = n.nextElementSibling;
			}
		}
		return null;
	}
	// Move specific native rows into a panel's table body, in listed order.
	function moveRows(tbody, selectors) {
		(selectors || []).forEach(function (sel) {
			var row = firstMatch(form, sel);
			if (row) tbody.appendChild(row);
		});
	}
	// Pour a section's nodes into a panel: `.form-table` rows merge into its
	// single table body; non-table content (e.g. the Application Passwords UI,
	// ACF field groups) is appended under an optional sub-heading.
	function consume(card, tbody, nodes, subTitle) {
		var subAdded = false;
		nodes.forEach(function (node) {
			if (node.nodeType !== 1) { if (node.parentNode) node.parentNode.removeChild(node); return; }
			if (node.classList && node.classList.contains('form-table')) {
				Array.prototype.forEach.call(node.querySelectorAll('tr'), function (tr) { tbody.appendChild(tr); });
				if (node.parentNode) node.parentNode.removeChild(node);
			} else {
				if (subTitle && !subAdded) {
					var sh = document.createElement('h3');
					sh.className = 'ak-card-sub';
					sh.textContent = subTitle;
					card.appendChild(sh);
					subAdded = true;
				}
				card.appendChild(node);
			}
		});
	}
	// Absorb a whole native section (heading + body) into a panel, then drop
	// the now-empty heading.
	function absorb(card, tbody, titles) {
		var h2 = topH2(titles);
		if (!h2) return;
		consume(card, tbody, sectionNodes(h2), h2.textContent.trim());
		if (h2.parentNode) h2.parentNode.removeChild(h2);
	}
	// Pick an icon for a swept plugin section from the fields it carries.
	function pluginIcon(scope) {
		// The profile-picture section, matched on non-localized markup (AdminKit's
		// own field, or WP's native picture row) so it stays translation-proof.
		if (scope.querySelector('.adminkit-local-avatar, .user-profile-picture')) return 'camera';
		if (scope.querySelector('[name^="billing_"], [id*="billing"]')) return 'billing';
		if (scope.querySelector('[name^="shipping_"], [id*="shipping"]')) return 'shipping';
		if (scope.querySelector('.acf-field, [name^="acf"]')) return 'acf';
		return 'plugin';
	}

	// Lift the profile-picture controls out of the form table and place them
	// beside the page title (avatar hero), above the tabs.
	function mountProfilePictureHero() {
		var row = firstMatch(form, '.user-profile-picture');
		if (!row) return;
		var cell = row.querySelector('td');
		if (!cell) return;

		var hero = document.createElement('div');
		hero.className = 'ak-profile-picture-hero';
		var content = document.createElement('div');
		content.className = 'ak-profile-picture-hero__content';
		while (cell.firstChild) {
			content.appendChild(cell.firstChild);
		}
		hero.appendChild(content);
		row.remove();

		// Tag the hero's avatar image so themes or other plugins can target it.
		// AdminKit itself no longer wires it to anything — the avatar feature runs
		// entirely through WordPress's native default-avatar pipeline.
		var heroImg = content.querySelector('img');
		if (heroImg && !heroImg.id) { heroImg.id = 'ak-hero-avatar'; }

		var title = document.querySelector('.wrap h1');
		if (title && title.parentNode) {
			var wrap = title.parentNode;
			var action = wrap.querySelector('.page-title-action');
			var header = document.createElement('div');
			header.className = 'ak-profile-header';
			wrap.insertBefore(header, title);
			header.appendChild(hero);
			header.appendChild(title);
			if (action) {
				header.appendChild(action);
			}
		} else {
			form.insertBefore(hero, form.firstChild);
		}
	}

	mountProfilePictureHero();

	// Application Passwords is the one heading WP nests in a <div>; lift the
	// heading out (the wrapper keeps its id + classes for WP's own JS) so
	// absorb() can find it as a top-level section.
	var apw = form.querySelector('#application-passwords-section');
	if (apw) {
		var apwH2 = apw.querySelector('h2');
		if (apwH2) form.insertBefore(apwH2, apw);
	}

	// Ensure the username carries a "can't be changed" note shown below it —
	// WP only prints this hint on profile.php, not on user-edit.php.
	var loginRow = firstMatch(form, ['.user-user-login-wrap', '.user-login-wrap']);
	if (loginRow) {
		var loginCell = loginRow.querySelector('td');
		if (loginCell && !loginCell.querySelector('.description')) {
			var note = document.createElement('p');
			note.className = 'description';
			note.textContent = S.username_locked;
			loginCell.appendChild(note);
		}
	}

	// --- layout shell: tab strip + panels ---------------------------------
	var box = document.createElement('div'); box.className = 'ak-account';
	var tabs = document.createElement('div'); tabs.className = 'ak-tabs'; tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', S.nav);
	var panels = document.createElement('div'); panels.className = 'ak-panels';
	box.appendChild(tabs);
	box.appendChild(panels);
	form.insertBefore(box, form.firstChild); // native submit row stays below

	var used = {};
	function uid(base) {
		var id = base, i = 2;
		while (used[id]) { id = base + '-' + (i++); }
		used[id] = 1;
		return id;
	}
	function makePanel(id, label, desc) {
		var card = document.createElement('section');
		card.className = 'ak-card';
		card.id = id;
		card.setAttribute('role', 'tabpanel');
		card.setAttribute('aria-labelledby', 'tab-' + id);
		var head = document.createElement('div'); head.className = 'ak-card-head';
		var h = document.createElement('h2'); h.textContent = label; head.appendChild(h);
		if (desc) {
			var p = document.createElement('p'); p.className = 'ak-card-desc'; p.textContent = desc; head.appendChild(p);
		}
		card.appendChild(head);
		var table = document.createElement('table');
		table.className = 'form-table';
		table.setAttribute('role', 'presentation');
		var tbody = document.createElement('tbody');
		table.appendChild(tbody);
		card.appendChild(table);
		return { card: card, tbody: tbody };
	}
	function addTab(id, icon, label, order) {
		var b = document.createElement('button');
		b.type = 'button';
		b.id = 'tab-' + id;
		b.dataset.target = id;
		b.dataset.order = order;
		b.setAttribute('role', 'tab');
		var ic = document.createElement('span'); ic.className = 'ic'; ic.innerHTML = I[icon] || I.plugin;
		var tx = document.createElement('span'); tx.className = 'tx'; tx.textContent = label;
		b.appendChild(ic);
		b.appendChild(tx);
		tabs.appendChild(b);
	}
	// Side-by-side: tag rows so the CSS grid pairs them two-up.
	function markHalf(card, selectors) {
		(selectors || []).forEach(function (sel) {
			var row = firstMatch(card, sel);
			if (row) row.classList.add('ak-half');
		});
	}
	// Three-up: tag rows so the CSS grid lines them up at 1/3 width each.
	function markThird(card, selectors) {
		(selectors || []).forEach(function (sel) {
			var row = firstMatch(card, sel);
			if (row) row.classList.add('ak-third');
		});
	}

	// --- curated tabs -----------------------------------------------------
	// `rows` fix the display order (moved in phase 1, before any whole-section
	// absorb in phase 2 — so cross-section donors like Language leave Personal
	// Options first). `half` rows pair up side by side.
	// `order` sets the tab position: Informations (0), then WooCommerce
	// customer addresses (10/20, assigned at sweep), Réglages (30), then any
	// other plugin section (40, sorted alphabetically), then loose (90).
	var CARDS = [
		{ id: 'ak-info', icon: 'users', t: S.cards.info, grid: true, order: 0,
		  // Display order top→bottom. Pairings (.ak-half = 1/2, .ak-third = 1/3)
		  // group fields visually: names side-by-side, email + nickname together,
		  // display name + role together, then login standalone (full width to give
		  // the "click to enable" affordance room), bio (textarea, full width), and
		  // a 3-up footer: avatar + new password + reset.
		  // `.user-url-wrap` is intentionally NOT pulled here — it stays in Contact
		  // Info and gets absorbed by the Settings card.
		  rows: [ '.user-first-name-wrap', '.user-last-name-wrap', '.user-email-wrap', '.user-nickname-wrap', '.user-display-name-wrap', '.user-role-wrap', '#ame-rex-other-roles-row', ['.user-user-login-wrap', '.user-login-wrap'], '.user-description-wrap', '#adminkit-profile-picture', ['#password', '.user-pass1-wrap'], '.user-generate-reset-link-wrap' ],
		  half: [ '.user-first-name-wrap', '.user-last-name-wrap', '.user-email-wrap', '.user-nickname-wrap', '.user-display-name-wrap', '.user-role-wrap' ],
		  third: [ '#adminkit-profile-picture', ['#password', '.user-pass1-wrap'], '.user-generate-reset-link-wrap' ],
		  absorb: [ S.sections.name ] },
		{ id: 'ak-settings', icon: 'sliders', t: S.cards.settings, order: 30,
		  rows: [ '.user-language-wrap', '.user-syntax-highlighting-wrap', '.user-comment-shortcuts-wrap', ['.user-admin-bar-front-wrap', '.show-admin-bar'], '.user-pass2-wrap', '.pw-weak' ],
		  absorb: [ S.sections.app_passwords, S.sections.personal, S.sections.contact, S.sections.about, S.sections.account, S.sections.capabilities ] }
	];

	var build = CARDS.map(function (def) {
		used[def.id] = 1;
		var c = makePanel(def.id, def.t.label, def.t.desc);
		moveRows(c.tbody, def.rows);
		return { def: def, c: c };
	});
	build.forEach(function (b) {
		(b.def.absorb || []).forEach(function (title) { absorb(b.c.card, b.c.tbody, title); });
	});
	build.forEach(function (b) {
		if (b.c.tbody.children.length || b.c.card.children.length > 2) {
			if (b.def.grid) {
				b.c.card.classList.add('ak-grid');
				markHalf(b.c.card, b.def.half);
				markThird(b.c.card, b.def.third);
			}
			panels.appendChild(b.c.card);
			addTab(b.def.id, b.def.icon, b.def.t.label, b.def.order);
		} else {
			delete used[b.def.id];
		}
	});

	// Drop the now-empty wrapper that PHP rendered around #adminkit-profile-picture
	// at the bottom of the form. The row has been moved into the ak-info card; the
	// outer <table> is just markup scaffolding for the show_user_profile hook (which
	// fires outside any table) and serves no purpose once the row is gone.
	var avatarWrap = form.querySelector('.adminkit-profile-picture-wrap');
	if (avatarWrap && !avatarWrap.querySelector('tr')) {
		avatarWrap.remove();
	}

	// Group WooCommerce customer addresses (billing + shipping) into one tidy
	// "Adresses" tab. Each address is a collapsible <details> so the panel
	// doesn't dump ~16 fields at once — the first (billing) opens, the rest
	// fold away. Native <details> = keyboard-accessible, instant, zero JS.
	(function () {
		var sections = ['[name^="billing_"]', '[name^="shipping_"]']
			.map(function (sel) { return sectionByField(sel); })
			.filter(Boolean);
		if (!sections.length) return;
		var c = makePanel(uid('ak-addresses'), S.addresses, '');
		c.card.classList.add('ak-grid'); // fields render label-above, paired two-up
		if (c.tbody.parentNode) c.tbody.parentNode.remove(); // drop the empty default table
		sections.forEach(function (h2, idx) {
			var d = document.createElement('details');
			d.className = 'ak-subsection';
			if (idx === 0) d.open = true;
			var sum = document.createElement('summary');
			sum.textContent = h2.textContent.trim();
			d.appendChild(sum);
			sectionNodes(h2).forEach(function (node) {
				if (node.nodeType === 1 && node.tagName !== 'H2') d.appendChild(node);
				else if (node.parentNode) node.parentNode.removeChild(node);
			});
			// Pair short address fields side by side; company + street stay full.
			['_first_name', '_last_name', '_city', '_postcode', '_country', '_state', '_phone', '_email'].forEach(function (suffix) {
				var field = d.querySelector('[id$="' + suffix + '"]');
				var tr = field && field.closest('tr');
				if (tr) tr.classList.add('ak-half');
			});
			c.card.appendChild(d);
			if (h2.parentNode) h2.parentNode.removeChild(h2);
		});
		panels.appendChild(c.card);
		addTab(c.card.id, 'address', S.addresses, 10);
	})();

	// --- sweep leftovers (plugin sections + loose tables) -----------------
	Array.prototype.forEach.call(form.querySelectorAll(':scope > h2'), function (h2) {
		var label = h2.textContent.trim();
		var slug = label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'section';
		var id = uid('ak-' + slug);
		var c = makePanel(id, label, '');
		consume(c.card, c.tbody, sectionNodes(h2), null);
		if (h2.parentNode) h2.parentNode.removeChild(h2);
		if (c.tbody.children.length || c.card.children.length > 2) {
			var icon = pluginIcon(c.card);
			var order = icon === 'billing' ? 10 : icon === 'shipping' ? 20 : 40;
			panels.appendChild(c.card);
			addTab(id, icon, label, order);
		}
	});
	var loose = Array.prototype.filter.call(form.children, function (ch) {
		return ch.classList && ch.classList.contains('form-table') && ch.querySelector('tr');
	});
	if (loose.length) {
		var moreId = uid('ak-more');
		var more = makePanel(moreId, S.more, '');
		loose.forEach(function (t) {
			Array.prototype.forEach.call(t.querySelectorAll('tr'), function (tr) { more.tbody.appendChild(tr); });
			if (t.parentNode) t.parentNode.removeChild(t);
		});
		if (more.tbody.children.length) {
			panels.appendChild(more.card);
			addTab(moreId, pluginIcon(more.card), S.more, 90);
		}
	}

	// Order the strip: by `data-order`, then alphabetically within a group
	// (so sibling plugin tabs sort by name). Panels follow their tab.
	Array.prototype.slice.call(tabs.children)
		.sort(function (a, b) {
			var d = (+a.dataset.order) - (+b.dataset.order);
			return d || a.textContent.localeCompare(b.textContent);
		})
		.forEach(function (btn) {
			tabs.appendChild(btn);
			var panel = document.getElementById(btn.dataset.target);
			if (panel) panels.appendChild(panel);
		});

	// --- tabs: show one panel at a time -----------------------------------
	var buttons = tabs.querySelectorAll('button');
	if (!buttons.length) {
		box.parentNode.removeChild(box); // nothing grouped — leave the form be
		form.removeAttribute('data-ak-account');
		reveal();
		return;
	}
	function activate(id) {
		Array.prototype.forEach.call(buttons, function (b) {
			var on = b.dataset.target === id;
			b.classList.toggle('on', on);
			b.setAttribute('aria-selected', on ? 'true' : 'false');
			b.tabIndex = on ? 0 : -1;
		});
		Array.prototype.forEach.call(panels.children, function (p) { p.hidden = (p.id !== id); });
	}
	tabs.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		if (b) activate(b.dataset.target);
	});
	// Roving arrow-key nav across the tab strip.
	tabs.addEventListener('keydown', function (e) {
		if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
		var list = Array.prototype.slice.call(buttons);
		var i = list.indexOf(document.activeElement);
		if (i === -1) return;
		var next = list[(i + (e.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length];
		next.focus();
		activate(next.dataset.target);
		e.preventDefault();
	});
	activate(buttons[0].dataset.target);

	// On narrow screens, move the page's primary action ("Add New User") below
	// the tabs + panels — it shouldn't sit above the content on mobile. The
	// button lives in the title header (a different container), so a media-query
	// listener relocates it rather than CSS order. Restored to the header on
	// wide screens.
	var pageAction = document.querySelector('.page-title-action');
	if (pageAction) {
		var actionHome = pageAction.parentNode;
		var narrow = window.matchMedia('(max-width: 782px)');
		var placeAction = function () {
			if (narrow.matches) {
				box.appendChild(pageAction);
			} else if (pageAction.parentNode !== actionHome) {
				actionHome.appendChild(pageAction);
			}
		};
		placeAction();
		if (narrow.addEventListener) narrow.addEventListener('change', placeAction);
		else if (narrow.addListener) narrow.addListener(placeAction);
	}

	// Layout fully built — reveal the form (clears the pre-paint anti-FOUC hide).
	reveal();
})();
