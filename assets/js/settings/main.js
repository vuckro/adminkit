/** AdminKit main screen. Loaded only on its own admin page; no build step. */
(function () {
	'use strict';
	window.AdminKitScreens = window.AdminKitScreens || {};
	window.AdminKitScreens.main = function (context) {
		var D = context.D;
		var I = context.I;
		var apiFetch = context.apiFetch;
		var state = context.state;
		var el = context.el;
		var intro = context.intro;
		var markDirty = context.markDirty;
		var setStatus = context.setStatus;
		var updateBar = context.updateBar;
		var emptyPanel = function () { return el('section', { class: 'ak-panel', role: 'tabpanel' }); };
		var buildDesign = window.AdminKitParts && window.AdminKitParts.design ? window.AdminKitParts.design(context) : emptyPanel;
		var buildFeatures = window.AdminKitParts && window.AdminKitParts.features ? window.AdminKitParts.features(context) : emptyPanel;
		var navPart = window.AdminKitParts && window.AdminKitParts.navigation ? window.AdminKitParts.navigation(context) : null;
		var ICONS = {
			brand:
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".9" fill="currentColor" stroke="none"/><circle cx="17.5" cy="10.5" r=".9" fill="currentColor" stroke="none"/><circle cx="8.5" cy="7.5" r=".9" fill="currentColor" stroke="none"/><circle cx="6.5" cy="12.5" r=".9" fill="currentColor" stroke="none"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
			plugins:
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v4M15 2v4M7 6h10a1 1 0 0 1 1 1v3a6 6 0 0 1-12 0V7a1 1 0 0 1 1-1zM12 16v6"/></svg>',
			menu:
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18M14 9h4M14 15h4"/></svg>',
			toolbar:
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M7 6h.01M11 6h.01"/></svg>'
		};
		Object.assign(state, {
			dirty: false,
			saving: false,
			features: {}, // setting key -> bool
			integrations: {}, // integration slug -> bool (adapter enabled)
			brandAccent: D.brandAccent || '', // user hex (only meaningful when accentSource === 'custom')
			// 'monochrome' = default; 'adminkit' = WaasKit blue; 'bricks' = provider; 'custom' = brandAccent hex
			accentSource: D.accentSource || 'monochrome'
		});

		(D.features || []).forEach(function (f) {
			state.features[f.key] = !!f.value;
			(f.options || []).forEach(function (option) { state.features[option.key] = !!option.value; });
		});
		// Per-plugin opt-out for generic plugins (no AdminKit adapter) — tracked as
		// a set of plugin file paths the user has flipped OFF. Default = themed;
		// posted as `generic_theming_off` array (see PHP gate_generic_theming()).
		state.genericThemingOff = {};
		((D.values || {}).generic_theming_off || []).forEach(function (file) { state.genericThemingOff[file] = true; });
		(D.integrations || []).forEach(function (i) {
			if (i.supported && i.slug) {
				state.integrations[i.slug] = !!i.enabled;
				return;
			}
			// Generic plugin pre-seeded into the opt-out list when explicitly disabled. System / theme rows without a file are no-ops.
			if (!i.system && !i.supported && i.file && i.enabled === false) {
				state.genericThemingOff[i.file] = true;
			}
		});

		state.appearance = Object.assign({ ui_font: 'geist', remote_fonts_enabled: false }, D.appearance || {});
		state.interfaceDefault = D.interfaceDefault || 'adminkit';
		state.appearance.remote_fonts_enabled = !!state.appearance.remote_fonts_enabled;

		state.designSystem = Object.assign({ radius_scale: 1, type_scale: 1, border_width: 1, elevation: 'default' }, D.designSystem || {});
		state.designSystem.components = { buttons: Object.assign({ highlight: 'always' }, (state.designSystem.components || {}).buttons || {}) };

		state.designSystem.colors = { light: Object.assign({}, state.designSystem.colors && state.designSystem.colors.light), dark: Object.assign({}, state.designSystem.colors && state.designSystem.colors.dark) };

		function exportSettings() {
			guideMark('export');
			var payload = {
				_adminkit: 'settings',
				version: D.version || '1',
				exported: new Date().toISOString(),
				values: gather(true)
			};
			var blob = new Blob([JSON.stringify(payload, null, '\t')], { type: 'application/json' });
			var url = URL.createObjectURL(blob);
			var a = el('a', { href: url, download: 'adminkit-settings.json' });
			document.body.appendChild(a);
			a.click();
			a.remove();
			URL.revokeObjectURL(url);
		}

		function importSettings(file) {
			if (state.saving || state.dirty) { setStatus('is-error', I.unsaved); return; }
			var reader = new FileReader();
			reader.onload = function () {
				if (state.saving || state.dirty) { setStatus('is-error', I.unsaved); return; }
				var data, values;
				try {
					data = JSON.parse(reader.result);
				} catch (e) {
					data = null;
				}
				values = data && !Array.isArray(data) && data._adminkit === 'settings' && data.values && typeof data.values === 'object' ? data.values : null;
				if (!values || Array.isArray(values) || !apiFetch) {
					setStatus('is-error', I.settingsImportError);
					return;
				}
				state.importing = true;
				state.saving = true;
				updateBar();
				apiFetch({ path: D.route, method: 'POST', data: { values: values } })
					.then(function () {
						// Keep controls locked until the server values are mounted by reload.
						window.location.reload();
					})
					.catch(function () {
						state.importing = false;
						state.saving = false;
						setStatus('is-error', I.error);
						updateBar();
					});
			};
			reader.readAsText(file);
		}

		function gather(includeAll) {
			var v = includeAll ? Object.assign({}, D.values || {}) : {};
			Object.keys(state.features).forEach(function (k) {
				v[k] = !!state.features[k];
			});
			Object.keys(state.integrations).forEach(function (slug) {
				v['integration_' + slug + '_enabled'] = !!state.integrations[slug];
			});
			// Per-plugin opt-in for generic plugins (no native adapter). POST as a
			// flat array of plugin file paths the user has turned theming off for.
			v.generic_theming_off = Object.keys(state.genericThemingOff);
			v.brand_accent = state.brandAccent;
			v.accent_source = state.accentSource;
			Object.assign(v, state.appearance);
			v.design_system = Object.assign({}, state.designSystem);
			if (D.interfaceDefault) { v.interface_default = state.interfaceDefault; }
			return v;
		}

		var overviewPanel = null, configurationActions = [], guideExpanded = false, guide = {};
		var guideKey = 'adminkit:guide:' + location.host + ':' + (D.route || '');
		try { var storedGuide = JSON.parse(localStorage.getItem(guideKey)); if (storedGuide && typeof storedGuide === 'object' && !Array.isArray(storedGuide)) { guide = storedGuide; } } catch (error) {}
		function guideMark(key) { guide[key] = true; try { localStorage.setItem(guideKey, JSON.stringify(guide)); } catch (error) {} }
		function pageLink(id, label, className, hash) { return el('a', { class: className || 'ak-btn', href: ((D.pages || {})[id] || ('#' + id)) + (hash || ''), text: label }); }
		function featureGroups() {
			var result = [];
			(D.features || []).forEach(function (feature) {
				var group = result.filter(function (item) { return item.label === feature.group; })[0];
				if (!group) { group = { label: feature.group, features: [] }; result.push(group); }
				group.features.push(feature);
			});
			return result;
		}
		function isActive(feature) { return feature.available !== false && !!state.features[feature.key] && (!feature.parent || !!state.features[feature.parent]); }
		function buildOverview() { overviewPanel = el('section', { class: 'ak-panel ak-overview', role: 'tabpanel' }); refreshOverview(); return overviewPanel; }
		function refreshOverview() {
			overviewPanel.textContent = '';
			var steps = ['explore', 'configure', 'export'], complete = steps.every(function (key) { return guide[key]; });
			overviewPanel.appendChild(el('h2', { class: 'ak-panel-title', text: I.pageOverviewTitle }));
			overviewPanel.appendChild(intro(I.pageOverviewIntro));
			if (guideExpanded || (!complete && !guide.hidden)) {
				var hide = el('button', { class: 'ak-btn ak-btn--quiet', type: 'button', text: I.pageGuideHide, onclick: function () { guideExpanded = false; guideMark('hidden'); refreshOverview(); overviewPanel.querySelector('.ak-guide-show').focus(); } });
				var progress = steps.filter(function (key) { return guide[key]; }).length;
				var list = el('ol', { class: 'ak-guide-steps' });
				[[I.pageGuideExplore, 'features'], [I.pageGuideConfigure, 'brand'], [I.pageGuideExport, 'configuration']].forEach(function (step, index) {
					list.appendChild(el('li', { class: guide[steps[index]] ? 'is-complete' : '' }, [el('span', { class: 'ak-guide-marker', 'aria-hidden': 'true', text: guide[steps[index]] ? '' : String(index + 1) }), pageLink(step[1], step[0], 'ak-guide-link')]));
				});
				overviewPanel.appendChild(el('section', { class: 'ak-guide' }, [el('div', { class: 'ak-guide-head' }, [el('div', {}, [el('h3', { text: I.pageGuideTitle }), el('p', { text: I.pageGuideIntro })]), el('div', { class: 'ak-guide-controls' }, [el('span', { class: 'ak-guide-progress', text: progress + ' / 3' }), hide])]), list, el('p', { class: 'ak-guide-note', text: I.pageGuideOptional })]));
			} else {
				overviewPanel.appendChild(el('button', { type: 'button', class: 'ak-btn ak-btn--quiet ak-guide-show', text: I.pageGuideShow, onclick: function () { guideExpanded = true; refreshOverview(); overviewPanel.querySelector('.ak-guide button').focus(); } }));
			}
			var available = (D.features || []).filter(function (feature) { return feature.available !== false; });
			var active = available.filter(isActive).length;
			var count = (I.pageActiveCount || '').replace('%1$s', active).replace('%2$s', available.length);
			overviewPanel.appendChild(el('h3', { class: 'ak-overview-section', text: I.pageSummary }));
			overviewPanel.appendChild(el('div', { class: 'ak-overview-summary' }, [el('div', {}, [el('strong', { text: count }), el('p', { text: I.pageSavedScope })]), pageLink('features', I.pageManageAll)]));
			overviewPanel.appendChild(el('h3', { class: 'ak-overview-section', text: I.pageCategories }));
			var categories = el('div', { class: 'ak-overview-groups' });
			featureGroups().forEach(function (group) {
				var link = pageLink('features', '', 'ak-overview-group', '#category=' + encodeURIComponent(group.label));
				var icon = el('span', { class: 'ak-features-icon', 'aria-hidden': 'true' });
				// eslint-disable-next-line no-unsanitized/property -- Trusted PHP Lucide SVG icon.
				icon.innerHTML = group.features[0].iconSvg || '';
				link.appendChild(icon); link.appendChild(el('span', { text: group.label }));
				link.appendChild(el('span', { class: 'ak-overview-count', text: group.features.filter(isActive).length + ' / ' + group.features.length })); categories.appendChild(link);
			});
			overviewPanel.appendChild(categories);
		}
		function buildConfiguration() {
			var panel = el('section', { class: 'ak-panel ak-configuration', role: 'tabpanel' }, [el('h2', { class: 'ak-panel-title', text: I.pageConfiguration }), intro(I.pageSettingsIntro)]);
			function row(title, description, controls) { panel.appendChild(el('section', { class: 'ak-configuration-row' }, [el('div', {}, [el('h3', { text: title }), el('p', { text: description })]), el('div', { class: 'ak-actions' }, controls)])); }
			if (D.interfaceDefault) {
				var interfaceSelect = el('select', { id: 'ak-interface-default', 'aria-label': I.interfaceDefault });
				[['adminkit', 'AdminKit'], ['wordpress', I.interfaceClassic]].forEach(function (option) { interfaceSelect.appendChild(el('option', { value: option[0], text: option[1] })); });
				interfaceSelect.value = state.interfaceDefault;
				interfaceSelect.addEventListener('change', function () { state.interfaceDefault = interfaceSelect.value; markDirty(); });
				row(I.interfaceDefault, I.interfaceDefaultHint, [interfaceSelect]);
				row(I.interfacePersonal, I.interfacePersonalHint, [el('a', { class: 'ak-btn', href: D.profileUrl, text: I.interfacePersonal })]);
			}
			row(I.pageExportTitle, I.pageExportDesc, configurationActions.length ? [configurationActions[0]] : []);
			row(I.pageImportTitle, I.pageImportDesc, configurationActions.slice(1));
			var defaults = el('details', { class: 'ak-defaults' }, [el('summary', { text: I.resetDefaults }), el('button', { type: 'button', class: 'ak-btn', text: I.pageDefaultsConfirm, onclick: function () {
				(D.features || []).forEach(function (feature) { if (feature.available === false || feature.bulk === false) { return; } state.features[feature.key] = !!feature.default; (feature.options || []).forEach(function (option) { state.features[option.key] = !!option.default; }); });
				markDirty(); if (buildFeatures.refresh) { buildFeatures.refresh(); } defaults.open = false;
			} })]);
			row(I.pageDefaultsTitle, I.pageDefaultsDesc, [defaults]);
			return panel;
		}

		var mainTabs = [
			{ id: 'overview', label: I.pageOverview, icon: ICONS.menu, build: buildOverview },
			{ id: 'brand', label: I.designSystem, icon: ICONS.brand, build: buildDesign },
			{ id: 'features', label: I.features, icon: ICONS.plugins, build: buildFeatures }
		];
		if (navPart || (D.pages && D.pages.navigation)) {
			mainTabs.push({ id: 'navigation', label: I.navigation || I.menu, icon: ICONS.menu, build: navPart ? navPart.buildNavigation : emptyPanel });
		}

		mainTabs.push({ id: 'configuration', label: I.pageConfiguration, icon: ICONS.toolbar, build: buildConfiguration });
		return {
			tabs: mainTabs,
			setActions: function (actions) { configurationActions = actions; },
			onSelect: function (id) { if (id === 'features') { guideMark('explore'); } if (id === 'overview' && overviewPanel) { refreshOverview(); } },
			exportSettings: exportSettings,
			importSettings: importSettings,
			onSaved: function (scope, data, response) {
				if (scope === 'settings') { guideMark('configure'); }
				if (scope === 'settings' && buildFeatures.acknowledge) { buildFeatures.acknowledge((response && response.values) || data.values || {}); }
			},
			onHash: function (rawId) {
				if (navPart && (rawId === 'menu' || rawId === 'toolbar')) {
					navPart.selectSection(rawId);
					return 'navigation';
				}
				return rawId;
			},
			requests: function () {
				var requests = { settings: { path: D.route, data: { values: gather() } } };
				if (navPart) {
					requests.menu = navPart.menuRequest();
					requests.toolbar = navPart.toolbarRequest();
				}
				return requests;
			},
			request: function (activeTabId) {
				if (navPart && activeTabId === 'navigation') {
					return navPart.activeRequest();
				}
				return { path: D.route, data: { values: gather() } };
			}
		};
	};
})();
