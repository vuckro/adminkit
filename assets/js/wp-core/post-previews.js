/**
 * AdminKit — post previews hover panel.
 *
 * Wires the screenshot thumbnails rendered into the preview column: a shared
 * floating panel shows a larger screenshot on hover, with graceful broken-image
 * handling. i18n labels arrive via `window.AdminKitPostPreviews` (set by an
 * inline bootstrap). Binds to ANY element carrying `data-ak-full` (the list-table
 * `.ak-preview` cells AND the dashboard's recent-activity thumbnails), so it no-ops
 * unless such an element is present. Footer script.
 */
(function () {
	if (!document.querySelector('[data-ak-full]')) { return; }
	var DATA = window.AdminKitPostPreviews || {};
	var LOADING_LABEL = DATA.loading || 'Loading preview…';
	var BROKEN_LABEL = DATA.broken || 'Preview unavailable';

	// If a screenshot fails to load, flag its cell as broken (a flat
	// placeholder) instead of showing the browser's broken-image glyph.
	function wireThumb(img) {
		if (img.dataset.akWired) { return; }
		img.dataset.akWired = '1';
		img.addEventListener('error', function () {
			var span = img.closest('.ak-preview');
			if (span) { span.classList.add('ak-preview--broken'); }
		});
	}
	Array.prototype.forEach.call(document.querySelectorAll('.ak-preview__thumb'), wireThumb);

	// One shared floating panel, reused across rows.
	var pop = null, popImg = null, current = null;

	function ensurePop() {
		if (pop) { return; }
		pop = document.createElement('div');
		pop.id = 'ak-preview-pop';
		pop.setAttribute('role', 'tooltip');
		popImg = document.createElement('img');
		popImg.alt = '';
		pop.appendChild(popImg);
		pop.setAttribute('data-loading-label', LOADING_LABEL);
		pop.setAttribute('data-broken-label', BROKEN_LABEL);
		document.body.appendChild(pop);
	}

	function position(anchor) {
		var r = anchor.getBoundingClientRect();
		var pw = pop.offsetWidth, ph = pop.offsetHeight, gap = 12;
		var left = r.right + gap;
		if (left + pw > window.innerWidth - 8) { left = r.left - gap - pw; } // flip left
		if (left < 8) { left = 8; }
		var top = r.top + r.height / 2 - ph / 2; // vertically centered on the thumb
		if (top < 8) { top = 8; }
		if (top + ph > window.innerHeight - 8) { top = window.innerHeight - 8 - ph; }
		pop.style.left = Math.round(left) + 'px';
		pop.style.top = Math.round(top) + 'px';
	}

	function show(span) {
		ensurePop();
		current = span;
		var full = span.getAttribute('data-ak-full');
		pop.className = 'is-visible is-loading';
		popImg.onload = function () { pop.classList.remove('is-loading'); position(span); };
		popImg.onerror = function () {
			pop.classList.remove('is-loading');
			pop.classList.add('is-broken');
			position(span);
		};
		popImg.setAttribute('src', full || '');
		position(span);
	}

	function hide() {
		if (!pop) { return; }
		pop.classList.remove('is-visible');
		current = null;
	}

	// A cell is previewable unless it's the empty placeholder. (Broken cells
	// still surface an "unavailable" message, so they count as previewable.)
	function previewable(span) {
		return !!span && !span.classList.contains('ak-preview--empty');
	}

	// Preload the larger hover images on idle so the first hover is instant — the
	// browser then serves them from cache. Bounded to this page's thumbs (admin
	// lists are paginated); skipped on very long pages to avoid a fetch storm.
	function preloadFulls() {
		var spans = document.querySelectorAll('[data-ak-full]');
		if (!spans.length || spans.length > 40) { return; }
		Array.prototype.forEach.call(spans, function (span) {
			var full = span.getAttribute('data-ak-full');
			if (full) { var im = new Image(); im.decoding = 'async'; im.src = full; }
		});
	}
	if (window.requestIdleCallback) { window.requestIdleCallback(preloadFulls); }
	else { window.setTimeout(preloadFulls, 1200); }

	document.addEventListener('mouseover', function (e) {
		var span = e.target.closest ? e.target.closest('[data-ak-full]') : null;
		if (span && span !== current && previewable(span)) { show(span); }
	});
	// Hide the instant the pointer leaves the active thumb. The panel is
	// pointer-events:none, so drifting onto it still counts as leaving — it can
	// never keep itself open. Sole exception: a move straight onto another
	// previewable thumb, which `mouseover` takes over (avoids a hide/show flash).
	document.addEventListener('mouseout', function (e) {
		if (!current) { return; }
		var to = e.relatedTarget;
		var next = (to && to.closest) ? to.closest('[data-ak-full]') : null;
		if (previewable(next)) { return; }
		hide();
	});
	window.addEventListener('scroll', hide, true);
	window.addEventListener('resize', hide);
})();
