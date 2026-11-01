/** Feature discovery: category filters, selectable list and persistent detail panels. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.features = function (context) {
		var D = context.D, I = context.I, state = context.state, el = context.el;
		var refreshView = null;
		function buildFeatures() {
			var features = D.features || [];
			var p = el('section', { class: 'ak-panel ak-features', role: 'tabpanel' }, [
				el('h2', { class: 'ak-panel-title', text: I.features }), context.intro(I.featuresIntro)
			]);
			if (D.interfaceMode === 'wordpress') { p.appendChild(context.intro(I.interfaceNativeNotice)); }
			var refs = {}, groups = [], selected = '', category = '';
			if (location.hash.indexOf('#category=') === 0) { try { category = decodeURIComponent(location.hash.slice(10)); } catch (error) {} }
			var search = el('input', { type: 'search', class: 'ak-search', placeholder: I.searchFeatures, 'aria-label': I.searchFeatures });
			var status = el('select', { class: 'ak-features-status', 'aria-label': I.filterFeatures });
			['all', 'active', 'inactive'].forEach(function (value) {
				status.appendChild(el('option', { value: value, text: I['featuresFilter' + value.charAt(0).toUpperCase() + value.slice(1)] }));
			});
			var bulk = el('details', { class: 'ak-features-bulk' });
			var actions = el('div', { class: 'ak-features-bulk__actions' }, [el('p', { text: I.featureBulkScope })]);
			bulk.appendChild(el('summary', { text: I.featureBulk }));
			bulk.appendChild(actions);
			function setAll(mode) {
				var changed = false;
				features.forEach(function (f) {
					if (f.bulk === false || f.available === false) { return; }
					var value = mode === 'default' ? !!f.default : mode === 'on';
					if (!!state.features[f.key] !== value) { state.features[f.key] = value; changed = true; }
					if (mode === 'default') {
						(f.options || []).forEach(function (option) {
							if (!!state.features[option.key] !== !!option.default) { state.features[option.key] = !!option.default; changed = true; }
						});
					}
				});
				bulk.open = false;
				refresh();
				if (changed) { context.markDirty(); }
			}
			[['on', I.enableAll], ['off', I.disableAll], ['default', I.resetDefaults]].forEach(function (action) {
				actions.appendChild(el('button', { type: 'button', class: 'ak-btn', text: action[1], onclick: function () {
					setAll(action[0]); bulk.querySelector('summary').focus();
				} }));
			});
			p.appendChild(el('div', { class: 'ak-features-tools' }, [search, status, bulk]));
			var categories = el('nav', { class: 'ak-features-categories', 'aria-label': I.featureCategories });
			var list = el('div', { class: 'ak-features-list', 'aria-label': I.featureChoose, id: 'ak-feature-list' });
			var returnFocus = null;
			var details = el('dialog', { class: 'ak-features-details ak-feature-dialog', 'aria-label': I.pageConfigure, hidden: 'hidden' });
			function closeDetail() {
				if (typeof details.close === 'function') { details.close(); } else { details.removeAttribute('open'); }
				details.hidden = true;
				if (selected && refs[selected]) { refs[selected].button.setAttribute('aria-expanded', 'false'); }
				if (returnFocus && returnFocus.isConnected && !returnFocus.closest('[hidden]')) { returnFocus.focus(); }
			}
			details.appendChild(el('div', { class: 'ak-feature-dialog-head' }, [el('span', { text: I.pageConfigure }), el('button', { type: 'button', class: 'ak-btn', text: I.pageClose, onclick: closeDetail })]));
			details.addEventListener('cancel', function (event) { event.preventDefault(); closeDetail(); });
			details.addEventListener('click', function (event) { if (event.target === details) { var bounds = details.getBoundingClientRect(); if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) { closeDetail(); } } });
			var workspace = el('div', { class: 'ak-features-workspace' }, [list, details]);
			var empty = el('p', { class: 'ak-empty', role: 'status', hidden: 'hidden' });
			p.appendChild(categories); p.appendChild(empty); p.appendChild(workspace);

			function icon(f) {
				var n = el('span', { class: 'ak-features-icon', 'aria-hidden': 'true' });
				// Trusted markup comes only from the shared PHP Lucide renderer.
				// eslint-disable-next-line no-unsanitized/property -- Trusted PHP Lucide SVG icon.
				n.innerHTML = f.iconSvg || '';
				return n;
			}
			function active(f) { return f.available !== false && !!state.features[f.key] && (!f.parent || !!state.features[f.parent]); }
			function locked(f) { return f.available === false || !!(f.parent && !state.features[f.parent]); }
			function select(key) {
				if (D.pages && D.pages.navigation && state.features[key] && (key === 'menu_manager_enabled' || key === 'toolbar_manager_enabled')) {
					window.location.href = D.pages.navigation + (key === 'toolbar_manager_enabled' ? '#toolbar' : '#menu'); return;
				}
				selected = key; returnFocus = refs[key].button; details.setAttribute('aria-labelledby', refs[key].heading.id); details.hidden = false; refresh();
				if (typeof details.showModal === 'function') { if (!details.open) { details.showModal(); } } else { details.setAttribute('open', ''); }
				refs[key].heading.focus();
			}
			features.forEach(function (f, index) {
				var group = groups.filter(function (g) { return g.label === (f.group || ''); })[0];
				if (!group) {
					group = { label: f.group || '', keys: [], count: el('span', { class: 'ak-features-count' }) };
					group.rows = el('div', { class: 'ak-features-group__items' });
					group.wrap = el('section', { class: 'ak-features-group' }, [
						group.label ? el('h3', { class: 'ak-features-group__title', text: group.label }) : null, group.rows
					]);
					groups.push(group); list.appendChild(group.wrap);
				}
				group.keys.push(f.key);
				var panelId = 'ak-feature-detail-' + index;
				var heading = el('h3', { id: panelId + '-title', tabindex: '-1', text: f.label });
				var rowStatus = el('span', { class: 'ak-features-item__status' });
				var button = el('button', { type: 'button', class: 'ak-features-item', 'aria-controls': panelId, 'aria-expanded': 'false', 'aria-haspopup': 'dialog', onclick: function () { select(f.key); } }, [
					icon(f), el('span', { class: 'ak-features-item__text' }, [el('span', { class: 'ak-feature-row-heading' }, [el('span', { class: 'ak-features-item__label', text: f.label }), rowStatus]), el('span', { class: 'ak-feature-row-desc', text: f.desc })]), el('span', { class: 'ak-feature-config-label', text: I.pageConfigure })
				]);
				var quickInput = el('input', { type: 'checkbox', class: 'ak-switch__input', 'aria-label': f.label });
				quickInput.addEventListener('change', function () { state.features[f.key] = quickInput.checked; refresh(); context.markDirty(); });
				group.rows.appendChild(el('article', { class: 'ak-feature-catalog-row' }, [button, el('label', { class: 'ak-switch' }, [quickInput, el('span', { class: 'ak-switch__track' }), el('span', { class: 'ak-switch__knob' })])]));
				var input = el('input', { type: 'checkbox', class: 'ak-switch__input', 'aria-label': f.label, 'aria-describedby': panelId + '-hint' });
				input.addEventListener('change', function () {
					state.features[f.key] = input.checked;
					refresh(); context.markDirty();
				});
				var stateLabel = el('span', { class: 'ak-features-state__label' });
				var hint = el('p', { id: panelId + '-hint', class: 'ak-features-hint' });
				var pendingBadge = el('span', { class: 'ak-feature-pending', text: I.featurePending, hidden: 'hidden' });
				var panel = el('section', { class: 'ak-features-detail', id: panelId, role: 'region', 'aria-labelledby': panelId + '-title', hidden: 'hidden' }, [
					icon(f), heading, f.group ? el('p', { class: 'ak-features-detail__category', text: f.group }) : null,
					el('h4', { text: I.featureEffect }), el('p', { class: 'ak-features-effect', text: f.desc }),
					el('div', { class: 'ak-features-state' }, [el('div', {}, [el('div', { class: 'ak-feature-state-heading' }, [stateLabel, pendingBadge]), hint]),
						el('label', { class: 'ak-switch' }, [input, el('span', { class: 'ak-switch__track' }), el('span', { class: 'ak-switch__knob' })])])
				]);
				var optionInputs = [];
				var optionSet = null, optionNote = null, optionNoteTitle = null, optionNoteText = null, optionFooter = null;
				if ((f.options || []).length) {
					var optionHintId = panelId + '-options-hint';
					optionNoteTitle = el('strong');
					optionNoteText = el('p');
					optionNote = el('div', { id: panelId + '-options-pause', class: 'ak-feature-options-note', role: 'status', hidden: 'hidden' }, [optionNoteTitle, optionNoteText]);
					panel.appendChild(optionNote);
					optionSet = el('fieldset', { class: 'ak-feature-options', 'aria-describedby': optionHintId }, [el('legend', { text: I.featureOptions })]);
					(f.options || []).forEach(function (option, optionIndex) {
						var optionId = panelId + '-option-' + optionIndex;
						var optionInput = el('input', { id: optionId, type: 'checkbox', class: 'ak-switch__input', 'aria-label': option.label, 'aria-describedby': optionId + '-desc ' + optionId + '-state' });
						optionInput.addEventListener('change', function () { state.features[option.key] = optionInput.checked; refresh(); context.markDirty(); });
						var optionState = el('span', { id: optionId + '-state', class: 'ak-feature-option__state' });
						var optionPending = el('span', { id: optionId + '-pending', class: 'ak-feature-option__pending', text: I.featurePending, hidden: 'hidden' });
						optionInputs.push({ key: option.key, input: optionInput, data: option, status: optionState, pending: optionPending });
						optionSet.appendChild(el('div', { class: 'ak-feature-option' }, [
							el('div', {}, [el('label', { for: optionId, text: option.label }), el('p', { id: optionId + '-desc', text: option.desc })]),
							el('div', { class: 'ak-feature-option__control' }, [optionState, el('label', { class: 'ak-switch' }, [optionInput, el('span', { class: 'ak-switch__track' }), el('span', { class: 'ak-switch__knob' })]), optionPending])
						]));
					});
					panel.appendChild(optionSet);
					optionFooter = el('p', { id: optionHintId, class: 'ak-features-hint', text: I.featureOptionsHint });
					panel.appendChild(optionFooter);
				}
				panel.appendChild(el('button', { type: 'button', class: 'ak-btn ak-feature-done', text: I.pageDone, onclick: closeDetail }));
				details.appendChild(panel);
				refs[f.key] = { button: button, input: input, quickInput: quickInput, panel: panel, heading: heading, status: rowStatus, stateLabel: stateLabel, hint: hint, pendingBadge: pendingBadge, optionNote: optionNote, optionNoteTitle: optionNoteTitle, optionNoteText: optionNoteText, optionFooter: optionFooter, optionSet: optionSet, optionInputs: optionInputs, visible: false };
			});
			var categoryButtons = [];
			function syncCategoryHash(value) {
				try {
					if (window.history && typeof window.history.replaceState === 'function') {
						var base = location.pathname + location.search;
						window.history.replaceState(null, '', value ? base + '#category=' + encodeURIComponent(value) : base);
					}
				} catch (error) {}
			}
			function categoryButton(label, value, count) {
				var b = el('button', { type: 'button', class: 'ak-btn', 'aria-pressed': 'false', onclick: function () { category = value; syncCategoryHash(value); refresh(); } }, [
					el('span', { text: label }), count
				]);
				categories.appendChild(b); categoryButtons.push({ button: b, value: value, count: count });
			}
			categoryButton(I.featureAllCategories, '', el('span', { class: 'ak-features-count' }));
			groups.forEach(function (g) { categoryButton(g.label, g.label, g.count); });
			window.addEventListener('hashchange', function () {
				var nextCategory = '';
				if (location.hash.indexOf('#category=') === 0) { try { nextCategory = decodeURIComponent(location.hash.slice(10)); } catch (error) {} }
				if (nextCategory !== category) { category = nextCategory; refresh(); }
			});
			function normalize(value) { return (value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
			function refresh() {
				var focused = document.activeElement;
				var q = normalize(search.value).trim(), visible = [], counts = { all: 0, active: 0, inactive: 0 }, categoryCounts = {};
				features.forEach(function (f) {
					var r = refs[f.key], on = active(f), isLocked = locked(f);
					var masterChanged = !!state.features[f.key] !== !!f.value;
					var optionsChanged = r.optionInputs.some(function (option) { return !!state.features[option.key] !== !!option.data.value; });
					var pending = masterChanged || optionsChanged;
					r.pendingBadge.hidden = !pending;
					var match = normalize(f.label + ' ' + f.desc + ' ' + (f.group || '') + ' ' + (f.options || []).map(function (option) { return option.label + ' ' + (option.desc || ''); }).join(' ')).indexOf(q) !== -1;
					if (match) { counts.all++; counts[on ? 'active' : 'inactive']++; }
					var matchesStatus = match && (status.value === 'all' || (status.value === 'active') === on);
					if (matchesStatus) { categoryCounts[f.group || ''] = (categoryCounts[f.group || ''] || 0) + 1; }
					r.visible = matchesStatus && (!category || category === f.group);
					r.button.hidden = !r.visible; r.button.parentElement.hidden = !r.visible;
					if (r.visible) { visible.push(f.key); }
					r.input.checked = f.available !== false && !!state.features[f.key];
					r.input.disabled = isLocked; r.quickInput.disabled = isLocked; r.quickInput.checked = r.input.checked;
					if (r.optionSet) {
						r.optionSet.disabled = !on;
						r.optionNote.hidden = on;
						r.optionFooter.hidden = !on;
						r.optionSet.setAttribute('aria-describedby', on ? r.optionFooter.id : r.optionNote.id);
						r.optionNoteTitle.textContent = isLocked ? I.featureUnavailable : (masterChanged ? I.featurePausePending : I.featureOptionsPaused);
						r.optionNoteText.textContent = isLocked ? (f.unavailableHint || I.featureParentOff) : (masterChanged ? I.featureOptionsPausePendingHint : I.featureOptionsPausedHint);
					}
					r.optionInputs.forEach(function (option) {
						var checked = !!state.features[option.key];
						var choice = checked ? (option.data.onLabel || I.featureOptionOn) : (option.data.offLabel || I.featureOptionOff);
						option.input.checked = checked;
						option.status.textContent = on ? choice : I.featureOptionChoice.replace('%s', choice);
						option.pending.hidden = !(masterChanged || checked !== !!option.data.value);
						option.input.setAttribute('aria-describedby', option.input.id + '-desc ' + option.status.id + (option.pending.hidden ? '' : ' ' + option.pending.id));
						option.status.setAttribute('data-choice', on && checked ? 'on' : 'off');
					});
					r.button.setAttribute('data-feature-state', isLocked ? 'unavailable' : (on ? 'active' : 'inactive'));
					r.button.setAttribute('data-feature-pending', pending ? 'true' : 'false');
					r.stateLabel.textContent = isLocked ? I.featureUnavailable : (on ? I.featureActive : I.featureInactive);
					r.status.textContent = isLocked ? I.featureUnavailable : (masterChanged ? (on ? I.featureActivationPending : I.featureDeactivationPending) : (optionsChanged ? I.featureOptionsPending : r.stateLabel.textContent));
					r.hint.textContent = f.available === false ? (f.unavailableHint || I.featureUnavailable) : (f.parent && !state.features[f.parent] ? I.featureParentOff : (masterChanged ? (on ? I.featureWillEnable : I.featureWillDisable) : (optionsChanged ? I.featureOptionsWillApply : (on ? I.featureSavedOn : I.featureSavedOff))));
					r.panel.classList.toggle('is-locked', isLocked);
				});
				if (visible.indexOf(selected) === -1) { selected = ''; if (!details.hidden) { closeDetail(); } }
				features.forEach(function (f) {
					var r = refs[f.key];
					r.button.setAttribute('aria-expanded', String(f.key === selected && !details.hidden));
					r.panel.hidden = f.key !== selected;
				});
				groups.forEach(function (g) { g.wrap.hidden = !g.keys.some(function (key) { return refs[key].visible; }); });
				categoryButtons.forEach(function (c) {
					c.button.setAttribute('aria-pressed', String(category === c.value));
					c.count.textContent = String(c.value ? (categoryCounts[c.value] || 0) : Object.keys(categoryCounts).reduce(function (n, key) { return n + categoryCounts[key]; }, 0));
				});
				['all', 'active', 'inactive'].forEach(function (value, index) { status.options[index].textContent = I['featuresFilter' + value.charAt(0).toUpperCase() + value.slice(1)] + ' (' + counts[value] + ')'; });
				workspace.hidden = !visible.length; empty.hidden = !!visible.length;
				empty.textContent = counts.all && category ? I.featureCategoryEmpty : I.noResults;
				// A status-filtered switch may hide its own panel. Keep keyboard focus in the workflow.
				if (focused && workspace.contains(focused) && (focused.closest('[hidden]') || focused.disabled || focused.matches(':disabled'))) {
					if (selected) { refs[selected].button.focus(); } else { status.focus(); }
				}
			}
			search.addEventListener('input', refresh); status.addEventListener('change', refresh);
			refreshView = refresh;
			refresh(); return p;
		}
		buildFeatures.refresh = function () { if (refreshView) { refreshView(); } };
		buildFeatures.acknowledge = function (values) {
			(D.features || []).forEach(function (f) {
				[f].concat(f.options || []).forEach(function (item) {
					if (Object.prototype.hasOwnProperty.call(values, item.key)) { item.value = !!values[item.key]; }
				});
			});
			if (refreshView) { refreshView(); }
		};
		return buildFeatures;
	};
})();
