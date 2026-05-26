/**
 * Bricks builder — mirror canvas dark mode onto the chrome via AdminKit's
 * own theme attribute.
 *
 * Bricks's light/dark toggle in the builder (visible only when at least one
 * Style Manager colour has darkModeEnabled) writes data-brx-theme="dark" to
 * the CANVAS iframe's <html> only. Bricks ALSO generates the matching dark
 * CSS rules (`:root[data-brx-theme="dark"] { --background: …; }`) and
 * injects them only into that same canvas iframe — never into the chrome.
 * So mirroring just the attribute onto the chrome would do nothing: no
 * rules to react to it.
 *
 * Fix: mirror onto AdminKit's OWN attribute (data-adminkit-theme), which
 * builder.css's dark-mode block already targets. That block re-points
 * Bricks's semantic tokens (--background, --surface, --heading, …) to the
 * AdminKit dark values, so the whole chrome cascade flips in one shot.
 *
 * Loaded only in the builder MAIN frame (see class-bricks.php
 * enqueue_builder), only when the "Bricks builder" feature toggle is on.
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
		// Bricks writes data-brx-theme on the canvas; we mirror to AdminKit's
		// own attribute on the chrome because the dark-mode CSS that flips
		// the chrome lives in adminkit-tokens.css + builder.css, both keyed
		// on data-adminkit-theme.
		if ( mode === 'dark' || mode === 'light' ) {
			CHROME.setAttribute( 'data-adminkit-theme', mode );
		} else {
			// No mode set on the canvas (light by default) — clear so light
			// styles win.
			CHROME.removeAttribute( 'data-adminkit-theme' );
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
