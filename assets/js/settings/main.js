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
		var ICONS = {
			brand:
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".9" fill="currentColor" stroke="none"/><circle cx="17.5" cy="10.5" r=".9" fill="currentColor" stroke="none"/><circle cx="8.5" cy="7.5" r=".9" fill="currentColor" stroke="none"/><circle cx="6.5" cy="12.5" r=".9" fill="currentColor" stroke="none"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
			features:
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
			plugins:
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v4M15 2v4M7 6h10a1 1 0 0 1 1 1v3a6 6 0 0 1-12 0V7a1 1 0 0 1 1-1zM12 16v6"/></svg>',
			// Upload arrow-up-tray — shown in empty brand-slot zones in lieu of a "Drop" text.
			upload:
				'<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>'
		};
		Object.assign(state, {
			dirty: false,
			saving: false,
			features: {}, // setting key -> bool
			integrations: {}, // integration slug -> bool (adapter enabled)
			logos: {
				// setting key -> url string
				light: (D.logos && D.logos.light) || '',
				dark: (D.logos && D.logos.dark) || ''
			},
			logoSource: D.logoSource === 'url' ? 'url' : 'upload',
			wpLogo: D.wpLogo === 'logo' ? 'logo' : 'favicon', // admin-bar / site-name mark: logo | favicon
			loginLogo: D.loginLogo === 'logo' ? 'logo' : 'favicon', // login screen mark: logo | favicon
			brandAccent: D.brandAccent || '', // user hex (only meaningful when accentSource === 'custom')
			// 'adminkit' = WaasKit roles; 'bricks' = provider; 'custom' = brandAccent hex
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

		state.appearance = Object.assign({ ui_density: 'default', ui_radius: 'default', ui_font: 'humanist' }, D.appearance || {});
		if (state.appearance.ui_font === 'default') { state.appearance.ui_font = 'humanist'; }

		state.designSystem = Object.assign({ radius_scale: 1, space_scale: 1, type_scale: 1, border_width: 1, elevation: 'flat' }, D.designSystem || {});

		state.designSystem.colors = { light: Object.assign({}, state.designSystem.colors && state.designSystem.colors.light), dark: Object.assign({}, state.designSystem.colors && state.designSystem.colors.dark) };

		function buildDesign() {
			var p = el('section', { class: 'ak-panel ak-panel--brand', role: 'tabpanel' });


			var paletteRefresh = function () {};

			// --- Common helpers used inside buildDesign() ---------------------------

			// Open the WP media frame and call back with the chosen attachment URL +
			// the full attachment object (so callers can grab `id` for site_icon-style
			// pipelines that need the attachment ID, not just the URL).
			function openMedia(onPick) {
				if (!window.wp || !wp.media) {
					return;
				}
				var frame = wp.media({
					title: I.mediaTitle || 'Select a logo',
					button: { text: I.mediaButton || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('select', function () {
					var att = frame.state().get('selection').first().toJSON();
					var url = (att && att.url) || '';
					onPick(url, att);
				});
				frame.open();
			}

			function singleLogo() {
				var preview = el('img', { alt: '', class: 'ak-single-logo-preview' });
				var source = el('select', { id: 'ak-logo-source' }, [el('option', { value: 'upload', text: I.logoUpload }), el('option', { value: 'url', text: I.logoUrl })]);
				source.value = state.logoSource;
				var url = el('input', { id: 'ak-logo-url', type: 'url', placeholder: 'https://', value: state.logos.light || state.logos.dark || '' });
				var urlLabel = el('label', { for: url.id, text: I.logoUrl });
				var upload = el('button', { type: 'button', class: 'ak-btn', text: I.slotUpload, onclick: function () { openMedia(function (value) { set(value); markDirty(); }); } });
				var remove = el('button', { type: 'button', class: 'ak-btn', text: I.slotRemove, onclick: function () { set(''); markDirty(); } });
				function set(value) { state.logos.light = state.logos.dark = value; url.value = value; sync(); }
				function sync() {
					var value = state.logos.light || state.logos.dark || '';
					preview.hidden = !value; if (value) { preview.src = value; } else { preview.removeAttribute('src'); }
					preview.classList.toggle('ak-single-logo-svg', /\.svg(?:[?#]|$)/i.test(value));
					upload.hidden = state.logoSource !== 'upload'; url.hidden = state.logoSource !== 'url'; urlLabel.hidden = url.hidden; remove.disabled = !value;
				}
				source.addEventListener('change', function () { state.logoSource = source.value; sync(); markDirty(); });
				url.addEventListener('change', function () { if (url.value && !/^https?:\/\//i.test(url.value)) { url.setAttribute('aria-invalid', 'true'); url.setCustomValidity(I.logoInvalidUrl); return; } url.removeAttribute('aria-invalid'); url.setCustomValidity(''); set(url.value.trim()); markDirty(); });
				sync();
				return el('div', { class: 'ak-single-logo' }, [el('label', { for: source.id, text: I.logoSource }), source, urlLabel, url, preview, el('div', { class: 'ak-actions' }, [upload, remove]), el('p', { text: I.logoHelp })]);
			}

			// Segmented control reused for the Display row (Admin bar + Login screen).
			// Same DOM as the rest of the SPA (.ak-seg / .ak-seg__opt) so the existing
			// styling kicks in.
			function logoSeg(stateKey, labelId, label, opts) {
				var btns = [];
				var seg = el('div', { class: 'ak-seg', role: 'radiogroup', 'aria-labelledby': labelId });
				opts.forEach(function (o) {
					var active = state[stateKey] === o.v;
					var b = el('button', {
						type: 'button',
						class: 'ak-seg__opt' + (active ? ' is-active' : ''),
						role: 'radio',
						'aria-checked': active ? 'true' : 'false',
						title: o.label,
						text: o.label
					});
					b._v = o.v;
					b.addEventListener('click', function () {
						if (state[stateKey] === o.v) {
							return;
						}
						state[stateKey] = o.v;
						btns.forEach(function (x) {
							var on = x._v === o.v;
							x.classList.toggle('is-active', on);
							x.setAttribute('aria-checked', on ? 'true' : 'false');
						});
						markDirty();
					});
					btns.push(b);
					seg.appendChild(b);
				});
				return el('div', { class: 'ak-display-row__field' }, [
					el('span', { class: 'ak-display-row__field-lbl', id: labelId, text: label }),
					seg
				]);
			}

			// Accent picker — a segmented control that switches between three sources
			// feeding `--ak-primary`:
			//
			//   • WaasKit (default) — native light/dark accent roles
			//   • Bricks              — the Bricks provider --accent (no override)
			//   • Custom              — the user's `brand_accent` hex
			//
			// The hex input only appears when source = 'custom'. The swatch always
			// shows the resolved colour (clicking it pops the OS-native picker, which
			// also auto-switches the source to 'custom').
			//
			// Live preview writes / removes a `<style id="ak-accent-preview">` node
			// so the whole accent family (hover, subtle, on-accent, focus ring) and
			// the token-map pills update without a reload. The node is REMOVED for
			// 'bricks' so the cascade picks the provider's --accent naturally;
			// REMOVED for 'custom' with an invalid/empty hex so the cascade takes back;
			// otherwise WRITTEN with the right value.
			function accentPicker() {
				// Bricks only appears in the segmented when the integration is connected
				// (theme active AND toggle on). If the user disables the integration the
				// pill silently drops; their stored `accentSource === 'bricks'` still
				// resolves server-side via accent_source(), but the UI no longer offers a
				// dead button. Hide rather than grey: a dead pill was confusing.
				var sources = [{ v: 'monochrome', label: I.accentSrcMonochrome }, { v: 'adminkit', label: I.accentSrcAdminKit || 'WordPress' }];
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
					var attrs = {
						type: 'button',
						class: 'ak-seg__opt' + (active ? ' is-active' : ''),
						role: 'radio',
						'aria-checked': active ? 'true' : 'false',
						text: o.label
					};
					if (o.title) {
						attrs.title = o.title;
					}
					if (o.disabled) {
						attrs.disabled = '';
						attrs['aria-disabled'] = 'true';
					}
					var b = el('button', attrs);
					b.tabIndex = active ? 0 : -1;
					b._v = o.v;
					if (!o.disabled) {
						b.addEventListener('click', function () {
							if (state.accentSource === o.v) {
								return;
							}
							setSource(o.v);
						});
					}
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
					maxlength: '7'
				});
				hexInput.value = state.brandAccent || '';
				// Both swatch and hex hide outside Custom mode — the segmented alone
				// is the affordance for AdminKit / Bricks (no per-pixel choice to make).
				if (state.accentSource !== 'custom') {
					hexInput.setAttribute('hidden', '');
					swatch.setAttribute('hidden', '');
				}
				var native = el('input', { type: 'color', class: 'ak-accent-inline__native' });
				native.value = isValidHex(state.brandAccent) ? state.brandAccent : D.adminkitBlue || '#1549AB';

				// Update the inline <style id="ak-accent-preview"> for live preview.
				// Mirrors AdminKit_Assets::inject_accent_family() in PHP, so what the
				// browser shows BEFORE save == what the server emits AFTER save.
				//
				// Default source inherits the native WaasKit light/dark roles.
				// Only a valid custom hex emits a preview override.
				function applyPreview() {
					// Keep unsaved experiments inside the palette editor. The shared
					// runtime compiler applies settings only after a successful save.
					swatch.style.background = isValidHex(state.brandAccent) ? state.brandAccent : 'var(--ak-primary)';
				}

				function syncSeg() {
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
				}

				function setSource(newSrc) {
					state.accentSource = newSrc;
					if (newSrc === 'custom' && !isValidHex(state.brandAccent)) { state.brandAccent = D.adminkitBlue || '#1549ab'; hexInput.value = state.brandAccent; }
					hexInput.removeAttribute('aria-invalid');
					syncSeg();
					applyPreview();
					paletteRefresh(true);
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
					applyPreview();
					paletteRefresh(true);
					markDirty();
				}

				hexInput.addEventListener('input', function () {
					setHex(hexInput.value);
				});
				// Clicking the swatch ALWAYS opens the native picker AND auto-switches
				// the source to Custom — the picker only makes sense in custom mode.
				swatch.addEventListener('click', function () {
					if (state.accentSource !== 'custom') {
						setSource('custom');
					}
					native.click();
				});
				native.addEventListener('input', function () {
					setHex(native.value);
				});

				applyPreview();

				// No inner "Accent" sub-label — the parent row already carries the
				// "Color" label on the left (same treatment as the Display row).
				return el('div', { class: 'ak-display-row__field ak-accent-inline' }, [seg, swatch, hexInput, native]);
			}

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

			var slotsRow = singleLogo();
			var wpField = logoSeg('wpLogo', 'ak-wp-logo-label', I.wpLogoLabel, [{ v: 'favicon', label: I.wpLogoFavicon }, { v: 'logo', label: I.wpLogoBrand }]);
			var sections = [];
			var editor = el('div', { class: 'ak-foundations-editor' });
			var nav = el('div', { class: 'ak-foundations-nav', role: 'tablist', 'aria-label': I.foundationSections, 'aria-orientation': window.matchMedia('(max-width: 782px)').matches ? 'horizontal' : 'vertical' });
			var panes = el('div', { class: 'ak-foundations-panes' });
			function number(value) { return Number(value).toLocaleString(document.documentElement.lang || 'fr', { maximumFractionDigits: 2 }); }
			function section(id, title, description, children) {
				var tab = el('button', { type: 'button', role: 'tab', id: 'ak-foundation-tab-' + id, 'aria-controls': 'ak-foundation-' + id, 'aria-selected': 'false', tabindex: '-1', text: title });
				var pane = el('section', { class: 'ak-foundations-panel', id: 'ak-foundation-' + id, role: 'tabpanel', 'aria-labelledby': tab.id, hidden: 'hidden' }, [
					el('h3', { text: title }), el('p', { class: 'ak-foundation-intro', text: description })
				].concat(children));
				tab.addEventListener('click', function () { selectSection(id); });
				sections.push({ id: id, tab: tab, pane: pane }); nav.appendChild(tab); panes.appendChild(pane);
				return pane;
			}
			function selectSection(id) {
				sections.forEach(function (item) {
					var active = item.id === id;
					item.tab.setAttribute('aria-selected', String(active)); item.tab.tabIndex = active ? 0 : -1; item.pane.hidden = !active;
				});
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
			section('identity', I.studioIdentity, I.foundationIdentityHelp, [slotsRow, el('div', { class: 'ak-identity-placements' }, [wpField])]);

			// Theme-scoped WaasKit roles come from the generated token stylesheet.
			var paletteCards = [];
			var roles = [
				['bg', I.foundationBackground], ['surface', I.foundationSurface], ['text', I.foundationText], ['text-muted', I.foundationMuted], ['border', I.foundationBorder],
				['accent', I.foundationAccent], ['on-accent', I.foundationOnAccent], ['success-text', I.foundationSuccess], ['warning-text', I.foundationWarning], ['danger-text', I.foundationDanger], ['info-text', I.foundationInfo]
			];
			function toHex(cssColor) {
				var canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
				var ctx = canvas.getContext('2d'); ctx.fillStyle = cssColor; ctx.fillRect(0, 0, 1, 1);
				return '#' + Array.from(ctx.getImageData(0, 0, 1, 1).data).slice(0, 3).map(function (v) { return v.toString(16).padStart(2, '0'); }).join('');
			}
			function contrast(a, b) {
				function luminance(hex) { var c = accentChannels(hex).map(function (v) { v /= 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return c[0] * .2126 + c[1] * .7152 + c[2] * .0722; }
				var x = luminance(a), y = luminance(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05);
			}
			['light', 'dark'].forEach(function (mode) {
				var modeLabel = mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark;
				var scope = el('div', { class: 'ak-bp__frame ak-palette-theme', 'data-preview-theme': mode });
				var rows = [], probes = {};
				var contrastText = el('p', { class: 'ak-palette-contrast', 'aria-live': 'polite' });
				var header = el('div', { class: 'ak-palette-header' }, [el('h4', { text: mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark }), el('button', { type: 'button', class: 'ak-btn', text: I.foundationPaletteReset, onclick: function () { state.designSystem.colors[mode] = {}; paletteRefresh(); markDirty(); } })]);
				scope.appendChild(header);
				roles.forEach(function (role) {
					var id = 'ak-palette-' + mode + '-' + role[0];
					var picker = el('input', { type: 'color', id: id, 'aria-label': role[1] + ' — ' + (mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark) });
					var hex = el('input', { type: 'text', class: 'ak-palette-hex', maxlength: '7', spellcheck: 'false', 'aria-label': role[1] + ' hex — ' + modeLabel });
					var inherited = el('span', { class: 'ak-palette-origin' });
					var error = el('span', { class: 'ak-palette-error', role: 'alert', id: id + '-error', hidden: 'hidden', text: I.foundationInvalidColor });
					hex.setAttribute('aria-describedby', error.id);
					var probe = el('span', { class: 'ak-palette-probe', 'aria-hidden': 'true' }); probe.style.backgroundColor = 'var(--wk-' + role[0] + ')'; probes[role[0]] = probe; scope.appendChild(probe);
					function change(value) {
						if (!value) { delete state.designSystem.colors[mode][role[0]]; paletteRefresh(); markDirty(); return; }
						if (!isValidHex(value)) { hex.setAttribute('aria-invalid', 'true'); error.hidden = false; return; }
						hex.removeAttribute('aria-invalid'); state.designSystem.colors[mode][role[0]] = value.toLowerCase(); paletteRefresh(); markDirty();
					}
					picker.addEventListener('input', function () { change(picker.value); });
					hex.addEventListener('change', function () { change(hex.value.trim()); });
					var reset = el('button', { type: 'button', class: 'ak-palette-reset', text: I.foundationReset, 'aria-label': I.foundationReset + ' — ' + role[1] + ' — ' + modeLabel, onclick: function () { delete state.designSystem.colors[mode][role[0]]; paletteRefresh(); markDirty(); } });
					reset.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10a9 9 0 1 1 2 8M3 4v6h6"/></svg>';
					scope.appendChild(el('div', { class: 'ak-palette-row' }, [picker, el('div', {}, [el('label', { for: id, text: role[1] }), inherited]), hex, reset, error]));
					rows.push({ role: role[0], picker: picker, hex: hex, inherited: inherited, reset: reset, error: error });
				});
				scope.appendChild(el('div', { class: 'ak-palette-reading', text: I.foundationPangram })); scope.appendChild(contrastText);
				paletteCards.push({ mode: mode, scope: scope, rows: rows, probes: probes, contrastText: contrastText });
			});
			paletteRefresh = function (resetAccent) {
				paletteCards.forEach(function (card) {
					var values = state.designSystem.colors[card.mode];
					card.scope.setAttribute('data-preview-accent', state.accentSource === 'adminkit' ? 'waaskit' : 'monochrome');
					if (resetAccent) { delete values.accent; delete values['on-accent']; }
					roles.forEach(function (role) { card.scope.style.removeProperty('--wk-' + role[0]); });
					if (state.accentSource === 'custom' && isValidHex(state.brandAccent)) {
						card.scope.style.setProperty('--wk-accent', state.brandAccent); card.scope.style.setProperty('--wk-on-accent', bestOnAccent(state.brandAccent));

					}
					Object.keys(values).forEach(function (role) { card.scope.style.setProperty('--wk-' + role, values[role]); });
					if (!card.scope.isConnected) { return; }
					card.rows.forEach(function (row) {
						var resolved = toHex(getComputedStyle(card.probes[row.role]).backgroundColor);
						row.picker.value = resolved; row.hex.value = values[row.role] || resolved; row.hex.removeAttribute('aria-invalid'); row.error.hidden = true;
						row.inherited.textContent = values[row.role] ? I.foundationCustom : I.foundationInherited; row.reset.disabled = !values[row.role];
					});
					var ratio = contrast(toHex(getComputedStyle(card.probes.text).backgroundColor), toHex(getComputedStyle(card.probes.bg).backgroundColor));
					card.contrastText.textContent = I.foundationContrast.replace('%s', number(ratio)); card.contrastText.dataset.pass = String(ratio >= 4.5);
				});
			};
			section('colors', I.studioColors, I.foundationColorsHelp, [el('div', { class: 'ak-foundation-accent' }, [accentPicker()]), el('div', { class: 'ak-palettes' }, paletteCards.map(function (card) { return card.scope; }))]);

			function developing() { return el('p', { class: 'ak-foundation-development', text: I.foundationDevelopment }); }
			section('type', I.studioType, I.studioTypeHint, [developing()]);
			section('shapes', I.studioShapes, I.studioShapesHint, [developing()]);
			section('spacing', I.studioSpacing, I.studioSpacingHint, [developing()]);
			window.addEventListener('resize', function () { nav.setAttribute('aria-orientation', window.matchMedia('(max-width: 782px)').matches ? 'horizontal' : 'vertical'); });
			p.classList.add('ak-foundations');
			p.appendChild(el('h2', { class: 'ak-foundations-title', text: I.designSystem })); p.appendChild(intro(I.designIntro));
			editor.appendChild(nav); editor.appendChild(panes); p.appendChild(editor); p.appendChild(el('p', { class: 'ak-foundation-scope', text: I.foundationScope }));
			selectSection('identity');
			return p;
		}

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
			v.logo_url = state.logos.light || state.logos.dark || '';
			v.logo_source = state.logoSource;
			v.logo_light = '';
			v.logo_dark = '';
			v.wp_logo = state.wpLogo;
			v.login_logo = state.loginLogo;
			v.brand_accent = state.brandAccent;
			v.accent_source = state.accentSource;
			Object.assign(v, state.appearance);
			v.design_system = Object.assign({}, state.designSystem);
			return v;
		}
		var refreshOverview = function () {};
		function buildOverview() {
			var p = el('section', { class: 'ak-panel ak-overview', role: 'tabpanel' });
			p.appendChild(el('h2', { class: 'ak-panel-title', text: I.overview }));
			p.appendChild(intro(I.overviewIntro));
			var list = el('dl', { class: 'ak-overview-list' });
			function entry(label, target, action) {
				var value = el('dd');
				list.appendChild(el('div', { class: 'ak-overview-entry' }, [
					el('div', {}, [el('dt', { text: label }), value]),
					el('a', { href: '#' + target, class: 'ak-btn ak-overview-link', text: action })
				]));
				return value;
			}
			var identity = entry(I.identitySummary, 'brand', I.configureDesign);
			var features = entry(I.activeFeatures, 'features', I.configureFeatures);
			var plugins = entry(I.integrationSummary, 'plugins', I.configurePlugins);
			refreshOverview = function () {
				identity.textContent = state.logos.light || state.logos.dark ? I.identityReady : I.identityDefault;
				features.textContent = (D.features || []).filter(function (f) { return f.available !== false && state.features[f.key] && (!f.parent || state.features[f.parent]); }).length + ' / ' + (D.features || []).filter(function (f) { return f.available !== false; }).length;
				var adapters = (D.integrations || []).filter(function (i) { return i.supported && i.slug && !i.system && i.active !== false; });
				plugins.textContent = adapters.filter(function (i) { return state.integrations[i.slug]; }).length + ' / ' + adapters.length;
			};
			refreshOverview();
			p.appendChild(list);
			p.appendChild(el('p', { class: 'ak-scope-note', text: I.overviewScope }));
			return p;
		}

		return {
			onSelect: function () { refreshOverview(); },
			tabs: [
				{ id: 'overview', label: I.overview, icon: ICONS.features, build: buildOverview },
				{ id: 'brand', label: I.designSystem, icon: ICONS.brand, build: buildDesign },
				{ id: 'features', label: I.features, icon: ICONS.features, build: buildFeatures },
				{ id: 'plugins', label: I.plugins, icon: ICONS.plugins, build: buildPlugins }
			],
			exportSettings: exportSettings,
			importSettings: importSettings,
			request: function () {
				return { path: D.route, data: { values: gather() } };
			}
		};
	};
})();
