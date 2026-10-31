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
		var open = panel.hidden;
		panel.hidden = !open;
		button.setAttribute('aria-expanded', String(open));
	});
	document.addEventListener('click', function (event) {
		if (!wrapper.contains(event.target)) { close(false); }
	});
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); close(true); }
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
		var keep = ['wp-admin-bar-menu-toggle', 'wp-admin-bar-site-name', 'wp-admin-bar-ak-notifications', 'wp-admin-bar-my-account'];
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
			list.appendChild(node);
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
	if (mq.addEventListener) { mq.addEventListener('change', sync); }
	else { mq.addListener(sync); }
	sync();
})();
