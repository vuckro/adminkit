/**
 * Bricks builder — mirror canvas light/dark onto the chrome.
 *
 * Bricks's Style Manager toggle writes data-brx-theme="dark" (or "light")
 * to the CANVAS iframe's <html> only. The builder chrome stays untouched,
 * so AdminKit's restyle (which reads data-adminkit-theme on the chrome
 * <html>) can't follow the user's choice.
 *
 * Bridge: poll the iframe every 500 ms, copy data-brx-theme onto
 * data-adminkit-theme. Polling instead of MutationObserver because the
 * iframe + its contentDocument lifecycle (replace, reload, blank doc on
 * resize) makes attaching observers fragile and a 500 ms read is cheap.
 *
 * Loaded only in the builder MAIN frame, only when the Bricks-builder
 * feature is on. See class-bricks.php enqueue_builder().
 *
 * @package AdminKit
 */
( function () {
	'use strict';

	var html = document.documentElement;

	function tick() {
		var iframe = document.getElementById( 'bricks-builder-iframe' );
		if ( ! iframe ) {
			return;
		}
		var canvasHtml;
		try {
			canvasHtml = iframe.contentDocument && iframe.contentDocument.documentElement;
		} catch ( e ) {
			return;
		}
		if ( ! canvasHtml ) {
			return;
		}
		var mode = canvasHtml.getAttribute( 'data-brx-theme' );
		if ( mode !== 'dark' && mode !== 'light' ) {
			mode = 'light'; // canvas default — no attribute means light
		}
		if ( html.getAttribute( 'data-adminkit-theme' ) !== mode ) {
			html.setAttribute( 'data-adminkit-theme', mode );
		}
	}

	tick();
	setInterval( tick, 500 );
}() );
