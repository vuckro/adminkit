/**
 * AdminKit — runtime auto-theming ("the safety net").
 *
 * Static CSS can only theme markup we can target by selector. Modern plugins
 * increasingly paint their admin UI with hardcoded colours via CSS-in-JS
 * (emotion / styled-components → hashed class names) or inline styles — e.g.
 * Elementor 4's MUI-based Home hardcodes background:#fff on .MuiPaper-root.
 * No stylesheet AdminKit ships can reach those. So for any screen not fully
 * handled by a native adapter, this brick scans the rendered DOM, classifies the
 * colour each element is still painted with, and tags it (.ak-auto-*) so the
 * companion dark-only sheet remaps the tag to a matching --ak-* token.
 *
 * What it maps (computed colour → token), dark mode only:
 *   • neutral light background → --ak-surface (nested → --ak-elevated)
 *   • PALE tinted background   → --ak-{info|success|warning|error}-subtle (by hue)
 *   • near-black text → --ak-text · mid-grey text → --ak-text-muted
 *   • fixed light / translucent border → --ak-border
 *   • light box-shadow on a darkened surface → removed (no halo)
 *   • the host's detected brand colour → --ak-primary (see detectBrand)
 *
 * SAFETY — it never restyles interactive controls' SURFACES (buttons, inputs…):
 * those are left alone (only their brand colour, if any, is unified to
 * --ak-primary — they stay strong coloured controls, never washed out). Only
 * PALE backgrounds are recoloured, so a brand fill is doubly safe.
 *
 * SELF-LIMITING — it reads each element's *computed* colour, so anything a native
 * adapter (or AdminKit core) already put on a token reads as the dark token value
 * and is skipped. TAGGING, not restyling (classes + dark-only CSS) → flipping to
 * light needs no cleanup.
 */
( function () {
	'use strict';

	var D = window.AdminKitAuto || {};
	if ( ! D.enabled ) { return; }
	var BRAND_ON = !! D.brand;

	// Tunable thresholds (0–255 luminance). Conservative: only PALE colours are
	// recoloured so vivid brand fills / CTAs are never caught by the surface pass.
	var T = {
		surfaceLum:   222, neutralSat:  0.10,
		tintLumMin:   218, tintSatMin:  0.10, tintSatMax: 0.55,
		textLum:      105, mutedLumMax: 170,  textSatMax: 0.30,
		borderLum:    165, borderSatMax: 0.22,
		minW:         24,  minH:        16,
		brandSatMin:  0.35, brandLumMin: 30, brandLumMax: 225, // brand = clearly hued, mid
		brandTol:     26,   brandExclTol: 38,                  // colour-match tolerances
		brandMinVotes: 3
	};

	var C = {
		surface: 'ak-auto-surface', elevated: 'ak-auto-elevated',
		info: 'ak-auto-info', success: 'ak-auto-success', warning: 'ak-auto-warning', error: 'ak-auto-error',
		text: 'ak-auto-text', muted: 'ak-auto-muted', bd: 'ak-auto-bd', noshadow: 'ak-auto-noshadow',
		brandBg: 'ak-auto-brand-bg', brandFg: 'ak-auto-brand-fg', brandBd: 'ak-auto-brand-bd'
	};
	var NEST_SELECTOR = '.' + C.surface + ',.' + C.elevated;

	var SKIP_TAGS = /^(IMG|SVG|PATH|G|USE|CANVAS|VIDEO|AUDIO|IFRAME|PICTURE|SOURCE|OBJECT|EMBED|MAP|AREA|HR|BR|SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/;
	// Never touched at all (media excluded above) — chrome AdminKit themes natively
	// + explicit opt-outs.
	var HARD_SKIP = '#wpadminbar, #adminmenuwrap, #adminmenuback, #adminkit-app, .adminkit-app, [data-ak-no-auto]';
	// Interactive controls: their SURFACE is left alone (only brand-unified). This
	// is the hard guarantee a CTA's colours are never washed out.
	var CONTROL = 'button, input, select, textarea, a.button, a.btn, .button, .btn, .components-button, .MuiButton-root, .ant-btn';

	function parse( c ) {
		if ( ! c ) { return null; }
		var m = c.match( /rgba?\(([^)]+)\)/ );
		if ( ! m ) { return null; }
		var p = m[ 1 ].split( ',' );
		return { r: parseFloat( p[ 0 ] ), g: parseFloat( p[ 1 ] ), b: parseFloat( p[ 2 ] ), a: p.length > 3 ? parseFloat( p[ 3 ] ) : 1 };
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
	function near( a, b, tol ) {
		return Math.abs( a.r - b.r ) <= tol && Math.abs( a.g - b.g ) <= tol && Math.abs( a.b - b.b ) <= tol;
	}

	function bgClass( el, c ) {
		var L = luminance( c ), S = saturation( c );
		if ( L < T.tintLumMin ) { return null; } // vivid / dark fills never touched
		if ( ! el.firstElementChild && el.offsetWidth < T.minW && el.offsetHeight < T.minH ) { return null; }
		if ( S < T.neutralSat && L >= T.surfaceLum ) {
			return el.closest( NEST_SELECTOR ) ? C.elevated : C.surface;
		}
		if ( S >= T.tintSatMin && S <= T.tintSatMax ) {
			var h = hue( c );
			if ( h >= 185 && h <= 255 ) { return C.info; }
			if ( h >= 90 && h < 170 ) { return C.success; }
			if ( h >= 35 && h < 75 ) { return C.warning; }
			if ( h < 20 || h >= 330 ) { return C.error; }
		}
		return null;
	}

	function subtleBorder( s ) {
		var sides = [ 'Top', 'Right', 'Bottom', 'Left' ];
		for ( var i = 0; i < 4; i++ ) {
			if ( parseFloat( s[ 'border' + sides[ i ] + 'Width' ] ) > 0 ) {
				var c = parse( s[ 'border' + sides[ i ] + 'Color' ] );
				if ( ! c ) { continue; }
				if ( c.a > 0 && c.a < 0.6 ) { return true; }
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

	// ── Brand detection ──────────────────────────────────────────────────────
	// The host's primary colour is the colour that recurs across its buttons (and,
	// secondarily, its links). We tally clearly-hued candidates, exclude colours
	// already equal to --ak-primary (core buttons AdminKit remapped), and take the
	// clear plurality. No clear winner → null (we never guess).
	var AKPRIMARY = null, BRAND = null, brandTries = 0;

	function resolveToken( name ) {
		var probe = document.createElement( 'span' );
		probe.style.cssText = 'color:var(' + name + ');position:absolute;left:-9999px;top:-9999px';
		document.body.appendChild( probe );
		var c = parse( window.getComputedStyle( probe ).color );
		document.body.removeChild( probe );
		return c;
	}
	function brandCandidate( c ) {
		if ( ! c || c.a < 0.7 ) { return false; }
		var L = luminance( c ), S = saturation( c );
		if ( S < T.brandSatMin || L < T.brandLumMin || L > T.brandLumMax ) { return false; }
		if ( AKPRIMARY && near( c, AKPRIMARY, T.brandExclTol ) ) { return false; }
		return true;
	}
	function quant( c ) { return ( c.r >> 4 ) + ',' + ( c.g >> 4 ) + ',' + ( c.b >> 4 ); }

	function detectBrand( root ) {
		var votes = {}, rep = {};
		function vote( c, w ) {
			if ( ! brandCandidate( c ) ) { return; }
			var k = quant( c );
			votes[ k ] = ( votes[ k ] || 0 ) + w;
			if ( ! rep[ k ] ) { rep[ k ] = c; }
		}
		var btns = root.querySelectorAll( 'button, [type="submit"], .button-primary, a.button-primary, .MuiButton-contained, .MuiButton-containedPrimary, .components-button.is-primary, [class*="-primary"]' );
		for ( var i = 0; i < btns.length; i++ ) { vote( parse( window.getComputedStyle( btns[ i ] ).backgroundColor ), 2 ); }
		var links = root.querySelectorAll( 'a' );
		for ( var j = 0; j < links.length && j < 400; j++ ) { vote( parse( window.getComputedStyle( links[ j ] ).color ), 1 ); }
		var bestK = null, best = 0;
		for ( var k in votes ) { if ( votes[ k ] > best ) { best = votes[ k ]; bestK = k; } }
		return ( bestK && best >= T.brandMinVotes ) ? rep[ bestK ] : null;
	}
	function brandClasses( s, add ) {
		if ( ! BRAND ) { return; }
		var bg = parse( s.backgroundColor );
		if ( bg && bg.a >= 0.7 && near( bg, BRAND, T.brandTol ) ) { add.push( C.brandBg ); }
		var col = parse( s.color );
		if ( col && col.a >= 0.7 && near( col, BRAND, T.brandTol ) ) { add.push( C.brandFg ); }
		var sides = [ 'Top', 'Right', 'Bottom', 'Left' ];
		for ( var i = 0; i < 4; i++ ) {
			if ( parseFloat( s[ 'border' + sides[ i ] + 'Width' ] ) > 0 ) {
				var bc = parse( s[ 'border' + sides[ i ] + 'Color' ] );
				if ( bc && bc.a >= 0.5 && near( bc, BRAND, T.brandTol ) ) { add.push( C.brandBd ); break; }
			}
		}
	}

	function shouldHardSkip( el ) {
		if ( SKIP_TAGS.test( el.tagName ) ) { return true; }
		return !! ( el.closest && el.closest( HARD_SKIP ) );
	}

	function tag( el ) {
		if ( el.nodeType !== 1 || el.__akAuto ) { return; }
		el.__akAuto = 1;
		if ( shouldHardSkip( el ) ) { return; }

		var s = window.getComputedStyle( el );
		var add = [];
		var bgCls = null;
		var control = el.closest && el.closest( CONTROL );

		if ( ! control ) {
			if ( s.backgroundImage === 'none' ) {
				var bg = parse( s.backgroundColor );
				if ( bg && bg.a >= 0.9 ) { bgCls = bgClass( el, bg ); if ( bgCls ) { add.push( bgCls ); } }
			}
			var col = parse( s.color );
			if ( col && col.a >= 0.5 && saturation( col ) < T.textSatMax ) {
				var lt = luminance( col );
				if ( lt <= T.textLum ) { add.push( C.text ); }
				else if ( lt <= T.mutedLumMax ) { add.push( C.muted ); }
			}
			if ( subtleBorder( s ) ) { add.push( C.bd ); }
			if ( ( bgCls === C.surface || bgCls === C.elevated ) && lightShadow( s.boxShadow ) ) { add.push( C.noshadow ); }
		}

		if ( BRAND ) { brandClasses( s, add ); el.__akBrand = 1; }

		for ( var i = 0; i < add.length; i++ ) { el.classList.add( add[ i ] ); }
	}

	// Brand-only re-pass, for when the brand is detected AFTER the first scan
	// already flagged everything (React apps mount their buttons late).
	function applyBrand( el ) {
		if ( el.nodeType !== 1 || el.__akBrand ) { return; }
		el.__akBrand = 1;
		if ( shouldHardSkip( el ) ) { return; }
		var add = [];
		brandClasses( window.getComputedStyle( el ), add );
		for ( var i = 0; i < add.length; i++ ) { el.classList.add( add[ i ] ); }
	}
	function brandPass( root ) {
		if ( ! BRAND || ! root || root.nodeType !== 1 ) { return; }
		applyBrand( root );
		var els = root.querySelectorAll( '*' );
		for ( var i = 0; i < els.length; i++ ) { applyBrand( els[ i ] ); }
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

	var queue = [], scheduled = false;
	function flush() {
		scheduled = false;
		// Late brand detection — React/MUI render their buttons after first paint.
		if ( BRAND_ON && ! BRAND && brandTries < 8 ) {
			brandTries++;
			if ( ! AKPRIMARY ) { AKPRIMARY = resolveToken( '--ak-primary' ); }
			BRAND = detectBrand( scope );
			if ( BRAND ) { brandPass( scope ); }
		}
		var q = queue; queue = [];
		for ( var i = 0; i < q.length; i++ ) { scan( q[ i ] ); }
	}
	function schedule() {
		if ( scheduled ) { return; }
		scheduled = true;
		idle( flush );
	}

	function boot() {
		if ( BRAND_ON ) {
			AKPRIMARY = resolveToken( '--ak-primary' );
			BRAND = detectBrand( scope );
		}
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
