/** AdminKit navigation screen. Loaded only on its own admin page; no build step. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitScreens = window.AdminKitScreens || {};
	window.AdminKitParts.navigation = window.AdminKitScreens.navigation = function (context) {
		var D = context.D;
		var I = context.I;
		var apiFetch = context.apiFetch;
		var state = context.state;
		var el = context.el;
		var intro = context.intro;
		var markDirty = context.markDirty;
		var setStatus = context.setStatus;
		var MM = D.menuManager || { menu: [], config: { top: [], sub: {} }, icons: {} };
		var dragState = null,
			openPicker = null,
			uidSeq = 0;

		function nextUid(prefix) {
			uidSeq += 1;
			return (prefix || 'ak-') + Date.now() + '-' + uidSeq;
		}

		function topFlipKey(item) {
			if (!item._uid) {
				item._uid = item.slug ? 'top:' + item.slug : nextUid('top:custom:');
			}
			return item._uid;
		}

		function subFlipKey(top, child) {
			return 'sub:' + (top.slug || topFlipKey(top)) + ':' + child.slug;
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
		state.menu = buildMenuModel(MM.menu || [], MM.config || { top: [], sub: {} });
		state.toolbar = { left: [], right: [] };
		function buildMenuModel(snapshot, config) {
			// Global lookups so a submenu can be rebuilt under ANY parent (cross-section):
			// childTitle = every submenu slug -> its original title; claimed = every slug
			// any saved parent lists (so it leaves its origin and shows under its new parent).
			var snapBySlug = {},
				childTitle = {};
			(snapshot || []).forEach(function (m) {
				snapBySlug[m.slug] = m;
				(m.children || []).forEach(function (c) {
					childTitle[c.slug] = c.title;
				});
			});
			var subCfg = (config && config.sub) || {};
			var claimed = {};
			Object.keys(subCfg).forEach(function (p) {
				(subCfg[p] || []).forEach(function (s) {
					claimed[s.slug] = true;
				});
			});
			var seen = {},
				model = [];
			((config && config.top) || []).forEach(function (t) {
				var m = snapBySlug[t.slug];
				if (m && m.type === 'separator') {
					seen[t.slug] = true;
					model.push({ type: 'separator', slug: t.slug, hidden: !!t.hidden });
				} else if (m) {
					seen[t.slug] = true;
					model.push(makeTop(m, t, subCfg, childTitle, claimed));
				} else if (t.type === 'link' || t.type === 'separator') {
					model.push(makeCustom(t));
				}
				// else: a real menu that no longer exists → drop it.
			});
			// Place the snapshot items the saved layout didn't already position. Each is set
			// at its NATURAL snapshot spot (right after the previous placed item) so the
			// menu reproduces WordPress's default order — separators interspersed, not
			// clustered. EXCEPTION: when a saved layout EXISTS, a real menu it doesn't list
			// is a genuinely new plugin → defer it to the very bottom (ordered by first-seen
			// below). With NO saved layout (a reset / pristine view) nothing is "new", so
			// everything — separators AND menus — is placed naturally.
			var hasConfig = ((config && config.top) || []).length > 0;
			var indexOfSlug = function (slug) {
				for (var i = 0; i < model.length; i++) {
					if (model[i].slug === slug) {
						return i;
					}
				}
				return -1;
			};
			var pending = [];
			var prevSlug = null;
			(snapshot || []).forEach(function (m) {
				if (seen[m.slug]) {
					prevSlug = m.slug;
					return;
				}
				if (hasConfig && m.type !== 'separator') {
					pending.push(m); // new plugin (layout exists but omits it) → bottom; does NOT advance prevSlug
					return;
				}
				var node =
					m.type === 'separator'
						? { type: 'separator', slug: m.slug, hidden: false }
						: makeTop(m, null, subCfg, childTitle, claimed);
				var at = prevSlug !== null ? indexOfSlug(prevSlug) : -1;
				var pos = at >= 0 ? at + 1 : null === prevSlug ? 0 : model.length;
				model.splice(pos, 0, node);
				prevSlug = m.slug;
			});
			// New plugins (only when a layout exists) → appended at the very bottom, ordered
			// by the order AdminKit first saw them (newest last), mirroring order_top_level().
			var seenRank = {};
			(MM.seen || []).forEach(function (slug, i) {
				seenRank[slug] = i;
			});
			pending.sort(function (a, b) {
				var ra = null != seenRank[a.slug] ? seenRank[a.slug] : 1e9;
				var rb = null != seenRank[b.slug] ? seenRank[b.slug] : 1e9;
				return ra - rb;
			});
			pending.forEach(function (m) {
				model.push(makeTop(m, null, subCfg, childTitle, claimed));
			});
			return model;
		}

		function makeTop(m, topCfg, subCfg, childTitle, claimed) {
			topCfg = topCfg || {};
			var children = [],
				seenC = {};
			// Configured children for this parent (may include children moved in from elsewhere).
			(subCfg[m.slug] || []).forEach(function (s) {
				if (!childTitle.hasOwnProperty(s.slug)) {
					return;
				} // child no longer exists → drop
				seenC[s.slug] = true;
				var ot = childTitle[s.slug];
				children.push({
					slug: s.slug,
					otitle: ot,
					title: s.title && s.title !== '' ? s.title : ot,
					hidden: !!s.hidden
				});
			});
			// This parent's own snapshot children not claimed elsewhere (new / unmoved).
			(m.children || []).forEach(function (c) {
				if (seenC[c.slug] || claimed[c.slug]) {
					return;
				}
				children.push({ slug: c.slug, otitle: c.title, title: c.title, hidden: false });
			});
			return {
				type: 'item',
				slug: m.slug,
				otitle: m.title,
				title: topCfg.title && topCfg.title !== '' ? topCfg.title : m.title,
				dashicon: m.dashicon || '',
				icon: topCfg.icon || '',
				url: topCfg.url || '',
				hidden: !!topCfg.hidden,
				open: false,
				children: children
			};
		}

		function makeCustom(t) {
			if (t.type === 'separator') {
				return { type: 'separator', slug: t.slug, hidden: !!t.hidden };
			}
			// Links store their destination URL as the slug.
			return {
				type: 'link',
				slug: t.slug,
				url: t.slug,
				title: t.title || '',
				icon: t.icon || '',
				hidden: !!t.hidden,
				newTab: !!t.new_tab
			};
		}

		function gatherMenu() {
			var items = [];
			state.menu.forEach(function (top, ti) {
				var type = top.type || 'item';
				if (type === 'link') {
					var url = (top.url || '').replace(/^\s+|\s+$/g, '');
					if (!url) {
						return;
					} // a link with no URL yet is dropped
					items.push({
						parent: '',
						slug: url,
						position: ti,
						icon: top.icon || '',
						hidden: !!top.hidden,
						type: 'link',
						title: top.title || '',
						newTab: !!top.newTab
					});
					return;
				}
				if (type === 'separator') {
					items.push({ parent: '', slug: top.slug, position: ti, hidden: !!top.hidden, type: 'separator', title: '' });
					return;
				}
				items.push({
					parent: '',
					slug: top.slug,
					position: ti,
					icon: top.icon || '',
					url: top.url || '',
					hidden: !!top.hidden,
					type: 'item',
					title: titleOverride(top)
				});
				(top.children || []).forEach(function (child, ci) {
					items.push({
						parent: top.slug,
						slug: child.slug,
						position: ci,
						hidden: !!child.hidden,
						title: titleOverride(child)
					});
				});
			});
			return items;
		}

		function titleOverride(it) {
			var t = it.title || '';
			return '' !== t && t !== (it.otitle || '') ? t : '';
		}

		function buildToolbar() {
			var TB = D.toolbar || {};
			var p = el('section', { class: 'ak-panel ak-panel--menu', role: 'tabpanel' }, [
				el('h3', { text: I.toolbarTab || 'Toolbar' }),
				el('p', { class: 'ak-foundation-intro', text: I.toolbarIntro })
			]);
			var listWrap = el('div', { class: 'ak-menu-tree ak-toolbar-columns' });
			var nodesRaw = []; // the pristine fetched nodes, kept so Reset can rebuild

			p.appendChild(
				el('div', { class: 'ak-actions ak-menu-toolbar' }, [
					el('button', {
						type: 'button',
						class: 'ak-btn ak-btn--ghost',
						text: I.toolbarReset || 'Reset',
						onclick: reset
					})
				])
			);
			p.appendChild(listWrap);

			function load() {
				if (!apiFetch || !TB.route) {
					listWrap.textContent = I.toolbarEmpty || '—';
					return;
				}
				apiFetch({ path: tbPath() })
					.then(function (res) {
						nodesRaw = (res && res.nodes) || [];
						state.toolbar = toSides(nodesRaw, (res && res.config) || {});
						render();
					})
					.catch(function () {
						listWrap.textContent = I.toolbarEmpty || '—';
					});
			}

			// Build { left, right } from the live nodes + a saved layout: honour the saved
			// order WITHIN each side, append any new/unseen node in native order, carry hidden,
			// title overrides, and icon overrides.
			function toSides(nodes, config) {
				var hidden = {};
				(config.hidden || []).forEach(function (id) {
					hidden[id] = true;
				});
				var titles = (config && config.titles) || {};
				var icons = (config && config.icons) || {};
				var byId = {};
				nodes.forEach(function (n) {
					var otitle = n.label || n.id;
					byId[n.id] = {
						id: n.id,
						label: otitle,
						otitle: otitle,
						title: titles[n.id] && titles[n.id] !== '' ? titles[n.id] : otitle,
						icon: icons[n.id] || '',
						defaultIcon: n.defaultIcon || '',
						dashicon: n.dashicon || '',
						group: n.group,
						hidden: !!hidden[n.id]
					};
				});
				var sides = { left: [], right: [] },
					seen = {};
				function place(it) {
					sides[it.group === 'secondary' ? 'right' : 'left'].push(it);
				}
				(config.order || []).forEach(function (id) {
					if (byId[id] && !seen[id]) {
						seen[id] = 1;
						place(byId[id]);
					}
				});
				nodes.forEach(function (n) {
					if (!seen[n.id]) {
						seen[n.id] = 1;
						place(byId[n.id]);
					}
				});
				return sides;
			}

			function reset() {
				state.toolbar = toSides(nodesRaw, {}); // native order, nothing hidden
				render();
				markDirty();
			}

			// Swap a row with its neighbour (↑↓), within its own side only.
			function move(side, i, dir) {
				var list = state.toolbar[side],
					to = i + dir;
				if (to < 0 || to >= list.length) {
					return;
				}
				var movedKey = 'tb:' + side + ':' + list[i].id;
				var tmp = list[i];
				list[i] = list[to];
				list[to] = tmp;
				render(movedKey);
				markDirty();
			}
			// Splice a dragged row out and back in at a new index, same side.
			function moveTo(side, from, to) {
				var list = state.toolbar[side];
				if (from === to || from < 0 || from >= list.length) {
					return;
				}
				list.splice(to, 0, list.splice(from, 1)[0]);
			}

			function render(movedKey) {
				var prevFlip = movedKey ? snapshotFlip(listWrap) : null;
				listWrap.textContent = '';
				if (!(state.toolbar.left.length + state.toolbar.right.length)) {
					listWrap.appendChild(
						el('p', { text: I.toolbarEmpty || 'No toolbar items found.' })
					);
					return;
				}
				section('left', I.toolbarLeft || 'Left');
				section('right', I.toolbarRight || 'Right');
				if (prevFlip) {
					playFlip(listWrap, prevFlip, movedKey);
				}
			}

			// One side: a column with a small heading + its draggable rows. Drag is scoped
			// to the side via the drop predicate (ds.side === side), so a row can't cross the boundary.
			function section(side, heading) {
				var list = state.toolbar[side];
				if (!list.length) {
					return;
				}
				var col = el('div', { class: 'ak-toolbar-col' });
				col.appendChild(el('div', { class: 'ak-toolbar__side' }, [el('span', { text: heading })]));
				list.forEach(function (item, i) {
					var row = el('div', { class: 'ak-menu-row ak-menu-row--top' + (item.hidden ? ' is-hidden' : '') });
					var h = handle();
					row.appendChild(h);
					row.appendChild(moveCtl(side, i));
					row.appendChild(iconBtn(item, listWrap));
					row.appendChild(titleInput(item));
					row.appendChild(hideCtl(item));
					dragSource(h, row, { kind: 'tb', side: side, i: i });
					dropZone(
						row,
						function (ds) {
							return ds.kind === 'tb' && ds.side === side;
						},
						function (ds) {
							var movedItem = state.toolbar[side] && state.toolbar[side][ds.i];
							var movedKey = movedItem ? 'tb:' + side + ':' + movedItem.id : null;
							moveTo(side, ds.i, i);
							render(movedKey);
							markDirty();
						}
					);
					col.appendChild(
						el('div', { class: 'ak-menu-group', 'data-flip-id': 'tb:' + side + ':' + item.id }, [row])
					);
				});
				listWrap.appendChild(col);
			}

			function moveCtl(side, i) {
				var up = el('button', {
					type: 'button',
					class: 'ak-menu-move__btn',
					title: I.menuMoveUp || 'Move up',
					'aria-label': I.menuMoveUp || 'Move up',
					onclick: function () {
						move(side, i, -1);
					}
				});
				up.innerHTML =
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
				var down = el('button', {
					type: 'button',
					class: 'ak-menu-move__btn',
					title: I.menuMoveDown || 'Move down',
					'aria-label': I.menuMoveDown || 'Move down',
					onclick: function () {
						move(side, i, 1);
					}
				});
				down.innerHTML =
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
				return el('span', { class: 'ak-menu-move' }, [up, down]);
			}

			function hideCtl(item) {
				var btn = el('button', { type: 'button', class: 'ak-menu-hide' });
				paint(btn, item);
				btn.addEventListener('click', function () {
					item.hidden = !item.hidden;
					paint(btn, item);
					var row = btn.closest && btn.closest('.ak-menu-row');
					if (row) {
						row.classList.toggle('is-hidden', !!item.hidden);
					}
					markDirty();
				});
				return btn;
			}
			function paint(btn, item) {
				var eye =
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S5.5 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
				var eyeOff =
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 6.1A9.6 9.6 0 0 1 12 6c6.5 0 9.5 6 9.5 6a16.2 16.2 0 0 1-2.3 3.1M6.6 6.6A16 16 0 0 0 2.5 12s3 6 9.5 6a9.3 9.3 0 0 0 4-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
				var lbl = item.hidden ? I.menuShow || 'Show' : I.menuHide || 'Hide';
				btn.innerHTML = item.hidden ? eyeOff : eye;
				btn.setAttribute('aria-pressed', item.hidden ? 'true' : 'false');
				btn.setAttribute('title', lbl);
				btn.setAttribute('aria-label', lbl);
			}

			load();
			return p;
		}

		function tbPath() {
			var r = (D.toolbar && D.toolbar.route) || '';
			return r.charAt(0) === '/' ? r : '/' + r;
		}

		function gatherToolbar() {
			var t = state.toolbar || { left: [], right: [] };
			var all = (t.left || []).concat(t.right || []);
			var titles = {},
				icons = {};
			all.forEach(function (m) {
				var ttl = titleOverride(m);
				if (ttl) {
					titles[m.id] = ttl;
				}
				if (m.icon) {
					icons[m.id] = m.icon;
				}
			});
			return {
				order: all.map(function (m) {
					return m.id;
				}),
				hidden: all
					.filter(function (m) {
						return m.hidden;
					})
					.map(function (m) {
						return m.id;
					}),
				titles: titles,
				icons: icons
			};
		}

		function buildMenu() {
			var p = el('section', { class: 'ak-panel ak-panel--menu', role: 'tabpanel' });
			p.appendChild(el('h3', { text: I.menu || 'Menu' }));
			p.appendChild(el('p', { class: 'ak-foundation-intro', text: I.menuIntro }));
			if (!MM.menu || !MM.menu.length) {
				return p;
			}
			var tree = el('div', { class: 'ak-menu-tree' });

			// Import loads a layout into the editor and marks it dirty — the user reviews
			// it, then Saves (consistent with every other menu edit; no silent write).
			var importInput = el('input', {
				type: 'file',
				accept: 'application/json,.json',
				hidden: 'hidden',
				onchange: function (e) {
					if (e.target.files && e.target.files[0]) {
						importMenu(e.target.files[0], tree);
					}
					e.target.value = '';
				}
			});

			p.appendChild(
				el('div', { class: 'ak-actions ak-menu-toolbar' }, [
					el('button', {
						type: 'button',
						class: 'ak-btn',
						text: I.menuAddLink,
						onclick: function () {
							addCustom('link', tree);
						}
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn',
						text: I.menuAddSeparator,
						onclick: function () {
							addCustom('separator', tree);
						}
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn ak-btn--ghost',
						text: I.menuReset,
						onclick: function () {
							state.menu = buildMenuModel(MM.menu, { top: [], sub: {} });
							renderTree(tree);
							markDirty();
						}
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn ak-menu-toolbar__spacer',
						text: I.menuExport || 'Export',
						title: I.menuExportHint || '',
						onclick: exportMenu
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn',
						text: I.menuImport || 'Import',
						title: I.menuImportHint || '',
						onclick: function () {
							importInput.click();
						}
					}),
					importInput
				])
			);
			renderTree(tree);
			p.appendChild(tree);
			return p;
		}

		function exportMenu() {
			var payload = {
				_adminkit: 'menu',
				version: D.version || '1',
				exported: new Date().toISOString(),
				items: gatherMenu()
			};
			var blob = new Blob([JSON.stringify(payload, null, '\t')], { type: 'application/json' });
			var url = URL.createObjectURL(blob);
			var a = el('a', { href: url, download: 'adminkit-menu.json' });
			document.body.appendChild(a);
			a.click();
			a.remove();
			URL.revokeObjectURL(url);
		}

		function configFromItems(items) {
			var top = [],
				sub = {};
			(items || []).forEach(function (it) {
				if (it && it.parent) {
					(sub[it.parent] = sub[it.parent] || []).push({
						slug: it.slug,
						position: it.position,
						hidden: !!it.hidden,
						title: it.title || ''
					});
				} else if (it) {
					top.push({
						slug: it.slug,
						position: it.position,
						icon: it.icon || '',
						url: it.url || '',
						new_tab: !!it.newTab,
						hidden: !!it.hidden,
						type: it.type || 'item',
						title: it.title || ''
					});
				}
			});
			return { top: top, sub: sub };
		}

		function importMenu(file, tree) {
			var reader = new FileReader();
			reader.onload = function () {
				var data, items;
				try {
					data = JSON.parse(reader.result);
				} catch (e) {
					data = null;
				}
				items =
					data && typeof data === 'object' && Array.isArray(data.items)
						? data.items
						: Array.isArray(data)
							? data
							: null;
				if (!items) {
					setStatus('is-error', I.menuImportError || 'That file isn’t a valid AdminKit menu export.');
					return;
				}
				state.menu = buildMenuModel(MM.menu, configFromItems(items));
				renderTree(tree);
				markDirty(); // shows "Unsaved changes" — the user reviews, then Saves.
			};
			reader.readAsText(file);
		}

		function addCustom(type, tree) {
			var item =
				type === 'separator'
					? { type: 'separator', slug: 'ak-sep-' + Date.now() + '-' + Math.floor(Math.random() * 1e6), hidden: false }
					: { type: 'link', slug: '', url: '', title: '', icon: '', hidden: false };
			state.menu.push(item);
			renderTree(tree);
			markDirty();
		}

		function renderTree(tree, movedKey) {
			var prevFlip = movedKey ? snapshotFlip(tree) : null;
			tree.textContent = '';
			state.menu.forEach(function (top, ti) {
				var t = top.type || 'item';
				if ('separator' === t) {
					tree.appendChild(separatorRow(top, ti, tree));
				} else if ('link' === t) {
					tree.appendChild(linkRow(top, ti, tree));
				} else {
					tree.appendChild(topGroup(top, ti, tree));
				}
			});
			if (prevFlip) {
				playFlip(tree, prevFlip, movedKey);
			}
		}

		function separatorRow(item, ti, tree) {
			var row = el('div', { class: 'ak-menu-row ak-menu-row--sep' + (item.hidden ? ' is-hidden' : '') });
			var h = handle();
			row.appendChild(h);
			row.appendChild(moveControl(moveTopBy(ti, tree)));
			row.appendChild(
				el('span', { class: 'ak-menu-row__title ak-menu-seplabel', text: '— ' + I.menuSeparator + ' —' })
			);
			row.appendChild(hideBtn(item, tree));
			row.appendChild(removeBtn(ti, tree));
			dragSource(h, row, { kind: 'top', ti: ti });
			dropZone(
				row,
				function (ds) {
					return ds.kind === 'top';
				},
				function (ds) {
					var movedTop = state.menu[ds.ti];
					var movedKey = movedTop ? topFlipKey(movedTop) : null;
					moveTop(ds.ti, ti);
					renderTree(tree, movedKey);
					markDirty();
				}
			);
			return el('div', { class: 'ak-menu-group', 'data-flip-id': topFlipKey(item) }, [row]);
		}

		function linkRow(item, ti, tree) {
			var row = el('div', { class: 'ak-menu-row ak-menu-row--link' + (item.hidden ? ' is-hidden' : '') });
			var h = handle();
			row.appendChild(h);
			row.appendChild(moveControl(moveTopBy(ti, tree)));
			row.appendChild(iconBtn(item, tree));
			var titleIn = el('input', {
				type: 'text',
				class: 'ak-menu-input',
				value: item.title || '',
				placeholder: I.menuLinkTitle
			});
			titleIn.addEventListener('input', function () {
				item.title = titleIn.value;
				markDirty();
			});
			var urlIn = el('input', {
				type: 'text',
				class: 'ak-menu-input ak-menu-input--url',
				value: item.url || '',
				placeholder: I.menuLinkUrl
			});
			urlIn.addEventListener('input', function () {
				item.url = urlIn.value;
				markDirty();
			});
			// Order: handle · move · icon · [title + URL fields] · new-tab · hide · remove.
			// The title + URL inputs are the editable content; new-tab, hide (eye) and the
			// remove (×) sit at the far right.
			row.appendChild(titleIn);
			row.appendChild(urlIn);
			row.appendChild(newTabBtn(item, tree));
			row.appendChild(hideBtn(item, tree));
			row.appendChild(removeBtn(ti, tree));
			// Drag from the handle only, so the URL/title inputs stay text-selectable.
			dragSource(h, row, { kind: 'top', ti: ti });
			dropZone(
				row,
				function (ds) {
					return ds.kind === 'top';
				},
				function (ds) {
					var movedTop = state.menu[ds.ti];
					var movedKey = movedTop ? topFlipKey(movedTop) : null;
					moveTop(ds.ti, ti);
					renderTree(tree, movedKey);
					markDirty();
				}
			);
			return el('div', { class: 'ak-menu-group', 'data-flip-id': topFlipKey(item) }, [row]);
		}

		function removeBtn(ti, tree) {
			return el('button', {
				type: 'button',
				class: 'ak-menu-remove',
				title: I.menuRemove,
				'aria-label': I.menuRemove,
				text: '×',
				onclick: function () {
					state.menu.splice(ti, 1);
					renderTree(tree);
					markDirty();
				}
			});
		}

		function topGroup(top, ti, tree) {
			var row = el('div', { class: 'ak-menu-row ak-menu-row--top' + (top.hidden ? ' is-hidden' : '') });
			var h = handle();
			row.appendChild(h);
			row.appendChild(moveControl(moveTopBy(ti, tree)));
			// Order: handle · move(↑↓) · icon · [title fills] · link(URL) · submenus · hide(eye).
			row.appendChild(iconBtn(top, tree));
			row.appendChild(titleInput(top));
			row.appendChild(urlBtn(top, tree));
			if (top.children.length) {
				var caret = el('span', { class: 'ak-menu-row__caret', 'aria-hidden': 'true' });
				caret.innerHTML =
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';
				row.appendChild(
					el(
						'button',
						{
							type: 'button',
							class: 'ak-menu-row__expand' + (top.open ? ' on' : ''),
							'aria-expanded': top.open ? 'true' : 'false',
							title: I.menuSubmenusToggle,
							onclick: function () {
								top.open = !top.open;
								renderTree(tree);
							}
						},
						[caret, el('span', { text: top.children.length + ' ' + I.menuSubmenus })]
					)
				);
			}
			// Hide (eye) sits at the row's far right (never AdminKit's own menu).
			if (top.slug !== MM.self) {
				row.appendChild(hideBtn(top, tree));
			}
			dragSource(h, row, { kind: 'top', ti: ti });
			// Reorder tops, and accept a submenu dragged from any section (nest it here).
			dropZone(
				row,
				function () {
					return true;
				},
				function (ds) {
					var movedKey = null;
					if (ds.kind === 'top') {
						var movedTop = state.menu[ds.ti];
						movedKey = movedTop ? topFlipKey(movedTop) : null;
						moveTop(ds.ti, ti);
					} else {
						var movedSub = state.menu[ds.ti] && state.menu[ds.ti].children && state.menu[ds.ti].children[ds.ci];
						moveChildToTop(ds.ti, ds.ci, ti);
						if (movedSub) {
							movedKey = subFlipKey(top, movedSub);
						}
					}
					renderTree(tree, movedKey);
					markDirty();
				}
			);

			var group = el('div', { class: 'ak-menu-group', 'data-flip-id': topFlipKey(top) }, [row]);
			if (top.children.length && top.open) {
				var kids = el('div', { class: 'ak-menu-children' });
				top.children.forEach(function (child, ci) {
					kids.appendChild(childRow(ti, child, ci, tree));
				});
				// Dropping into the open children area (not onto a row) appends here.
				dropZone(
					kids,
					function (ds) {
						return ds.kind === 'sub';
					},
					function (ds) {
						var movedSub = state.menu[ds.ti] && state.menu[ds.ti].children && state.menu[ds.ti].children[ds.ci];
						moveChildToTop(ds.ti, ds.ci, ti);
						var movedKey = movedSub ? subFlipKey(top, movedSub) : null;
						renderTree(tree, movedKey);
						markDirty();
					}
				);
				group.appendChild(kids);
			}
			return group;
		}

		function childRow(ti, child, ci, tree) {
			var top = state.menu[ti];
			var row = el('div', {
				class: 'ak-menu-row ak-menu-row--child' + (child.hidden ? ' is-hidden' : ''),
				'data-flip-id': subFlipKey(top, child)
			});
			var h = handle();
			row.appendChild(h);
			row.appendChild(moveControl(moveChildBy(ti, ci, tree)));
			row.appendChild(titleInput(child));
			row.appendChild(hideBtn(child, tree));
			dragSource(h, row, { kind: 'sub', ti: ti, ci: ci });
			dropZone(
				row,
				function (ds) {
					return ds.kind === 'sub';
				},
				function (ds) {
					var movedSub = state.menu[ds.ti] && state.menu[ds.ti].children && state.menu[ds.ti].children[ds.ci];
					moveChild(ds.ti, ds.ci, ti, ci);
					var movedKey = movedSub ? subFlipKey(top, movedSub) : null;
					renderTree(tree, movedKey);
					markDirty();
				}
			);
			return row;
		}

		function handle() {
			var h = el('span', { class: 'ak-menu-handle', title: I.menuDragHint, 'aria-hidden': 'true' });
			h.innerHTML =
				'<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.6"/><circle cx="15" cy="5" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="19" r="1.6"/><circle cx="15" cy="19" r="1.6"/></svg>';
			return h;
		}

		function moveControl(move) {
			var up = el('button', {
				type: 'button',
				class: 'ak-menu-move__btn',
				title: I.menuMoveUp || 'Move up',
				'aria-label': I.menuMoveUp || 'Move up',
				onclick: function () {
					move(-1);
				}
			});
			up.innerHTML =
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
			var down = el('button', {
				type: 'button',
				class: 'ak-menu-move__btn',
				title: I.menuMoveDown || 'Move down',
				'aria-label': I.menuMoveDown || 'Move down',
				onclick: function () {
					move(1);
				}
			});
			down.innerHTML =
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
			return el('span', { class: 'ak-menu-move' }, [up, down]);
		}

		function moveTopBy(ti, tree) {
			return function (dir) {
				var to = ti + dir,
					m = state.menu;
				if (to < 0 || to >= m.length) {
					return;
				}
				var movedKey = topFlipKey(m[ti]);
				var tmp = m[ti];
				m[ti] = m[to];
				m[to] = tmp;
				renderTree(tree, movedKey);
				markDirty();
			};
		}

		function moveChildBy(ti, ci, tree) {
			return function (dir) {
				var top = state.menu[ti],
					kids = top.children,
					to = ci + dir;
				if (to < 0 || to >= kids.length) {
					return;
				}
				var movedKey = subFlipKey(top, kids[ci]);
				var tmp = kids[ci];
				kids[ci] = kids[to];
				kids[to] = tmp;
				renderTree(tree, movedKey);
				markDirty();
			};
		}

		function hideBtn(item) {
			var btn = el('button', { type: 'button', class: 'ak-menu-hide' });
			paintHide(btn, item);
			btn.addEventListener('click', function () {
				item.hidden = !item.hidden;
				paintHide(btn, item);
				var row = btn.closest && btn.closest('.ak-menu-row');
				if (row) {
					row.classList.toggle('is-hidden', !!item.hidden);
				}
				markDirty();
			});
			return btn;
		}

		function paintHide(btn, item) {
			var eye =
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S5.5 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
			var eyeOff =
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 6.1A9.6 9.6 0 0 1 12 6c6.5 0 9.5 6 9.5 6a16.2 16.2 0 0 1-2.3 3.1M6.6 6.6A16 16 0 0 0 2.5 12s3 6 9.5 6a9.3 9.3 0 0 0 4-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
			var lbl = item.hidden ? I.menuShow || 'Show' : I.menuHide || 'Hide';
			btn.innerHTML = item.hidden ? eyeOff : eye;
			btn.setAttribute('aria-pressed', item.hidden ? 'true' : 'false');
			btn.setAttribute('title', lbl);
			btn.setAttribute('aria-label', lbl);
		}

		function iconBtn(top, tree) {
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
				openIconPicker(btn, top, tree);
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
				g.innerHTML = MM.icons[top.icon];
			} else if (top.defaultIcon && MM.icons[top.defaultIcon]) {
				g = el('span', { class: 'ak-menu-icon__g' });
				g.innerHTML = MM.icons[top.defaultIcon];
			} else if (top.dashicon) {
				g = el('span', { class: 'dashicons ' + top.dashicon });
			} else {
				g = el('span', { class: 'ak-menu-icon__g' });
				g.innerHTML = MM.icons.app || '';
			}
			btn.appendChild(g);
		}

		function openIconPicker(anchor, top, tree) {
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
				markDirty();
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
				text: I.menuIconWpSet || 'WordPress icons'
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
					sw.innerHTML = MM.icons[name];
					choices.push({ el: sw, val: name });
					grid.appendChild(sw);
				});
				shown = Math.min(shown + 120, filtered.length);
				paint();
			}
			search.addEventListener('input', function () {
				var words = { calendrier: 'calendar', fleche: 'arrow', gauche: 'left', droite: 'right', haut: 'up', bas: 'down', reglage: 'settings', reglages: 'settings', parametres: 'settings', image: 'image', photo: 'image', dossier: 'folder', fichier: 'file', message: 'message', utilisateur: 'user', recherche: 'search', maison: 'house', panier: 'cart', courriel: 'mail', horloge: 'clock', alerte: 'alert' };
				var q = search.value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').split(/\s+/).map(function (word) { return words[word] || word; }).join('-');
				filtered = names.filter(function (name) { return name.indexOf(q) !== -1; });
				choices = choices.filter(function (c) { return !grid.contains(c.el); });
				grid.textContent = '';
				empty.textContent = filtered.length ? '' : I.menuIconEmpty;
				shown = 0;
				appendIcons();
			});
			pop.addEventListener('scroll', function () {
				if (akPane.style.display !== 'none' && pop.scrollTop + pop.clientHeight >= pop.scrollHeight - 120 && shown < filtered.length) appendIcons();
			});
			appendIcons();
			akPane.appendChild(grid);
			pop.appendChild(akPane);

			// --- WordPress pane: search (at the top) + a "restore the item's original
			// WordPress icon" reset + the native-dashicon grid. The dashicons font is already
			// loaded in wp-admin, so each swatch renders with no extra assets; PHP only offers
			// the dashicons AdminKit doesn't remap, so picking one paints the NATIVE dashicon.
			var wpSearch = el('input', {
				type: 'search',
				class: 'ak-icon-pop__search',
				placeholder: I.menuIconSearch || 'Search…',
				'aria-label': I.menuIconWpSet || 'WordPress icons'
			});
			wpPane.appendChild(wpSearch);
			var wpBtn = el('button', {
				type: 'button',
				class: 'ak-icon-pop__def',
				text: I.menuIconWp || 'WordPress icon',
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
					document.createTextNode((I.menuIconHint || 'Need an icon? Copy an SVG from') + ' '),
					el('a', { href: 'https://lucide.dev/icons/', target: '_blank', rel: 'noopener', text: 'Lucide' }),
					document.createTextNode('.')
				])
			);

			anchor.parentNode.appendChild(pop);
			positionPopover(pop, anchor);
			pop.__akAnchor = anchor;
			openPicker = pop;
			search.focus();
			paint(); // seed the highlight (no dirty flag).
			setTimeout(function () {
				document.addEventListener('mousedown', onDocDown, true);
			}, 0);
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

		function newTabBtn(item, tree) {
			var btn = el('button', {
				type: 'button',
				class: 'ak-menu-newtab' + (item.newTab ? ' on' : ''),
				'aria-pressed': item.newTab ? 'true' : 'false',
				title: I.menuNewTab,
				'aria-label': I.menuNewTab,
				onclick: function () {
					item.newTab = !item.newTab;
					renderTree(tree);
					markDirty();
				}
			});
			btn.innerHTML =
				'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M10 14L20 4"/><path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>';
			return btn;
		}

		function urlBtn(top, tree) {
			var on = !!(top.url && top.url !== '');
			var btn = el('button', {
				type: 'button',
				class: 'ak-menu-url' + (on ? ' on' : ''),
				title: I.menuUrlTitle,
				'aria-label': I.menuUrlTitle
			});
			btn.innerHTML =
				'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				openUrlPicker(btn, top, tree);
			});
			return btn;
		}

		function openUrlPicker(anchor, top, tree) {
			closeIconPicker();
			var pop = el('div', { class: 'ak-icon-pop ak-url-pop' });
			pop.appendChild(el('div', { class: 'ak-icon-pop__customlbl', text: I.menuUrlLabel }));

			// Show where this item points right now — the custom override if set, else
			// its native WordPress destination (the slug). Read-only, so you SEE the link
			// before changing it.
			var current = top.url || top.slug || '';
			if (current) {
				pop.appendChild(
					el('div', { class: 'ak-url-pop__current' }, [
						el('span', { class: 'ak-url-pop__current-lbl', text: (I.menuUrlCurrent || 'Current') + ' : ' }),
						el('code', { class: 'ak-url-pop__current-val', title: current, text: current })
					])
				);
			}

			// Pre-fill with the custom override; the placeholder is the native slug so an
			// empty field still hints the current destination.
			var inp = el('input', {
				type: 'text',
				class: 'ak-menu-input',
				value: top.url || '',
				placeholder: top.slug || I.menuLinkUrl
			});
			// Apply reflects the URL state on the anchor button in place (its `on` class)
			// and closes — no full tree rebuild (which would destroy the anchor the popover
			// hangs off, same trap the icon picker had). To clear: empty the field + Apply
			// (an empty value resets the item to its native destination).
			var apply = el('button', {
				type: 'button',
				class: 'ak-btn ak-btn--small',
				text: I.menuIconApply,
				onclick: function () {
					top.url = (inp.value || '').replace(/^\s+|\s+$/g, '');
					anchor.classList.toggle('on', !!top.url);
					markDirty();
					closeIconPicker();
				}
			});
			pop.appendChild(el('div', { class: 'ak-icon-pop__custom ak-url-pop__row' }, [inp, apply]));
			anchor.parentNode.appendChild(pop);
			positionPopover(pop, anchor);
			pop.__akAnchor = anchor;
			openPicker = pop;
			setTimeout(function () {
				document.addEventListener('mousedown', onDocDown, true);
			}, 0);
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

		function moveTop(from, to) {
			var m = state.menu.splice(from, 1)[0];
			state.menu.splice(to, 0, m);
		}

		function moveChild(fromTi, fromCi, toTi, toCi) {
			var c = state.menu[fromTi].children.splice(fromCi, 1)[0];
			if (fromTi === toTi && fromCi < toCi) {
				toCi--;
			}
			if (!state.menu[toTi].children) {
				state.menu[toTi].children = [];
			}
			state.menu[toTi].children.splice(toCi, 0, c);
			state.menu[toTi].open = true;
		}

		function moveChildToTop(fromTi, fromCi, toTi) {
			var c = state.menu[fromTi].children.splice(fromCi, 1)[0];
			if (!state.menu[toTi].children) {
				state.menu[toTi].children = [];
			}
			state.menu[toTi].children.push(c);
			state.menu[toTi].open = true;
		}

		function titleInput(item) {
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
				markDirty();
			});
			return inp;
		}
		var activeSection = 'menu';
		var selectNavSection = null;

		function buildNavigation() {
			var p = el('section', { class: 'ak-panel ak-panel--navigation ak-foundations', role: 'tabpanel' });
			var sections = [];
			var sectionKey = 'adminkit:navigation-section:' + location.pathname + location.search;
			var remembered = 'menu';
			try {
				remembered = sessionStorage.getItem(sectionKey) || remembered;
			} catch (error) {}
			if (location.hash === '#toolbar') {
				remembered = 'toolbar';
			} else if (location.hash === '#menu') {
				remembered = 'menu';
			}

			var editor = el('div', { class: 'ak-foundations-editor' });
			var nav = el('div', {
				class: 'ak-foundations-nav',
				role: 'tablist',
				'aria-label': I.navigation || 'Navigation',
				'aria-orientation': 'horizontal'
			});
			var panes = el('div', { class: 'ak-foundations-panes' });

			function addSection(id, title, pane) {
				var tab = el('button', {
					type: 'button',
					role: 'tab',
					id: 'ak-navigation-tab-' + id,
					'aria-controls': 'ak-navigation-' + id,
					'aria-selected': 'false',
					tabindex: '-1',
					text: title
				});
				pane.classList.add('ak-foundations-panel');
				pane.id = 'ak-navigation-' + id;
				pane.setAttribute('aria-labelledby', tab.id);
				pane.hidden = true;
				tab.addEventListener('click', function () {
					selectSection(id);
				});
				sections.push({ id: id, title: title, tab: tab, pane: pane });
				nav.appendChild(tab);
				panes.appendChild(pane);
			}

			function selectSection(id) {
				if (
					!sections.some(function (item) {
						return item.id === id;
					})
				) {
					id = 'menu';
				}
				activeSection = id;
				try {
					sessionStorage.setItem(sectionKey, id);
				} catch (error) {}
				sections.forEach(function (item) {
					var active = item.id === id;
					item.tab.setAttribute('aria-selected', String(active));
					item.tab.tabIndex = active ? 0 : -1;
					item.pane.hidden = !active;
				});
			}

			selectNavSection = selectSection;

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

			addSection('menu', I.menu || 'Menu', buildMenu());
			addSection('toolbar', I.toolbarTab || 'Toolbar', buildToolbar());

			p.appendChild(el('h2', { class: 'ak-foundations-title', text: I.navigation || 'Navigation' }));
			p.appendChild(intro(I.navigationIntro || I.menuIntro));
			editor.appendChild(nav);
			editor.appendChild(panes);
			p.appendChild(editor);
			selectSection(remembered);
			return p;
		}

		return {
			buildMenu: buildMenu,
			buildToolbar: buildToolbar,
			buildNavigation: buildNavigation,
			selectSection: function (id) {
				activeSection = id;
				if (selectNavSection) {
					selectNavSection(id);
				}
			},
			activeRequest: function () {
				return activeSection === 'toolbar'
					? { path: tbPath(), data: gatherToolbar() }
					: { path: MM.route, data: { items: gatherMenu() } };
			},
			menuRequest: function () {
				return { path: MM.route, data: { items: gatherMenu() } };
			},
			toolbarRequest: function () {
				return { path: tbPath(), data: gatherToolbar() };
			},
			tabs:
				D.screen === 'toolbar'
					? [{ id: 'toolbar', label: I.toolbarTab, build: buildToolbar }]
					: [{ id: 'menu', label: I.menu, build: buildMenu }],
			request: function () {
				return D.screen === 'toolbar'
					? { path: tbPath(), data: gatherToolbar() }
					: { path: MM.route, data: { items: gatherMenu() } };
			}
		};
	};
})();
