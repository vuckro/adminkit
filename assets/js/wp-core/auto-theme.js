/**
 * AdminKit — runtime auto-theming ("the safety net").
 *
 * Static CSS can only theme markup we can target by selector. Modern plugins
 * increasingly paint their admin UI with hardcoded colours via CSS-in-JS
 * (emotion / styled-components → hashed class names) or inline styles — e.g.
 * Elementor 4's MUI-based Home hardcodes background:#fff on .MuiPaper-root.
 * No stylesheet AdminKit ships can reach those. So for any screen not fully
 * handled by a native adapter, this brick scans the rendered DOM, finds the
 * surfaces / text still painted in fixed light/dark values, and tags them so the
 * companion dark-only sheet remaps them to --ak-* tokens.
 *
 * SELF-LIMITING: it only tags elements whose *computed* colour is still a fixed
 * near-white / near-black — anything a native adapter already remapped to a token
 * reads as the (dark) token value and is skipped. So it layers cleanly on top of
 * adapters without fighting them.
 *
 * TAGGING, not restyling: it adds classes (.ak-auto-*) and lets CSS do the work
 * (paint only in dark), so flipping back to light removes the effect with no
 * cleanup. Tagging runs in any mode so a later flip to dark is instant.
 */
( function () {
	'use strict';

	var D = window.AdminKitAuto || {};
	if ( ! D.enabled ) { return; }

	var SURFACE = 'ak-auto-surface';
	var TEXT    = 'ak-auto-text';

	// Media + replaced elements: never restyle their box colours.
	var SKIP_TAGS = /^(IMG|SVG|PATH|G|USE|CANVAS|VIDEO|AUDIO|IFRAME|PICTURE|SOURCE|OBJECT|EMBED|MAP|AREA|HR|BR|SCRIPT|STYLE|LINK|TEMPLATE)$/;
	// Chrome AdminKit already themes + explicit opt-outs.
	var SKIP_CLOSEST = '#wpadminbar, #adminmenuwrap, #adminmenuback, #adminkit-app, .adminkit-app, [data-ak-no-auto]';

	function parseColor( c ) {
		if ( ! c ) { return null; }
		var m = c.match( /rgba?\(([^)]+)\)/ );
		if ( ! m ) { return null; }
		var p = m[ 1 ].split( ',' );
		return {
			r: parseFloat( p[ 0 ] ),
			g: parseFloat( p[ 1 ] ),
			b: parseFloat( p[ 2 ] ),
			a: p.length > 3 ? parseFloat( p[ 3 ] ) : 1
		};
	}
	function luminance( c ) { return 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b; }
	function saturation( c ) {
		var mx = Math.max( c.r, c.g, c.b ), mn = Math.min( c.r, c.g, c.b );
		return mx === 0 ? 0 : ( mx - mn ) / mx;
	}

	// A "light surface": (near-)opaque, very light, and not strongly hued — so
	// brand fills / coloured badges are left to keep their identity.
	function isLightSurface( c ) {
		return !! c && c.a >= 0.9 && luminance( c ) >= 224 && saturation( c ) < 0.14;
	}
	// Dark, low-chroma text → body text (must follow a darkened surface, else it
	// would be near-invisible once the surface goes dark).
	function isDarkText( c ) {
		return !! c && c.a >= 0.6 && luminance( c ) <= 100 && saturation( c ) < 0.30;
	}

	function shouldSkip( el ) {
		if ( SKIP_TAGS.test( el.tagName ) ) { return true; }
		if ( el.closest && el.closest( SKIP_CLOSEST ) ) { return true; }
		return false;
	}

	function tag( el ) {
		if ( el.nodeType !== 1 || el.__akAuto ) { return; }
		el.__akAuto = 1;
		if ( shouldSkip( el ) ) { return; }
		var s = window.getComputedStyle( el );
		// Backgrounds carrying an image (gradient / photo) are left alone.
		if ( s.backgroundImage === 'none' && isLightSurface( parseColor( s.backgroundColor ) ) ) {
			el.classList.add( SURFACE );
		}
		if ( isDarkText( parseColor( s.color ) ) ) {
			el.classList.add( TEXT );
		}
	}

	function scan( root ) {
		if ( ! root || root.nodeType !== 1 ) { return; }
		tag( root );
		var els = root.querySelectorAll( '*' );
		for ( var i = 0; i < els.length; i++ ) { tag( els[ i ] ); }
	}

	var scope = document.getElementById( 'wpbody-content' ) || document.body;

	// Modern admin UIs (React / MUI) mount + inject their emotion styles after
	// load, so a one-shot scan misses them. Watch for added subtrees and scan
	// just those, debounced to let CSS-in-JS settle before we read computed styles.
	var queue = [];
	var scheduled = false;
	function flush() {
		scheduled = false;
		var q = queue;
		queue = [];
		for ( var i = 0; i < q.length; i++ ) { scan( q[ i ] ); }
	}
	function schedule() {
		if ( scheduled ) { return; }
		scheduled = true;
		if ( window.requestIdleCallback ) {
			window.requestIdleCallback( flush, { timeout: 500 } );
		} else {
			window.setTimeout( flush, 150 );
		}
	}

	function boot() {
		scan( scope );
		if ( ! window.MutationObserver ) { return; }
		var mo = new MutationObserver( function ( muts ) {
			for ( var i = 0; i < muts.length; i++ ) {
				var added = muts[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					if ( added[ j ].nodeType === 1 ) { queue.push( added[ j ] ); }
				}
			}
			if ( queue.length ) { schedule(); }
		} );
		mo.observe( scope, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
