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
	var revisions = {};
	var revision = 0;
	var reloadTimer = null;
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
	var fallbackTitle = app.previousElementSibling;
	if (fallbackTitle && fallbackTitle.matches('h1.screen-reader-text')) { fallbackTitle.remove(); }
	app.textContent = '';
	app.removeAttribute('aria-busy');
	var actions = [];
	if (D.pages) { app.classList.add('has-native-pages'); }
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
				id: 'ak-settings-import',
				text: I.settingsImport,
				title: I.settingsImportHint,
				onclick: function () {
					input.click();
				}
			}),
			input
		);
	}
	if (surface.setActions) { surface.setActions(actions); }
	app.appendChild(
		el('div', { class: 'ak-head' }, [
			el('h1', { class: 'ak-title', text: D.pageTitle || 'AdminKit' }),
			el('div', { class: 'ak-actions' }, surface.setActions ? [] : actions)
		])
	);
	var tabs = surface.tabs;
	var activeTabId = tabs[0] ? tabs[0].id : '';
	var panelWrap = el('div', { class: 'ak-panels' });
	if (tabs.length === 1) {
		var single = tabs[0].build();
		single.removeAttribute('role');
		panelWrap.appendChild(single);
	} else {
		var nav = el('div', { class: 'ak-tabs', role: 'tablist', 'aria-label': 'AdminKit', hidden: D.pages ? 'hidden' : null });
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
	if (surface.request) {
		app.appendChild(el('div', { class: 'ak-savebar' }, [statusEl, saveBtn]));
	}
	updateBar();

	window.addEventListener('beforeunload', function (event) { if (state.dirty) { event.preventDefault(); event.returnValue = ''; } });

	function go(id) {
		if (location.hash === '#' + id) {
			selectTab(id);
		} else {
			location.hash = id;
		}
	}
	function applyHash() {
		var rawId = location.hash.slice(1);
		var id = surface.onHash ? surface.onHash(rawId) : rawId;
		selectTab(
			tabs.some(function (tab) {
				return tab.id === id;
			})
				? id
				: (D.initialTab || tabs[0].id)
		);
		if (surface.onHash && rawId !== id) {
			surface.onHash(rawId);
		}
	}
	function selectTab(id) {
		activeTabId = id;
		if (D.pages) { var activePage = tabs.filter(function (tab) { return tab.id === id; })[0]; var pageTitle = app.querySelector('.ak-title'); if (activePage && pageTitle) { pageTitle.textContent = activePage.label; } }
		if (surface.onSelect) { surface.onSelect(id); }
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
		// Imports replace the workspace: prevent edits until reload, but keep status accessible.
		app.querySelectorAll('.ak-panels, .ak-tabs, .ak-actions').forEach(function (region) {
			region.inert = !!state.importing;
		});
		saveBtn.disabled = state.saving || !state.dirty;
		var importBtn = document.getElementById('ak-settings-import');
		if (importBtn) { importBtn.disabled = state.saving || state.dirty; }
		if (state.saving) {
			setStatus('is-saving', I.saving);
		} else if (state.dirty) {
			setStatus('is-dirty', I.unsaved);
		}
	}
	function markDirty(scope) {
		clearTimeout(reloadTimer);
		revisions[scope || (screen === 'main' ? 'settings' : screen)] = ++revision;
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
		var invalid = app.querySelector('[aria-invalid="true"]');
		if (invalid) {
			setStatus('is-error', invalid.validationMessage || I.foundationInvalidColor || I.error);
			invalid.dispatchEvent(new Event('adminkit:reveal-field'));
			var panel = invalid.closest('[role="tabpanel"][hidden]');
			if (panel) { var tab = document.getElementById(panel.getAttribute('aria-labelledby')); if (tab) { tab.click(); } }
			invalid.focus();
			return;
		}
		var requests = surface.requests ? surface.requests() : {};
		if (!surface.requests) {
			Object.keys(revisions).forEach(function (scope) { requests[scope] = surface.request(activeTabId); });
		}
		// Save layouts before settings can disable their routes on the next request.
		var pending = Object.keys(revisions).sort(function (a, b) {
			return (a === 'settings' ? 1 : 0) - (b === 'settings' ? 1 : 0);
		}).map(function (scope) {
			return { scope: scope, revision: revisions[scope], request: requests[scope] ? JSON.parse(JSON.stringify(requests[scope])) : null };
		});
		if (!pending.length || pending.some(function (item) { return !item.request || !item.request.path; })) {
			setStatus('is-error', I.error);
			return;
		}
		state.saving = true;
		updateBar();
		// Keep endpoint ownership separate, but save every pending draft before reloading.
		var chain = Promise.resolve();
		pending.forEach(function (item) {
			chain = chain.then(function () {
				item.request.path = '/' + item.request.path.replace(/^\//, '');
				item.request.method = 'POST';
				return apiFetch(item.request);
			}).then(function (response) {
				if (surface.onSaved) { surface.onSaved(item.scope, item.request.data, response); }
				if (revisions[item.scope] === item.revision) { delete revisions[item.scope]; }
			});
		});
		chain.then(function () {
			state.saving = false;
			state.dirty = Object.keys(revisions).length > 0;
			updateBar();
			if (!state.dirty) {
				setStatus('is-saved', I.saved);
				reloadTimer = setTimeout(function () { if (!state.dirty) { location.reload(); } }, 600);
			}
		}).catch(function () {
			state.saving = false;
			state.dirty = Object.keys(revisions).length > 0;
			updateBar();
			setStatus('is-error', I.error);
		});
	}
})();
