/** Responsive toolbar: preserve native nodes, group secondary actions on touch widths. */
(function () {
	'use strict';
	var bar = document.getElementById('wpadminbar');
	var toolbar = document.getElementById('wp-toolbar');
	var data = window.AdminKitMobileBar || {};
	if (!bar || !toolbar || !data.more) { return; }
	var mq = window.matchMedia('(max-width: 782px)');
	// Public-site toolbars retain their original scroll behavior and native search.
	if (!data.admin) {
		function updateEdges() {
			var overflow = toolbar.scrollWidth - toolbar.clientWidth;
			bar.classList.toggle('ak-bar-no-scroll', mq.matches && overflow <= 1);
			bar.classList.toggle('ak-bar-at-start', mq.matches && toolbar.scrollLeft <= 1);
			bar.classList.toggle('ak-bar-at-end', mq.matches && toolbar.scrollLeft >= overflow - 1);
		}
		toolbar.addEventListener('scroll', updateEdges, { passive: true });
		window.addEventListener('resize', updateEdges);
		window.addEventListener('load', updateEdges);
		if (window.ResizeObserver) { new ResizeObserver(updateEdges).observe(toolbar); }
		updateEdges();
		return;
	}
	var moved = [];
	var wrapper = document.createElement('div');
	wrapper.className = 'ak-mobile-bar-tools';
	var button = document.createElement('button');
	button.type = 'button';
	button.className = 'ak-mobile-bar-toggle';
	button.setAttribute('aria-label', data.more);
	button.setAttribute('aria-expanded', 'false');
	button.setAttribute('aria-controls', 'ak-mobile-bar-panel');
	button.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>';
	// Reuse only the avatar image; the native account action stays intact.
	var avatar = bar.querySelector('#wp-admin-bar-my-account > .ab-item img.avatar');
	if (avatar) {
		var photo = avatar.cloneNode(false);
		photo.alt = ''; photo.removeAttribute('id'); photo.setAttribute('aria-hidden', 'true');
		button.replaceChildren(photo);
	}
	var panel = document.createElement('div');
	panel.id = 'ak-mobile-bar-panel';
	panel.className = 'ak-mobile-bar-panel';
	panel.hidden = true;
	var heading = document.createElement('div');
	heading.className = 'ak-mobile-bar-heading';
	heading.textContent = data.more;
	var list = document.createElement('ul');
	list.className = 'ak-mobile-bar-list';
	panel.appendChild(heading); panel.appendChild(list);
	wrapper.appendChild(button); wrapper.appendChild(panel);
	function close(restoreFocus) {
		panel.hidden = true;
		button.setAttribute('aria-expanded', 'false');
		if (restoreFocus) { button.focus(); }
	}
	button.addEventListener('click', function () {
		if (navigation && navigation.isOpen()) { navigation.close(false); }
		var open = panel.hidden;
		panel.hidden = !open;
		button.setAttribute('aria-expanded', String(open));
	});
	document.addEventListener('click', function (event) {
		if (!wrapper.contains(event.target)) { close(false); }
		if (navigation && navigation.isOpen() && bar.contains(event.target) && !event.target.closest('#wp-admin-bar-menu-toggle')) { navigation.close(false); }
	});
	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') { return; }
		if (!panel.hidden) { event.preventDefault(); close(true); }
		else if (navigation && navigation.isOpen()) { event.preventDefault(); navigation.close(true); }
	});
	wrapper.addEventListener('focusout', function (event) {
		if (event.relatedTarget && !wrapper.contains(event.relatedTarget)) { close(false); }
	});
	function sync() {
		if (!mq.matches) {
			close(false);
			moved.forEach(function (item) {
				item.placeholder.parentNode.replaceChild(item.node, item.placeholder);
				if (item.label) { item.label.remove(); item.icon.remove(); }
			});
			moved = [];
			wrapper.remove();
			bar.classList.remove('ak-bar-compact');
			return;
		}
		if (bar.classList.contains('ak-bar-compact')) { return; }
		var keep = ['wp-admin-bar-menu-toggle', 'wp-admin-bar-site-name', 'wp-admin-bar-ak-notifications', 'wp-admin-bar-ak-view-site', 'wp-admin-bar-ak-theme-toggle'];
		toolbar.querySelectorAll(':scope > ul > li').forEach(function (node) {
			if (keep.indexOf(node.id) !== -1 || node.hidden || getComputedStyle(node).display === 'none' && node.style.display === 'none') { return; }
			var action = node.querySelector(':scope > .ab-item');
			if (!action) { return; }
			var placeholder = document.createComment('adminkit-toolbar-position');
			node.parentNode.insertBefore(placeholder, node);
			var label = document.createElement('span');
			label.className = 'ak-mobile-action-label';
			var reader = action.querySelector('.screen-reader-text');
			label.textContent = action.getAttribute('aria-label') || action.getAttribute('title') || (reader && reader.textContent.trim()) || action.textContent.trim();
			if (node.id === 'wp-admin-bar-query-monitor') { label.textContent = data.queryMonitor; }
			var icon = document.createElement('span');
			icon.className = 'ak-mobile-action-icon';
			icon.setAttribute('aria-hidden', 'true');
			icon.innerHTML = (data.icons && data.icons[node.id]) || data.fallbackIcon || '';
			action.appendChild(icon); action.appendChild(label);
			moved.push({ node: node, placeholder: placeholder, label: label, icon: icon });
			if (node.id === 'wp-admin-bar-my-account') { list.prepend(node); }
			else { list.appendChild(node); }
		});
		if (!moved.length) { return; }
		toolbar.appendChild(wrapper);
		bar.classList.add('ak-bar-compact');
	}
	new MutationObserver(function () {
		moved.forEach(function (item) {
			var action = item.node.querySelector(':scope > .ab-item');
			if (action && item.label.parentNode !== action) { action.appendChild(item.icon); action.appendChild(item.label); }
		});
	}).observe(list, { childList: true, subtree: true });
	function enhanceNavigation() {
		if (data.navigation === false) { return null; }
		var trigger = bar.querySelector('#wp-admin-bar-menu-toggle > .ab-item');
		var drawer = document.getElementById('adminmenuwrap');
		var page = document.getElementById('wpwrap');
		if (!trigger || !drawer || !page) { return null; }
		var root = document.documentElement;
		var regions = [];
		var wasOpen = false;
		var pendingScroll = null, lockedScroll = null;
		var scrim = document.createElement('button');
		scrim.type = 'button'; scrim.className = 'ak-admin-menu-scrim'; scrim.tabIndex = -1;
		scrim.setAttribute('aria-label', data.closeMenu); scrim.hidden = true;
		document.body.appendChild(scrim);
		var glyph = document.createElement('span'); glyph.className = 'ak-menu-trigger-glyph'; glyph.setAttribute('aria-hidden', 'true');
		glyph.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M4 12h16"/><path d="M4 12h16"/><path d="M4 12h16"/></svg>';
		trigger.appendChild(glyph);
		trigger.setAttribute('aria-controls', drawer.id);
		trigger.addEventListener('click', function () { if (!isOpen()) { pendingScroll = { y: window.scrollY, top: document.body.getBoundingClientRect().top }; } }, true);
		document.body.classList.add('ak-frame-enhanced');
		bar.classList.add('ak-frame-enhanced');
		function isOpen() { return mq.matches && page.classList.contains('wp-responsive-open'); }
		function closeDrawer(restoreFocus) {
			if (isOpen()) { trigger.click(); }
			if (restoreFocus) { trigger.focus({ preventScroll: true }); }
		}
		function render() {
			if (!mq.matches && page.classList.contains('wp-responsive-open')) { page.classList.remove('wp-responsive-open'); }
			var open = isOpen();
			if (open && !wasOpen) { lockedScroll = pendingScroll || { y: window.scrollY, top: document.body.getBoundingClientRect().top }; pendingScroll = null; root.style.setProperty('--ak-menu-scroll-offset', lockedScroll.top + 'px'); }
			trigger.setAttribute('aria-expanded', String(open));
			trigger.setAttribute('aria-label', open ? data.closeMenu : data.openMenu);
			root.classList.toggle('ak-admin-menu-open', open);
			scrim.hidden = !open;
			drawer.inert = mq.matches && !open;
			if (mq.matches) { drawer.setAttribute('aria-hidden', String(!open)); }
			else { drawer.removeAttribute('aria-hidden'); }
			if (open && !wasOpen) {
				close(false);
				regions = Array.prototype.slice.call(document.querySelectorAll('#wpbody, #wpfooter')).map(function (node) { var old = node.inert; node.inert = true; return { node: node, inert: old }; });
				window.requestAnimationFrame(function () { var first = drawer.querySelector('#adminmenu a'); if (isOpen() && first) { first.focus({ preventScroll: true }); } });
			} else if (!open && wasOpen) {
				regions.forEach(function (region) { region.node.inert = region.inert; }); regions = [];
				root.style.removeProperty('--ak-menu-scroll-offset');
				if (lockedScroll) { window.scrollTo(0, lockedScroll.y); lockedScroll = null; }
			}
			wasOpen = open;
		}
		scrim.addEventListener('click', function () { closeDrawer(true); });
		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Tab' || !isOpen()) { return; }
			var items = [trigger].concat(Array.prototype.slice.call(drawer.querySelectorAll('a[href], button, input, select')).filter(function (node) { return !node.disabled && node.getClientRects().length; }));
			var first = items[0], last = items[items.length - 1];
			if (event.shiftKey && (document.activeElement === first || items.indexOf(document.activeElement) === -1)) { event.preventDefault(); last.focus(); }
			else if (!event.shiftKey && (document.activeElement === last || items.indexOf(document.activeElement) === -1)) { event.preventDefault(); first.focus(); }
		});
		new MutationObserver(render).observe(page, { attributes: true, attributeFilter: ['class'] });
		render();
		return { isOpen: isOpen, close: closeDrawer, sync: render };
	}
	var navigation = enhanceNavigation();
	function layout() { sync(); if (navigation) { navigation.sync(); } }
	if (mq.addEventListener) { mq.addEventListener('change', layout); }
	else { mq.addListener(layout); }
	layout();
})();
