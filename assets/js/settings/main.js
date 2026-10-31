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
		var buildDesign = window.AdminKitParts.design(context);
		var buildFeatures = window.AdminKitParts.features(context);
		var navPart = window.AdminKitParts.navigation ? window.AdminKitParts.navigation(context) : null;
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
		});
		// Per-plugin opt-out for generic plugins (no AdminKit adapter) — tracked as
		// a set of plugin file paths the user has flipped OFF. Default = themed;
		// posted as `generic_theming_off` array (see PHP gate_generic_theming()).
		state.genericThemingOff = {};
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

		state.appearance = Object.assign({ ui_font: 'geist' }, D.appearance || {});

		state.designSystem = Object.assign({ radius_scale: 1, type_scale: 1, border_width: 1, elevation: 'default' }, D.designSystem || {});
		state.designSystem.components = { buttons: Object.assign({ highlight: 'always' }, (state.designSystem.components || {}).buttons || {}) };

		state.designSystem.colors = { light: Object.assign({}, state.designSystem.colors && state.designSystem.colors.light), dark: Object.assign({}, state.designSystem.colors && state.designSystem.colors.dark) };

		function exportSettings() {
			var payload = {
				_adminkit: 'settings',
				version: D.version || '1',
				exported: new Date().toISOString(),
				values: gather()
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
			var reader = new FileReader();
			reader.onload = function () {
				var data, values;
				try {
					data = JSON.parse(reader.result);
				} catch (e) {
					data = null;
				}
				values =
					data && typeof data === 'object' && data.values && typeof data.values === 'object'
						? data.values
						: data && typeof data === 'object'
							? data
							: null;
				if (!values || !apiFetch) {
					setStatus('is-error', I.settingsImportError || 'That file isn’t a valid AdminKit settings export.');
					return;
				}
				state.saving = true;
				updateBar();
				apiFetch({ path: D.route, method: 'POST', data: { values: values } })
					.then(function () {
						window.location.reload();
					})
					.catch(function () {
						state.saving = false;
						setStatus('is-error', I.error);
						updateBar();
					});
			};
			reader.readAsText(file);
		}

		function gather() {
			var v = {};
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
			return v;
		}

		var mainTabs = [
			{ id: 'brand', label: I.designSystem, icon: ICONS.brand, build: buildDesign },
			{ id: 'features', label: I.features, icon: ICONS.plugins, build: buildFeatures }
		];
		if (navPart) {
			mainTabs.push({ id: 'menu', label: I.menu, icon: ICONS.menu, build: navPart.buildMenu });
			mainTabs.push({ id: 'toolbar', label: I.toolbarTab, icon: ICONS.toolbar, build: navPart.buildToolbar });
		}

		return {
			tabs: mainTabs,
			exportSettings: exportSettings,
			importSettings: importSettings,
			request: function (activeTabId) {
				if (navPart && activeTabId === 'menu') {
					return navPart.menuRequest();
				}
				if (navPart && activeTabId === 'toolbar') {
					return navPart.toolbarRequest();
				}
				return { path: D.route, data: { values: gather() } };
			}
		};
	};
})();
