/**
 * AdminKit — account screen DOM harvesting, section absorption and grid pairing helpers.
 *
 * Consumed by `assets/js/wp-core/profile-account.js` without a build step via
 * `window.AdminKitProfileAccountDom`.
 */
(function () {
	'use strict';

	var ICONS = {
		users:    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0M16 11a3 3 0 0 0 0-6M22 21a6 6 0 0 0-4-5.6"/></svg>',
		sliders:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>',
		billing:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
		shipping: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-1"/><circle cx="7" cy="18" r="2"/><path d="M9 18h6"/><circle cx="18" cy="18" r="2"/></svg>',
		address:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>',
		camera:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l1.2-2h3.6L15 6h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3z"/><circle cx="12" cy="13" r="3.2"/></svg>',
		acf:      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',
		lock:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
		plugin:   '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3v4M14 3v4M6 7h12v5a6 6 0 0 1-12 0zM12 17v4"/></svg>'
	};

	function firstMatch(root, selectorOrList) {
		var selectors = [].concat(selectorOrList);
		for (var i = 0; i < selectors.length; i++) {
			var node = root.querySelector(selectors[i]);
			if (node) {
				return node;
			}
		}
		return null;
	}

	function topH2(form, titles) {
		titles = [].concat(titles);
		var h2s = form.querySelectorAll(':scope > h2');
		for (var i = 0; i < h2s.length; i++) {
			if (titles.indexOf(h2s[i].textContent.trim()) !== -1) {
				return h2s[i];
			}
		}
		return null;
	}

	function sectionNodes(h2) {
		var nodes = [],
			n = h2.nextSibling;
		while (n) {
			if (n.nodeType === 1 && (n.tagName === 'H2' || (n.classList && n.classList.contains('submit')))) {
				break;
			}
			nodes.push(n);
			n = n.nextSibling;
		}
		return nodes;
	}

	function sectionByField(form, sel) {
		var h2s = form.querySelectorAll(':scope > h2');
		for (var i = 0; i < h2s.length; i++) {
			var n = h2s[i].nextElementSibling;
			while (n && n.tagName !== 'H2') {
				if (n.classList && n.classList.contains('submit')) {
					break;
				}
				if ((n.matches && n.matches(sel)) || (n.querySelector && n.querySelector(sel))) {
					return h2s[i];
				}
				n = n.nextElementSibling;
			}
		}
		return null;
	}

	function moveRows(form, tbody, selectors) {
		(selectors || []).forEach(function (sel) {
			var row = firstMatch(form, sel);
			if (row) {
				tbody.appendChild(row);
			}
		});
	}

	function consume(card, tbody, nodes, subTitle) {
		var subAdded = false;
		nodes.forEach(function (node) {
			if (node.nodeType !== 1) {
				if (node.parentNode) {
					node.parentNode.removeChild(node);
				}
				return;
			}
			if (node.classList && node.classList.contains('form-table')) {
				Array.prototype.forEach.call(node.querySelectorAll('tr'), function (tr) {
					tbody.appendChild(tr);
				});
				if (node.parentNode) {
					node.parentNode.removeChild(node);
				}
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

	function absorb(form, card, tbody, titles) {
		var h2 = topH2(form, titles);
		if (!h2) {
			return;
		}
		consume(card, tbody, sectionNodes(h2), h2.textContent.trim());
		if (h2.parentNode) {
			h2.parentNode.removeChild(h2);
		}
	}

	function pluginIcon(scope) {
		if (scope.querySelector('.adminkit-local-avatar, .user-profile-picture')) {
			return 'camera';
		}
		if (scope.querySelector('[name^="billing_"], [id*="billing"]')) {
			return 'billing';
		}
		if (scope.querySelector('[name^="shipping_"], [id*="shipping"]')) {
			return 'shipping';
		}
		if (scope.querySelector('.acf-field, [name^="acf"]')) {
			return 'acf';
		}
		return 'plugin';
	}

	function tagCreateUserRows(form) {
		if (form.id !== 'createuser') {
			return;
		}
		var TAGS = {
			user_login:             'user-user-login-wrap',
			email:                  'user-email-wrap',
			first_name:             'user-first-name-wrap',
			last_name:              'user-last-name-wrap',
			url:                    'user-url-wrap',
			pass1:                  'user-pass1-wrap',
			pass2:                  'user-pass2-wrap',
			role:                   'user-role-wrap',
			locale:                 'user-language-wrap',
			send_user_notification: 'user-send-notification-wrap',
			noconfirmation:         'user-noconfirmation-wrap'
		};
		Object.keys(TAGS).forEach(function (name) {
			var input = form.querySelector('#' + name);
			var tr = input && input.closest('tr');
			if (tr) {
				tr.classList.add(TAGS[name]);
			}
		});
	}

	function describeRoleSelect(form, S) {
		if (form.id !== 'createuser') {
			return;
		}
		var roleSelect = form.querySelector('select#role');
		if (!roleSelect || !S.role_descriptions) {
			return;
		}
		var roleHelp = document.createElement('div');
		roleHelp.id = 'ak-role-description';
		roleHelp.className = 'description';
		roleHelp.setAttribute('aria-live', 'polite');
		roleHelp.setAttribute('aria-atomic', 'true');
		roleSelect.insertAdjacentElement('afterend', roleHelp);
		roleSelect.setAttribute('aria-describedby', [roleSelect.getAttribute('aria-describedby'), roleHelp.id].filter(Boolean).join(' '));
		var roleTitle = document.createElement('strong');
		var roleDetail = document.createElement('span');
		roleHelp.append(roleTitle, roleDetail);
		function describeRole() {
			var level =
				{ administrator: 'critical', editor: 'high', author: 'medium', contributor: 'limited', subscriber: 'minimal' }[
					roleSelect.value
				] || 'custom';
			roleHelp.dataset.level = level;
			roleTitle.textContent = S.role_levels[level];
			roleDetail.textContent = Object.prototype.hasOwnProperty.call(S.role_descriptions, roleSelect.value)
				? S.role_descriptions[roleSelect.value]
				: S.role_descriptions.custom;
		}
		roleSelect.addEventListener('change', describeRole);
		describeRole();
	}

	function mountProfilePictureHero(form) {
		var row = firstMatch(form, '.user-profile-picture');
		if (!row) {
			return;
		}
		var cell = row.querySelector('td');
		if (!cell) {
			return;
		}

		var hero = document.createElement('div');
		hero.className = 'ak-profile-picture-hero';
		var content = document.createElement('div');
		content.className = 'ak-profile-picture-hero__content';
		while (cell.firstChild) {
			content.appendChild(cell.firstChild);
		}
		hero.appendChild(content);
		row.remove();

		var heroImg = content.querySelector('img');
		if (heroImg && !heroImg.id) {
			heroImg.id = 'ak-hero-avatar';
		}

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

	function makePanel(id, label, desc) {
		var card = document.createElement('section');
		card.className = 'ak-card';
		card.id = 'ak-account-panel-' + id;
		card.dataset.tab = id;
		card.setAttribute('role', 'tabpanel');
		card.setAttribute('aria-labelledby', 'tab-' + id);
		var head = document.createElement('div');
		head.className = 'ak-card-head';
		var h = document.createElement('h2');
		h.textContent = label;
		head.appendChild(h);
		if (desc) {
			var p = document.createElement('p');
			p.className = 'ak-card-desc';
			p.textContent = desc;
			head.appendChild(p);
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

	function markHalf(card, selectors) {
		(selectors || []).forEach(function (sel) {
			var row = firstMatch(card, sel);
			if (row) {
				row.classList.add('ak-half');
			}
		});
	}

	function markThird(card, selectors) {
		(selectors || []).forEach(function (sel) {
			var row = firstMatch(card, sel);
			if (row) {
				row.classList.add('ak-third');
			}
		});
	}

	function pairFractions(tbody) {
		if (!tbody) {
			return;
		}
		['ak-half', 'ak-third'].forEach(function (cls) {
			var span = cls === 'ak-half' ? 2 : 3;
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

	window.AdminKitProfileAccountDom = {
		ICONS: ICONS,
		firstMatch: firstMatch,
		topH2: topH2,
		sectionNodes: sectionNodes,
		sectionByField: sectionByField,
		moveRows: moveRows,
		consume: consume,
		absorb: absorb,
		pluginIcon: pluginIcon,
		tagCreateUserRows: tagCreateUserRows,
		describeRoleSelect: describeRoleSelect,
		mountProfilePictureHero: mountProfilePictureHero,
		makePanel: makePanel,
		markHalf: markHalf,
		markThird: markThird,
		pairFractions: pairFractions
	};
})();
