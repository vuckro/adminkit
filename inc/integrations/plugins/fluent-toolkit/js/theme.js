/** FluentHub / Fluent apps <-> AdminKit theme intent, without replacing host UI. */
(function () {
	function init() {
		var D = window.AdminKitFluentHub;
		var root = document.documentElement;
		var body = document.body;
		if (!D || !body.classList.contains('adminkit') || !document.querySelector('.fluent_uui')) { return; }
		var channel;
		var media = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
		var lastNative = '';
		function read(key) { try { return localStorage.getItem(key) || ''; } catch (e) { return ''; } }
		function write(key, value) { try { if (value) { localStorage.setItem(key, value); } else { localStorage.removeItem(key); } } catch (e) { /* Storage may be unavailable. */ } }
		function mode(value) { var m = (value || '').split(':')[0]; return /^(light|dark|system)$/.test(m) ? m : ''; }
		function resolved(m) { return m === 'system' ? (media && media.matches ? 'dark' : 'light') : m; }
		function render() {
			var button = document.getElementById('ak-fui-theme-toggle');
			if (button) { button.setAttribute('aria-pressed', root.getAttribute(D.attr) === 'dark' ? 'true' : 'false'); }
		}
		function adopt(m) {
			m = mode(m);
			if (!m) { return; }
			var next = D.enabled ? resolved(m) : 'light';
			root.setAttribute(D.attr, next);
			write(D.key, m === 'system' && D.enabled ? '' : next);
			render();
		}
		function publish(m) {
			var value = m === 'system' ? 'system:' + resolved(m) : m;
			lastNative = value;
			write('fluent_theme_mode', value);
			if (channel) { channel.postMessage({ mode: m }); }
		}
		if (typeof window.BroadcastChannel !== 'undefined') {
			channel = new window.BroadcastChannel('fluent_theme_changed:' + window.location.origin);
			channel.onmessage = function (event) {
				if (event.data && mode(event.data.mode)) {
					lastNative = read('fluent_theme_mode');
					adopt(event.data.mode);
				}
			};
		}
		var saved = mode(read(D.key));
		var nativeMode = mode(read('fluent_theme_mode'));
		var initial = !D.enabled ? 'light' : (saved || nativeMode || mode(root.getAttribute(D.attr)) || 'system');
		adopt(initial);
		publish(initial);
		// Native apps also mutate these classes. Reading their persisted intent
		// avoids interpreting unrelated class changes as a theme choice.
		new MutationObserver(function () {
			var value = read('fluent_theme_mode');
			if (value !== lastNative && mode(value)) { lastNative = value; adopt(mode(value)); }
		}).observe(body, { attributes: true, subtree: true, attributeFilter: ['class'] });
		new MutationObserver(function (records) {
			var changed = records.some(function (record) { return record.attributeName === D.attr; });
			if (!changed) { return; }
			var current = root.getAttribute(D.attr);
			if (!D.enabled && current !== 'light') { adopt('light'); return; }
			if (current !== resolved(mode(lastNative))) { publish(current); }
			render();
		}).observe(root, { attributes: true, attributeFilter: [D.attr] });
		window.addEventListener('storage', function (event) {
			if (event.key === 'fluent_theme_mode' && mode(event.newValue)) {
				lastNative = event.newValue;
				adopt(mode(event.newValue));
			} else if (event.key === D.key) {
				var next = mode(event.newValue) || 'system';
				adopt(next); publish(next);
			}
		});
		if (media && media.addEventListener) {
			media.addEventListener('change', function () {
				if (mode(read('fluent_theme_mode')) === 'system') { adopt('system'); publish('system'); }
			});
		}
		// Apps without a native theme selector lose WordPress's hidden toolbar
		// switch inside Hub. Supply the same accessible button in the host footer.
		var footer = document.querySelector('.fui-sidebar-footer--left');
		if (D.enabled && footer && !document.querySelector('#fui-theme-toggle, .fsnip_theme_trigger')) {
			var button = document.createElement('button');
			button.id = 'ak-fui-theme-toggle';
			button.className = 'ak-fui-theme-toggle';
			button.type = 'button';
			button.setAttribute('aria-label', D.label);
			button.title = D.label;
			var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('viewBox', '0 0 24 24');
			svg.setAttribute('fill', 'none');
			svg.setAttribute('stroke', 'currentColor');
			svg.setAttribute('stroke-width', '1.75');
			svg.setAttribute('aria-hidden', 'true');
			var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
			path.setAttribute('d', 'M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401');
			svg.appendChild(path); button.appendChild(svg); footer.appendChild(button);
			button.addEventListener('click', function () {
				var next = root.getAttribute(D.attr) === 'dark' ? 'light' : 'dark';
				adopt(next); publish(next);
			});
		}
		render();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { window.setTimeout(init, 0); });
	} else { window.setTimeout(init, 0); }
})();
