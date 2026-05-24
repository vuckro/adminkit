/**
 * AdminKit — post previews hover panel.
 *
 * Wires the screenshot thumbnails rendered into the preview column: a shared
 * floating panel shows a larger screenshot on hover, with graceful broken-image
 * handling. i18n labels arrive via `window.AdminKitPostPreviews` (set by an
 * inline bootstrap). No-op when no `.ak-preview` cell is present. Footer script,
 * loaded only on targeted list-table screens.
 */
(function () {
	if (!document.querySelector('.ak-preview')) { return; }
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
	var pop = null, popImg = null, hideTimer = null, current = null;

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
		pop.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
		pop.addEventListener('mouseleave', scheduleHide);
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
		clearTimeout(hideTimer);
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

	function scheduleHide() { hideTimer = setTimeout(hide, 120); }

	function hide() {
		if (!pop) { return; }
		pop.classList.remove('is-visible');
		current = null;
	}

	document.addEventListener('mouseover', function (e) {
		var span = e.target.closest ? e.target.closest('.ak-preview') : null;
		if (span && span !== current && !span.classList.contains('ak-preview--empty')) { show(span); }
	});
	document.addEventListener('mouseout', function (e) {
		var span = e.target.closest ? e.target.closest('.ak-preview') : null;
		if (span) { scheduleHide(); }
	});
	window.addEventListener('scroll', hide, true);
	window.addEventListener('resize', hide);
})();
