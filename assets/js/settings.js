/** AdminKit screen host: shared controls, tab routing and one save endpoint per page. */
(function () {
	'use strict';
	var D = window.AdminKitData;
	var app = document.getElementById('adminkit-app');
	if (!D || !app) {
		return;
	}
	var I = D.i18n || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	var state = { dirty: false, saving: false };
	var screen = D.screen || 'main';
	var module = screen === 'menu' || screen === 'toolbar' ? 'navigation' : screen;
	var factory = window.AdminKitScreens && window.AdminKitScreens[module];
	if (!factory) {
		return;
	}

	var statusEl = el('span', { class: 'ak-status', 'aria-live': 'polite' });
	var saveBtn = el('button', { class: 'ak-btn ak-btn--primary', type: 'button', text: I.save, onclick: save });
	var surface = factory({
		D: D,
		I: I,
		apiFetch: apiFetch,
		state: state,
		el: el,
		intro: intro,
		markDirty: markDirty,
		setStatus: setStatus,
		updateBar: updateBar
	});
	app.textContent = '';
	app.removeAttribute('aria-busy');
	var actions = [statusEl];
	if (screen === 'main') {
		var input = el('input', {
			type: 'file',
			accept: 'application/json,.json',
			hidden: 'hidden',
			onchange: function (e) {
				if (e.target.files[0]) {
					surface.importSettings(e.target.files[0]);
				}
				e.target.value = '';
			}
		});
		actions.push(
			el('button', {
				class: 'ak-btn',
				type: 'button',
				text: I.settingsExport,
				title: I.settingsExportHint,
				onclick: surface.exportSettings
			})
		);
		actions.push(
			el('button', {
				class: 'ak-btn',
				type: 'button',
				text: I.settingsImport,
				title: I.settingsImportHint,
				onclick: function () {
					input.click();
				}
			}),
			input
		);
	}
	if (surface.request) {
		actions.push(saveBtn);
	}
	app.appendChild(
		el('div', { class: 'ak-head' }, [
			el('h1', { class: 'ak-title', text: 'AdminKit' }),
			el('div', { class: 'ak-actions' }, actions)
		])
	);
	var tabs = surface.tabs;
	var panelWrap = el('div', { class: 'ak-panels' });
	if (tabs.length === 1) {
		var single = tabs[0].build();
		single.removeAttribute('role');
		panelWrap.appendChild(single);
	} else {
		var nav = el('div', { class: 'ak-tabs', role: 'tablist', 'aria-label': 'AdminKit' });
		tabs.forEach(function (tab) {
			var icon = el('span', { class: 'ic', 'aria-hidden': 'true' });
			icon.innerHTML = tab.icon;
			tab.button = el(
				'button',
				{
					type: 'button',
					role: 'tab',
					id: 'ak-tab-' + tab.id,
					'aria-controls': 'ak-panel-' + tab.id,
					'aria-selected': 'false',
					tabindex: '-1',
					onclick: function () {
						go(tab.id);
					}
				},
				[icon, el('span', { class: 'tx', text: tab.label })]
			);
			nav.appendChild(tab.button);
			tab.panel = el('section', {
				id: 'ak-panel-' + tab.id,
				role: 'tabpanel',
				hidden: 'hidden',
				'aria-labelledby': tab.button.id
			});
			panelWrap.appendChild(tab.panel);
		});
		nav.addEventListener('keydown', function (e) {
			var i = tabs.findIndex(function (tab) {
				return tab.button.getAttribute('aria-selected') === 'true';
			});
			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
				i = (i + 1) % tabs.length;
			} else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
				i = (i - 1 + tabs.length) % tabs.length;
			} else if (e.key === 'Home') {
				i = 0;
			} else if (e.key === 'End') {
				i = tabs.length - 1;
			} else {
				return;
			}
			e.preventDefault();
			go(tabs[i].id);
			tabs[i].button.focus();
		});
		app.appendChild(nav);
		window.addEventListener('hashchange', applyHash);
		applyHash();
	}
	app.appendChild(panelWrap);
	updateBar();

	function go(id) {
		if (location.hash === '#' + id) {
			selectTab(id);
		} else {
			location.hash = id;
		}
	}
	function applyHash() {
		var id = location.hash.slice(1);
		if (id === 'menu' || id === 'stats') {
			location.href = 'admin.php?page=adminkit-' + id;
			return;
		}
		selectTab(
			tabs.some(function (tab) {
				return tab.id === id;
			})
				? id
				: tabs[0].id
		);
	}
	function selectTab(id) {
		tabs.forEach(function (tab) {
			var selected = tab.id === id;
			if (selected && !tab.built) {
				var previous = tab.panel;
				tab.panel = tab.build();
				tab.built = true;
				tab.panel.id = 'ak-panel-' + tab.id;
				tab.panel.setAttribute('aria-labelledby', tab.button.id);
				panelWrap.replaceChild(tab.panel, previous);
			}
			tab.button.classList.toggle('on', selected);
			tab.button.setAttribute('aria-selected', String(selected));
			tab.button.tabIndex = selected ? 0 : -1;
			if (tab.panel) {
				tab.panel.hidden = !selected;
			}
		});
	}
	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v == null) {
					return;
				}
				if (k === 'class') {
					n.className = v;
				} else if (k === 'text') {
					n.textContent = v;
				} else if (k.slice(0, 2) === 'on' && typeof v === 'function') {
					n.addEventListener(k.slice(2), v);
				} else {
					n.setAttribute(k, v);
				}
			});
		}
		(kids || []).forEach(function (c) {
			if (c == null) {
				return;
			}
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}
	function intro(text) {
		return el('p', { class: 'ak-intro', text: text });
	}
	function setStatus(cls, text) {
		statusEl.className = 'ak-status' + (cls ? ' ' + cls : '');
		statusEl.textContent = text || '';
	}
	function updateBar() {
		saveBtn.disabled = state.saving || !state.dirty;
		if (state.saving) {
			setStatus('is-saving', I.saving);
		} else if (state.dirty) {
			setStatus('is-dirty', I.unsaved);
		}
	}
	function markDirty() {
		state.dirty = true;
		updateBar();
	}
	function save() {
		if (state.saving || !state.dirty || !surface.request) {
			return;
		}
		if (!apiFetch) {
			setStatus('is-error', I.error);
			return;
		}
		var request = surface.request();
		if (!request || !request.path) {
			return;
		}
		request.path = '/' + request.path.replace(/^\//, '');
		request.method = 'POST';
		state.saving = true;
		updateBar();
		apiFetch(request)
			.then(function () {
				state.saving = false;
				state.dirty = false;
				updateBar();
				setStatus('is-saved', I.saved);
				setTimeout(function () {
					location.reload();
				}, 600);
			})
			.catch(function () {
				state.saving = false;
				updateBar();
				setStatus('is-error', I.error);
			});
	}
})();
