/**
 * AdminKit — responsive admin bar.
 *
 * On WP's touch bar (≤782px) adminbar.css turns #wp-toolbar into a single
 * horizontal-scroll row so the toolbar items no longer wrap onto a second line
 * that overlaps the page. This script drives the edge "fondu": it toggles three
 * state classes on #wpadminbar so the CSS overlays fade only where it helps —
 *
 *   ak-bar-at-start   scrolled hard left  → hide the LEFT fade
 *   ak-bar-at-end     scrolled hard right → hide the RIGHT fade
 *   ak-bar-no-scroll  bar fits, nothing to scroll → hide BOTH
 *
 * No-JS degrades cleanly: the CSS shows both fades by default (symmetric).
 * A no-op on desktop and wherever the bar is absent.
 */
(function () {
	var bar = document.getElementById('wpadminbar');
	var toolbar = document.getElementById('wp-toolbar');
	if (!bar || !toolbar) {
		return;
	}

	var mq = window.matchMedia('(max-width: 782px)');
	var STATES = ['ak-bar-at-start', 'ak-bar-at-end', 'ak-bar-no-scroll'];

	function update() {
		// Off the touch bar there's nothing to fade — clear any stale state.
		if (!mq.matches) {
			bar.classList.remove(STATES[0], STATES[1], STATES[2]);
			return;
		}
		var overflow = toolbar.scrollWidth - toolbar.clientWidth;
		if (overflow <= 1) {
			bar.classList.add('ak-bar-no-scroll');
			bar.classList.remove('ak-bar-at-start', 'ak-bar-at-end');
			return;
		}
		bar.classList.remove('ak-bar-no-scroll');
		// 1px slack absorbs sub-pixel rounding at the extremes.
		var x = toolbar.scrollLeft;
		bar.classList.toggle('ak-bar-at-start', x <= 1);
		bar.classList.toggle('ak-bar-at-end', x >= overflow - 1);
	}

	// Coalesce bursts of scroll/resize work into one paint frame.
	var ticking = false;
	function schedule() {
		if (ticking) {
			return;
		}
		ticking = true;
		window.requestAnimationFrame(function () {
			ticking = false;
			update();
		});
	}

	toolbar.addEventListener('scroll', schedule, { passive: true });
	window.addEventListener('resize', schedule);
	window.addEventListener('load', update); // web-font swap can resize items
	if (mq.addEventListener) {
		mq.addEventListener('change', update);
	} else if (mq.addListener) {
		mq.addListener(update); // Safari < 14
	}
	if (window.ResizeObserver) {
		new ResizeObserver(schedule).observe(toolbar);
	}

	update();
})();
