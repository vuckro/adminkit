/** Sidebar menu editor data model: snapshot merge, serialisation, import sanitisation and tree moves. */
(function () {
	'use strict';
	window.AdminKitParts = window.AdminKitParts || {};

	function makeTop(m, topCfg, subCfg, childTitle, childTitleByParent, claimed, snapBySlug) {
		topCfg = topCfg || {};
		var children = [],
			seenC = {};
		(subCfg[m.slug] || []).forEach(function (s) {
			var compKey = m.slug + '\0' + s.slug;
			var hasLocal = Object.prototype.hasOwnProperty.call(childTitleByParent, compKey);
			if (!hasLocal && !Object.prototype.hasOwnProperty.call(childTitle, s.slug)) {
				return;
			}
			seenC[s.slug] = true;
			var ot = hasLocal ? childTitleByParent[compKey] : childTitle[s.slug];
			children.push({
				slug: s.slug,
				otitle: ot,
				title: s.title && s.title !== '' ? s.title : ot,
				hidden: !!s.hidden,
				sourceTop: s.slug !== m.slug && snapBySlug[s.slug] && snapBySlug[s.slug].type !== 'separator' ? snapBySlug[s.slug] : null
			});
		});
		(m.children || []).forEach(function (c) {
			var claimedByOther = claimed[c.slug] && claimed[c.slug] !== m.slug && !claimed[m.slug + '\0' + c.slug];
			if (seenC[c.slug] || claimedByOther) {
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

	function buildMenuModel(snapshot, config, seenSlugs) {
		var snapBySlug = {},
			childTitle = {},
			childTitleByParent = {};
		(snapshot || []).forEach(function (m) {
			snapBySlug[m.slug] = m;
			if (m.type !== 'separator') { childTitle[m.slug] = m.title; }
			(m.children || []).forEach(function (c) {
				childTitleByParent[m.slug + '\0' + c.slug] = c.title;
				if (!Object.prototype.hasOwnProperty.call(childTitle, c.slug)) {
					childTitle[c.slug] = c.title;
				}
			});
		});
		var subCfg = (config && config.sub) || {};
		var claimed = {};
		Object.keys(subCfg).forEach(function (p) {
			(subCfg[p] || []).forEach(function (s) {
				claimed[s.slug] = p;
				claimed[p + '\0' + s.slug] = true;
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
				model.push(makeTop(m, t, subCfg, childTitle, childTitleByParent, claimed, snapBySlug));
			} else if (t.type === 'link' || t.type === 'separator') {
				model.push(makeCustom(t));
			}
		});
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
			if (claimed[m.slug] && claimed[m.slug] !== m.slug) { return; }
			if (seen[m.slug]) {
				prevSlug = m.slug;
				return;
			}
			if (hasConfig && m.type !== 'separator') {
				pending.push(m);
				return;
			}
			var node =
				m.type === 'separator'
					? { type: 'separator', slug: m.slug, hidden: false }
					: makeTop(m, null, subCfg, childTitle, childTitleByParent, claimed, snapBySlug);
			var at = prevSlug !== null ? indexOfSlug(prevSlug) : -1;
			var pos = at >= 0 ? at + 1 : null === prevSlug ? 0 : model.length;
			model.splice(pos, 0, node);
			prevSlug = m.slug;
		});
		var seenRank = {};
		(seenSlugs || []).forEach(function (slug, i) {
			seenRank[slug] = i;
		});
		pending.sort(function (a, b) {
			var ra = null != seenRank[a.slug] ? seenRank[a.slug] : 1e9;
			var rb = null != seenRank[b.slug] ? seenRank[b.slug] : 1e9;
			return ra - rb;
		});
		pending.forEach(function (m) {
			model.push(makeTop(m, null, subCfg, childTitle, childTitleByParent, claimed, snapBySlug));
		});
		// Include newly registered children of a menu already moved below a parent.
		(snapshot || []).forEach(function (source) {
			var parent = claimed[source.slug];
			if (!parent || parent === source.slug) { return; }
			var target = model.filter(function (top) { return top.slug === parent; })[0];
			if (!target) { return; }
			(source.children || []).forEach(function (child) {
				if ((!claimed[child.slug] || claimed[child.slug] === parent) && !target.children.some(function (entry) { return entry.slug === child.slug; })) {
					target.children.push({ slug: child.slug, otitle: child.title, title: child.title, hidden: false });
				}
			});
		});
		return model;
	}

	function gatherMenu(menu, titleOverride) {
		var items = [];
		(menu || []).forEach(function (top, ti) {
			var type = top.type || 'item';
			if (type === 'link') {
				var url = (top.url || '').replace(/^\s+|\s+$/g, '');
				if (!url) {
					return;
				}
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

	function isSafeMenuUrl(raw) {
		if (typeof raw !== 'string') {
			return false;
		}
		var s = raw.trim();
		if (!s || s.length > 2048 || /['"<>`\\\x00-\x20\x7f]/.test(s) || s.indexOf('//') === 0) {
			return false;
		}
		var colon = s.indexOf(':');
		var boundary = s.search(/[/?#]/);
		if (colon !== -1 && (boundary === -1 || colon < boundary)) {
			return /^(?:https?|mailto|tel):/i.test(s);
		}
		return true;
	}

	function sanitizeImportedItems(items) {
		return (items || [])
			.filter(function (it) {
				if (!it || typeof it !== 'object' || typeof it.slug !== 'string') {
					return false;
				}
				if (it.type === 'link' && !isSafeMenuUrl(it.slug)) {
					return false;
				}
				return true;
			})
			.map(function (it) {
				if (it.url && !isSafeMenuUrl(it.url)) {
					return Object.assign({}, it, { url: '' });
				}
				return it;
			});
	}

	function demoteTop(menu, fromTi, toTi) {
		var source = menu[fromTi], target = menu[toTi];
		if (!source || !target || source === target || source.type !== 'item' || target.type !== 'item') { return; }
		var children = target.children || (target.children = []);
		var moved = [Object.assign({}, source, { sourceTop: { slug: source.slug, title: source.otitle, dashicon: source.dashicon }, children: undefined })].concat(source.children || []);
		moved.forEach(function (child) {
			if (!children.some(function (existing) { return existing.slug === child.slug; })) { children.push(child); }
		});
		target.open = true;
		menu.splice(fromTi, 1);
	}

	function promoteChild(menu, ti, ci) {
		var child = menu[ti].children[ci];
		if (!child.sourceTop) { return; }
		menu[ti].children.splice(ci, 1);
		menu.push(Object.assign({}, child, { type: 'item', children: [], open: false, dashicon: child.sourceTop.dashicon || '' }));
	}

	function moveTop(menu, from, to) {
		var m = menu.splice(from, 1)[0];
		menu.splice(to, 0, m);
	}

	function moveChild(menu, fromTi, fromCi, toTi, toCi) {
		var c = menu[fromTi].children.splice(fromCi, 1)[0];
		if (fromTi === toTi && fromCi < toCi) {
			toCi--;
		}
		if (!menu[toTi].children) {
			menu[toTi].children = [];
		}
		menu[toTi].children.splice(toCi, 0, c);
		menu[toTi].open = true;
	}

	function moveChildToTop(menu, fromTi, fromCi, toTi) {
		var c = menu[fromTi].children.splice(fromCi, 1)[0];
		if (!menu[toTi].children) {
			menu[toTi].children = [];
		}
		menu[toTi].children.push(c);
		menu[toTi].open = true;
	}

	window.AdminKitParts.navigationMenuModel = {
		buildMenuModel: buildMenuModel,
		gatherMenu: gatherMenu,
		configFromItems: configFromItems,
		isSafeMenuUrl: isSafeMenuUrl,
		sanitizeImportedItems: sanitizeImportedItems,
		moveTop: moveTop,
		demoteTop: demoteTop,
		promoteChild: promoteChild,
		moveChild: moveChild,
		moveChildToTop: moveChildToTop
	};
})();
