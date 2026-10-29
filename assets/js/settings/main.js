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
			// Generic plugin pre-seeded into the off-list when the schema says it
			// starts disabled. System / theme rows without a file are no-ops.
			if (!i.system && i.file && i.enabled === false) {
				state.genericThemingOff[i.file] = true;
			}
		});

		function buildDesign() {
			var p = el('section', { class: 'ak-panel ak-panel--brand', role: 'tabpanel' });

			// Assigned once the live-preview pane is built (below). Logo + display-mode
			// controls call it to re-skin the mock in real time. (Accent rides the
			// global --ak-primary the accent picker already updates, so it needs no
			// explicit refresh here.) Closures capture the var, so the stub is safe
			// until the real updater lands.
			var previewUpdate = function () {};

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

			// One brand slot — a dashed card with a fixed-backdrop drop zone (preview
			// or upload-arrow placeholder) + label + sub + Upload / Remove button.
			// `slotKey` is 'light' or 'dark' → the brand wordmark URL in `state.logos[key]`.
			function brandSlot(slotKey, label, sub) {
				var preview = el('img', { class: 'ak-brand-slot__preview', alt: '' });
				// Empty-state placeholder is an upload-arrow icon (the zone is a
				// click-to-upload shortcut alongside the Upload button below).
				var dropTxt = el('span', { class: 'ak-brand-slot__drop', 'aria-hidden': 'true' });
				dropTxt.innerHTML = ICONS.upload;
				var zone = el('div', { class: 'ak-brand-slot__zone' }, [preview, dropTxt]);

				// One button per slot, label + action toggle on filled state:
				//   empty   → "↑ Upload"  → opens the WP media frame
				//   filled  → "Remove"    → clears the slot (sets it to empty)
				// Clicking the drop zone is a separate shortcut that ALWAYS opens
				// the media frame (to replace whatever is there).
				var actionBtn = el('button', {
					type: 'button',
					class: 'ak-brand-slot__btn'
				});

				function currentUrl() {
					return state.logos[slotKey] || '';
				}
				function syncPreview() {
					var url = currentUrl();
					if (url) {
						preview.src = url;
						preview.style.display = '';
						dropTxt.style.display = 'none';
						actionBtn.textContent = I.slotRemove || 'Remove';
						actionBtn.classList.add('is-remove');
					} else {
						preview.removeAttribute('src');
						preview.style.display = 'none';
						dropTxt.style.display = '';
						actionBtn.textContent = '↑ ' + (I.slotUpload || 'Upload');
						actionBtn.classList.remove('is-remove');
					}
				}
				function setLogo(url) {
					state.logos[slotKey] = url || '';
					syncPreview();
					markDirty();
					previewUpdate();
				}

				// Logos use the plain media frame (free-form aspect, no crop).
				var pick = openMedia;

				// Drop zone is always a "pick / replace" shortcut. The action button
				// branches on current state — clears when filled, picks when empty.
				zone.addEventListener('click', function () {
					pick(setLogo);
				});
				actionBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					if (currentUrl()) {
						setLogo('', null);
					} else {
						pick(setLogo);
					}
				});

				syncPreview();

				// Label row (always present) + optional badge chip next to it.
				return el('div', { class: 'ak-brand-slot ak-brand-slot--' + slotKey }, [
					zone,
					el('div', { class: 'ak-brand-slot__body' }, [
						el('div', { class: 'ak-brand-slot__label', text: label }),
						sub ? el('div', { class: 'ak-brand-slot__sub', text: sub }) : null,
						el('div', { class: 'ak-brand-slot__btns' }, [actionBtn])
					])
				]);
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
						previewUpdate();
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
				var sources = [{ v: 'monochrome', label: I.accentSrcMonochrome }, { v: 'adminkit', label: I.accentSrcAdminKit || 'WaasKit' }];
				if (D.bricksConnected) {
					sources.push({ v: 'bricks', label: I.accentSrcBricks || 'Bricks' });
				}
				sources.push({ v: 'custom', label: I.accentSrcCustom || 'Custom' });

				var btns = [];
				var seg = el('div', { class: 'ak-seg', role: 'radiogroup', 'aria-label': I.accentLabel || 'Accent' });
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
					var source = state.accentSource === 'adminkit' ? 'waaskit' : state.accentSource;
					var hex = source === 'custom' && isValidHex(state.brandAccent) ? state.brandAccent : null;
					document.documentElement.setAttribute('data-adminkit-accent', source === 'custom' && !hex ? 'monochrome' : source);
					var existing = document.getElementById('ak-accent-preview');
					if (source === 'custom') {
						var rgb = hex ? accentChannels(hex) : [];
						if (!existing) {
							existing = document.createElement('style');
							existing.id = 'ak-accent-preview';
							document.head.appendChild(existing);
						}
						var values = {
							'--ak-custom-accent': hex || 'initial',
							'--ak-custom-on-accent': hex ? bestOnAccent(hex) : 'initial',
							'--ak-custom-on-hover-light': hex ? bestOnAccent(hoverHex(rgb, false)) : 'initial',
							'--ak-custom-on-hover-dark': hex ? bestOnAccent(hoverHex(rgb, true)) : 'initial',
							'--ak-custom-r': hex ? rgb[0] : 'initial',
							'--ak-custom-g': hex ? rgb[1] : 'initial',
							'--ak-custom-b': hex ? rgb[2] : 'initial'
						};
						existing.textContent = ':root,#wpadminbar{' + Object.keys(values).map(function (key) {
							return key + ':' + values[key];
						}).join(';') + '}';
					} else if (existing) {
						existing.remove();
					}
					swatch.style.background = 'var(--ak-primary)';
				}

				function syncSeg() {
					btns.forEach(function (b) {
						var on = b._v === state.accentSource;
						b.classList.toggle('is-active', on);
						b.setAttribute('aria-checked', on ? 'true' : 'false');
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
					syncSeg();
					applyPreview();
					markDirty();
				}

				function setHex(raw) {
					var v = (raw || '').trim().toLowerCase();
					if (v && v.charAt(0) !== '#') {
						v = '#' + v;
					}
					state.brandAccent = v;
					if (hexInput.value !== v) {
						hexInput.value = v;
					}
					applyPreview();
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

			// --- Brand card ----------------------------------------------------------
			// The card head used to carry an uppercase eyebrow above the title
			// (e.g. "BRAND" + "Logo, favicon & accent"). With the title now reading
			// simply "Marque", the eyebrow just duplicates it — removed.
			var cardHead = el('div', { class: 'ak-card__head' });
			var headMain = el('div', { class: 'ak-card__head-main' }, [
				el('h2', { class: 'ak-card__title', text: I.brandTitle || 'Marque' })
			]);
			// Bricks-sync status — visible ONLY when the integration is connected
			// (theme active AND toggle on). Green dot + label + (N tokens) when
			// available. Hidden entirely when disconnected — keeps the card clean
			// rather than showing a dead "Bricks not connected" sentence on every
			// site that doesn't use Bricks.
			if (D.bricksConnected) {
				var status = el('div', { class: 'ak-card__status is-connected' }, [
					el('span', { class: 'ak-card__status-dot', 'aria-hidden': 'true' }),
					el('span', { class: 'ak-card__status-label', text: I.brandSyncStatus || 'Tokens synced with Bricks Builder' })
				]);
				var n = parseInt(D.bricksTokenCount, 10);
				if (n > 0) {
					var fmt = I.brandSyncStatusCount || '%d tokens';
					status.appendChild(el('span', { class: 'ak-card__status-count', text: fmt.replace('%d', String(n)) }));
				}
				headMain.appendChild(status);
			}
			cardHead.appendChild(headMain);

			var slotsRow = el('div', { class: 'ak-brand-slots' }, [
				brandSlot('light', I.slotLight || 'Logo Light Mode', I.slotLightSub || 'SVG · PNG ≥ 400×100'),
				brandSlot('dark', I.slotDark || 'Logo Dark Mode', I.slotDarkSub || 'SVG · PNG ≥ 400×100')
			]);

			// Display row — segmented controls for Admin bar + Login screen, and
			// the compact inline Accent picker all on the same line. Everything
			// the user can configure post-upload lives here, in one row, after
			// the brand slots. The derived-colours strip is intentionally gone —
			// the cascade derives Hover / Subtle / On-accent / Focus from --ak-primary
			// automatically through color-mix(), so showing those values added clutter
			// without a control. The Accent column anchors the right of the row so
			// the colour is always close to the controls it tints.
			// Admin bar: no `hide` option — picking Favicon when no Site Icon is set
			// already yields a bare title (favicon_chip_css returns ''), so the third
			// choice was redundant. Login screen keeps its `hide` (separate control).
			// Order: Favicon first (it's the schema default — see register_branding),
			// Logo second (the customisation step). Reads left-to-right as "starts
			// here, swap in your logo if you have one".
			var wpField = logoSeg('wpLogo', 'ak-wp-logo-label', I.wpLogoLabel || 'WordPress', [
				{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' },
				{ v: 'logo', label: I.wpLogoBrand || 'Logo' }
			]);
			// Login screen mirrors the admin bar: no `hide` option. Favicon with no
			// Site Icon set collapses the WP login logo entirely (see class-login.php).
			var loginField = logoSeg('loginLogo', 'ak-login-logo-label', I.loginLogoLabel || 'Login', [
				{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' },
				{ v: 'logo', label: I.wpLogoBrand || 'Logo' }
			]);
			// Two rows of identical chrome — same .ak-display-row, same row label
			// treatment — so Display and Color read as siblings, not as a row + a
			// pushed-right inline picker.
			var displayRow = el('div', { class: 'ak-display-row' }, [
				el('span', { class: 'ak-display-row__lbl', text: I.displayLabel || 'Display' }),
				wpField,
				loginField
			]);
			var colorRow = el('div', { class: 'ak-display-row' }, [
				el('span', { class: 'ak-display-row__lbl', text: I.accentLabel || 'Color' }),
				accentPicker()
			]);

			// --- Live preview pane ---------------------------------------------------
			// A self-contained mock of the admin chrome + login screen that re-skins as
			// you edit: the logo (light/dark variant), the favicon-vs-logo display mode,
			// and the accent (which rides the global --ak-primary the accent picker
			// already updates live). Its OWN light/dark toggle uses fixed backdrops —
			// like a swatch — so you see the brand in both themes regardless of the
			// admin's current theme. Decorative throughout: aria-hidden, buttons inert.
			function brandPreview() {
				var theme = document.documentElement.getAttribute('data-adminkit-theme') === 'dark' ? 'dark' : 'light';

				var barMark = el('span', { class: 'ak-bp__mark' });

				// Paint a brand mark (admin bar or login) for the current mode + theme:
				//   logo mode    → the logo image for the active preview theme
				//   favicon mode → the Site Icon (or an accent chip) + the site name
				//   no asset     → a "Your logo" placeholder
				function paintMark(markEl, mode) {
					markEl.textContent = '';
					var logo =
						(theme === 'dark' ? state.logos.dark : state.logos.light) || state.logos.light || state.logos.dark || '';
					if ('favicon' === mode) {
						if (D.siteIcon) {
							markEl.appendChild(el('img', { class: 'ak-bp__favicon', src: D.siteIcon, alt: '' }));
						} else {
							markEl.appendChild(el('span', { class: 'ak-bp__favicon ak-bp__favicon--empty' }));
						}
						markEl.appendChild(el('span', { class: 'ak-bp__site', text: D.siteName || '' }));
					} else if (logo) {
						markEl.appendChild(el('img', { class: 'ak-bp__logo', src: logo, alt: '' }));
					} else {
						markEl.appendChild(el('span', { class: 'ak-bp__logo-ph', text: I.brandPreviewLogo || 'Your logo' }));
					}
				}

				var bar = el('div', { class: 'ak-bp__bar' }, [
					barMark,
					el('span', { class: 'ak-bp__dots' }, [
						el('span', { class: 'ak-bp__dot' }),
						el('span', { class: 'ak-bp__dot' }),
						el('span', { class: 'ak-bp__dot' })
					])
				]);

				// Menu rail — five rows, the second active (painted in the accent).
				var menu = el('div', { class: 'ak-bp__menu' });
				for (var i = 0; i < 5; i++) {
					menu.appendChild(
						el('div', { class: 'ak-bp__nav' + (1 === i ? ' is-active' : '') }, [
							el('span', { class: 'ak-bp__nav-ic' }),
							el('span', { class: 'ak-bp__nav-bar' })
						])
					);
				}

				// A small dashboard widget (postbox): a title line + a couple of body lines.
				function bpCard() {
					return el('div', { class: 'ak-bp__card' }, [
						el('div', { class: 'ak-bp__card-h' }),
						el('div', { class: 'ak-bp__line' }),
						el('div', { class: 'ak-bp__line ak-bp__line--short' })
					]);
				}
				// A list-table row — three skeleton cells (the header variant reads a touch stronger).
				function bpRow(head) {
					return el('div', { class: 'ak-bp__trow' + (head ? ' ak-bp__trow--head' : '') }, [
						el('span', { class: 'ak-bp__tcell' }),
						el('span', { class: 'ak-bp__tcell' }),
						el('span', { class: 'ak-bp__tcell' })
					]);
				}

				var content = el('div', { class: 'ak-bp__content' }, [
					// Header row — page title + a primary action (the accent button), like the
					// dashboard greeting + its quick actions.
					el('div', { class: 'ak-bp__chead' }, [
						el('div', { class: 'ak-bp__h' }),
						el('button', {
							type: 'button',
							tabindex: '-1',
							class: 'ak-bp__btn',
							text: I.brandPreviewButton || 'Button'
						})
					]),
					// Dashboard widgets — two postbox cards on the canvas.
					el('div', { class: 'ak-bp__cards' }, [bpCard(), bpCard()]),
					// List-table view — a loading skeleton (no fake data): a header band + rows.
					el('div', { class: 'ak-bp__table' }, [bpRow(true), bpRow(), bpRow(), bpRow()])
				]);

				var frame = el('div', { class: 'ak-bp__frame', 'data-preview-theme': theme }, [
					bar,
					el('div', { class: 'ak-bp__layout' }, [menu, content])
				]);

				function update() {
					frame.setAttribute('data-preview-theme', theme);
					paintMark(barMark, state.wpLogo);
				}

				var lightBtn = el('button', {
					type: 'button',
					class: 'ak-bp__theme' + ('light' === theme ? ' is-active' : ''),
					text: I.brandPreviewLight || 'Light'
				});
				var darkBtn = el('button', {
					type: 'button',
					class: 'ak-bp__theme' + ('dark' === theme ? ' is-active' : ''),
					text: I.brandPreviewDark || 'Dark'
				});
				function setTheme(t) {
					theme = t;
					lightBtn.classList.toggle('is-active', 'light' === t);
					darkBtn.classList.toggle('is-active', 'dark' === t);
					update();
				}
				lightBtn.addEventListener('click', function () {
					setTheme('light');
				});
				darkBtn.addEventListener('click', function () {
					setTheme('dark');
				});

				var head = el('div', { class: 'ak-bp__head' }, [
					el('span', { class: 'ak-bp__title', text: I.brandPreviewTitle || 'Live preview' }),
					el('div', { class: 'ak-bp__themes', role: 'group' }, [lightBtn, darkBtn])
				]);

				var root = el('div', { class: 'ak-brand-preview' }, [head, frame]);
				update();
				return { el: root, update: update };
			}

			// Studio: identity + controls on the left, the live preview on the right.
			var controls = el('div', { class: 'ak-brand-studio__controls' }, [slotsRow, displayRow, colorRow]);
			var preview = brandPreview();
			previewUpdate = preview.update;

			var card = el('section', { class: 'ak-card ak-card--studio' }, [
				cardHead,
				el('div', { class: 'ak-brand-studio' }, [controls, preview.el])
			]);
			p.appendChild(card);

			return p;
		}

		function buildFeatures() {
			var p = el('section', { class: 'ak-panel', role: 'tabpanel' }, [intro(I.featuresIntro)]);

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
			// for a child OR an unavailable feature (e.g. Bricks builder without
			// the Bricks theme), lock it ("is-locked": dimmed + the switch made
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
				markDirty();
			}

			(D.features || []).forEach(function (f) {
				var input = el('input', { type: 'checkbox', class: 'ak-switch__input' });
				input.checked = !!state.features[f.key];
				input.addEventListener('change', function () {
					state.features[f.key] = input.checked;
					(D.features || []).forEach(refreshRow);
					markDirty();
				});
				var rowAttrs = { class: 'ak-row' + (f.parent ? ' ak-row--child' : '') };
				// Show the prereq hint on hover when the feature can't run (e.g.
				// Bricks builder without the Bricks theme). refreshRow() applies the
				// is-locked + disabled styling; this just explains why.
				if (f.available === false && f.unavailableHint) {
					rowAttrs.title = f.unavailableHint;
				}
				var row = el('div', rowAttrs, [
					el('div', { class: 'ak-row__main' }, [
						el('span', { class: 'ak-row__label', text: f.label }),
						el('span', { class: 'ak-row__desc', text: f.desc })
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
			function filter() {
				var q = (search.value || '').trim().toLowerCase();
				(D.features || []).forEach(function (f) {
					var r = refs[f.key];
					if (!r) {
						return;
					}
					var hay = ((f.label || '') + ' ' + (f.desc || '')).toLowerCase();
					r.row.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
				});
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

			return p;
		}

		function buildPlugins() {
			var p = el('section', { class: 'ak-panel', role: 'tabpanel' }, [intro(I.pluginsIntro)]);
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
					return el('div', { class: 'ak-row is-muted' }, [main]);
				}

				// Active plugin (native or generic) → user-facing toggle.
				// `.is-off` from the start paints the row dim with no flash.
				var input = el('input', { type: 'checkbox', class: 'ak-switch__input' });
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
					// Generic plugin → file path tracked in the off-list.
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
			// which is ON across the board (native integrations' schema default,
			// generic plugins not in the opt-out list). markDirty so the user
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
			function filterPlugins() {
				var q = (search.value || '').trim().toLowerCase();
				rowsRef.forEach(function (r) {
					r.row.style.display = !q || r.text.indexOf(q) !== -1 ? '' : 'none';
				});
				groupsRef.forEach(function (g) {
					var any = g.rows.some(function (r) {
						return r.row.style.display !== 'none';
					});
					g.wrap.style.display = any ? '' : 'none';
				});
			}
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
			// Per-plugin opt-out for generic plugins (no native adapter). POST as a
			// flat array of plugin file paths the user has turned theming off for.
			v.generic_theming_off = Object.keys(state.genericThemingOff);
			v.logo_light = state.logos.light;
			v.logo_dark = state.logos.dark;
			v.wp_logo = state.wpLogo;
			v.login_logo = state.loginLogo;
			v.brand_accent = state.brandAccent;
			v.accent_source = state.accentSource;
			return v;
		}
		return {
			tabs: [
				{ id: 'brand', label: I.brandTitle, icon: ICONS.brand, build: buildDesign },
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
