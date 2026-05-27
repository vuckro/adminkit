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

	// user-new.php ships its rows as bare `tr.form-field` (no `.user-X-wrap`
	// hooks the curated CARDS below match against). Tag them up front by walking
	// from each input id back to its <tr>, so the rest of this script — built for
	// profile.php / user-edit.php — works unchanged on the Add New User screen.
	if (form.id === 'createuser') {
		var TAGS = {
			user_login:  'user-user-login-wrap',
			email:       'user-email-wrap',
			first_name:  'user-first-name-wrap',
			last_name:   'user-last-name-wrap',
			url:         'user-url-wrap',
			pass1:       'user-pass1-wrap',
			pass2:       'user-pass2-wrap',
			role:        'user-role-wrap',
			locale:      'user-language-wrap',
			send_user_notification: 'user-send-notification-wrap',
			noconfirmation:         'user-noconfirmation-wrap'
		};
		Object.keys(TAGS).forEach(function (name) {
			var input = form.querySelector('#' + name);
			var tr = input && input.closest('tr');
			if (tr) { tr.classList.add(TAGS[name]); }
		});
	}

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
			// Order: avatar → title → "Refresh avatar" (AJAX, when PHP supplied
			// the endpoint data) → native "Add User" action. Refresh sits to the
			// LEFT of Add User because it's about the user being edited; Add User
			// is the global navigation action and stays the rightmost CTA.
			//
			// The refresh button does an AJAX POST to `adminkit_shuffle_avatar`
			// (the same endpoint the users.php Quick Edit inline editor uses) and
			// swaps the hero img src on success. No page reload, no URL
			// round-trip — the only state that needs updating is the seed +
			// the visible <img>.
			if (S.refreshAvatarAjaxUrl && S.refreshAvatarNonce && S.refreshAvatarUserId) {
				var refresh = document.createElement('button');
				refresh.type = 'button';
				refresh.className = 'page-title-action';
				refresh.textContent = S.refreshAvatarLabel || 'Refresh avatar';
				refresh.addEventListener('click', function () {
					if (refresh.disabled) { return; }
					refresh.disabled = true;
					var fd = new FormData();
					fd.append('action', 'adminkit_shuffle_avatar');
					fd.append('user_id', S.refreshAvatarUserId);
					fd.append('_wpnonce', S.refreshAvatarNonce);
					fetch(S.refreshAvatarAjaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
						.then(function (r) { return r.json().catch(function () { return null; }); })
						.then(function (json) {
							refresh.disabled = false;
							var url = json && json.success && json.data && json.data.avatar_url;
							if (url && heroImg) {
								heroImg.srcset = '';
								heroImg.src = url;
							} else {
								window.alert(S.refreshAvatarError || 'Could not refresh the avatar.');
							}
						})
						.catch(function () {
							refresh.disabled = false;
							window.alert(S.refreshAvatarError || 'Could not refresh the avatar.');
						});
				});
				header.appendChild(refresh);
			}
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
		card.dataset.tab = id;
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
	// Walk a tbody and demote orphan .ak-half / .ak-third rows to full width.
	// Halves pair in 2s, thirds in 3s — if a run of consecutive same-class rows
	// has odd / non-multiple length, the trailing rows would sit in a half / third
	// grid slot with empty space next to them. Stripping the class lets them span
	// the full row instead, which fills the panel cleanly.
	function pairFractions(tbody) {
		if (!tbody) { return; }
		['ak-half', 'ak-third'].forEach(function (cls) {
			var span = (cls === 'ak-half') ? 2 : 3;
			var rows = Array.prototype.slice.call(tbody.children);
			var run = [];
			function flush() {
				var extras = run.length % span;
				for (var k = 0; k < extras; k++) {
					run[run.length - 1 - k].classList.remove(cls);
				}
				run = [];
			}
			rows.forEach(function (r) {
				if (r.classList && r.classList.contains(cls)) {
					run.push(r);
				} else {
					flush();
				}
			});
			flush();
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
		{ id: 'information', icon: 'users', t: S.cards.info, grid: true, order: 0,
		  // Display order top→bottom. Pairings (.ak-half = 1/2, .ak-third = 1/3)
		  // group fields visually: names side-by-side, email + nickname together,
		  // display name + role together, then login standalone (full width to give
		  // the "click to enable" affordance room), and a 2-up footer: new password
		  // + reset. The "Refresh avatar" button is NOT pulled here —
		  // mountProfilePictureHero() places it in the page header so this card
		  // stays focused on identity data. Website (`.user-url-wrap`) and
		  // biographical info (`.user-description-wrap`) live on the Settings tab
		  // — the user-facing copy / locale set, not the identity set.
		  rows: [ '.user-first-name-wrap', '.user-last-name-wrap', '.user-email-wrap', '.user-nickname-wrap', '.user-display-name-wrap', '.user-role-wrap', '#ame-rex-other-roles-row', ['.user-user-login-wrap', '.user-login-wrap'], ['#password', '.user-pass1-wrap'], '.user-generate-reset-link-wrap' ],
		  half: [ '.user-first-name-wrap', '.user-last-name-wrap', '.user-email-wrap', '.user-nickname-wrap', '.user-display-name-wrap', '.user-role-wrap', ['#password', '.user-pass1-wrap'], '.user-generate-reset-link-wrap' ],
		  absorb: [ S.sections.name ] },
		{ id: 'settings', icon: 'sliders', t: S.cards.settings, order: 30,
		  // Curated triad first (language / website / bio), then per-user prefs
		  // (syntax highlighting, comment shortcuts, admin bar) and pw confirm.
		  // Anything else WP exposes (Personal Options, App Passwords, About
		  // Yourself leftover, Account Management, Capabilities) folds in via
		  // absorb — the section heading text comes from WP core so it survives
		  // translation.
		  rows: [ '.user-language-wrap', '.user-url-wrap', '.user-description-wrap', '.user-syntax-highlighting-wrap', '.user-comment-shortcuts-wrap', ['.user-admin-bar-front-wrap', '.show-admin-bar'], '.user-pass2-wrap', '.pw-weak' ],
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
				// Demote orphan half/third rows to full-width — a single .ak-half
				// in a row otherwise sits in a 3/6 slot with empty space on the
				// other side (user-new.php has no nickname/display_name, so the
				// pairs the curated list assumes don't form). Walk the tbody,
				// detect runs of consecutive same-class rows, and if a run has
				// odd length (.ak-half: groups of 2; .ak-third: groups of 3),
				// strip the class from the tail row so it spans full width.
				pairFractions(b.c.tbody);
			}
			panels.appendChild(b.c.card);
			addTab(b.def.id, b.def.icon, b.def.t.label, b.def.order);
		} else {
			delete used[b.def.id];
		}
	});

	// Group WooCommerce customer addresses (billing + shipping) into one tidy
	// "Adresses" tab. Each address is a collapsible <details> so the panel
	// doesn't dump ~16 fields at once — the first (billing) opens, the rest
	// fold away. Native <details> = keyboard-accessible, instant, zero JS.
	(function () {
		var sections = ['[name^="billing_"]', '[name^="shipping_"]']
			.map(function (sel) { return sectionByField(sel); })
			.filter(Boolean);
		if (!sections.length) return;
		var c = makePanel(uid('addresses'), S.addresses, '');
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
		var id = uid(slug);
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
		// Prefer attaching loose rows (e.g. user-new.php's send_user_notification)
		// as a SECONDARY .ak-card on the Settings tab — both cards share the same
		// `data-tab` and toggle together, so one tab shows two cards instead of
		// us shipping a thin "Other settings" tab with a single row in it. Falls
		// back to its own tab when Settings isn't on the page (Settings bailed,
		// or another screen shape).
		var settingsCard = used['settings'] ? document.getElementById('settings') : null;
		if (settingsCard) {
			var secondary = document.createElement('section');
			secondary.className = 'ak-card';
			secondary.dataset.tab = 'settings';
			var head = document.createElement('div'); head.className = 'ak-card-head';
			var hh = document.createElement('h2'); hh.textContent = S.more; head.appendChild(hh);
			secondary.appendChild(head);
			var stable = document.createElement('table'); stable.className = 'form-table'; stable.setAttribute('role', 'presentation');
			var stbody = document.createElement('tbody'); stable.appendChild(stbody); secondary.appendChild(stable);
			loose.forEach(function (t) {
				Array.prototype.forEach.call(t.querySelectorAll('tr'), function (tr) { stbody.appendChild(tr); });
				if (t.parentNode) t.parentNode.removeChild(t);
			});
			if (stbody.children.length) { panels.appendChild(secondary); }
		} else {
			var moreId = uid('more');
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

	// Anchor any secondary cards (cards whose `data-tab` doesn't match their own
	// id) right after their primary so each tab's content stays grouped after
	// the sort+re-append above moved the primaries around.
	Array.prototype.slice.call(panels.children).forEach(function (p) {
		var owner = p.dataset.tab;
		if (!owner || owner === p.id) { return; }
		var primary = document.getElementById(owner);
		if (primary && primary !== p) {
			primary.insertAdjacentElement('afterend', p);
		}
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
		// Toggle by `data-tab` (not `id`) so secondary cards sharing a tab with
		// their primary show/hide together — see the loose-rows attach above.
		Array.prototype.forEach.call(panels.children, function (p) { p.hidden = (p.dataset.tab !== id); });
		// Reflect the active tab in the URL so the page can be deep-linked
		// (e.g. `profile.php#settings`). `replaceState` keeps history clean
		// — clicking tabs doesn't pile up entries.
		if (location.hash.slice(1) !== id) {
			if (history.replaceState) {
				history.replaceState(null, '', '#' + id);
			} else {
				location.hash = id;
			}
		}
	}
	function tabFor(slug) {
		for (var i = 0; i < buttons.length; i++) {
			if (buttons[i].dataset.target === slug) { return slug; }
		}
		return null;
	}
	tabs.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		if (b) activate(b.dataset.target);
	});
	// Respond to manual URL edits / back-forward / same-page anchor clicks so
	// the tab follows the address bar.
	window.addEventListener('hashchange', function () {
		var id = tabFor(location.hash.slice(1));
		if (id) { activate(id); }
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
	// Initial activation: URL hash wins (deep link from support / docs), then
	// first tab as fallback.
	activate(tabFor(location.hash.slice(1)) || buttons[0].dataset.target);

	// On narrow screens, move all page-title-action buttons (native "Add New User"
	// + any added by AdminKit, e.g. "Refresh avatar") below the tabs + panels —
	// they shouldn't sit above the content on mobile. Restored to the header on
	// wide screens.
	var pageActions = Array.prototype.slice.call(document.querySelectorAll('.page-title-action'));
	if (pageActions.length) {
		var actionHome = pageActions[0].parentNode;
		var narrow = window.matchMedia('(max-width: 782px)');
		var placeAction = function () {
			pageActions.forEach(function (a) {
				if (narrow.matches) {
					box.appendChild(a);
				} else if (a.parentNode !== actionHome) {
					actionHome.appendChild(a);
				}
			});
		};
		placeAction();
		if (narrow.addEventListener) narrow.addEventListener('change', placeAction);
		else if (narrow.addListener) narrow.addListener(placeAction);
	}

	// Layout fully built — reveal the form (clears the pre-paint anti-FOUC hide).
	reveal();
})();
