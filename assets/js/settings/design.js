/** AdminKit Design system tab: section shell, typography, shapes & elevation, and plugins. */
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
			var sections = [];
			var shapePreviews = null;
			var sectionKey = 'adminkit:foundation-section:' + location.pathname + location.search;
			var rememberedSection = 'colors';
			try {
				rememberedSection = sessionStorage.getItem(sectionKey) || rememberedSection;
			} catch (error) {}
			var editor = el('div', { class: 'ak-foundations-editor' });
			var nav = el('div', {
				class: 'ak-foundations-nav',
				role: 'tablist',
				'aria-label': I.foundationSections,
				'aria-orientation': 'horizontal'
			});
			var panes = el('div', { class: 'ak-foundations-panes' });
			var scopeNote = el('p', { class: 'ak-foundation-scope', text: I.foundationScope });

			var previewGroups = {};
			var previewMode = document.documentElement.getAttribute('data-adminkit-theme') === 'dark' ? 'dark' : 'light';
			var previewSection = rememberedSection;
			var previewTitle = el('h3', { id: 'ak-design-preview-title' });
			var previewBody = el('div');
			var themeButtons = [];

			var colorsCtrl = window.AdminKitParts.designColors.createColorsController(context, {
				onRevealMode: function (mode) {
					previewMode = mode;
					if (previewSection === 'colors') {
						refreshPreview();
					} else {
						selectSection('colors');
					}
				},
				onPaletteRefresh: function () {
					if (shapePreviews) {
						colorsCtrl.applyPreviewFrameColors(shapePreviews);
					}
				}
			});

			function themeSwitch(label) {
				return el(
					'div',
					{ class: 'ak-design-preview__themes', role: 'group', 'aria-label': label },
					['light', 'dark'].map(function (mode) {
						var button = el('button', {
							type: 'button',
							class: 'ak-btn',
							text: mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark,
							'aria-pressed': String(mode === previewMode),
							onclick: function () {
								previewMode = mode;
								refreshPreview();
							}
						});
						themeButtons.push({ button: button, mode: mode });
						return button;
					})
				);
			}

			var previewDock = el(
				'section',
				{ class: 'ak-design-preview', role: 'region', 'aria-labelledby': previewTitle.id, hidden: 'hidden' },
				[el('div', { class: 'ak-design-preview__head' }, [previewTitle]), previewBody]
			);

			function attachPreview(id, frames) {
				var group = el('div', { hidden: 'hidden' }, frames);
				previewGroups[id] = { group: group, frames: frames };
				previewBody.appendChild(group);
			}

			function refreshPreview() {
				var selected = sections.find(function (item) {
					return item.id === previewSection;
				});
				previewDock.hidden = !previewGroups[previewSection];
				previewTitle.textContent = I.designPreview + (selected ? ' — ' + selected.title : '');
				themeButtons.forEach(function (item) {
					item.button.setAttribute('aria-pressed', String(item.mode === previewMode));
				});
				colorsCtrl.paletteCards.forEach(function (card) {
					card.scope.hidden = card.mode !== previewMode;
				});
				var administrationTheme = document.documentElement.getAttribute('data-adminkit-theme') === 'dark' ? 'dark' : 'light';
				Object.keys(previewGroups).forEach(function (id) {
					previewGroups[id].group.hidden = id !== previewSection;
					previewGroups[id].frames.forEach(function (frame) {
						frame.hidden = frame.dataset.previewTheme !== administrationTheme;
					});
				});
			}

			function number(value) {
				return Number(value).toLocaleString(document.documentElement.lang || 'fr', { maximumFractionDigits: 2 });
			}

			function section(id, title, description, children) {
				var tab = el('button', {
					type: 'button',
					role: 'tab',
					id: 'ak-foundation-tab-' + id,
					'aria-controls': 'ak-foundation-' + id,
					'aria-selected': 'false',
					tabindex: '-1',
					text: title
				});
				var pane = el(
					'section',
					{
						class: 'ak-foundations-panel',
						id: 'ak-foundation-' + id,
						role: 'tabpanel',
						'aria-labelledby': tab.id,
						hidden: 'hidden'
					},
					[el('h3', { text: title }), el('p', { class: 'ak-foundation-intro', text: description })].concat(children)
				);
				tab.addEventListener('click', function () {
					selectSection(id);
				});
				sections.push({ id: id, title: title, tab: tab, pane: pane });
				nav.appendChild(tab);
				panes.appendChild(pane);
				return pane;
			}

			function selectSection(id) {
				if (
					!sections.some(function (item) {
						return item.id === id;
					})
				) {
					id = 'colors';
				}
				try {
					sessionStorage.setItem(sectionKey, id);
				} catch (error) {}
				sections.forEach(function (item) {
					var active = item.id === id;
					item.tab.setAttribute('aria-selected', String(active));
					item.tab.tabIndex = active ? 0 : -1;
					item.pane.hidden = !active;
				});
				previewSection = id;
				scopeNote.hidden = id === 'colors' || id === 'plugins';
				refreshPreview();
				requestAnimationFrame(function () {
					colorsCtrl.paletteRefresh();
				});
			}

			nav.addEventListener('keydown', function (event) {
				var index = sections.findIndex(function (item) {
					return item.tab === document.activeElement;
				});
				if (index < 0) {
					return;
				}
				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
					index = (index + 1) % sections.length;
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
					index = (index + sections.length - 1) % sections.length;
				} else if (event.key === 'Home') {
					index = 0;
				} else if (event.key === 'End') {
					index = sections.length - 1;
				} else {
					return;
				}
				event.preventDefault();
				selectSection(sections[index].id);
				sections[index].tab.focus();
			});

			// --- Section 1: Colors ---------------------------------------------------
			section('colors', I.studioColors, I.foundationColorsHelp, colorsCtrl.buildChildren(themeSwitch(I.studioColors)));

			// --- Section 2: Typography -----------------------------------------------
			var fontCatalog = D.fonts || {
				geist: { label: 'Geist (AdminKit)', stack: '"Geist",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif', google: null },
				system: { label: I.fontSystem, stack: 'ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif', google: null },
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
				if (!isFinite(num)) {
					return fallback;
				}
				var best = choices[0];
				choices.forEach(function (c) {
					if (Math.abs(c - num) < Math.abs(best - num)) {
						best = c;
					}
				});
				return best;
			}
			function scaleChoice(value, choices, min, max) {
				var num = Number(value);
				if (!isFinite(num) || num < min || num > max) {
					return 1;
				}
				if (choices.indexOf(num) < 0) {
					choices.push(num);
					choices.sort(function (a, b) {
						return a - b;
					});
				}
				return num;
			}
			var fontSelect = el(
				'select',
				{ id: 'ak-type-font' },
				Object.keys(fontCatalog).map(function (key) {
					var label = key === 'system' && I.fontSystem ? I.fontSystem : fontCatalog[key].label;
					return el('option', { value: key, text: label });
				})
			);
			fontSelect.value = state.appearance.ui_font;
			var typeChoices = [0.9, 0.95, 1, 1.05, 1.1];
			var displayedTypeScale = scaleChoice(state.designSystem.type_scale, typeChoices, 0.75, 1.5);
			var typeScale = el(
				'select',
				{ id: 'ak-type-scale' },
				typeChoices.map(function (scale) {
					var pct = Math.round(scale * 100) + ' %';
					return el('option', { value: String(scale), text: scale === 1 ? I.defaultPreset + ' (' + pct + ')' : pct });
				})
			);
			typeScale.value = String(displayedTypeScale);
			var scalePreviews = ['light', 'dark'].map(function (mode) {
				return el('div', { class: 'ak-bp__frame ak-shape-preview ak-scale-preview', 'data-preview-theme': mode }, [
					el('div', { class: 'ak-scale-sample' }, [
						el('strong', { text: I.pageOverviewTitle }),
						el('p', { text: I.pageOverviewIntro }),
						el('div', { class: 'ak-scale-rows' }, [
							el('span', { text: I.pageCategories }),
							el('span', { text: I.pageGuideIntro }),
							el('span', { text: I.pageGuideOptional })
						])
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
				var root = document.documentElement.style;
				root.setProperty('--ak-type-scale', state.designSystem.type_scale);
				root.setProperty('--ak-font-body', font);
				root.setProperty('--wk-font-sans', font);
				scalePreviews.forEach(function (preview) {
					preview.style.setProperty('--ak-prev-type', state.designSystem.type_scale);
					preview.style.setProperty('--ak-prev-font', font);
				});
			}
			function onTypeScale() {
				state.designSystem.type_scale = Number(typeScale.value);
				refreshScales();
				markDirty();
			}
			fontSelect.addEventListener('change', function () {
				state.appearance.ui_font = fontSelect.value;
				refreshScales();
				markDirty();
			});
			typeScale.addEventListener('change', onTypeScale);
			typeScale.addEventListener('input', onTypeScale);
			section('type', I.studioType, I.studioTypeHint, [
				el('div', { class: 'ak-shape-selects' }, [
					el('div', {}, [el('label', { for: fontSelect.id, text: I.studioFont }), fontSelect]),
					el('div', {}, [el('label', { for: typeScale.id, text: I.studioTypeScale }), typeScale])
				]),
				el('button', {
					type: 'button',
					class: 'ak-btn',
					text: I.resetDefaults,
					onclick: function () {
						state.appearance.ui_font = 'geist';
						fontSelect.value = 'geist';
						state.designSystem.type_scale = 1;
						typeScale.value = '1';
						refreshScales();
						markDirty();
					}
				})
			]);
			attachPreview('type', scalePreviews);

			// --- Section 3: Shapes & Surfaces ----------------------------------------
			var radiusChoices = [0, 0.5, 1, 1.25, 1.5];
			var displayedRadiusScale = scaleChoice(state.designSystem.radius_scale, radiusChoices, 0, 2);
			var radius = el(
				'select',
				{ id: 'ak-shape-radius' },
				radiusChoices.map(function (scale) {
					var label = scale === 0 ? '0 px' : scale === 1 ? I.defaultPreset + ' (1 ×)' : number(scale) + ' ×';
					return el('option', { value: String(scale), text: label });
				})
			);
			radius.value = String(displayedRadiusScale);
			var elevation = el(
				'select',
				{ id: 'ak-shape-elevation' },
				[
					['default', I.defaultPreset],
					['flat', I.studioFlat],
					['subtle', I.studioSubtle],
					['raised', I.studioRaised]
				].map(function (item) {
					return el('option', { value: item[0], text: item[1] });
				})
			);
			elevation.value = state.designSystem.elevation;
			state.designSystem.border_width = snapChoice(state.designSystem.border_width, [0, 1, 2], 1);
			var borderWidth = el(
				'select',
				{ id: 'ak-shape-border' },
				[0, 1, 2].map(function (width) {
					return el('option', { value: String(width), text: width + ' px' });
				})
			);
			borderWidth.value = String(state.designSystem.border_width);
			var highlightSelect = el(
				'select',
				{ id: 'ak-buttons-highlight', 'aria-describedby': 'ak-buttons-highlight-help' },
				[
					['off', I.componentOff],
					['hover', I.componentHover],
					['always', I.componentAlways]
				].map(function (item) {
					return el('option', { value: item[0], text: item[1] });
				})
			);
			highlightSelect.value = state.designSystem.components.buttons.highlight;

			shapePreviews = ['light', 'dark'].map(function (mode) {
				return el('div', { class: 'ak-bp__frame ak-shape-preview', 'data-preview-theme': mode }, [
					el('div', { class: 'ak-shape-controls' }, [
						el('button', { type: 'button', class: 'ak-btn ak-btn--primary', text: I.previewAction }),
						el('button', { type: 'button', class: 'ak-btn', text: I.studioSecondary }),
						el('button', { type: 'button', class: 'ak-btn ak-btn--primary', disabled: 'disabled', text: I.componentDisabled }),
						el('label', {}, [
							el('span', { text: I.studioField }),
							el('input', {
								type: 'text',
								value: 'AdminKit',
								'aria-label': I.studioField + ' — ' + (mode === 'light' ? I.brandPreviewLight : I.brandPreviewDark)
							})
						])
					]),
					el('div', { class: 'ak-shape-card' }, [el('strong', { text: I.shapeCard }), el('p', { text: I.studioSample })]),
					el('div', { class: 'ak-shape-table', role: 'img', 'aria-label': I.shapeTable }, [
						el('strong', { text: I.shapeTable }),
						el('span', { text: I.studioSample }),
						el('span', { text: I.studioSample })
					])
				]);
			});

			function refreshShapes() {
				var highlight = state.designSystem.components.buttons.highlight;
				var depth = { flat: 'none', subtle: 'var(--wk-elevation-1)', raised: 'var(--wk-elevation-2)' }[state.designSystem.elevation];
				var root = document.documentElement.style;
				root.setProperty('--ak-radius-scale', state.designSystem.radius_scale);
				root.setProperty('--wk-radius-scale', state.designSystem.radius_scale);
				root.setProperty('--ak-border-width', state.designSystem.border_width + 'px');
				[
					['s', 8],
					['m', 12],
					['l', 16],
					['xl', 24]
				].forEach(function (r) {
					root.setProperty('--ak-radius-' + r[0], 'calc(' + r[1] + 'px * ' + state.designSystem.radius_scale + ')');
				});
				if (depth) {
					root.setProperty('--ak-design-elevation', depth);
				} else {
					root.removeProperty('--ak-design-elevation');
				}
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
			function onRadius() {
				state.designSystem.radius_scale = Number(radius.value);
				refreshShapes();
				markDirty();
			}
			borderWidth.addEventListener('change', function () {
				state.designSystem.border_width = Number(borderWidth.value);
				refreshShapes();
				markDirty();
			});
			radius.addEventListener('change', onRadius);
			radius.addEventListener('input', onRadius);
			elevation.addEventListener('change', function () {
				state.designSystem.elevation = elevation.value;
				refreshShapes();
				markDirty();
			});
			highlightSelect.addEventListener('change', function () {
				state.designSystem.components.buttons.highlight = highlightSelect.value;
				refreshShapes();
				markDirty();
			});

			section('shapes', I.studioShapes, I.shapeHint, [
				el('div', { class: 'ak-shape-selects' }, [
					el('div', {}, [el('label', { for: radius.id, text: I.studioRadiusScale }), radius]),
					el('div', {}, [el('label', { for: borderWidth.id, text: I.studioBorder }), borderWidth]),
					el('div', {}, [el('label', { for: elevation.id, text: I.studioElevation }), elevation]),
					el('div', {}, [el('label', { for: highlightSelect.id, text: I.componentHighlight }), highlightSelect])
				]),
				el('p', { id: 'ak-buttons-highlight-help', class: 'ak-foundation-intro ak-shape-help', text: I.shapeHelp }),
				el('button', {
					type: 'button',
					class: 'ak-btn',
					text: I.resetDefaults,
					onclick: function () {
						state.designSystem.radius_scale = 1;
						radius.value = '1';
						state.designSystem.border_width = 1;
						borderWidth.value = '1';
						state.designSystem.elevation = 'default';
						elevation.value = 'default';
						state.designSystem.components.buttons.highlight = 'always';
						highlightSelect.value = 'always';
						refreshShapes();
						markDirty();
					}
				})
			]);
			attachPreview('shapes', shapePreviews);

			// --- Section 4: Extensions / Plugins supported by the Design System ------
			if (window.AdminKitParts && typeof window.AdminKitParts.plugins === 'function') {
				var buildPlugins = window.AdminKitParts.plugins(context);
				section('plugins', I.plugins, I.pluginsIntro, buildPlugins());
			}

			refreshShapes();
			colorsCtrl.applyPreviewFrameColors(shapePreviews);
			refreshScales();
			p.classList.add('ak-foundations');
			p.appendChild(el('h2', { class: 'ak-foundations-title', text: I.designSystem }));
			p.appendChild(intro(I.designIntro));
			editor.appendChild(nav);
			editor.appendChild(panes);
			p.appendChild(editor);
			p.appendChild(previewDock);
			p.appendChild(scopeNote);
			selectSection(rememberedSection);
			new MutationObserver(refreshPreview).observe(document.documentElement, {
				attributes: true,
				attributeFilter: ['data-adminkit-theme']
			});
			return p;
		}

		return buildDesign;
	};
})();
