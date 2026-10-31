/** AdminKit Design system tab. Part of the main screen (settings/main.js); no build step. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.design = function (context) {
		var D = context.D;
		var I = context.I;
		var state = context.state;
		var el = context.el;
		var intro = context.intro;
		var markDirty = context.markDirty;

		function buildDesign() {
			var p = el('section', { class: 'ak-panel ak-panel--brand', role: 'tabpanel' });

			var paletteRefresh = function () {};
			var syncSeg = function () {};

			// Compact hex validator — same shape as `sanitize_hex_color` PHP-side:
			// #abc or #aabbcc only, no rgba / hsl.
			function isValidHex(v) {
				return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test((v || '').trim());
			}

			// Use the stronger black/white contrast, matching contrast_text_for() in PHP.
			function accentChannels(hex) {
				var h = hex.replace('#', '');
				if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
				return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
			}
			function hoverHex(rgb, dark) {
				return '#' + rgb.map(function (channel) { return Math.round(channel * 0.82 + (dark ? 255 * 0.18 : 0)).toString(16).padStart(2, '0'); }).join('');
			}
			function bestOnAccent(hex) {
				if (!isValidHex(hex)) { return '#ffffff'; }
				var rgb = accentChannels(hex);
				function lin(byte) {
					var c = byte / 255;
					return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
				}
				var L = 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
				return 1.05 / (L + 0.05) >= (L + 0.05) / 0.05 ? '#ffffff' : '#000000';
			}

			// Accent picker — switches between Monochrome (default), WordPress (WaasKit blue),
			// Bricks (when active + enabled), and Custom.
			function accentPicker() {
				var sources = [
					{ v: 'monochrome', label: I.accentSrcMonochrome },
					{ v: 'adminkit', label: I.accentSrcAdminKit || 'WordPress' }
				];
				if (D.bricksConnected) {
					sources.push({ v: 'bricks', label: I.accentSrcBricks || 'Bricks' });
				}
				sources.push({ v: 'custom', label: I.accentSrcCustom || 'Custom' });

				var btns = [];
				var seg = el('div', { class: 'ak-seg', role: 'radiogroup', 'aria-label': I.accentLabel || 'Accent' });
				seg.style.setProperty('--ak-accent-options', sources.length);
				seg.addEventListener('keydown', function (event) {
					var index = btns.indexOf(document.activeElement);
					if (index < 0) { return; }
					if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { index = (index + 1) % btns.length; }
					else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { index = (index + btns.length - 1) % btns.length; }
					else if (event.key === 'Home') { index = 0; }
					else if (event.key === 'End') { index = btns.length - 1; }
					else { return; }
					event.preventDefault(); btns[index].click(); btns[index].focus();
				});
				sources.forEach(function (o) {
					var active = state.accentSource === o.v;
					var b = el('button', {
						type: 'button',
						class: 'ak-seg__opt' + (active ? ' is-active' : ''),
						role: 'radio',
						'aria-checked': active ? 'true' : 'false',
						text: o.label
					});
					b.tabIndex = active ? 0 : -1;
					b._v = o.v;
					b.addEventListener('click', function () {
						if (state.accentSource === o.v) { return; }
						setSource(o.v);
					});
					btns.push(b);
					seg.appendChild(b);
				});

				var swatch = el('button', {
					type: 'button',
					class: 'ak-accent-inline__sw',
					'aria-label': I.accentLabel || 'Accent'
				});
				var hexInput = el('input', {
					type: 'text',
					class: 'ak-accent-inline__hex',
					placeholder: '#1549AB',
					spellcheck: 'false',
					maxlength: '7',
					'aria-label': I.accentLabel || 'Accent'
				});
				hexInput.value = state.brandAccent || '';
				if (state.accentSource !== 'custom') {
					hexInput.setAttribute('hidden', '');
					swatch.setAttribute('hidden', '');
				}
				var native = el('input', { type: 'color', class: 'ak-accent-inline__native', tabindex: '-1', 'aria-label': I.accentLabel || 'Accent' });
				native.value = isValidHex(state.brandAccent) ? state.brandAccent : D.adminkitBlue || '#1549AB';

				function applyPreview() {
					swatch.style.background = isValidHex(state.brandAccent) ? state.brandAccent : 'var(--ak-primary)';
				}

				syncSeg = function () {
					btns.forEach(function (b) {
						var on = b._v === state.accentSource;
						b.classList.toggle('is-active', on);
						b.setAttribute('aria-checked', on ? 'true' : 'false');
						b.tabIndex = on ? 0 : -1;
					});
					if (state.accentSource === 'custom') {
						hexInput.removeAttribute('hidden');
						swatch.removeAttribute('hidden');
					} else {
						hexInput.setAttribute('hidden', '');
						swatch.setAttribute('hidden', '');
					}
					applyPreview();
				};

				function setSource(newSrc) {
					state.accentSource = newSrc;
					if (newSrc === 'custom' && !isValidHex(state.brandAccent)) {
						state.brandAccent = D.adminkitBlue || '#1549ab';
						hexInput.value = state.brandAccent;
					}
					hexInput.removeAttribute('aria-invalid');
					syncSeg();
					native.value = isValidHex(state.brandAccent) ? state.brandAccent : native.value;
					paletteRefresh(false);
					markDirty();
				}

				function setHex(raw) {
					var v = (raw || '').trim().toLowerCase();
					if (v && v.charAt(0) !== '#') {
						v = '#' + v;
					}
					state.brandAccent = v;
					hexInput.setAttribute('aria-invalid', String(!isValidHex(v)));
					if (hexInput.value !== v) {
						hexInput.value = v;
					}
					if (isValidHex(v)) {
						native.value = v;
						// Keep per-theme accent overrides aligned when the user picks a new global brand accent.
						delete state.designSystem.colors.light.accent;
						delete state.designSystem.colors.dark.accent;
						delete state.designSystem.colors.light['on-accent'];
						delete state.designSystem.colors.dark['on-accent'];
					}
					applyPreview();
					paletteRefresh(false);
					markDirty();
				}

				hexInput.addEventListener('input', function () { setHex(hexInput.value); });
				swatch.addEventListener('click', function () {
					if (state.accentSource !== 'custom') { setSource('custom'); }
					native.click();
				});
				native.addEventListener('input', function () { setHex(native.value); });

				applyPreview();
				return el('div', { class: 'ak-display-row__field ak-accent-inline' }, [seg, swatch, hexInput, native]);
			}

			var sections = [];
			var sectionKey = 'adminkit:foundation-section:' + location.pathname + location.search;
			var rememberedSection = 'colors';
			try { rememberedSection = sessionStorage.getItem(sectionKey) || rememberedSection; } catch (error) {}
			var editor = el('div', { class: 'ak-foundations-editor' });
			var nav = el('div', { class: 'ak-foundations-nav', role: 'tablist', 'aria-label': I.foundationSections, 'aria-orientation': 'horizontal' });
			var panes = el('div', { class: 'ak-foundations-panes' });
			var scopeNote = el('p', { class: 'ak-foundation-scope', text: I.foundationScope });

			var previewGroups = {};
			var previewMode = document.documentElement.getAttribute('data-adminkit-theme') === 'dark' ? 'dark' : 'light';
			var previewSection = rememberedSection;
			var previewTitle = el('h3', { id: 'ak-design-preview-title' });
			var previewBody = el('div');
			var themeButtons = [];
			function themeSwitch(label) {
				return el('div', { class: 'ak-design-preview__themes', role: 'group', 'aria-label': label }, ['light', 'dark'].map(function (mode) {
					var button = el('button', { type: 'button', class: 'ak-btn', text: mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark, 'aria-pressed': String(mode === previewMode), onclick: function () {
						previewMode = mode; refreshPreview();
					} });
					themeButtons.push({ button: button, mode: mode }); return button;
				}));
			}

			var previewDock = el('section', { class: 'ak-design-preview', role: 'region', 'aria-labelledby': previewTitle.id, hidden: 'hidden' }, [
				el('div', { class: 'ak-design-preview__head' }, [previewTitle]), previewBody
			]);
			function attachPreview(id, frames) {
				var group = el('div', { hidden: 'hidden' }, frames);
				previewGroups[id] = { group: group, frames: frames }; previewBody.appendChild(group);
			}
			function refreshPreview() {
				var selected = sections.find(function (item) { return item.id === previewSection; });
				previewDock.hidden = !previewGroups[previewSection];
				previewTitle.textContent = I.designPreview + (selected ? ' — ' + selected.title : '');
				themeButtons.forEach(function (item) { item.button.setAttribute('aria-pressed', String(item.mode === previewMode)); });
				paletteCards.forEach(function (card) { card.scope.hidden = card.mode !== previewMode; });
				var administrationTheme = document.documentElement.getAttribute('data-adminkit-theme') === 'dark' ? 'dark' : 'light';
				Object.keys(previewGroups).forEach(function (id) {
					previewGroups[id].group.hidden = id !== previewSection;
					previewGroups[id].frames.forEach(function (frame) { frame.hidden = frame.dataset.previewTheme !== administrationTheme; });
				});
			}

			function number(value) { return Number(value).toLocaleString(document.documentElement.lang || 'fr', { maximumFractionDigits: 2 }); }
			function section(id, title, description, children) {
				var tab = el('button', { type: 'button', role: 'tab', id: 'ak-foundation-tab-' + id, 'aria-controls': 'ak-foundation-' + id, 'aria-selected': 'false', tabindex: '-1', text: title });
				var pane = el('section', { class: 'ak-foundations-panel', id: 'ak-foundation-' + id, role: 'tabpanel', 'aria-labelledby': tab.id, hidden: 'hidden' }, [
					el('h3', { text: title }), el('p', { class: 'ak-foundation-intro', text: description })
				].concat(children));
				tab.addEventListener('click', function () { selectSection(id); });
				sections.push({ id: id, title: title, tab: tab, pane: pane }); nav.appendChild(tab); panes.appendChild(pane);
				return pane;
			}
			function selectSection(id) {
				if (!sections.some(function (item) { return item.id === id; })) { id = 'colors'; }
				try { sessionStorage.setItem(sectionKey, id); } catch (error) {}
				sections.forEach(function (item) {
					var active = item.id === id;
					item.tab.setAttribute('aria-selected', String(active)); item.tab.tabIndex = active ? 0 : -1; item.pane.hidden = !active;
				});
				previewSection = id; scopeNote.hidden = (id === 'colors' || id === 'plugins'); refreshPreview();
				requestAnimationFrame(function () { paletteRefresh(); });
			}
			nav.addEventListener('keydown', function (event) {
				var index = sections.findIndex(function (item) { return item.tab === document.activeElement; });
				if (index < 0) { return; }
				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { index = (index + 1) % sections.length; }
				else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { index = (index + sections.length - 1) % sections.length; }
				else if (event.key === 'Home') { index = 0; } else if (event.key === 'End') { index = sections.length - 1; } else { return; }
				event.preventDefault(); selectSection(sections[index].id); sections[index].tab.focus();
			});

			// --- Section 1: Colors (Preset + 11 semantic roles per theme) ------------
			var paletteCards = [];
			var roles = [
				['accent', I.foundationAccent], ['on-accent', I.foundationOnAccent],
				['bg', I.foundationBackground], ['surface', I.foundationSurface], ['text', I.foundationText], ['text-muted', I.foundationMuted], ['border', I.foundationBorder],
				['success-text', I.foundationSuccess], ['warning-text', I.foundationWarning], ['danger-text', I.foundationDanger], ['info-text', I.foundationInfo]
			];
			function toHex(cssColor) {
				var canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
				var ctx = canvas.getContext('2d'); ctx.fillStyle = cssColor; ctx.fillRect(0, 0, 1, 1);
				return '#' + Array.from(ctx.getImageData(0, 0, 1, 1).data).slice(0, 3).map(function (v) { return v.toString(16).padStart(2, '0'); }).join('');
			}
			['light', 'dark'].forEach(function (mode) {
				var modeLabel = mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark;
				var scope = el('div', { class: 'ak-bp__frame ak-palette-theme', 'data-preview-theme': mode });
				var rows = [], probes = {};
				var header = el('div', { class: 'ak-palette-header' }, [
					el('h4', { text: modeLabel }),
					el('button', { type: 'button', class: 'ak-btn', text: I.foundationPaletteReset, onclick: function () {
						state.designSystem.colors[mode] = {};
						paletteRefresh();
						markDirty();
					} })
				]);
				scope.appendChild(header);
				roles.forEach(function (role) {
					var id = 'ak-palette-' + mode + '-' + role[0];
					var picker = el('input', { type: 'color', id: id, 'aria-label': role[1] + ' — ' + modeLabel });
					var hex = el('input', { type: 'text', class: 'ak-palette-hex', maxlength: '7', spellcheck: 'false', 'aria-label': role[1] + ' hex — ' + modeLabel });
					var inherited = el('span', { class: 'ak-palette-origin' });
					var error = el('span', { class: 'ak-palette-error', role: 'alert', id: id + '-error', hidden: 'hidden', text: I.foundationInvalidColor });
					hex.setAttribute('aria-describedby', error.id);
					var probe = el('span', { class: 'ak-palette-probe', 'aria-hidden': 'true' }); probe.style.backgroundColor = 'var(--wk-' + role[0] + ')'; probes[role[0]] = probe; scope.appendChild(probe);
					function change(value) {
						// Editing any color automatically activates the Custom palette mode.
						if (state.accentSource !== 'custom') {
							state.accentSource = 'custom';
							if (!isValidHex(state.brandAccent)) { state.brandAccent = D.adminkitBlue || '#1549ab'; }
							syncSeg();
						}
						if (!value) { delete state.designSystem.colors[mode][role[0]]; paletteRefresh(); markDirty(); return; }
						if (!isValidHex(value)) { hex.setAttribute('aria-invalid', 'true'); error.hidden = false; return; }
						hex.removeAttribute('aria-invalid'); state.designSystem.colors[mode][role[0]] = value.toLowerCase(); paletteRefresh(); markDirty();
					}
					hex.addEventListener('adminkit:reveal-field', function () { previewMode = mode; if (previewSection === 'colors') { refreshPreview(); } else { selectSection('colors'); } });
					picker.addEventListener('input', function () { change(picker.value); });
					hex.addEventListener('change', function () { change(hex.value.trim()); });
					var reset = el('button', { type: 'button', class: 'ak-palette-reset', text: I.foundationReset, 'aria-label': I.foundationReset + ' — ' + role[1] + ' — ' + modeLabel, onclick: function () { delete state.designSystem.colors[mode][role[0]]; paletteRefresh(); markDirty(); } });
					reset.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10a9 9 0 1 1 2 8M3 4v6h6"/></svg>';
					scope.appendChild(el('div', { class: 'ak-palette-row' }, [picker, el('div', {}, [el('label', { for: id, text: role[1] }), inherited]), hex, reset, error]));
					rows.push({ role: role[0], picker: picker, hex: hex, inherited: inherited, reset: reset, error: error });
				});
				paletteCards.push({ mode: mode, scope: scope, rows: rows, probes: probes });
			});
			paletteRefresh = function () {
				paletteCards.forEach(function (card) {
					var overrides = state.accentSource === 'custom' ? state.designSystem.colors[card.mode] : {};
					var values = state.accentSource === 'custom' ? Object.assign({}, overrides) : {};
					card.scope.setAttribute('data-preview-accent', state.accentSource === 'adminkit' ? 'waaskit' : 'monochrome');
					roles.forEach(function (role) { card.scope.style.removeProperty('--wk-' + role[0]); });
					if (state.accentSource === 'custom' && isValidHex(state.brandAccent)) {
						card.scope.style.setProperty('--wk-accent', state.brandAccent);
						card.scope.style.setProperty('--wk-on-accent', bestOnAccent(state.brandAccent));
					}
					Object.keys(values).forEach(function (role) { card.scope.style.setProperty('--wk-' + role, values[role]); });
					if (!card.scope.isConnected) { return; }
					card.rows.forEach(function (row) {
						var resolved = toHex(getComputedStyle(card.probes[row.role]).backgroundColor);
						row.picker.value = resolved;
						row.hex.value = values[row.role] || resolved;
						row.hex.removeAttribute('aria-invalid');
						row.error.hidden = true;
						row.inherited.textContent = overrides[row.role] ? I.foundationCustom : I.foundationInherited;
						row.reset.disabled = !overrides[row.role];
					});
				});
				if (shapePreviews) { refreshShapeColors(); }
			};
			section('colors', I.studioColors, I.foundationColorsHelp, [
				el('div', { class: 'ak-color-controls' }, [
					el('div', { class: 'ak-foundation-accent' }, [accentPicker()]),
					el('div', { class: 'ak-palette-theme-switch' }, [themeSwitch(I.studioColors)])
				]),
				el('div', { class: 'ak-palettes' }, paletteCards.map(function (card) { return card.scope; }))
			]);

			// --- Section 2: Typography (Font family + Text scale) --------------------
			var fontCatalog = D.fonts || {
				geist: { label: 'Geist (AdminKit)', stack: '"Geist",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif', google: null },
				system: { label: I.fontSystem || 'System UI', stack: 'ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif', google: null },
				inter: { label: 'Inter (Google Fonts)', stack: '"Inter",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'Inter:wght@400;500;600;700' },
				'plus-jakarta-sans': { label: 'Plus Jakarta Sans (Google Fonts)', stack: '"Plus Jakarta Sans",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'Plus+Jakarta+Sans:wght@400;500;600;700' },
				'dm-sans': { label: 'DM Sans (Google Fonts)', stack: '"DM Sans",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'DM+Sans:wght@400;500;600;700' },
				manrope: { label: 'Manrope (Google Fonts)', stack: '"Manrope",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'Manrope:wght@400;500;600;700' },
				outfit: { label: 'Outfit (Google Fonts)', stack: '"Outfit",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'Outfit:wght@400;500;600;700' },
				poppins: { label: 'Poppins (Google Fonts)', stack: '"Poppins",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'Poppins:wght@400;500;600;700' },
				'space-grotesk': { label: 'Space Grotesk (Google Fonts)', stack: '"Space Grotesk",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'Space+Grotesk:wght@400;500;600;700' },
				'ibm-plex-sans': { label: 'IBM Plex Sans (Google Fonts)', stack: '"IBM Plex Sans",ui-sans-serif,system-ui,-apple-system,sans-serif', google: 'IBM+Plex+Sans:wght@400;500;600;700' }
			};
			if (state.appearance.ui_font === 'humanist' || !fontCatalog[state.appearance.ui_font]) {
				state.appearance.ui_font = 'geist';
			}
			function snapChoice(val, choices, fallback) {
				var num = Number(val);
				if (!isFinite(num)) { return fallback; }
				var best = choices[0];
				choices.forEach(function (c) {
					if (Math.abs(c - num) < Math.abs(best - num)) { best = c; }
				});
				return best;
			}
			var fontSelect = el('select', { id: 'ak-type-font' }, Object.keys(fontCatalog).map(function (key) {
				var label = key === 'system' && I.fontSystem ? I.fontSystem : fontCatalog[key].label;
				return el('option', { value: key, text: label });
			}));
			fontSelect.value = state.appearance.ui_font;
			var typeChoices = [0.9, 0.95, 1, 1.05, 1.1];
			state.designSystem.type_scale = snapChoice(state.designSystem.type_scale, typeChoices, 1);
			var typeScale = el('select', { id: 'ak-type-scale' }, typeChoices.map(function (scale) {
				var pct = Math.round(scale * 100) + ' %';
				return el('option', { value: String(scale), text: scale === 1 ? I.defaultPreset + ' (' + pct + ')' : pct });
			}));
			typeScale.value = String(state.designSystem.type_scale);
			var scalePreviews = ['light', 'dark'].map(function (mode) {
				return el('div', { class: 'ak-bp__frame ak-shape-preview ak-scale-preview', 'data-preview-theme': mode }, [
					el('div', { class: 'ak-scale-sample' }, [
						el('strong', { text: I.studioType }), el('p', { text: I.studioSample }),
						el('div', { class: 'ak-scale-rows' }, [el('span', { text: I.studioSample }), el('span', { text: I.studioSample }), el('span', { text: I.studioSample })])
					])
				]);
			});
			function ensureGoogleFont(googleSpec) {
				var linkId = 'ak-google-font-preview';
				var existing = document.getElementById(linkId);
				if (!googleSpec) {
					return;
				}
				var href = 'https://fonts.googleapis.com/css2?family=' + googleSpec + '&display=swap';
				if (!existing) {
					existing = el('link', { id: linkId, rel: 'stylesheet', href: href });
					document.head.appendChild(existing);
				} else if (existing.getAttribute('href') !== href) {
					existing.setAttribute('href', href);
				}
			}
			function refreshScales() {
				var entry = fontCatalog[state.appearance.ui_font] || fontCatalog.geist;
				var font = entry.stack;
				ensureGoogleFont(entry.google);
				// Live preview on the page itself AND in the specimen frame.
				var root = document.documentElement.style;
				root.setProperty('--ak-type-scale', state.designSystem.type_scale);
				root.setProperty('--ak-font-body', font);
				root.setProperty('--wk-font-sans', font);
				scalePreviews.forEach(function (preview) {
					preview.style.setProperty('--ak-prev-type', state.designSystem.type_scale);
					preview.style.setProperty('--ak-prev-font', font);
				});
			}
			function onTypeScale() { state.designSystem.type_scale = Number(typeScale.value); refreshScales(); markDirty(); }
			fontSelect.addEventListener('change', function () { state.appearance.ui_font = fontSelect.value; refreshScales(); markDirty(); });
			typeScale.addEventListener('change', onTypeScale);
			typeScale.addEventListener('input', onTypeScale);
			section('type', I.studioType, I.studioTypeHint, [
				el('div', { class: 'ak-shape-selects' }, [
					el('div', {}, [el('label', { for: fontSelect.id, text: I.studioFont }), fontSelect]),
					el('div', {}, [el('label', { for: typeScale.id, text: I.studioTypeScale }), typeScale])
				]),
				el('button', { type: 'button', class: 'ak-btn', text: I.resetDefaults, onclick: function () {
					state.appearance.ui_font = 'geist'; fontSelect.value = 'geist';
					state.designSystem.type_scale = 1; typeScale.value = '1';
					refreshScales(); markDirty();
				} })
			]);
			attachPreview('type', scalePreviews);

			// --- Section 3: Shapes & Surfaces (Radius + Border + Elevation + Button highlight) ---
			var radiusChoices = [0, 0.5, 1, 1.25, 1.5];
			state.designSystem.radius_scale = snapChoice(state.designSystem.radius_scale, radiusChoices, 1);
			var radius = el('select', { id: 'ak-shape-radius' }, radiusChoices.map(function (scale) {
				var label = scale === 0 ? '0 px' : scale === 1 ? I.defaultPreset + ' (1 ×)' : number(scale) + ' ×';
				return el('option', { value: String(scale), text: label });
			}));
			radius.value = String(state.designSystem.radius_scale);
			var elevation = el('select', { id: 'ak-shape-elevation' }, [
				['default', I.defaultPreset], ['flat', I.studioFlat], ['subtle', I.studioSubtle], ['raised', I.studioRaised]
			].map(function (item) { return el('option', { value: item[0], text: item[1] }); }));
			elevation.value = state.designSystem.elevation;
			state.designSystem.border_width = snapChoice(state.designSystem.border_width, [0, 1, 2], 1);
			var borderWidth = el('select', { id: 'ak-shape-border' }, [0, 1, 2].map(function (width) { return el('option', { value: String(width), text: width + ' px' }); }));
			borderWidth.value = String(state.designSystem.border_width);
			var highlightSelect = el('select', { id: 'ak-buttons-highlight', 'aria-describedby': 'ak-buttons-highlight-help' }, [
				['off', I.componentOff], ['hover', I.componentHover], ['always', I.componentAlways]
			].map(function (item) { return el('option', { value: item[0], text: item[1] }); }));
			highlightSelect.value = state.designSystem.components.buttons.highlight;

			var shapePreviews = ['light', 'dark'].map(function (mode) {
				return el('div', { class: 'ak-bp__frame ak-shape-preview', 'data-preview-theme': mode }, [
					el('div', { class: 'ak-shape-controls' }, [
						el('button', { type: 'button', class: 'ak-btn ak-btn--primary', text: I.previewAction }),
						el('button', { type: 'button', class: 'ak-btn', text: I.studioSecondary }),
						el('button', { type: 'button', class: 'ak-btn ak-btn--primary', disabled: 'disabled', text: I.componentDisabled }),
						el('label', {}, [el('span', { text: I.studioField }), el('input', { type: 'text', value: 'AdminKit', 'aria-label': I.studioField + ' — ' + (mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark) })])
					]),
					el('div', { class: 'ak-shape-card' }, [el('strong', { text: I.shapeCard }), el('p', { text: I.studioSample })]),
					el('div', { class: 'ak-shape-table', role: 'img', 'aria-label': I.shapeTable }, [
						el('strong', { text: I.shapeTable }), el('span', { text: I.studioSample }), el('span', { text: I.studioSample })
					])
				]);
			});
			function refreshShapeColors() {
				shapePreviews.forEach(function (preview) {
					preview.setAttribute('data-preview-accent', state.accentSource === 'adminkit' ? 'waaskit' : 'monochrome');
					roles.forEach(function (role) { preview.style.removeProperty('--wk-' + role[0]); });
					preview.style.removeProperty('--wk-accent-hover');
					if (state.accentSource !== 'custom') { return; }
					var values = Object.assign({}, state.designSystem.colors[preview.dataset.previewTheme]);
					if (!values.accent && isValidHex(state.brandAccent)) { values.accent = state.brandAccent; }
					if (values.accent) {
						if (!values['on-accent']) { values['on-accent'] = bestOnAccent(values.accent); }
						preview.style.setProperty('--wk-accent-hover', hoverHex(accentChannels(values.accent), bestOnAccent(values.accent) === '#000000'));
					}
					Object.keys(values).forEach(function (role) { preview.style.setProperty('--wk-' + role, values[role]); });
				});
			}
			function refreshShapes() {
				var highlight = state.designSystem.components.buttons.highlight;
				var depth = { flat: 'none', subtle: 'var(--wk-elevation-1)', raised: 'var(--wk-elevation-2)' }[state.designSystem.elevation];
				// Live preview on the page itself AND in the specimen frame.
				var root = document.documentElement.style;
				root.setProperty('--ak-radius-scale', state.designSystem.radius_scale);
				root.setProperty('--wk-radius-scale', state.designSystem.radius_scale);
				root.setProperty('--ak-border-width', state.designSystem.border_width + 'px');
				[['s', 8], ['m', 12], ['l', 16], ['xl', 24]].forEach(function (r) {
					root.setProperty('--ak-radius-' + r[0], 'calc(' + r[1] + 'px * ' + state.designSystem.radius_scale + ')');
				});
				if (depth) { root.setProperty('--ak-design-elevation', depth); } else { root.removeProperty('--ak-design-elevation'); }
				root.setProperty('--ak-btn-primary-elevation', highlight === 'always' ? 'var(--wk-elevation-1)' : 'var(--wk-elevation-1-shadow)');
				root.setProperty('--ak-btn-primary-elevation-hover', highlight === 'off' ? 'var(--wk-elevation-1-shadow)' : 'var(--wk-elevation-1)');
				root.setProperty('--ak-btn-primary-highlight', highlight === 'always' ? 'var(--ak-btn-highlight-effect)' : 'inset 0 0 0 transparent');
				root.setProperty('--ak-btn-primary-highlight-hover', highlight === 'off' ? 'inset 0 0 0 transparent' : 'var(--ak-btn-highlight-effect)');
				shapePreviews.forEach(function (preview) {
					preview.style.setProperty('--ak-btn-primary-elevation', highlight === 'always' ? 'var(--wk-elevation-1)' : 'var(--wk-elevation-1-shadow)');
					preview.style.setProperty('--ak-btn-primary-elevation-hover', highlight === 'off' ? 'var(--wk-elevation-1-shadow)' : 'var(--wk-elevation-1)');
					preview.style.setProperty('--ak-btn-primary-highlight', highlight === 'always' ? 'var(--ak-btn-highlight-effect)' : 'inset 0 0 0 transparent');
					preview.style.setProperty('--ak-btn-primary-highlight-hover', highlight === 'off' ? 'inset 0 0 0 transparent' : 'var(--ak-btn-highlight-effect)');
					preview.style.setProperty('--ak-shape-scale', state.designSystem.radius_scale);
					preview.style.setProperty('--ak-border-width', state.designSystem.border_width + 'px');
					preview.style.setProperty('--ak-radius-s', 'calc(8px * ' + state.designSystem.radius_scale + ')');
					preview.style.setProperty('--ak-shape-shadow', depth || 'var(--ak-default-card-shadow)');
				});
			}
			function onRadius() { state.designSystem.radius_scale = Number(radius.value); refreshShapes(); markDirty(); }
			borderWidth.addEventListener('change', function () { state.designSystem.border_width = Number(borderWidth.value); refreshShapes(); markDirty(); });
			radius.addEventListener('change', onRadius);
			radius.addEventListener('input', onRadius);
			elevation.addEventListener('change', function () { state.designSystem.elevation = elevation.value; refreshShapes(); markDirty(); });
			highlightSelect.addEventListener('change', function () { state.designSystem.components.buttons.highlight = highlightSelect.value; refreshShapes(); markDirty(); });

			section('shapes', I.studioShapes, I.shapeHint, [
				el('div', { class: 'ak-shape-selects' }, [
					el('div', {}, [el('label', { for: radius.id, text: I.studioRadiusScale }), radius]),
					el('div', {}, [el('label', { for: borderWidth.id, text: I.studioBorder }), borderWidth]),
					el('div', {}, [el('label', { for: elevation.id, text: I.studioElevation }), elevation]),
					el('div', {}, [el('label', { for: highlightSelect.id, text: I.componentHighlight }), highlightSelect])
				]),
				el('p', { id: 'ak-buttons-highlight-help', class: 'ak-foundation-intro ak-shape-help', text: I.shapeHelp }),
				el('button', { type: 'button', class: 'ak-btn', text: I.resetDefaults, onclick: function () {
					state.designSystem.radius_scale = 1; radius.value = '1';
					state.designSystem.border_width = 1; borderWidth.value = '1';
					state.designSystem.elevation = 'default'; elevation.value = 'default';
					state.designSystem.components.buttons.highlight = 'always'; highlightSelect.value = 'always';
					refreshShapes(); markDirty();
				} })
			]);
			attachPreview('shapes', shapePreviews);

			// --- Section 4: Extensions / Plugins supported by the Design System ------
			if (window.AdminKitParts && typeof window.AdminKitParts.plugins === 'function') {
				var buildPlugins = window.AdminKitParts.plugins(context);
				section('plugins', I.plugins, I.pluginsIntro, buildPlugins());
			}

			refreshShapes(); refreshShapeColors(); refreshScales();
			p.classList.add('ak-foundations');
			p.appendChild(el('h2', { class: 'ak-foundations-title', text: I.designSystem })); p.appendChild(intro(I.designIntro));
			editor.appendChild(nav); editor.appendChild(panes); p.appendChild(editor); p.appendChild(previewDock); p.appendChild(scopeNote);
			selectSection(rememberedSection);
			new MutationObserver(refreshPreview).observe(document.documentElement, { attributes: true, attributeFilter: ['data-adminkit-theme'] });
			return p;
		}

		return buildDesign;
	};
})();
