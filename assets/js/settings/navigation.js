/** Navigation host: pane routing and main/legacy request contracts. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitScreens = window.AdminKitScreens || {};
	window.AdminKitParts.navigation = window.AdminKitScreens.navigation = function (context) {
		var D = context.D;
		var I = context.I;
		var el = context.el;
		var intro = context.intro;
		var controls = window.AdminKitParts.navigationControls(context);
		var menu = window.AdminKitParts.navigationMenu(context, controls);
		var toolbar = window.AdminKitParts.navigationToolbar(context, controls);
		var activeSection = 'menu';
		var selectNavSection = null;

		function buildNavigation() {
			var p = el('section', { class: 'ak-panel ak-panel--navigation ak-foundations', role: 'tabpanel' });
			var sections = [];
			var sectionKey = 'adminkit:navigation-section:' + location.pathname + location.search;
			var remembered = 'menu';
			try {
				remembered = sessionStorage.getItem(sectionKey) || remembered;
			} catch (error) {}
			if (location.hash === '#toolbar') {
				remembered = 'toolbar';
			} else if (location.hash === '#menu') {
				remembered = 'menu';
			}

			var editor = el('div', { class: 'ak-foundations-editor' });
			var nav = el('div', {
				class: 'ak-foundations-nav',
				role: 'tablist',
				'aria-label': I.navigation,
				'aria-orientation': 'horizontal'
			});
			var panes = el('div', { class: 'ak-foundations-panes' });

			function addSection(id, title, pane) {
				var tab = el('button', {
					type: 'button',
					role: 'tab',
					id: 'ak-navigation-tab-' + id,
					'aria-controls': 'ak-navigation-' + id,
					'aria-selected': 'false',
					tabindex: '-1',
					text: title
				});
				pane.classList.add('ak-foundations-panel');
				pane.id = 'ak-navigation-' + id;
				pane.setAttribute('aria-labelledby', tab.id);
				pane.hidden = true;
				tab.addEventListener('click', function () {
					selectSection(id);
				});
				sections.push({ id: id, title: title, tab: tab, pane: pane });
				nav.appendChild(tab);
				panes.appendChild(pane);
			}

			function selectSection(id) {
				if (
					!sections.some(function (item) {
						return item.id === id;
					})
				) {
					id = 'menu';
				}
				activeSection = id;
				try {
					sessionStorage.setItem(sectionKey, id);
				} catch (error) {}
				sections.forEach(function (item) {
					var active = item.id === id;
					item.tab.setAttribute('aria-selected', String(active));
					item.tab.tabIndex = active ? 0 : -1;
					item.pane.hidden = !active;
				});
			}

			selectNavSection = selectSection;

			nav.addEventListener('keydown', function (event) {
				var index = sections.findIndex(function (item) {
					return item.tab === document.activeElement;
				});
				if (index < 0) {
					return;
				}
				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
					index = (index + 1) % sections.length;
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
					index = (index + sections.length - 1) % sections.length;
				} else if (event.key === 'Home') {
					index = 0;
				} else if (event.key === 'End') {
					index = sections.length - 1;
				} else {
					return;
				}
				event.preventDefault();
				selectSection(sections[index].id);
				sections[index].tab.focus();
			});

			addSection('menu', I.menu, menu.build());
			addSection('toolbar', I.toolbarTab, toolbar.build());

			p.appendChild(el('h2', { class: 'ak-foundations-title', text: I.navigation }));
			p.appendChild(intro(I.navigationIntro || I.menuIntro));
			editor.appendChild(nav);
			editor.appendChild(panes);
			p.appendChild(editor);
			selectSection(remembered);
			return p;
		}

		return {
			buildMenu: menu.build,
			buildToolbar: toolbar.build,
			buildNavigation: buildNavigation,
			selectSection: function (id) {
				activeSection = id;
				if (selectNavSection) {
					selectNavSection(id);
				}
			},
			activeRequest: function () {
				return activeSection === 'toolbar'
					? toolbar.request()
					: menu.request();
			},
			menuRequest: function () {
				return menu.request();
			},
			toolbarRequest: function () {
				return toolbar.request();
			},
			tabs:
				D.screen === 'toolbar'
					? [{ id: 'toolbar', label: I.toolbarTab, build: toolbar.build }]
					: [{ id: 'menu', label: I.menu, build: menu.build }],
			request: function () {
				return D.screen === 'toolbar'
					? toolbar.request()
					: menu.request();
			}
		};
	};
})();
