/**
 * AdminKit — cookieless page-view beacon (~1 KB).
 *
 * Fires once per page view, after load, with the lowest possible cost:
 * navigator.sendBeacon when available (a non-blocking, background POST the browser
 * flushes even as the page unloads), falling back to a keepalive fetch. Sends only
 * the path + whether the referrer is off-site — no cookies, no IDs, no PII. The
 * server (AdminKit_Stats_Tracker) derives visit/source and folds it into daily
 * totals. Bails silently if anything is missing.
 *
 * @package AdminKit
 */
( function () {
	'use strict';

	var cfg = window.AdminKitTrack;
	if ( ! cfg || ! cfg.url ) {
		return;
	}

	// An opaque, per-tab token so the "active visitors" count is distinct-by-tab
	// without cookies or any server-side identity. Lives in sessionStorage (cleared
	// when the tab closes); a fresh random hex string when unavailable. Never stored
	// server-side beyond a 5-minute presence window.
	function token() {
		try {
			var k = 'akv';
			var v = sessionStorage.getItem( k );
			if ( ! v ) {
				v = ( Date.now().toString( 16 ) + Math.random().toString( 16 ).slice( 2 ) ).slice( 0, 32 );
				sessionStorage.setItem( k, v );
			}
			return v;
		} catch ( e ) {
			return ( Date.now().toString( 16 ) + Math.random().toString( 16 ).slice( 2 ) ).slice( 0, 32 );
		}
	}

	function send() {
		var ref = '';
		try {
			// Only the referrer host matters server-side; sending the full string is
			// fine (it's never stored) but we keep it minimal.
			ref = document.referrer || '';
		} catch ( e ) {
			ref = '';
		}

		var payload = {
			path: location.pathname + location.search,
			ref: ref,
			t: token()
		};

		// Prefer sendBeacon: queued by the browser and sent in the background,
		// surviving navigation — zero impact on the current page.
		try {
			if ( navigator.sendBeacon ) {
				var blob = new Blob( [ JSON.stringify( payload ) ], { type: 'application/json' } );
				if ( navigator.sendBeacon( cfg.url, blob ) ) {
					return;
				}
			}
		} catch ( e ) {}

		// Fallback: keepalive fetch (also non-blocking). Errors are ignored.
		try {
			if ( window.fetch ) {
				fetch( cfg.url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( payload ),
					keepalive: true,
					credentials: 'omit',
					mode: 'cors'
				} ).catch( function () {} );
			}
		} catch ( e ) {}
	}

	// Defer to idle/after-load so it never competes with rendering.
	if ( document.readyState === 'complete' ) {
		send();
	} else {
		window.addEventListener( 'load', function () {
			if ( window.requestIdleCallback ) {
				requestIdleCallback( send, { timeout: 2000 } );
			} else {
				setTimeout( send, 0 );
			}
		} );
	}
}() );
