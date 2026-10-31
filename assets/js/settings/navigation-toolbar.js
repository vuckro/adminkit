/** Toolbar editor: load, edit and serialize independent admin/frontend drafts. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};
	window.AdminKitParts.navigationToolbar = function (context, controls) {
		var D = context.D;
		var I = context.I;
		var state = context.state;
		var el = context.el;
		var markDirty = context.markDirty;
		var apiFetch = context.apiFetch;
		var snapshotFlip = controls.snapshotFlip;
		var playFlip = controls.playFlip;
		var titleOverride = controls.titleOverride;
		var handle = controls.handle;
		var iconBtn = controls.iconBtn;
		var closeIconPicker = controls.closeIconPicker;
		var dragSource = controls.dragSource;
		var dropZone = controls.dropZone;
		var titleInput = controls.titleInput;

		state.toolbar = { admin: { left: [], right: [] }, frontend: { left: [], right: [] } };

		function buildToolbar() {
			var TB = D.toolbar || {};
			var activeCtx = 'admin';
			var nodesByCtx = { admin: [], frontend: [] };
			var contextTitle = el('h3', { text: I.toolbarAdminTitle });
			var scopeHint = el('p', { id: 'ak-toolbar-scope', class: 'ak-toolbar-scope', 'aria-live': 'polite', text: I.toolbarAdminScope });
			var p = el('section', { class: 'ak-panel ak-panel--menu', role: 'tabpanel' }, [
				contextTitle,
				el('p', { class: 'ak-foundation-intro', text: I.toolbarIntro })
			]);
			if (TB.enabled === false) { return p; }
			var listWrap = el('div', { class: 'ak-menu-tree ak-toolbar-columns' });

			var ctxButtons = {};
			function switchContext(ctx) {
				if (ctx !== 'admin' && ctx !== 'frontend') {
					return;
				}
				closeIconPicker();
				activeCtx = ctx;
				contextTitle.textContent = ctx === 'admin' ? I.toolbarAdminTitle : I.toolbarFrontendTitle;
				scopeHint.textContent = ctx === 'admin' ? I.toolbarAdminScope : I.toolbarFrontendScope;
				['admin', 'frontend'].forEach(function (k) {
					var btn = ctxButtons[k];
					if (!btn) {
						return;
					}
					var on = k === activeCtx;
					btn.classList.toggle('is-active', on);
					btn.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				render();
			}

			var seg = el(
				'div',
				{
					class: 'ak-seg ak-menu-toolbar__seg',
					role: 'group',
					'aria-label': I.toolbarContext,
					'aria-describedby': 'ak-toolbar-scope'
				},
				[
					(ctxButtons.admin = el('button', {
						type: 'button',
						class: 'ak-seg__opt is-active',
						'aria-pressed': 'true',
						text: I.toolbarAdmin,
						onclick: function () {
							switchContext('admin');
						}
					})),
					(ctxButtons.frontend = el('button', {
						type: 'button',
						class: 'ak-seg__opt',
						'aria-pressed': 'false',
						text: I.toolbarFrontend,
						onclick: function () {
							switchContext('frontend');
						}
					}))
				]
			);

			p.appendChild(
				el('div', { class: 'ak-actions ak-menu-toolbar' }, [
					seg,
					el('button', {
						type: 'button',
						class: 'ak-btn ak-btn--ghost ak-menu-toolbar__spacer',
						text: I.toolbarReset,
						onclick: reset
					})
				])
			);
			p.appendChild(scopeHint);
			p.appendChild(el('p', { class: 'ak-toolbar-save-hint', text: I.toolbarSaveScope }));
			p.appendChild(listWrap);

			function currentSides() {
				if (!state.toolbar || !state.toolbar[activeCtx]) {
					state.toolbar = state.toolbar || {};
					state.toolbar[activeCtx] = { left: [], right: [] };
				}
				return state.toolbar[activeCtx];
			}

			function load() {
				if (!apiFetch || !TB.route) {
					listWrap.textContent = I.toolbarEmpty || '—';
					return;
				}
				p.inert = true;
				p.setAttribute('aria-busy', 'true');
				apiFetch({ path: tbPath() })
					.then(function (res) {
						var nbc = (res && res.nodesByContext) || {};
						var cbc = (res && res.configByContext) || {};
						nodesByCtx.admin = nbc.admin || (res && res.nodes) || [];
						nodesByCtx.frontend = nbc.frontend || [];
						state.toolbar = {
							admin: toSides(nodesByCtx.admin, cbc.admin || (res && res.config) || {}),
							frontend: toSides(nodesByCtx.frontend, cbc.frontend || {})
						};
						render();
						p.inert = false;
						p.removeAttribute('aria-busy');
					})
					.catch(function () {
						p.removeAttribute('aria-busy');
						listWrap.textContent = I.toolbarEmpty || '—';
					});
			}

			// Build { left, right } from the live nodes + a saved layout: honour the saved
			// order WITHIN each side, append any new/unseen node in native order, carry hidden,
			// title overrides, and icon overrides.
			function toSides(nodes, config) {
				var hidden = {};
				((config && config.hidden) || []).forEach(function (id) {
					hidden[id] = true;
				});
				var titles = (config && config.titles) || {};
				var icons = (config && config.icons) || {};
				var byId = {};
				(nodes || []).forEach(function (n) {
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
				((config && config.order) || []).forEach(function (id) {
					if (byId[id] && !seen[id]) {
						seen[id] = 1;
						place(byId[id]);
					}
				});
				(nodes || []).forEach(function (n) {
					if (!seen[n.id]) {
						seen[n.id] = 1;
						place(byId[n.id]);
					}
				});
				return sides;
			}

			function reset() {
				state.toolbar[activeCtx] = toSides(nodesByCtx[activeCtx] || [], {}); // native order, nothing hidden
				render();
				markDirty('toolbar');
			}

			// Swap a row with its neighbour (↑↓), within its own side only.
			function move(side, i, dir) {
				var sides = currentSides();
				var list = sides[side],
					to = i + dir;
				if (to < 0 || to >= list.length) {
					return;
				}
				var movedKey = 'tb:' + activeCtx + ':' + side + ':' + list[i].id;
				var tmp = list[i];
				list[i] = list[to];
				list[to] = tmp;
				render(movedKey);
				markDirty('toolbar');
			}
			// Splice a dragged row out and back in at a new index, same side.
			function moveTo(side, from, to) {
				var list = currentSides()[side];
				if (from === to || from < 0 || from >= list.length) {
					return;
				}
				list.splice(to, 0, list.splice(from, 1)[0]);
			}

			function render(movedKey) {
				var prevFlip = movedKey ? snapshotFlip(listWrap) : null;
				var sides = currentSides();
				listWrap.textContent = '';
				if (!(sides.left.length + sides.right.length)) {
					listWrap.appendChild(
						el('p', { text: I.toolbarEmpty })
					);
					return;
				}
				section('left', I.toolbarLeft);
				section('right', I.toolbarRight);
				if (prevFlip) {
					playFlip(listWrap, prevFlip, movedKey);
				}
			}

			// One side: a column with a small heading + its draggable rows. Drag is scoped
			// to the side via the drop predicate (ds.side === side), so a row can't cross the boundary.
			function section(side, heading) {
				var list = currentSides()[side];
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
					row.appendChild(iconBtn(item, listWrap, 'toolbar'));
					row.appendChild(titleInput(item, 'toolbar'));
					row.appendChild(hideCtl(item));
					dragSource(h, row, { kind: 'tb', ctx: activeCtx, side: side, i: i });
					dropZone(
						row,
						function (ds) {
							return ds.kind === 'tb' && ds.ctx === activeCtx && ds.side === side;
						},
						function (ds) {
							var sides = currentSides();
							var movedItem = sides[side] && sides[side][ds.i];
							var movedKey = movedItem ? 'tb:' + activeCtx + ':' + side + ':' + movedItem.id : null;
							moveTo(side, ds.i, i);
							render(movedKey);
							markDirty('toolbar');
						}
					);
					col.appendChild(
						el('div', { class: 'ak-menu-group', 'data-flip-id': 'tb:' + activeCtx + ':' + side + ':' + item.id }, [row])
					);
				});
				listWrap.appendChild(col);
			}

			function moveCtl(side, i) {
				var up = el('button', {
					type: 'button',
					class: 'ak-menu-move__btn',
					title: I.menuMoveUp,
					'aria-label': I.menuMoveUp,
					onclick: function () {
						move(side, i, -1);
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
					markDirty('toolbar');
				});
				return btn;
			}
			function paint(btn, item) {
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

			load();
			return p;
		}

		function tbPath() {
			var r = (D.toolbar && D.toolbar.route) || '';
			return r.charAt(0) === '/' ? r : '/' + r;
		}

		function gatherToolbarSlice(sides) {
			var t = sides || { left: [], right: [] };
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

		function gatherToolbar() {
			var tb = state.toolbar || {};
			return {
				admin: gatherToolbarSlice(tb.admin),
				frontend: gatherToolbarSlice(tb.frontend)
			};
		}

		return {
			build: buildToolbar,
			request: function () { return { path: tbPath(), data: gatherToolbar() }; }
		};
	};
})();
