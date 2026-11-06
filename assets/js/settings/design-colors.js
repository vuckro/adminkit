/** Theme accent controls: shared palette math and two editable roles per mode. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};

	function isValidHex(v) {
		return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test((v || '').trim());
	}

	function isFullHex(v) {
		return /^#[0-9a-f]{6}$/i.test((v || '').trim());
	}

	function expandHex(raw) {
		var v = (raw || '').trim().toLowerCase();
		if (v && v.charAt(0) !== '#') {
			v = '#' + v;
		}
		if (/^#[0-9a-f]{3}$/i.test(v)) {
			return '#' + v.charAt(1) + v.charAt(1) + v.charAt(2) + v.charAt(2) + v.charAt(3) + v.charAt(3);
		}
		return v;
	}

	function accentChannels(hex) {
		var h = hex.replace('#', '');
		if (h.length === 3) {
			h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
		}
		return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
	}

	function hoverHex(rgb, dark) {
		return (
			'#' +
			rgb
				.map(function (channel) {
					return Math.round(channel * 0.82 + (dark ? 255 * 0.18 : 0))
						.toString(16)
						.padStart(2, '0');
				})
				.join('')
		);
	}

	function bestOnAccent(hex) {
		if (!isValidHex(hex)) {
			return '#ffffff';
		}
		var rgb = accentChannels(hex);
		function lin(byte) {
			var c = byte / 255;
			return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		}
		var L = 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
		return 1.05 / (L + 0.05) >= (L + 0.05) / 0.05 ? '#ffffff' : '#000000';
	}

	function toHex(cssColor) {
		var canvas = document.createElement('canvas');
		canvas.width = canvas.height = 1;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = cssColor;
		ctx.fillRect(0, 0, 1, 1);
		return (
			'#' +
			Array.from(ctx.getImageData(0, 0, 1, 1).data)
				.slice(0, 3)
				.map(function (v) {
					return v.toString(16).padStart(2, '0');
				})
				.join('')
		);
	}

	function providerValues(accentSource, mode) {
		if (accentSource !== 'bricks') {
			return {};
		}
		var style = getComputedStyle(document.documentElement);
		var map = {
			bg: mode === 'dark' ? '--neutral-d-1' : '--background',
			surface: mode === 'dark' ? '--neutral-d-2' : '--surface',
			accent: '--accent',
			'on-accent': '--accent-on',
			'accent-hover': '--accent-hover'
		};
		var values = {};
		Object.keys(map).forEach(function (role) {
			var value = style.getPropertyValue(map[role]).trim();
			if (value) {
				values[role] = value;
			}
		});
		return values;
	}

	function createColorsController(context, hooks) {
		var D = context.D;
		var I = context.I;
		var state = context.state;
		var el = context.el;
		var markDirty = context.markDirty;

		var syncSeg = function () {};
		var paletteCards = [];
		var roles = [['accent', I.foundationAccent], ['on-accent', I.foundationOnAccent]];

		function accentPicker() {
			var sources = [
				{ v: 'monochrome', label: I.accentSrcMonochrome },
				{ v: 'adminkit', label: I.accentSrcAdminKit }
			];
			if (D.bricksConnected) {
				sources.push({ v: 'bricks', label: I.accentSrcBricks });
			}
			sources.push({ v: 'custom', label: I.accentSrcCustom });

			var select = el('select', { class: 'ak-theme-color-source', 'aria-label': I.accentLabel });
			sources.forEach(function (source) { select.appendChild(el('option', { value: source.v, text: source.label })); });
			syncSeg = function () { select.value = state.accentSource; };
			syncSeg();
			select.addEventListener('change', function () {
				state.accentSource = select.value;
				paletteRefresh(); markDirty();
			});
			return el('label', { class: 'ak-theme-color-source-row' }, [el('span', { text: I.accentLabel }), select]);
		}

		['light', 'dark'].forEach(function (mode) {
			var modeLabel = mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark;
			var scope = el('div', { class: 'ak-theme-palette' });
			// Only invisible probes use the edited palette; controls keep the current UI theme.
			var preview = el('div', { class: 'ak-bp__frame ak-palette-theme ak-color-probes', 'data-preview-theme': mode, 'aria-hidden': 'true' });
			scope.appendChild(preview);
			var rows = [],
				probes = {};
			var header = el('div', { class: 'ak-palette-header' }, [
				el('button', {
					type: 'button',
					class: 'ak-btn',
					text: I.foundationPaletteReset,
					onclick: function () {
						roles.forEach(function (role) { delete state.designSystem.colors[mode][role[0]]; });
						paletteRefresh();
						markDirty();
					}
				})
			]);

			roles.forEach(function (role) {
				var id = 'ak-palette-' + mode + '-' + role[0];
				var picker = el('input', { type: 'color', id: id, 'aria-label': role[1] + ' — ' + modeLabel });
				var hex = el('input', {
					type: 'text',
					class: 'ak-palette-hex',
					maxlength: '7',
					spellcheck: 'false',
					'aria-label': role[1] + ' hex — ' + modeLabel
				});
				var inherited = el('span', { class: 'ak-palette-origin' });
				var error = el('span', {
					class: 'ak-palette-error',
					role: 'alert',
					id: id + '-error',
					hidden: 'hidden',
					text: I.foundationInvalidColor
				});
				hex.setAttribute('aria-describedby', error.id);
				var probe = el('span', { class: 'ak-palette-probe', 'aria-hidden': 'true' });
				probe.style.backgroundColor = 'var(--wk-' + role[0] + ')';
				probes[role[0]] = probe;
				preview.appendChild(probe);
				function change(value) {
					var expanded = value ? expandHex(value) : '';
					if (value && !isFullHex(expanded)) {
						hex.setAttribute('aria-invalid', 'true'); error.hidden = false; return;
					}
					hex.removeAttribute('aria-invalid');
					if (value) {
						state.accentSource = 'custom';
						state.designSystem.colors[mode][role[0]] = expanded;
						syncSeg();
					} else { delete state.designSystem.colors[mode][role[0]]; }
					paletteRefresh(); markDirty();
				}

				hex.addEventListener('adminkit:reveal-field', function () {
					hooks.onRevealMode(mode);
				});
				picker.addEventListener('input', function () {
					change(picker.value);
				});
				hex.addEventListener('input', function () {
					var raw = hex.value.trim();
					if (/^#?[0-9a-f]{6}$/i.test(raw)) { change(raw); }
					else {
						var invalid = !/^#?[0-9a-f]{0,5}$/i.test(raw);
						hex.setAttribute('aria-invalid', String(invalid)); error.hidden = !invalid;
					}
				});
				hex.addEventListener('change', function () {
					change(hex.value.trim());
				});
				var reset = el('button', {
					type: 'button',
					class: 'ak-palette-reset',
					text: I.foundationReset,
					'aria-label': I.foundationReset + ' — ' + role[1] + ' — ' + modeLabel,
					onclick: function () {
						delete state.designSystem.colors[mode][role[0]];
						paletteRefresh();
						markDirty();
					}
				});
				reset.innerHTML =
					'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10a9 9 0 1 1 2 8M3 4v6h6"/></svg>';
				scope.appendChild(
					el('div', { class: 'ak-palette-row' }, [
						picker,
						el('div', {}, [el('label', { for: id, text: role[1] }), inherited]),
						hex,
						reset,
						error
					])
				);
				rows.push({ role: role[0], picker: picker, hex: hex, inherited: inherited, reset: reset, error: error });
			});
			scope.appendChild(header);
			paletteCards.push({ mode: mode, scope: scope, preview: preview, rows: rows, probes: probes });
		});

		function paletteRefresh() {
			paletteCards.forEach(function (card) {
				var overrides = state.accentSource === 'custom' ? state.designSystem.colors[card.mode] : {};
				var values = state.accentSource === 'custom' ? Object.assign({}, overrides) : providerValues(state.accentSource, card.mode);
				card.preview.setAttribute('data-preview-accent', state.accentSource === 'adminkit' ? 'waaskit' : 'monochrome');
				Object.keys(state.designSystem.colors[card.mode]).concat(['accent', 'on-accent', 'bg', 'surface']).forEach(function (role) {
					card.preview.style.removeProperty('--wk-' + role);
				});
				card.preview.style.removeProperty('--wk-accent-hover');
				if (state.accentSource === 'custom') {
					if (!values.accent && isValidHex(state.brandAccent)) {
						values.accent = state.brandAccent;
					}
					if (values.accent && !values['on-accent']) {
						values['on-accent'] = bestOnAccent(values.accent);
					}
					if (values.accent) {
						values['accent-hover'] = hoverHex(accentChannels(values.accent), values['on-accent'] === '#000000');
					}
				}
				Object.keys(values).forEach(function (role) {
					card.preview.style.setProperty('--wk-' + role, values[role]);
				});
				if (!card.scope.isConnected) {
					return;
				}
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
			hooks.onPaletteRefresh();
		}

		return {
			paletteCards: paletteCards,
			paletteRefresh: paletteRefresh,
			buildChildren: function (themeSwitchNode) {
				return [
					el('div', { class: 'ak-color-controls' }, [
						el('div', { class: 'ak-foundation-accent' }, [accentPicker()]),
						el('div', { class: 'ak-palette-theme-switch' }, [themeSwitchNode])
					]),
					el('div', { class: 'ak-palettes' }, paletteCards.map(function (card) {
						return card.scope;
					}))
				];
			}
		};
	}

	window.AdminKitParts.designColors = {
		isValidHex: isValidHex,
		isFullHex: isFullHex,
		expandHex: expandHex,
		createColorsController: createColorsController
	};
})();
