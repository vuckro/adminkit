/**
 * Bricks builder — mirror canvas dark mode onto the chrome.
 *
 * Bricks's light/dark toggle in the builder (visible only when at least one
 * Style Manager colour has darkModeEnabled) writes data-brx-theme="dark" to
 * the CANVAS iframe's <html> only — not to the builder chrome's <html>.
 * That's enough for Bricks's own purposes (the canvas is where the page
 * being designed renders, and the dark variants of --background / --surface
 * / etc. are needed THERE), but AdminKit's chrome restyle reads the same
 * tokens off the chrome's :root. With no data-brx-theme set on the chrome,
 * --background never flips, so toggling dark mode leaves the toolbar,
 * panels and structure tree painting in their light-mode colours.
 *
 * Fix: observe the canvas iframe's <html> for data-brx-theme changes and
 * mirror them onto the chrome's <html>. Bricks's generated rules
 * (`:root[data-brx-theme="dark"] { --background: … }`) are already loaded
 * via style-manager.min.css in the chrome, so once the attribute lands the
 * cascade does the rest.
 *
 * Loaded only in the builder MAIN frame (see class-bricks.php enqueue_builder).
 * Loaded only when the "Bricks builder" feature toggle is on.
 *
 * @package AdminKit
 */
(function () {
	'use strict';

	var CHROME = document.documentElement;
	var poll   = null;
	var POLL_INTERVAL_MS = 250;
	var POLL_TIMEOUT_MS  = 30000; // 30 s — defensive, in case the iframe never mounts.

	function getCanvasHtml( iframe ) {
		try {
			return iframe.contentDocument && iframe.contentDocument.documentElement;
		} catch ( e ) {
			// Cross-origin iframe (shouldn't happen — Bricks's canvas is same-origin —
			// but the access can still throw briefly while the document is reloading).
			return null;
		}
	}

	function syncOnce( canvasHtml ) {
		var mode = canvasHtml.getAttribute( 'data-brx-theme' );
		if ( mode === 'dark' || mode === 'light' ) {
			CHROME.setAttribute( 'data-brx-theme', mode );
		} else {
			CHROME.removeAttribute( 'data-brx-theme' );
		}
	}

	function attach( iframe ) {
		var canvasHtml = getCanvasHtml( iframe );
		if ( ! canvasHtml ) {
			return false;
		}
		syncOnce( canvasHtml );
		// MutationObserver fires whenever Bricks's toggle writes the attribute.
		new MutationObserver( function () { syncOnce( canvasHtml ); } )
			.observe( canvasHtml, { attributes: true, attributeFilter: [ 'data-brx-theme' ] } );
		return true;
	}

	function tryAttach() {
		var iframe = document.getElementById( 'bricks-builder-iframe' );
		if ( ! iframe ) {
			return false;
		}
		if ( attach( iframe ) ) {
			return true;
		}
		// Iframe in DOM but contentDocument not ready yet (still loading). Wait
		// for the load event and try again.
		iframe.addEventListener( 'load', function () { attach( iframe ); }, { once: true } );
		return true;
	}

	function start() {
		if ( tryAttach() ) {
			return;
		}
		// Iframe not yet in the DOM — poll briefly. Bricks injects it after Vue
		// hydrates the builder shell, which can take a beat on a cold load.
		var started = Date.now();
		poll = setInterval( function () {
			if ( tryAttach() || ( Date.now() - started ) > POLL_TIMEOUT_MS ) {
				clearInterval( poll );
				poll = null;
			}
		}, POLL_INTERVAL_MS );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}());
