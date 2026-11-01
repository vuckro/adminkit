/**
 * AdminKit — account screen tabs.
 *
 * Rebuilds profile.php / user-edit.php / user-new.php as a tab strip + one visible
 * panel by moving native `<tr>`s without cloning. DOM harvesting helpers live in
 * `assets/js/wp-core/profile-account-dom.js` (`window.AdminKitProfileAccountDom`).
 */
(function () {
	'use strict';

	var S = window.AdminKitProfileAccount || {};
	var dom = window.AdminKitProfileAccountDom;
	var docEl = document.documentElement;

	function reveal() {
		if (S.pendingClass) {
			docEl.classList.remove(S.pendingClass);
		}
		if (S.readyClass) {
			docEl.classList.add(S.readyClass);
		}
	}

	if (!dom) {
		reveal();
		return;
	}

	var isExistingAccount = document.body.classList.contains('profile-php') || document.body.classList.contains('user-edit-php');
	var form = document.querySelector('#your-profile, #createuser');
	if (!form || form.dataset.akAccount) {
		reveal();
		return;
	}
	form.dataset.akAccount = '1';

	var bootHash = location.hash.slice(1);

	dom.tagCreateUserRows(form);
	dom.describeRoleSelect(form, S);
	dom.mountProfilePictureHero(form);

	var apw = form.querySelector('#application-passwords-section');
	if (apw) {
		var apwH2 = apw.querySelector('h2');
		if (apwH2) {
			form.insertBefore(apwH2, apw);
		}
	}

	var loginRow = dom.firstMatch(form, ['.user-user-login-wrap', '.user-login-wrap']);
	if (loginRow && form.id !== 'createuser') {
		var loginCell = loginRow.querySelector('td');
		if (loginCell && !loginCell.querySelector('.description')) {
			var note = document.createElement('p');
			note.className = 'description';
			note.textContent = S.username_locked;
			loginCell.appendChild(note);
		}
	}

	var box = document.createElement('div');
	box.className = 'ak-account';
	var tabs = document.createElement('div');
	tabs.className = 'ak-tabs';
	tabs.setAttribute('role', 'tablist');
	tabs.setAttribute('aria-label', S.nav);
	var panels = document.createElement('div');
	panels.className = 'ak-panels';
	box.appendChild(tabs);
	box.appendChild(panels);
	form.insertBefore(box, form.firstChild);

	var used = {};
	function uid(base) {
		var id = base,
			i = 2;
		while (used[id]) {
			id = base + '-' + i++;
		}
		used[id] = 1;
		return id;
	}
	function panelFor(id) {
		return document.getElementById('ak-account-panel-' + id);
	}
	function addTab(id, icon, label, order) {
		var b = document.createElement('button');
		b.type = 'button';
		b.id = 'tab-' + id;
		b.dataset.target = id;
		b.dataset.order = order;
		b.setAttribute('role', 'tab');
		b.setAttribute('aria-controls', 'ak-account-panel-' + id);
		var ic = document.createElement('span');
		ic.className = 'ic';
		// eslint-disable-next-line no-unsanitized/property -- Static dom.ICONS SVG map.
		ic.innerHTML = dom.ICONS[icon] || dom.ICONS.plugin;
		var tx = document.createElement('span');
		tx.className = 'tx';
		tx.textContent = label;
		b.appendChild(ic);
		b.appendChild(tx);
		tabs.appendChild(b);
	}

	var CARDS = [
		{
			id: 'information',
			icon: 'users',
			t: S.cards.info,
			grid: true,
			order: 0,
			rows: [
				'.user-first-name-wrap',
				'.user-last-name-wrap',
				'.user-email-wrap',
				'.user-nickname-wrap',
				'.user-display-name-wrap',
				'.user-role-wrap',
				'#ame-rex-other-roles-row',
				['.user-user-login-wrap', '.user-login-wrap'],
				['#password', '.user-pass1-wrap'],
				'.user-pass2-wrap',
				'.pw-weak',
				'.user-generate-reset-link-wrap'
			],
			half: [
				'.user-first-name-wrap',
				'.user-last-name-wrap',
				'.user-email-wrap',
				'.user-nickname-wrap',
				'.user-display-name-wrap',
				'.user-role-wrap',
				['#password', '.user-pass1-wrap'],
				'.user-generate-reset-link-wrap'
			],
			absorb: [S.sections.name]
		},
		{
			id: 'settings',
			icon: 'sliders',
			t: S.cards.settings,
			order: 30,
			rows: [
				'.user-language-wrap',
				'.user-url-wrap',
				'.user-description-wrap',
				'.user-syntax-highlighting-wrap',
				'.user-comment-shortcuts-wrap',
				['.user-admin-bar-front-wrap', '.show-admin-bar']
			],
			absorb: [
				S.sections.app_passwords,
				S.sections.personal,
				S.sections.contact,
				S.sections.about,
				S.sections.account,
				S.sections.capabilities
			]
		}
	];

	var build = CARDS.map(function (def) {
		used[def.id] = 1;
		var c = dom.makePanel(def.id, def.t.label, def.t.desc);
		dom.moveRows(form, c.tbody, def.rows);
		return { def: def, c: c };
	});
	build.forEach(function (b) {
		(b.def.absorb || []).forEach(function (title) {
			dom.absorb(form, b.c.card, b.c.tbody, title);
		});
	});
	build.forEach(function (b) {
		if (b.c.tbody.children.length || b.c.card.children.length > 2) {
			if (b.def.grid) {
				b.c.card.classList.add('ak-grid');
				dom.markHalf(b.c.card, b.def.half);
				dom.markThird(b.c.card, b.def.third);
				dom.pairFractions(b.c.tbody);
			}
			panels.appendChild(b.c.card);
			addTab(b.def.id, b.def.icon, b.def.t.label, b.def.order);
		} else {
			delete used[b.def.id];
		}
	});

	(function () {
		var sections = ['[name^="billing_"]', '[name^="shipping_"]']
			.map(function (sel) {
				return dom.sectionByField(form, sel);
			})
			.filter(Boolean);
		if (!sections.length) {
			return;
		}
		var c = dom.makePanel(uid('addresses'), S.addresses, '');
		c.card.classList.add('ak-grid');
		if (c.tbody.parentNode) {
			c.tbody.parentNode.remove();
		}
		sections.forEach(function (h2, idx) {
			var d = document.createElement('details');
			d.className = 'ak-subsection';
			if (idx === 0) {
				d.open = true;
			}
			var sum = document.createElement('summary');
			sum.textContent = h2.textContent.trim();
			d.appendChild(sum);
			dom.sectionNodes(h2).forEach(function (node) {
				if (node.nodeType === 1 && node.tagName !== 'H2') {
					d.appendChild(node);
				} else if (node.parentNode) {
					node.parentNode.removeChild(node);
				}
			});
			['_first_name', '_last_name', '_city', '_postcode', '_country', '_state', '_phone', '_email'].forEach(function (suffix) {
				var field = d.querySelector('[id$="' + suffix + '"]');
				var tr = field && field.closest('tr');
				if (tr) {
					tr.classList.add('ak-half');
				}
			});
			c.card.appendChild(d);
			if (h2.parentNode) {
				h2.parentNode.removeChild(h2);
			}
		});
		panels.appendChild(c.card);
		addTab(c.card.dataset.tab, 'address', S.addresses, 10);
	})();

	Array.prototype.forEach.call(form.querySelectorAll(':scope > h2'), function (h2) {
		var label = h2.textContent.trim();
		var slug = label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'section';
		var id = uid(slug);
		var c = dom.makePanel(id, label, '');
		dom.consume(c.card, c.tbody, dom.sectionNodes(h2), null);
		if (h2.parentNode) {
			h2.parentNode.removeChild(h2);
		}
		if (c.tbody.children.length || c.card.children.length > 2) {
			var icon = dom.pluginIcon(c.card);
			var order = icon === 'billing' ? 10 : icon === 'shipping' ? 20 : 40;
			panels.appendChild(c.card);
			addTab(id, icon, label, order);
		}
	});

	var loose = Array.prototype.filter.call(form.children, function (ch) {
		return ch.classList && ch.classList.contains('form-table') && ch.querySelector('tr');
	});
	if (loose.length) {
		var settingsCard = used['settings'] ? panelFor('settings') : null;
		if (settingsCard) {
			var secondary = document.createElement('section');
			secondary.className = 'ak-card';
			secondary.dataset.tab = 'settings';
			var head = document.createElement('div');
			head.className = 'ak-card-head';
			var hh = document.createElement('h2');
			hh.textContent = S.more;
			head.appendChild(hh);
			secondary.appendChild(head);
			var stable = document.createElement('table');
			stable.className = 'form-table';
			stable.setAttribute('role', 'presentation');
			var stbody = document.createElement('tbody');
			stable.appendChild(stbody);
			secondary.appendChild(stable);
			loose.forEach(function (t) {
				Array.prototype.forEach.call(t.querySelectorAll('tr'), function (tr) {
					stbody.appendChild(tr);
				});
				if (t.parentNode) {
					t.parentNode.removeChild(t);
				}
			});
			if (stbody.children.length) {
				panels.appendChild(secondary);
			}
		} else {
			var moreId = uid('more');
			var more = dom.makePanel(moreId, S.more, '');
			loose.forEach(function (t) {
				Array.prototype.forEach.call(t.querySelectorAll('tr'), function (tr) {
					more.tbody.appendChild(tr);
				});
				if (t.parentNode) {
					t.parentNode.removeChild(t);
				}
			});
			if (more.tbody.children.length) {
				panels.appendChild(more.card);
				addTab(moreId, dom.pluginIcon(more.card), S.more, 90);
			}
		}
	}

	if (isExistingAccount) {
		var settings = panelFor('settings');
		if (settings) {
			var parts = [
				{ id: 'public-profile', title: S.public_profile, desc: S.public_profile_desc },
				{ id: 'admin-preferences', title: S.admin_preferences, desc: document.body.classList.contains('user-edit-php') ? '' : S.admin_preferences_desc },
				{ id: 'sessions', title: S.profile_sessions, desc: document.body.classList.contains('user-edit-php') ? '' : S.profile_sessions_desc },
				{ id: 'applications', title: S.profile_apps, desc: document.body.classList.contains('user-edit-php') ? '' : S.profile_apps_desc }
			].map(function (def) {
				var part = dom.makePanel('profile-' + def.id, def.title, def.desc);
				part.card.id = 'ak-profile-' + def.id;
				part.card.removeAttribute('role');
				part.card.removeAttribute('data-tab');
				part.card.querySelector('h2').id = part.card.id + '-heading';
				part.card.setAttribute('aria-labelledby', part.card.id + '-heading');
				return part;
			});
			Array.prototype.slice.call(settings.querySelectorAll(':scope > .form-table > tbody > tr')).forEach(function (row) {
				var target = row.matches('.user-language-wrap, .user-url-wrap, .user-description-wrap')
					? 0
					: row.matches('.user-sessions-wrap')
						? 2
						: 1;
				parts[target].tbody.appendChild(row);
			});
			var applications = settings.querySelector('#application-passwords-section');
			if (applications) {
				parts[3].card.appendChild(applications);
			}
			Array.prototype.slice.call(settings.children).forEach(function (node) {
				if (node.classList.contains('ak-card-head') || node.classList.contains('form-table')) {
					return;
				}
				if (node.classList.contains('ak-card-sub') && node.textContent.trim() === S.sections.app_passwords) {
					return;
				}
				parts[1].card.appendChild(node);
			});
			settings.className = 'ak-settings-layout';
			settings.replaceChildren();
			var securityPanel = document.createElement('div');
			securityPanel.className = 'ak-settings-layout';
			securityPanel.id = 'ak-account-panel-security';
			securityPanel.dataset.tab = 'security';
			securityPanel.setAttribute('role', 'tabpanel');
			securityPanel.setAttribute('aria-labelledby', 'tab-security');
			parts.forEach(function (part, index) {
				if (!part.tbody.children.length) {
					part.tbody.parentNode.remove();
				}
				if (part.card.querySelector('.form-table, #application-passwords-section') || part.card.children.length > 1) {
					(index < 2 ? settings : securityPanel).appendChild(part.card);
				}
			});
			if (securityPanel.children.length) {
				panels.appendChild(securityPanel);
				addTab('security', 'lock', S.security, 31);
			}
		}
	}

	Array.prototype.slice
		.call(tabs.children)
		.sort(function (a, b) {
			var d = +a.dataset.order - +b.dataset.order;
			return d || a.textContent.localeCompare(b.textContent);
		})
		.forEach(function (btn) {
			tabs.appendChild(btn);
			var panel = panelFor(btn.dataset.target);
			if (panel) {
				panels.appendChild(panel);
			}
		});

	Array.prototype.slice.call(panels.children).forEach(function (p) {
		var owner = p.dataset.tab;
		if (!owner) {
			return;
		}
		var primary = panelFor(owner);
		if (primary && primary !== p) {
			primary.insertAdjacentElement('afterend', p);
		}
	});

	if (isExistingAccount || form.id === 'createuser') {
		var information = panelFor('information');
		if (information) {
			var identity = dom.makePanel('ak-profile-identity', S.identity_contact, '');
			var security = dom.makePanel('ak-profile-security', S.security, '');
			identity.card.id = 'ak-profile-identity';
			security.card.id = 'ak-profile-security';
			[identity, security].forEach(function (part) {
				part.card.classList.add('ak-grid');
				part.card.removeAttribute('role');
				part.card.removeAttribute('aria-labelledby');
				part.card.removeAttribute('data-tab');
				part.card.setAttribute('aria-labelledby', part.card.id + '-heading');
				part.card.querySelector('h2').id = part.card.id + '-heading';
			});
			var securityRows = '.user-user-login-wrap, .user-login-wrap, #password, .user-pass1-wrap, .user-pass2-wrap, .pw-weak, .user-generate-reset-link-wrap';
			Array.prototype.slice.call(information.querySelectorAll(':scope > .form-table > tbody > tr')).forEach(function (row) {
				row.classList.remove('ak-half', 'ak-third');
				(row.matches(securityRows) ? security.tbody : identity.tbody).appendChild(row);
			});
			dom.markHalf(identity.card, ['.user-first-name-wrap', '.user-last-name-wrap', '.user-nickname-wrap', '.user-display-name-wrap']);
			dom.pairFractions(identity.tbody);
			information.className = 'ak-information-layout';
			information.replaceChildren(identity.card, security.card);
			form.classList.add('ak-profile-workspace');
		}
	}

	if (form.id === 'createuser') {
		var creationSettings = panelFor('settings');
		if (creationSettings) {
			var settingsLayout = document.createElement('div');
			settingsLayout.className = 'ak-settings-layout';
			settingsLayout.id = creationSettings.id;
			settingsLayout.dataset.tab = 'settings';
			settingsLayout.setAttribute('role', 'tabpanel');
			settingsLayout.setAttribute('aria-labelledby', 'tab-settings');
			creationSettings.id = 'ak-createuser-settings';
			creationSettings.removeAttribute('role');
			creationSettings.removeAttribute('aria-labelledby');
			panels.insertBefore(settingsLayout, creationSettings);
			Array.prototype.slice.call(panels.children).forEach(function (part) {
				if (part !== settingsLayout && part.dataset.tab === 'settings') {
					part.removeAttribute('data-tab');
					settingsLayout.appendChild(part);
				}
			});
		}
	}

	var buttons = tabs.querySelectorAll('button');
	if (!buttons.length) {
		box.parentNode.removeChild(box);
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
		Array.prototype.forEach.call(panels.children, function (p) {
			p.hidden = p.dataset.tab !== id;
		});
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
			if (buttons[i].dataset.target === slug) {
				return slug;
			}
		}
		return null;
	}
	tabs.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		if (b) {
			activate(b.dataset.target);
		}
	});
	window.addEventListener('hashchange', function () {
		var id = tabFor(location.hash.slice(1));
		if (id) {
			activate(id);
		}
	});
	tabs.addEventListener('keydown', function (e) {
		if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].indexOf(e.key) === -1) {
			return;
		}
		var list = Array.prototype.slice.call(buttons);
		var i = list.indexOf(document.activeElement);
		if (i === -1) {
			return;
		}
		var next =
			e.key === 'Home'
				? list[0]
				: e.key === 'End'
					? list[list.length - 1]
					: list[(i + (e.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length];
		next.focus();
		activate(next.dataset.target);
		e.preventDefault();
	});
	var revealingInvalid = false;
	form.addEventListener(
		'invalid',
		function (e) {
			if (revealingInvalid) {
				return;
			}
			revealingInvalid = true;
			setTimeout(function () {
				revealingInvalid = false;
			}, 0);
			var details = e.target.closest && e.target.closest('details');
			while (details) {
				details.open = true;
				details = details.parentElement && details.parentElement.closest('details');
			}
			var panel = e.target.closest('[data-tab]');
			if (panel) {
				activate(panel.dataset.tab);
			}
		},
		true
	);
	activate(tabFor(bootHash) || buttons[0].dataset.target);

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
		if (narrow.addEventListener) {
			narrow.addEventListener('change', placeAction);
		} else if (narrow.addListener) {
			narrow.addListener(placeAction);
		}
	}

	reveal();
})();
