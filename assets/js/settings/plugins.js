/** AdminKit Plugins tab. Part of the main screen (settings/main.js); no build step. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.plugins = function (context) {
		var D = context.D;
		var I = context.I;
		var state = context.state;
		var el = context.el;
		var intro = context.intro;
		var markDirty = context.markDirty;

		function buildPlugins() {
			var p = el('section', { class: 'ak-panel', role: 'tabpanel' }, [el('h2', { class: 'ak-panel-title', text: I.plugins }), intro(I.pluginsIntro)]);
			var list = D.integrations || [];
			if (!list.length) {
				return p;
			}

			var inputs = []; // every toggle (native + generic), for the Reset button
			var rowsRef = []; // { row, text } per plugin row, for the search filter
			var groupsRef = []; // { wrap, rows[] } per section, to hide an emptied group

			// Brand "Native" chip, left of the plugin name — supported plugins only.
			function nativeBadge() {
				return el('span', {
					class: 'ak-badge ak-badge--brand',
					title: I.nativeHint || '',
					text: I.native || 'Native'
				});
			}

			// Neutral "System" chip for AdminKit's own row — it's listed but locked.
			function systemBadge() {
				return el('span', {
					class: 'ak-badge',
					title: I.systemHint || '',
					text: I.system || 'System'
				});
			}

			// Neutral "Generic" chip for installed plugins with no adapter — the base
			// layer themes them automatically, so it shares the System badge's look.
			function genericBadge() {
				return el('span', {
					class: 'ak-badge',
					title: I.genericHint || '',
					text: I.generic || 'Generic'
				});
			}

			// Keep the row's `.is-off` class in sync with the switch state so
			// every disabled row (native OR generic) reads as greyed-out, the
			// same treatment AdminKit's own System row gets.
			function syncOffClass(row, on) {
				if (on) {
					row.classList.remove('is-off');
				} else {
					row.classList.add('is-off');
				}
			}

			function pluginRow(i) {
				// Badge + name hug the left together. System (AdminKit itself) →
				// neutral System; supported → brand Native; any other installed
				// plugin → neutral Generic (no dedicated adapter).
				var badge = i.system ? systemBadge() : i.supported ? nativeBadge() : genericBadge();
				var main = el('div', { class: 'ak-row__main' }, [
					el('div', { class: 'ak-row__head' }, [badge, el('span', { class: 'ak-row__label', text: i.label })])
				]);

				// System (AdminKit itself) → locked row, switch reads ON but is dead.
				if (i.system) {
					var lock = el('input', { type: 'checkbox', class: 'ak-switch__input' });
					lock.checked = true;
					lock.disabled = true;
					return el('div', { class: 'ak-row is-locked' }, [
						main,
						el('label', { class: 'ak-switch' }, [
							lock,
							el('span', { class: 'ak-switch__track' }),
							el('span', { class: 'ak-switch__knob' })
						])
					]);
				}

				// Installed but not active in WP → muted row, no switch. AdminKit
				// can't act on a plugin that isn't loaded, so the toggle would be
				// noise — the dim treatment alone reads as "not actionable here".
				if (false === i.active) {
					main.appendChild(el('span', { class: 'ak-row__desc', text: I.pluginInactive }));
					return el('div', { class: 'ak-row is-muted' }, [main]);
				}

				// Active plugin (native or generic) → user-facing toggle.
				// `.is-off` from the start paints the row dim with no flash.
				var input = el('input', { type: 'checkbox', class: 'ak-switch__input', 'aria-label': i.label });
				input.checked = !!i.enabled;
				var row = el('div', { class: 'ak-row' + (i.enabled ? '' : ' is-off') }, [
					main,
					el('label', { class: 'ak-switch' }, [
						input,
						el('span', { class: 'ak-switch__track' }),
						el('span', { class: 'ak-switch__knob' })
					])
				]);

				if (i.supported && i.slug) {
					// Native adapter → its `integration_{slug}_enabled` setting.
					input.addEventListener('change', function () {
						state.integrations[i.slug] = input.checked;
						syncOffClass(row, input.checked);
						markDirty();
					});
					inputs.push({ kind: 'native', slug: i.slug, input: input, row: row });
				} else if (i.file) {
					// Generic plugin → file path tracked in the opt-out list.
					input.addEventListener('change', function () {
						if (input.checked) {
							delete state.genericThemingOff[i.file];
						} else {
							state.genericThemingOff[i.file] = true;
						}
						syncOffClass(row, input.checked);
						markDirty();
					});
					inputs.push({ kind: 'generic', file: i.file, input: input, row: row });
				}
				return row;
			}

			// Reset every toggle to its "AdminKit handles this plugin" default,
			// which is ON for every detected host. Explicit opt-outs remain
			// available for debugging. markDirty so the user
			// can still walk away without committing.
			function resetAll() {
				inputs.forEach(function (r) {
					r.input.checked = true;
					if ('native' === r.kind) {
						state.integrations[r.slug] = true;
					} else {
						delete state.genericThemingOff[r.file];
					}
					syncOffClass(r.row, true);
				});
				markDirty();
			}

			// Build the sections first (this fills `inputs` + the search refs), then the
			// search field + bulk bar above them.
			var sections = [];
			[
				{ type: 'theme', label: I.themesLabel || 'Themes' },
				{ type: 'plugin', label: I.plugins || 'Plugins' }
			].forEach(function (sec) {
				var items = list.filter(function (i) {
					return i.type === sec.type;
				});
				if (!items.length) {
					return;
				}
				var rows = el('div', { class: 'ak-rows' });
				var groupRows = [];
				items.forEach(function (i) {
					var r = pluginRow(i);
					// Searchable by plugin name + its badge word (so "native" / "generic"
					// narrow to that kind). The badge tracks the adapter status.
					var badge = i.system ? I.system || 'System' : i.supported ? I.native || 'Native' : I.generic || 'Generic';
					var ref = { row: r, text: ((i.label || '') + ' ' + badge).toLowerCase() };
					rowsRef.push(ref);
					groupRows.push(ref);
					rows.appendChild(r);
				});
				var wrap = el('div', { class: 'ak-group' }, [
					el('h2', { class: 'ak-group__title' }, [
						el('span', { text: sec.label }),
						el('span', { class: 'ak-badge ak-group__count', text: String(items.length) })
					]),
					rows
				]);
				groupsRef.push({ wrap: wrap, rows: groupRows });
				sections.push(wrap);
			});

			// Search — filters plugin rows by name / badge as you type, hiding groups
			// left with nothing (same idiom as the Features tab).
			var search = el('input', {
				type: 'search',
				class: 'ak-search',
				placeholder: I.searchPlugins || 'Search plugins…',
				'aria-label': I.searchPlugins || 'Search plugins…'
			});
			var empty = el('p', { class: 'ak-empty', role: 'status', hidden: 'hidden', text: I.noResults });
			function filterPlugins() {
				var q = (search.value || '').trim().toLowerCase();
				rowsRef.forEach(function (r) {
					r.row.style.display = !q || r.text.indexOf(q) !== -1 ? '' : 'none';
				});
				empty.hidden = rowsRef.some(function (r) { return r.row.style.display !== 'none'; });
				groupsRef.forEach(function (g) {
					var any = g.rows.some(function (r) {
						return r.row.style.display !== 'none';
					});
					g.wrap.style.display = any ? '' : 'none';
				});
			}
			p.appendChild(empty);
			search.addEventListener('input', filterPlugins);
			p.appendChild(el('div', { class: 'ak-search-wrap' }, [search]));

			// Single bulk control — Reset puts every toggle back to ON (the
			// "AdminKit handles this plugin" default for both native + generic).
			// Skipped when no row carries a toggle (e.g. every plugin inactive).
			if (inputs.length) {
				p.appendChild(
					el('div', { class: 'ak-actions ak-bulk' }, [
						el('button', {
							type: 'button',
							class: 'ak-btn',
							text: I.resetDefaults || 'Reset to defaults',
							onclick: resetAll
						})
					])
				);
			}
			sections.forEach(function (s) {
				p.appendChild(s);
			});
			return p;
		}

		return buildPlugins;
	};
})();
