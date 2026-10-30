/** AdminKit Features tab. Part of the main screen (settings/main.js); no build step. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.features = function (context) {
		var D = context.D;
		var I = context.I;
		var state = context.state;
		var el = context.el;
		var intro = context.intro;
		var markDirty = context.markDirty;

		function buildFeatures() {
			var p = el('section', { class: 'ak-panel', role: 'tabpanel' }, [el('h2', { class: 'ak-panel-title', text: I.features }), intro(I.featuresIntro)]);

			var refs = {}; // key -> { input, row }
			var groups = []; // [{ label, rows }] in first-seen order
			var byGroup = {};
			// Bucket each feature row under its `group` label (identical labels share
			// a block); keeps a child in the same group as its parent.
			function rowsFor(label) {
				if (!byGroup[label]) {
					byGroup[label] = { label: label, rows: el('div', { class: 'ak-rows' }), keys: [], wrapEl: null };
					groups.push(byGroup[label]);
				}
				return byGroup[label].rows;
			}

			// Reflect a feature's state on its row: dim it ("is-off") when switched
			// off — the switch stays clickable so it can be turned back on — and,
			// for a child OR an unavailable feature (e.g. a host-specific tool without
			// its host), lock it ("is-locked": dimmed + the switch made
			// non-operable, both via CSS and the disabled input).
			// `available: false` is permanent for the session; `parent` locks/
			// unlocks live as the parent toggles.
			function refreshRow(f) {
				var r = refs[f.key];
				if (!r) {
					return;
				}
				if (f.available === false) {
					// Hard lock — prerequisite isn't met. Force the visual state to
					// OFF so it doesn't read as "on but blocked" (confusing — looks
					// like the feature is somehow half-active). The saved value in
					// state.features stays untouched: when the prerequisite returns,
					// the row re-renders with the user's last actual choice.
					r.input.disabled = true;
					r.input.checked = false;
					r.row.classList.add('is-locked');
					r.row.classList.add('is-off');
					return;
				}
				r.row.classList.toggle('is-off', !state.features[f.key]);
				r.input.checked = !!state.features[f.key];
				if (f.parent) {
					var parentOn = !!state.features[f.parent];
					r.input.disabled = !parentOn;
					r.row.classList.toggle('is-locked', !parentOn);
				}
			}

			// Flip every feature at once (the "enable / disable all" controls).
			// Rows flagged `bulk: false` OR `available: false` (prerequisite missing)
			// are left untouched — sweeping them would be nonsensical.
			function setAll(on) {
				(D.features || []).forEach(function (f) {
					if (f.bulk === false || f.available === false) {
						return;
					}
					state.features[f.key] = on;
					if (refs[f.key]) {
						refs[f.key].input.checked = on;
					}
				});
				(D.features || []).forEach(refreshRow);
				filter();
				markDirty();
			}

			// Restore every feature to its registered schema default (passed in
			// `f.default` from boot_data). No confirm prompt — markDirty puts the
			// page in "unsaved" state so the user can still walk away without
			// committing by reloading or navigating off. Same skip rules as setAll.
			function resetAll() {
				(D.features || []).forEach(function (f) {
					if (f.bulk === false || f.available === false) {
						return;
					}
					var def = !!f.default;
					state.features[f.key] = def;
					if (refs[f.key]) {
						refs[f.key].input.checked = def;
					}
				});
				(D.features || []).forEach(refreshRow);
				filter();
				markDirty();
			}

			(D.features || []).forEach(function (f) {
				var input = el('input', { type: 'checkbox', class: 'ak-switch__input', 'aria-label': f.label });
				input.checked = !!state.features[f.key];
				input.addEventListener('change', function () {
					state.features[f.key] = input.checked;
					(D.features || []).forEach(refreshRow);
					filter();
					markDirty();
				});
				var rowAttrs = { class: 'ak-row' + (f.parent ? ' ak-row--child' : '') };
				// Show the prereq hint on hover when the feature can't run (e.g.
				// a host-specific tool without its host). refreshRow() applies the
				// is-locked + disabled styling; this just explains why.
				if (f.available === false && f.unavailableHint) {
					rowAttrs.title = f.unavailableHint;
				}
				var helpId = 'ak-feature-help-' + f.key;
				var helpName = I.featureHelp.replace('%s', f.label);
				var infoButton = el('button', {
					type: 'button', class: 'ak-info-button', 'aria-label': helpName,
					'aria-expanded': 'false', 'aria-controls': helpId, title: helpName
				});
				infoButton.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v.01"/></svg>';
				var technical = el('dl', { class: 'ak-feature-technical' });
				var storage = D.settingsStorage || {};
				[
					[I.storageTable, storage.table], [I.storageOption, storage.option],
					[I.storageKey, f.key], [I.defaultValue, f.default ? I.enabledState : I.disabledState]
				].forEach(function (item) {
					technical.appendChild(el('div', {}, [el('dt', { text: item[0] }), el('dd', {}, [el('code', { text: item[1] || '' })])]));
				});
				var help = el('div', { id: helpId, class: 'ak-feature-help', role: 'region', 'aria-label': helpName, hidden: 'hidden' }, [
					el('p', { class: 'ak-row__desc', text: f.desc }),
					el('h3', { text: I.technicalDetails }), technical,
					el('p', { class: 'ak-feature-save-note', text: I.saveTiming })
				]);
				function toggleHelp(open) {
					help.hidden = !open;
					infoButton.setAttribute('aria-expanded', String(open));
				}
				infoButton.addEventListener('click', function () { toggleHelp(help.hidden); });
				[infoButton, help].forEach(function (target) {
					target.addEventListener('keydown', function (event) {
						if (event.key === 'Escape' && !help.hidden) { toggleHelp(false); infoButton.focus(); event.stopPropagation(); }
					});
				});
				var row = el('div', rowAttrs, [
					el('div', { class: 'ak-row__main' }, [
						el('div', { class: 'ak-feature-heading' }, [el('span', { class: 'ak-row__label', text: f.label }), infoButton]),
						f.summary ? el('p', { class: 'ak-feature-summary', text: f.summary }) : null,
						f.settingsUrl ? el('a', { class: 'ak-btn ak-feature-settings', href: f.settingsUrl, text: I.editIdentitySettings }) : null,
						help
					]),
					el('label', { class: 'ak-switch' }, [
						input,
						el('span', { class: 'ak-switch__track' }),
						el('span', { class: 'ak-switch__knob' })
					])
				]);
				refs[f.key] = { input: input, row: row };
				rowsFor(f.group || '').appendChild(row);
				byGroup[f.group || ''].keys.push(f.key);
			});

			(D.features || []).forEach(refreshRow); // initial dim + dependency state

			// Search/filter — narrows the visible rows by label + description as you
			// type, hides groups left with nothing, and expands all while searching.
			var search = el('input', {
				type: 'search',
				class: 'ak-search',
				placeholder: I.searchFeatures || 'Search features…',
				'aria-label': I.searchFeatures || 'Search features…'
			});
			var empty = el('p', { class: 'ak-empty', role: 'status', hidden: 'hidden', text: I.noResults });
			var selectedStatus = 'all';
			var statusButtons = {};
			var statusFilters = el('div', { class: 'ak-feature-filters', role: 'group', 'aria-label': I.filterFeatures });
			['all', 'active', 'inactive'].forEach(function (value) {
				var button = el('button', {
					type: 'button', class: 'ak-btn', 'aria-pressed': String(value === selectedStatus),
					onclick: function () { selectedStatus = value; filter(); }
				});
				statusButtons[value] = button;
				statusFilters.appendChild(button);
			});
			function filter() {
				var q = (search.value || '').trim().toLowerCase();
				var counts = { all: 0, active: 0, inactive: 0 };
				(D.features || []).forEach(function (f) {
					var r = refs[f.key];
					if (!r) {
						return;
					}
					var hay = ((f.label || '') + ' ' + (f.desc || '') + ' ' + (f.summary || '')).toLowerCase();
					var active = f.available !== false && !!state.features[f.key] && (!f.parent || !!state.features[f.parent]);
					var status = active ? 'active' : 'inactive';
					var matchesSearch = !q || hay.indexOf(q) !== -1;
					if (matchesSearch) { counts.all++; counts[status]++; }
					r.row.style.display = matchesSearch && (selectedStatus === 'all' || selectedStatus === status) ? '' : 'none';
				});
				['all', 'active', 'inactive'].forEach(function (value) {
					statusButtons[value].textContent = I['featuresFilter' + value.charAt(0).toUpperCase() + value.slice(1)] + ' (' + counts[value] + ')';
					statusButtons[value].setAttribute('aria-pressed', String(value === selectedStatus));
				});
				empty.hidden = Object.keys(refs).some(function (key) { return refs[key].row.style.display !== 'none'; });
				groups.forEach(function (g) {
					if (!g.wrapEl) {
						return;
					}
					var anyVisible = g.keys.some(function (k) {
						return refs[k] && refs[k].row.style.display !== 'none';
					});
					g.wrapEl.style.display = anyVisible ? '' : 'none';
				});
			}

			search.addEventListener('input', filter);
			p.appendChild(el('div', { class: 'ak-search-wrap' }, [search]));
			p.appendChild(statusFilters);

			// Bulk controls — flip every feature on/off, or restore the registered
			// schema defaults (reuses the header's flex row + secondary buttons;
			// no new layout CSS).
			p.appendChild(
				el('div', { class: 'ak-actions ak-bulk' }, [
					el('button', {
						type: 'button',
						class: 'ak-btn',
						text: I.enableAll,
						onclick: function () {
							setAll(true);
						}
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn',
						text: I.disableAll,
						onclick: function () {
							setAll(false);
						}
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn',
						text: I.resetDefaults || 'Reset to defaults',
						onclick: resetAll
					})
				])
			);

			p.appendChild(el('p', { class: 'ak-feature-bulk-note', text: I.featuresBulkScope }));
			p.appendChild(empty);
			// One titled block per group label (order = first-seen). Static heading —
			// the search field handles discovery; folding groups was visual noise.
			groups.forEach(function (g) {
				var wrap = el('div', { class: 'ak-group' }, [
					g.label ? el('h2', { class: 'ak-group__title', text: g.label }) : null,
					g.rows
				]);
				g.wrapEl = wrap;
				p.appendChild(wrap);
			});

			filter();
			return p;
		}

		return buildFeatures;
	};
})();
