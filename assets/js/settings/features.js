/** Feature discovery: category filters, selectable list and persistent detail panels. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.features = function (context) {
		var D = context.D, I = context.I, state = context.state, el = context.el;
		return function buildFeatures() {
			var features = D.features || [];
			var p = el('section', { class: 'ak-panel ak-features', role: 'tabpanel' }, [
				el('h2', { class: 'ak-panel-title', text: I.features }), context.intro(I.featuresIntro)
			]);
			var refs = {}, groups = [], selected = '', category = '';
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
			var list = el('nav', { class: 'ak-features-list', 'aria-label': I.featureChoose, id: 'ak-feature-list' });
			var details = el('div', { class: 'ak-features-details' });
			var workspace = el('div', { class: 'ak-features-workspace' }, [list, details]);
			var empty = el('p', { class: 'ak-empty', role: 'status', hidden: 'hidden' });
			p.appendChild(categories); p.appendChild(empty); p.appendChild(workspace);

			function icon(f) {
				var n = el('span', { class: 'ak-features-icon', 'aria-hidden': 'true' });
				// Trusted markup comes only from the shared PHP Lucide renderer.
				n.innerHTML = f.iconSvg || '';
				return n;
			}
			function active(f) { return f.available !== false && !!state.features[f.key] && (!f.parent || !!state.features[f.parent]); }
			function locked(f) { return f.available === false || !!(f.parent && !state.features[f.parent]); }
			function revealDetail() {
				if (selected && window.matchMedia('(max-width: 782px)').matches) {
					refs[selected].panel.scrollIntoView({ block: 'start' });
					refs[selected].heading.focus({ preventScroll: true });
				}
			}
			function select(key) { selected = key; refresh(); revealDetail(); }
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
				var button = el('button', { type: 'button', class: 'ak-features-item', 'aria-controls': panelId, 'aria-pressed': 'false', onclick: function () { select(f.key); } }, [
					icon(f), el('span', { class: 'ak-features-item__text' }, [el('span', { class: 'ak-features-item__label', text: f.label }), rowStatus]), el('span', { class: 'ak-features-dot', 'aria-hidden': 'true' })
				]);
				group.rows.appendChild(button);
				var input = el('input', { type: 'checkbox', class: 'ak-switch__input', 'aria-label': f.label, 'aria-describedby': panelId + '-hint' });
				input.addEventListener('change', function () {
					state.features[f.key] = input.checked;
					refresh(); context.markDirty();
				});
				var stateLabel = el('span', { class: 'ak-features-state__label' });
				var hint = el('p', { id: panelId + '-hint', class: 'ak-features-hint' });
				var back = el('button', { type: 'button', class: 'ak-features-back', text: I.featureBack, onclick: function () {
					list.scrollIntoView({ block: 'start' }); button.focus({ preventScroll: true });
				} });
				var panel = el('section', { class: 'ak-features-detail', id: panelId, role: 'region', 'aria-labelledby': panelId + '-title', hidden: 'hidden' }, [
					back, icon(f), heading, f.group ? el('p', { class: 'ak-features-detail__category', text: f.group }) : null,
					el('h4', { text: I.featureEffect }), el('p', { class: 'ak-features-effect', text: f.desc }),
					el('div', { class: 'ak-features-state' }, [el('div', {}, [stateLabel, hint]),
						el('label', { class: 'ak-switch' }, [input, el('span', { class: 'ak-switch__track' }), el('span', { class: 'ak-switch__knob' })])])
				]);
				details.appendChild(panel);
				refs[f.key] = { button: button, input: input, panel: panel, heading: heading, status: rowStatus, stateLabel: stateLabel, hint: hint, visible: false };
			});
			var categoryButtons = [];
			function categoryButton(label, value, count) {
				var b = el('button', { type: 'button', class: 'ak-btn', 'aria-pressed': 'false', onclick: function () { category = value; refresh(); } }, [
					el('span', { text: label }), count
				]);
				categories.appendChild(b); categoryButtons.push({ button: b, value: value, count: count });
			}
			categoryButton(I.featureAllCategories, '', el('span', { class: 'ak-features-count' }));
			groups.forEach(function (g) { categoryButton(g.label, g.label, g.count); });
			function normalize(value) { return (value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
			function refresh() {
				var focused = document.activeElement;
				var q = normalize(search.value).trim(), visible = [], counts = { all: 0, active: 0, inactive: 0 }, categoryCounts = {};
				features.forEach(function (f) {
					var r = refs[f.key], on = active(f), isLocked = locked(f);
					var match = normalize(f.label + ' ' + f.desc + ' ' + (f.group || '')).indexOf(q) !== -1;
					if (match) { counts.all++; counts[on ? 'active' : 'inactive']++; }
					var matchesStatus = match && (status.value === 'all' || (status.value === 'active') === on);
					if (matchesStatus) { categoryCounts[f.group || ''] = (categoryCounts[f.group || ''] || 0) + 1; }
					r.visible = matchesStatus && (!category || category === f.group);
					r.button.hidden = !r.visible;
					if (r.visible) { visible.push(f.key); }
					r.input.checked = f.available !== false && !!state.features[f.key];
					r.input.disabled = isLocked;
					r.button.setAttribute('data-feature-state', isLocked ? 'unavailable' : (on ? 'active' : 'inactive'));
					r.status.textContent = isLocked ? I.featureUnavailable : (on ? I.featureActive : I.featureInactive);
					r.stateLabel.textContent = r.status.textContent;
					r.hint.textContent = f.available === false ? (f.unavailableHint || I.featureUnavailable) : (f.parent && !state.features[f.parent] ? I.featureParentOff : I.featureSaveHint);
					r.panel.classList.toggle('is-locked', isLocked);
				});
				if (visible.indexOf(selected) === -1) { selected = visible[0] || ''; }
				features.forEach(function (f) {
					var r = refs[f.key];
					r.button.setAttribute('aria-pressed', String(f.key === selected));
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
				if (focused && workspace.contains(focused) && (focused.closest('[hidden]') || focused.disabled)) {
					if (selected) { refs[selected].button.focus(); } else { status.focus(); }
				}
			}
			search.addEventListener('input', refresh); status.addEventListener('change', refresh);
			refresh(); return p;
		};
	};
})();
