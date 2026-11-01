/** Shared navigation controls: icons/popovers, drag gestures and reorder animation. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.navigationControls = function (context) {
		var D = context.D;
		var I = context.I;
		var el = context.el;
		var markDirty = context.markDirty;
		var MM = D.menuManager || { icons: {} };
		MM.icons = MM.icons || {};
		var dragState = null, openPicker = null;
		var catalogLoaded = !MM.iconsUrl;
		var catalogPromise = null;

		function svgFromPath(inner) {
			return (
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
				inner +
				'</svg>'
			);
		}

		function ensureIconCatalog() {
			if (catalogLoaded || !MM.iconsUrl || typeof window.fetch !== 'function') {
				catalogLoaded = true;
				return Promise.resolve(MM.icons);
			}
			if (catalogPromise) {
				return catalogPromise;
			}
			catalogPromise = window
				.fetch(MM.iconsUrl, { credentials: 'same-origin', cache: 'force-cache' })
				.then(function (res) {
					return res && res.ok ? res.json() : null;
				})
				.then(function (raw) {
					if (raw && typeof raw === 'object') {
						var aliases = MM.iconAliases || {};
						Object.keys(aliases).forEach(function (role) {
							var target = aliases[role];
							if (typeof raw[target] === 'string' && !MM.icons[role]) {
								MM.icons[role] = svgFromPath(raw[target]);
							}
						});
						Object.keys(raw).forEach(function (name) {
							if (typeof raw[name] === 'string' && !MM.icons[name]) {
								MM.icons[name] = svgFromPath(raw[name]);
							}
						});
						catalogLoaded = true;
					}
					catalogPromise = null;
					return MM.icons;
				})
				.catch(function () {
					catalogPromise = null;
					return MM.icons;
				});
			return catalogPromise;
		}

		function snapshotFlip(container) {
			var map = {};
			if (!container || !container.querySelectorAll) {
				return map;
			}
			var nodes = container.querySelectorAll('[data-flip-id]');
			for (var i = 0; i < nodes.length; i++) {
				var k = nodes[i].getAttribute('data-flip-id');
				if (k && typeof nodes[i].getBoundingClientRect === 'function') {
					map[k] = nodes[i].getBoundingClientRect().top;
				}
			}
			return map;
		}

		function playFlip(container, prevMap, movedKey) {
			if (!container || !prevMap || !container.querySelectorAll) {
				return;
			}
			if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
				return;
			}
			var nodes = container.querySelectorAll('[data-flip-id]');
			var animated = [];
			for (var i = 0; i < nodes.length; i++) {
				var node = nodes[i];
				var k = node.getAttribute('data-flip-id');
				if (!k || typeof prevMap[k] !== 'number' || typeof node.getBoundingClientRect !== 'function') {
					continue;
				}
				var dy = prevMap[k] - node.getBoundingClientRect().top;
				if (Math.abs(dy) < 1) {
					continue;
				}
				node.style.transition = 'none';
				node.style.transform = 'translateY(' + dy + 'px)';
				if (movedKey && k === movedKey) {
					node.classList.add('is-moved');
				}
				animated.push(node);
			}
			if (!animated.length) {
				return;
			}
			void container.offsetHeight;
			animated.forEach(function (node) {
				node.style.transition =
					'transform 220ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 220ms ease, border-color 220ms ease';
				node.style.transform = '';
				setTimeout(function () {
					node.style.transition = '';
					node.style.transform = '';
					node.classList.remove('is-moved');
				}, 240);
			});
		}

		function titleOverride(it) {
			var t = it.title || '';
			return '' !== t && t !== (it.otitle || '') ? t : '';
		}

		function handle() {
			var h = el('span', { class: 'ak-menu-handle', title: I.menuDragHint, 'aria-hidden': 'true' });
			h.innerHTML =
				'<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.6"/><circle cx="15" cy="5" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="19" r="1.6"/><circle cx="15" cy="19" r="1.6"/></svg>';
			return h;
		}

		function iconBtn(top, tree, scope) {
			var btn = el('button', {
				type: 'button',
				class: 'ak-menu-icon',
				title: I.menuIconPick,
				'aria-label': I.menuIconPick
			});
			paintIcon(btn, top);
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				// Clicking the same icon button while its picker is open closes it (toggle).
				if (openPicker && openPicker.__akAnchor === btn) {
					closeIconPicker();
					return;
				}
				openIconPicker(btn, top, tree, scope);
			});
			return btn;
		}

		function paintIcon(btn, top) {
			btn.textContent = '';
			var g;
			if (top.icon === 'wp-default') {
				// Show the item's original WordPress dashicon in the picker button.
				g = el('span', { class: 'dashicons ' + (top.dashicon || 'dashicons-admin-settings') });
			} else if (top.icon && top.icon.indexOf('dashicons-') === 0) {
				// A chosen WordPress dashicon.
				g = el('span', { class: 'dashicons ' + top.icon });
			} else if (top.icon && top.icon.indexOf('data:') === 0) {
				g = el('span', { class: 'ak-menu-icon__g ak-menu-icon__mask' });
				g.style.webkitMaskImage = 'url("' + top.icon + '")';
				g.style.maskImage = 'url("' + top.icon + '")';
			} else if (top.icon && MM.icons[top.icon]) {
				g = el('span', { class: 'ak-menu-icon__g' });
				// eslint-disable-next-line no-unsanitized/property -- Trusted PHP-compiled Lucide SVG.
				g.innerHTML = MM.icons[top.icon];
			} else if (top.defaultIcon && MM.icons[top.defaultIcon]) {
				g = el('span', { class: 'ak-menu-icon__g' });
				// eslint-disable-next-line no-unsanitized/property -- Trusted PHP-compiled Lucide SVG.
				g.innerHTML = MM.icons[top.defaultIcon];
			} else if (top.dashicon) {
				g = el('span', { class: 'dashicons ' + top.dashicon });
			} else {
				g = el('span', { class: 'ak-menu-icon__g' });
				// eslint-disable-next-line no-unsanitized/property -- Trusted PHP-compiled Lucide SVG.
				g.innerHTML = MM.icons.app || '';
			}
			btn.appendChild(g);
		}

		function openIconPicker(anchor, top, tree, scope) {
			closeIconPicker();
			var pop = el('div', { class: 'ak-icon-pop', role: 'dialog', 'aria-label': I.menuIconPick });
			anchor.setAttribute('aria-expanded', 'true');
			pop.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); closeIconPicker(); anchor.focus(); } });
			var choices = []; // { el, val } — every selectable swatch, for the highlight sweep.

			// Repaint highlight + the anchor button. `commit` also flags the menu dirty;
			// the initial paint (on open) must NOT, or merely opening the picker would
			// mark unsaved changes.
			function paint() {
				choices.forEach(function (c) {
					c.el.classList.toggle('on', top.icon === c.val);
				});
				paintIcon(anchor, top);
			}
			function choose(val) {
				top.icon = val;
				paint();
				markDirty(scope || 'menu');
			}

			// Tabbed picker: an "AdminKit" pane (the curated glyph set) and a "WordPress"
			// pane (native dashicons). Only one shows at a time — the two palettes no longer
			// stack in one jumbled column. AdminKit is the default view; each pane carries
			// its own reset action, and the WordPress pane leads with its search.
			var akTab = el('button', {
				type: 'button',
				class: 'ak-icon-pop__tab on',
				text: 'Lucide'
			});
			var wpTab = el('button', {
				type: 'button',
				class: 'ak-icon-pop__tab',
				text: I.menuIconWpSet
			});
			var akPane = el('div', { class: 'ak-icon-pop__pane' });
			var wpPane = el('div', { class: 'ak-icon-pop__pane' });
			wpPane.style.display = 'none';
			function showPane(ak) {
				akPane.style.display = ak ? '' : 'none';
				wpPane.style.display = ak ? 'none' : '';
				akTab.classList.toggle('on', ak);
				wpTab.classList.toggle('on', !ak);
			}
			akTab.addEventListener('click', function () {
				showPane(true);
			});
			wpTab.addEventListener('click', function () {
				showPane(false);
			});
			pop.appendChild(el('div', { class: 'ak-icon-pop__tabs' }, [akTab, wpTab]));

			// --- AdminKit pane: the "default (auto) icon" reset + the glyph grid.
			var noneBtn = el('button', {
				type: 'button',
				class: 'ak-icon-pop__def',
				text: I.menuIconNone,
				onclick: function () {
					choose('');
				}
			});
			choices.push({ el: noneBtn, val: '' });
			akPane.appendChild(el('div', { class: 'ak-icon-pop__defbar' }, [noneBtn]));
			var search = el('input', { type: 'search', class: 'ak-icon-pop__search', placeholder: I.menuIconSearch, 'aria-label': I.menuIconSearch });
			akPane.appendChild(search);
			var empty = el('p', { class: 'ak-icon-pop__empty', role: 'status', 'aria-live': 'polite' });
			akPane.appendChild(empty);
			var grid = el('div', { class: 'ak-icon-pop__grid' });
			var names = Object.keys(MM.icons || {});
			var filtered = names;
			var shown = 0;
			function appendIcons() {
				filtered.slice(shown, shown + 120).forEach(function (name) {
					var sw = el('button', { type: 'button', class: 'ak-icon-pop__sw', title: name, 'aria-label': name, onclick: function () { choose(name); } });
					// eslint-disable-next-line no-unsanitized/property -- Trusted PHP-compiled Lucide SVG.
					sw.innerHTML = MM.icons[name];
					choices.push({ el: sw, val: name });
					grid.appendChild(sw);
				});
				shown = Math.min(shown + 120, filtered.length);
				paint();
			}
			function applyIconFilter() {
				var words = { calendrier: 'calendar', fleche: 'arrow', gauche: 'left', droite: 'right', haut: 'up', bas: 'down', reglage: 'settings', reglages: 'settings', parametres: 'settings', image: 'image', photo: 'image', dossier: 'folder', fichier: 'file', message: 'message', utilisateur: 'user', recherche: 'search', maison: 'house', panier: 'cart', courriel: 'mail', horloge: 'clock', alerte: 'alert' };
				var q = search.value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').split(/\s+/).map(function (word) { return words[word] || word; }).join('-');
				filtered = names.filter(function (name) { return name.indexOf(q) !== -1; });
				choices = choices.filter(function (c) { return !grid.contains(c.el); });
				grid.textContent = '';
				empty.textContent = filtered.length ? '' : I.menuIconEmpty;
				shown = 0;
				appendIcons();
			}
			search.addEventListener('input', applyIconFilter);
			pop.addEventListener('scroll', function () {
				if (akPane.style.display !== 'none' && pop.scrollTop + pop.clientHeight >= pop.scrollHeight - 120 && shown < filtered.length) appendIcons();
			});
			appendIcons();
			if (!catalogLoaded) {
				ensureIconCatalog().then(function () {
					if (openPicker !== pop) {
						return;
					}
					names = Object.keys(MM.icons || {});
					applyIconFilter();
				});
			}
			akPane.appendChild(grid);
			pop.appendChild(akPane);

			// --- WordPress pane: search (at the top) + a "restore the item's original
			// WordPress icon" reset + the native-dashicon grid. The dashicons font is already
			// loaded in wp-admin, so each swatch renders with no extra assets; PHP only offers
			// the dashicons AdminKit doesn't remap, so picking one paints the NATIVE dashicon.
			var wpSearch = el('input', {
				type: 'search',
				class: 'ak-icon-pop__search',
				placeholder: I.menuIconSearch,
				'aria-label': I.menuIconWpSet
			});
			wpPane.appendChild(wpSearch);
			var wpBtn = el('button', {
				type: 'button',
				class: 'ak-icon-pop__def',
				text: I.menuIconWp,
				onclick: function () {
					choose('wp-default');
				}
			});
			choices.push({ el: wpBtn, val: 'wp-default' });
			wpPane.appendChild(el('div', { class: 'ak-icon-pop__defbar' }, [wpBtn]));
			var wpGrid = el('div', { class: 'ak-icon-pop__grid ak-icon-pop__grid--dash' });
			var dashSw = [];
			(MM.dashicons || []).forEach(function (cls) {
				var sw = el('button', {
					type: 'button',
					class: 'ak-icon-pop__sw',
					title: cls,
					'aria-label': cls,
					onclick: function () {
						choose(cls);
					}
				});
				sw.appendChild(el('span', { class: 'dashicons ' + cls }));
				choices.push({ el: sw, val: cls });
				dashSw.push({ el: sw, key: cls.replace('dashicons-', '') });
				wpGrid.appendChild(sw);
			});
			wpSearch.addEventListener('input', function () {
				var q = wpSearch.value
					.trim()
					.toLowerCase()
					.replace(/^dashicons-/, '');
				dashSw.forEach(function (d) {
					d.el.style.display = !q || d.key.indexOf(q) !== -1 ? '' : 'none';
				});
			});
			wpPane.appendChild(wpGrid);
			pop.appendChild(wpPane);

			// Custom icon — one line: a leading image glyph (the affordance) + a field to
			// paste raw <svg> markup or a data:image/svg+xml URI (base64 or encoded) + Apply.
			var customIc = el('span', { class: 'ak-icon-pop__customic', 'aria-hidden': 'true' });
			customIc.innerHTML =
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg>';
			var ta = el('input', { type: 'text', class: 'ak-icon-pop__ta', placeholder: I.menuIconCustom });
			var applyBtn = el('button', {
				type: 'button',
				class: 'ak-btn ak-btn--small',
				text: I.menuIconApply,
				onclick: function () {
					var v = (ta.value || '').replace(/^\s+|\s+$/g, '');
					if (!v) {
						return;
					}
					if (v.indexOf('<svg') === 0) {
						v = 'data:image/svg+xml,' + encodeURIComponent(v);
					}
					if (v.indexOf('data:image/svg+xml') !== 0) {
						return;
					}
					choose(v);
					ta.value = '';
				}
			});
			ta.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					applyBtn.click();
				}
			});
			pop.appendChild(el('div', { class: 'ak-icon-pop__custom' }, [customIc, ta, applyBtn]));

			// Quick tip: where to grab a pasteable SVG icon.
			pop.appendChild(
				el('div', { class: 'ak-icon-pop__hint' }, [
					document.createTextNode((I.menuIconHint) + ' '),
					el('a', { href: 'https://lucide.dev/icons/', target: '_blank', rel: 'noopener', text: 'Lucide' }),
					document.createTextNode('.')
				])
			);

			mountPopover(pop, anchor);
			search.focus();
			paint(); // seed the highlight (no dirty flag).
		}

		function positionPopover(pop, anchor) {
			var row = anchor.parentNode;
			var rowW = row.offsetWidth;
			var popW = pop.offsetWidth || 264;
			var left = anchor.offsetLeft;
			if (left + popW > rowW) {
				left = Math.max(0, rowW - popW);
			}
			pop.style.left = left + 'px';
			pop.style.right = 'auto';
			// Open below the button by default, but flip ABOVE it when below would spill
			// past the viewport bottom and there's more room above — so the picker stays
			// fully reachable from rows low on the page.
			var popH = pop.offsetHeight || 0;
			var aRect = anchor.getBoundingClientRect();
			var vh = window.innerHeight || document.documentElement.clientHeight;
			if (aRect.bottom + 4 + popH > vh && aRect.top - 4 - popH > 0) {
				// Flip above. Clamp to 0 (row-relative) so a tall picker in a scrolled tree
				// never pins with a negative top above the row box.
				pop.style.top = Math.max(0, anchor.offsetTop - popH - 4) + 'px';
			} else {
				pop.style.top = anchor.offsetTop + anchor.offsetHeight + 4 + 'px';
			}
		}

		function onDocDown(e) {
			if (openPicker && !openPicker.contains(e.target)) {
				closeIconPicker();
			}
		}

		function closeIconPicker() {
			if (openPicker && openPicker.parentNode) {
				if (openPicker.__akAnchor) openPicker.__akAnchor.setAttribute('aria-expanded', 'false');
				openPicker.parentNode.removeChild(openPicker);
			}
			openPicker = null;
			document.removeEventListener('mousedown', onDocDown, true);
		}

		function dragSource(handle, row, info) {
			handle.setAttribute('draggable', 'true');
			handle.addEventListener('dragstart', function (e) {
				e.stopPropagation();
				dragState = info;
				e.dataTransfer.effectAllowed = 'move';
				try {
					e.dataTransfer.setData('text/plain', '1');
				} catch (err) {}
				row.classList.add('is-dragging');
			});
			handle.addEventListener('dragend', function () {
				row.classList.remove('is-dragging');
			});
		}

		function dropZone(zone, accepts, onDrop) {
			zone.addEventListener('dragover', function (e) {
				if (!dragState || !accepts(dragState)) {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				e.dataTransfer.dropEffect = 'move';
				zone.classList.add('is-drop');
			});
			zone.addEventListener('dragleave', function () {
				zone.classList.remove('is-drop');
			});
			zone.addEventListener('drop', function (e) {
				if (!dragState || !accepts(dragState)) {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				zone.classList.remove('is-drop');
				var ds = dragState;
				dragState = null;
				onDrop(ds);
			});
		}

		function titleInput(item, scope) {
			var inp = el('input', {
				type: 'text',
				class: 'ak-menu-input ak-menu-title',
				value: item.title || '',
				placeholder: item.otitle || item.slug || '',
				title: I.menuRename,
				'aria-label': I.menuRename
			});
			inp.addEventListener('input', function () {
				item.title = inp.value;
				markDirty(scope || 'menu');
			});
			return inp;
		}

		function mountPopover(pop, anchor) {
			anchor.parentNode.appendChild(pop);
			positionPopover(pop, anchor);
			pop.__akAnchor = anchor;
			openPicker = pop;
			setTimeout(function () {
				document.addEventListener('mousedown', onDocDown, true);
			}, 0);
		}

		return {
			snapshotFlip: snapshotFlip,
			playFlip: playFlip,
			titleOverride: titleOverride,
			handle: handle,
			iconBtn: iconBtn,
			closeIconPicker: closeIconPicker,
			dragSource: dragSource,
			dropZone: dropZone,
			titleInput: titleInput,
			mountPopover: mountPopover
		};
	};
})();
