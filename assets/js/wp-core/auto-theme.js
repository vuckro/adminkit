/**
 * AdminKit — runtime auto-theming ("the safety net").
 *
 * Static CSS can only theme markup we can target by selector. Modern plugins
 * increasingly paint their admin UI with hardcoded colours via CSS-in-JS
 * (emotion / styled-components → hashed class names) or inline styles — e.g.
 * Elementor 4's MUI-based Home hardcodes background:#fff on .MuiPaper-root.
 * No stylesheet AdminKit ships can reach those. So for any screen not fully
 * handled by a native adapter, this brick scans the rendered DOM, classifies the
 * colours each element is still painted with, and tags it (.ak-auto-*) so the
 * companion dark-only sheet remaps the tag to a matching --ak-* token.
 *
 * What it maps (computed colour → token), dark mode only:
 *   • neutral light background → --ak-surface (nested → --ak-elevated)
 *   • tinted light background  → --ak-{info|success|warning|error}-subtle (by hue)
 *   • near-black text → --ak-text · mid-grey text → --ak-text-muted
 *   • fixed light / translucent border → --ak-border
 *   • light box-shadow on a darkened surface → removed (no halo)
 *
 * SELF-LIMITING: it reads each element's *computed* colour, so anything a native
 * adapter (or AdminKit core) already put on a token reads as the dark token value
 * and is skipped — it layers on top of adapters without fighting them. TAGGING,
 * not restyling (classes + dark-only CSS) → flipping to light needs no cleanup.
 * Tagging runs in any mode so a later flip to dark is instant.
 */
( function () {
	'use strict';

	var D = window.AdminKitAuto || {};
	if ( ! D.enabled ) { return; }

	// Tunable thresholds — starting points, calibrated against real plugin admins.
	var T = {
		surfaceLum:   205,  // background at/above this luminance (0–255) is a candidate
		neutralSat:   0.10, // below this saturation → neutral (surface/elevated)
		tintedSatMax: 0.90, // above this → too vivid (a brand fill) → leave it
		textLum:      105,  // text at/below → body text
		mutedLumMax:  175,  // text between textLum and this → muted
		textSatMax:   0.30, // only low-chroma text is remapped (coloured text kept)
		borderLum:    165,  // opaque border at/above (low sat) → neutral → remap
		borderSatMax: 0.22,
		minW:         24,   // a childless element smaller than this (a swatch/dot)…
		minH:         16    // …is left alone for backgrounds
	};

	var C = {
		surface:  'ak-auto-surface',
		elevated: 'ak-auto-elevated',
		info:     'ak-auto-info',
		success:  'ak-auto-success',
		warning:  'ak-auto-warning',
		error:    'ak-auto-error',
		text:     'ak-auto-text',
		muted:    'ak-auto-muted',
		bd:       'ak-auto-bd',
		noshadow: 'ak-auto-noshadow'
	};
	var NEST_SELECTOR = '.' + C.surface + ',.' + C.elevated;

	// Media + replaced elements never get their box colours touched.
	var SKIP_TAGS = /^(IMG|SVG|PATH|G|USE|CANVAS|VIDEO|AUDIO|IFRAME|PICTURE|SOURCE|OBJECT|EMBED|MAP|AREA|HR|BR|SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/;
	// Chrome AdminKit themes natively + explicit opt-outs.
	var SKIP_CLOSEST = '#wpadminbar, #adminmenuwrap, #adminmenuback, #adminkit-app, .adminkit-app, [data-ak-no-auto]';

	function parse( c ) {
		if ( ! c ) { return null; }
		var m = c.match( /rgba?\(([^)]+)\)/ );
		if ( ! m ) { return null; }
		var p = m[ 1 ].split( ',' );
		return {
			r: parseFloat( p[ 0 ] ), g: parseFloat( p[ 1 ] ), b: parseFloat( p[ 2 ] ),
			a: p.length > 3 ? parseFloat( p[ 3 ] ) : 1
		};
	}
	function luminance( c ) { return 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b; }
	function saturation( c ) {
		var mx = Math.max( c.r, c.g, c.b ), mn = Math.min( c.r, c.g, c.b );
		return mx === 0 ? 0 : ( mx - mn ) / mx;
	}
	function hue( c ) {
		var r = c.r / 255, g = c.g / 255, b = c.b / 255;
		var mx = Math.max( r, g, b ), mn = Math.min( r, g, b ), d = mx - mn;
		if ( d === 0 ) { return 0; }
		var h;
		if ( mx === r ) { h = ( ( g - b ) / d ) % 6; }
		else if ( mx === g ) { h = ( b - r ) / d + 2; }
		else { h = ( r - g ) / d + 4; }
		h *= 60;
		return h < 0 ? h + 360 : h;
	}

	// Decide a background class for an opaque-ish light colour, or null to leave it.
	function bgClass( el, c ) {
		if ( luminance( c ) < T.surfaceLum ) { return null; }
		// Size guard: a childless light leaf this small is a swatch / dot / icon
		// chip, not a panel — leave it (avoids dotting the UI with dark squares).
		if ( ! el.firstElementChild && el.offsetWidth < T.minW && el.offsetHeight < T.minH ) {
			return null;
		}
		var s = saturation( c );
		if ( s < T.neutralSat ) {
			return el.closest( NEST_SELECTOR ) ? C.elevated : C.surface;
		}
		if ( s <= T.tintedSatMax ) {
			var h = hue( c );
			if ( h >= 185 && h <= 255 ) { return C.info; }
			if ( h >= 90 && h < 170 ) { return C.success; }
			if ( h >= 35 && h < 75 ) { return C.warning; }
			if ( h < 20 || h >= 330 ) { return C.error; }
		}
		return null; // purple / pink / other tints → leave (brand identity)
	}

	// True when an element has a visible border that's neutral-light or translucent
	// (the subtle dividers MUI / plugins draw; coloured borders are left semantic).
	function subtleBorder( s ) {
		var sides = [ 'Top', 'Right', 'Bottom', 'Left' ];
		for ( var i = 0; i < 4; i++ ) {
			if ( parseFloat( s[ 'border' + sides[ i ] + 'Width' ] ) > 0 ) {
				var c = parse( s[ 'border' + sides[ i ] + 'Color' ] );
				if ( ! c ) { continue; }
				if ( c.a > 0 && c.a < 0.6 ) { return true; } // translucent (e.g. rgba(0,0,0,.12))
				if ( c.a >= 0.6 && luminance( c ) >= T.borderLum && saturation( c ) < T.borderSatMax ) { return true; }
			}
		}
		return false;
	}
	function lightShadow( sh ) {
		if ( ! sh || sh === 'none' ) { return false; }
		var c = parse( sh );
		return !! c && luminance( c ) >= 180;
	}

	function shouldSkip( el ) {
		if ( SKIP_TAGS.test( el.tagName ) ) { return true; }
		return !! ( el.closest && el.closest( SKIP_CLOSEST ) );
	}

	function tag( el ) {
		if ( el.nodeType !== 1 || el.__akAuto ) { return; }
		el.__akAuto = 1;
		if ( shouldSkip( el ) ) { return; }

		var s = window.getComputedStyle( el );
		var add = [];
		var bgCls = null;

		// Background (skip gradients / images — leave decorative fills alone).
		if ( s.backgroundImage === 'none' ) {
			var bg = parse( s.backgroundColor );
			if ( bg && bg.a >= 0.9 ) {
				bgCls = bgClass( el, bg );
				if ( bgCls ) { add.push( bgCls ); }
			}
		}

		// Text — low-chroma only (coloured links/labels keep their colour).
		var col = parse( s.color );
		if ( col && col.a >= 0.5 && saturation( col ) < T.textSatMax ) {
			var lt = luminance( col );
			if ( lt <= T.textLum ) { add.push( C.text ); }
			else if ( lt <= T.mutedLumMax ) { add.push( C.muted ); }
		}

		// Borders.
		if ( subtleBorder( s ) ) { add.push( C.bd ); }

		// Light shadow on a surface we're darkening → drop the halo.
		if ( ( bgCls === C.surface || bgCls === C.elevated ) && lightShadow( s.boxShadow ) ) {
			add.push( C.noshadow );
		}

		for ( var i = 0; i < add.length; i++ ) { el.classList.add( add[ i ] ); }
	}

	function scan( node ) {
		if ( ! node || node.nodeType !== 1 ) { return; }
		tag( node );
		var els = node.querySelectorAll( '*' );
		for ( var i = 0; i < els.length; i++ ) { tag( els[ i ] ); }
	}

	function idle( fn ) {
		if ( window.requestIdleCallback ) { window.requestIdleCallback( fn, { timeout: 500 } ); }
		else { window.setTimeout( function () { fn( null ); }, 16 ); }
	}

	// Initial pass, time-sliced so a huge app (MUI = thousands of nodes) never
	// blocks the main thread. Document order means ancestors are tagged before
	// descendants, so the nested-surface (elevated) lookup is correct.
	function scanChunked( root ) {
		tag( root );
		var els = root.querySelectorAll( '*' ), i = 0;
		function step( dl ) {
			while ( i < els.length ) {
				tag( els[ i++ ] );
				if ( dl && dl.timeRemaining ) { if ( dl.timeRemaining() < 4 ) { break; } }
				else if ( i % 250 === 0 ) { break; }
			}
			if ( i < els.length ) { idle( step ); }
		}
		idle( step );
	}

	var scope = document.getElementById( 'wpcontent' )
		|| document.getElementById( 'wpbody-content' )
		|| document.body;

	// React / MUI mount + inject their emotion styles after load, so watch for
	// added subtrees and scan just those, debounced to let CSS-in-JS settle.
	var queue = [], scheduled = false;
	function flush() {
		scheduled = false;
		var q = queue; queue = [];
		for ( var i = 0; i < q.length; i++ ) { scan( q[ i ] ); }
	}
	function schedule() {
		if ( scheduled ) { return; }
		scheduled = true;
		idle( flush );
	}

	function boot() {
		scanChunked( scope );
		if ( ! window.MutationObserver ) { return; }
		new MutationObserver( function ( muts ) {
			for ( var i = 0; i < muts.length; i++ ) {
				var added = muts[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					if ( added[ j ].nodeType === 1 ) { queue.push( added[ j ] ); }
				}
			}
			if ( queue.length ) { schedule(); }
		} ).observe( scope, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
