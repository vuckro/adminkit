/** Sidebar menu editor: tree UI, drag/drop, FLIP animation and URL/icon controls. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.navigationMenu = function (context, controls) {
		var M = window.AdminKitParts.navigationMenuModel;
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

		state.menu = M.buildMenuModel(MM.menu || [], MM.config || { top: [], sub: {} }, MM.seen || []);

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

		function gatherMenu() {
			return M.gatherMenu(state.menu, titleOverride);
		}

		function buildMenu() {
			var p = el('section', { class: 'ak-panel ak-panel--menu', role: 'tabpanel' });
			p.appendChild(el('h3', { text: I.menu }));
			p.appendChild(el('p', { class: 'ak-foundation-intro', text: I.menuIntro }));
			if (!MM.menu || !MM.menu.length) {
				return p;
			}
			var tree = el('div', { class: 'ak-menu-tree' });

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
							state.menu = M.buildMenuModel(MM.menu, { top: [], sub: {} }, MM.seen || []);
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
					data && typeof data === 'object' && data._adminkit === 'menu' && Array.isArray(data.items)
						? data.items
						: null;
				if (!items) {
					setStatus('is-error', I.menuImportError);
					return;
				}
				state.menu = M.buildMenuModel(MM.menu, M.configFromItems(M.sanitizeImportedItems(items)), MM.seen || []);
				renderTree(tree);
				markDirty('menu');
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
					M.moveTop(state.menu, ds.ti, ti);
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
			row.appendChild(titleIn);
			row.appendChild(urlIn);
			row.appendChild(newTabBtn(item, tree));
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
					M.moveTop(state.menu, ds.ti, ti);
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
			if (top.slug !== MM.self) {
				row.appendChild(hideBtn(top, tree));
			}
			dragSource(h, row, { kind: 'top', ti: ti });
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
						M.moveTop(state.menu, ds.ti, ti);
					} else {
						var movedSub = state.menu[ds.ti] && state.menu[ds.ti].children && state.menu[ds.ti].children[ds.ci];
						M.moveChildToTop(state.menu, ds.ti, ds.ci, ti);
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
				dropZone(
					kids,
					function (ds) {
						return ds.kind === 'sub';
					},
					function (ds) {
						var movedSub = state.menu[ds.ti] && state.menu[ds.ti].children && state.menu[ds.ti].children[ds.ci];
						M.moveChildToTop(state.menu, ds.ti, ds.ci, ti);
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
					M.moveChild(state.menu, ds.ti, ds.ci, ti, ci);
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
			// eslint-disable-next-line no-unsanitized/property -- Static inline eye/eyeOff SVG constants.
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
				openUrlPicker(btn, top);
			});
			return btn;
		}

		function openUrlPicker(anchor, top) {
			closeIconPicker();
			var pop = el('div', { class: 'ak-icon-pop ak-url-pop' });
			pop.appendChild(el('div', { class: 'ak-icon-pop__customlbl', text: I.menuUrlLabel }));

			var current = top.url || top.slug || '';
			if (current) {
				pop.appendChild(
					el('div', { class: 'ak-url-pop__current' }, [
						el('span', { class: 'ak-url-pop__current-lbl', text: I.menuUrlCurrent + ' : ' }),
						el('code', { class: 'ak-url-pop__current-val', title: current, text: current })
					])
				);
			}

			var inp = el('input', {
				type: 'text',
				class: 'ak-menu-input',
				value: top.url || '',
				placeholder: top.slug || I.menuLinkUrl
			});
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

		return {
			build: buildMenu,
			request: function () { return { path: MM.route, data: { items: gatherMenu() } }; }
		};
	};
})();
