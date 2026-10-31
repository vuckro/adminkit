/** Sidebar menu editor: native snapshot, draft model, controls and import/export. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.navigationMenu = function (context, controls) {
		var D = context.D;
		var I = context.I;
		var state = context.state;
		var el = context.el;
		var markDirty = context.markDirty;
		var setStatus = context.setStatus;
		var MM = D.menuManager || { menu: [], config: { top: [], sub: {} }, icons: {} };
		var uidSeq = 0;
		var snapshotFlip = controls.snapshotFlip;
		var playFlip = controls.playFlip;
		var titleOverride = controls.titleOverride;
		var handle = controls.handle;
		var iconBtn = controls.iconBtn;
		var closeIconPicker = controls.closeIconPicker;
		var dragSource = controls.dragSource;
		var dropZone = controls.dropZone;
		var titleInput = controls.titleInput;

		state.menu = buildMenuModel(MM.menu || [], MM.config || { top: [], sub: {} });

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

		function buildMenu() {
			var p = el('section', { class: 'ak-panel ak-panel--menu', role: 'tabpanel' });
			p.appendChild(el('h3', { text: I.menu }));
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
							markDirty('menu');
						}
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn ak-menu-toolbar__spacer',
						text: I.menuExport,
						title: I.menuExportHint || '',
						onclick: exportMenu
					}),
					el('button', {
						type: 'button',
						class: 'ak-btn',
						text: I.menuImport,
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
					setStatus('is-error', I.menuImportError);
					return;
				}
				state.menu = buildMenuModel(MM.menu, configFromItems(items));
				renderTree(tree);
				markDirty('menu'); // shows "Unsaved changes" — the user reviews, then Saves.
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
			markDirty('menu');
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
					markDirty('menu');
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
				markDirty('menu');
			});
			var urlIn = el('input', {
				type: 'text',
				class: 'ak-menu-input ak-menu-input--url',
				value: item.url || '',
				placeholder: I.menuLinkUrl
			});
			urlIn.addEventListener('input', function () {
				item.url = urlIn.value;
				markDirty('menu');
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
					markDirty('menu');
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
					markDirty('menu');
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
					markDirty('menu');
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
						markDirty('menu');
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
					markDirty('menu');
				}
			);
			return row;
		}

		function moveControl(move) {
			var up = el('button', {
				type: 'button',
				class: 'ak-menu-move__btn',
				title: I.menuMoveUp,
				'aria-label': I.menuMoveUp,
				onclick: function () {
					move(-1);
				}
			});
			up.innerHTML =
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
			var down = el('button', {
				type: 'button',
				class: 'ak-menu-move__btn',
				title: I.menuMoveDown,
				'aria-label': I.menuMoveDown,
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
				markDirty('menu');
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
				markDirty('menu');
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
				markDirty('menu');
			});
			return btn;
		}

		function paintHide(btn, item) {
			var eye =
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S5.5 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
			var eyeOff =
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 6.1A9.6 9.6 0 0 1 12 6c6.5 0 9.5 6 9.5 6a16.2 16.2 0 0 1-2.3 3.1M6.6 6.6A16 16 0 0 0 2.5 12s3 6 9.5 6a9.3 9.3 0 0 0 4-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
			var lbl = item.hidden ? I.menuShow : I.menuHide;
			btn.innerHTML = item.hidden ? eyeOff : eye;
			btn.setAttribute('aria-pressed', item.hidden ? 'true' : 'false');
			btn.setAttribute('title', lbl);
			btn.setAttribute('aria-label', lbl);
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
					markDirty('menu');
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
						el('span', { class: 'ak-url-pop__current-lbl', text: (I.menuUrlCurrent) + ' : ' }),
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
					markDirty('menu');
					closeIconPicker();
				}
			});
			pop.appendChild(el('div', { class: 'ak-icon-pop__custom ak-url-pop__row' }, [inp, apply]));
			controls.mountPopover(pop, anchor);
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

		return {
			build: buildMenu,
			request: function () { return { path: MM.route, data: { items: gatherMenu() } }; }
		};
	};
})();
