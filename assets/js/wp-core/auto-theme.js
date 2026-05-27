/**
 * AdminKit — runtime auto-theming ("the safety net").
 *
 * Static CSS can only theme markup we can target by selector. Modern plugins
 * increasingly paint their admin UI with hardcoded colours via CSS-in-JS
 * (emotion / styled-components → hashed class names) or inline styles — e.g.
 * Elementor 4's MUI Home hardcodes background:#fff on .MuiPaper-root. No
 * stylesheet AdminKit ships can reach those. So this brick scans the rendered
 * DOM, classifies the colour each element is still painted with, and tags it
 * (.ak-auto-*) so the companion dark-only sheet remaps the tag to a --ak-* token.
 *
 * The classifier mirrors dev/css-scan.php's ak_classify() — the same logic that
 * backs every hand-tuned adapter — so the runtime mapping matches the adapters'
 * semantics. Per the property an element paints (background / border / text) and
 * the colour's HSL lightness + absolute CHROMA (a far better "is this grey?"
 * signal than HSL saturation) + hue:
 *
 *   background  light neutral → --ak-surface (nested → --ak-elevated)
 *               pale tinted   → --ak-{info|success|warning|error|primary}-subtle (hue)
 *   border      light → --ak-border · medium/strong → --ak-border-strong
 *               (pale hued + medium-diluted hued borders demote to these tokens)
 *   text        near-black → --ak-heading · dark → --ak-text · grey → --ak-text-muted
 *               (heading-semantic tags — H1-H6, TH, LEGEND, … — also force --ak-heading)
 *   brand       the host's detected primary colour → --ak-primary (see detectBrand)
 *   modal       [role=dialog] / <dialog> / .modal / .popover root → --ak-elevated + lift
 *   hover       arbitrary :hover/:focus light bg → --ak-hover-bg (see scanHoverRules)
 *
 * SAFETY — only true BUTTONS (and special inputs) keep their surface untouched
 * (so a CTA can't be washed out); their brand colour is still unified. Form
 * fields (text inputs / selects / textareas) ARE themed, so no surface/border is
 * missed. Everything reads the *computed* colour, so anything already on a token
 * (adapters / core) is skipped — it layers on top, never fights them. Crash-proof
 * (every per-element read is wrapped) and TAGGING-only (dark-only CSS → no cleanup).
 */
( function () {
	'use strict';

	var D = window.AdminKitAuto || {};
	if ( ! D.enabled ) { return; }
	var BRAND_ON = !! D.brand;

	var C = {
		surface: 'ak-auto-surface', elevated: 'ak-auto-elevated', primarySub: 'ak-auto-primary-sub',
		info: 'ak-auto-info', success: 'ak-auto-success', warning: 'ak-auto-warning', error: 'ak-auto-error',
		heading: 'ak-auto-heading', text: 'ak-auto-text', muted: 'ak-auto-muted',
		bd: 'ak-auto-bd', bdStrong: 'ak-auto-bd-strong', noshadow: 'ak-auto-noshadow',
		brandBg: 'ak-auto-brand-bg', brandFg: 'ak-auto-brand-fg', brandBd: 'ak-auto-brand-bd',
		modal: 'ak-auto-modal', hoverable: 'ak-auto-hoverable-light'
	};
	var NEST_SELECTOR = '.' + C.surface + ',.' + C.elevated;
	var MIN_W = 24, MIN_H = 16; // a childless light leaf this small = swatch/dot → leave

	var SKIP_TAGS = /^(IMG|SVG|PATH|G|USE|CANVAS|VIDEO|AUDIO|IFRAME|PICTURE|SOURCE|OBJECT|EMBED|MAP|AREA|HR|BR|SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/;
	var HARD_SKIP = '#wpadminbar, #adminmenuwrap, #adminmenuback, #adminkit-app, .adminkit-app, [data-ak-no-auto]';
	// Real action buttons: their surface is only ever darkened when NEUTRAL (white /
	// grey secondary buttons, incl. Element-UI .el-button) — a vivid or tinted CTA
	// fill is left untouched (it goes through the brand pass instead). That's the
	// hard guarantee a CTA can't be washed out. Form fields / selects are NOT here,
	// so they're themed like any surface.
	var ACTION_BTN = 'button, a.button, a.btn, .button, .btn, .components-button, .MuiButton-root, .ant-btn, .el-button, ' +
		'input[type="submit"], input[type="button"], input[type="reset"]';
	// Tiny native controls — never touch their box colours (browser-rendered).
	var SPECIAL_TYPES = /^(checkbox|radio|range|color|file)$/;
	// Heading-semantic tags — promoted to --ak-heading even when their text colour
	// would otherwise classify as body/muted by lightness alone. A grey-painted <h2>
	// is still a heading.
	var HEADING_TAGS = /^(H1|H2|H3|H4|H5|H6|TH|LEGEND|DT|SUMMARY|CAPTION|STRONG)$/;
	// Modal-like containers — their root gets `ak-auto-modal` for surface + lift
	// treatment, and the alpha guard is relaxed inside (so semi-opaque white panels
	// like MUI / SweetAlert / Tippy get themed). Covers role-based, native <dialog>,
	// and the most common library class names.
	var MODAL_SEL = '[role="dialog"],[aria-modal="true"],dialog,.modal,.ui-dialog,.modal-content,.swal2-container,.popover,.tippy-box';

	function parse( c ) {
		if ( ! c ) { return null; }
		var m = c.match( /rgba?\(([^)]+)\)/ );
		if ( ! m ) { return null; }
		var p = m[ 1 ].split( ',' );
		return { r: parseFloat( p[ 0 ] ), g: parseFloat( p[ 1 ] ), b: parseFloat( p[ 2 ] ), a: p.length > 3 ? parseFloat( p[ 3 ] ) : 1 };
	}
	function hslL( c ) { return ( Math.max( c.r, c.g, c.b ) + Math.min( c.r, c.g, c.b ) ) / 510 * 100; }
	function chroma( c ) { return Math.max( c.r, c.g, c.b ) - Math.min( c.r, c.g, c.b ); }
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
	function near( a, b, tol ) { return Math.abs( a.r - b.r ) <= tol && Math.abs( a.g - b.g ) <= tol && Math.abs( a.b - b.b ) <= tol; }

	// Mirror of ak_classify(), adapted for runtime (dark mode): we remap LIGHT
	// surfaces / borders and DARK text, and leave already-dark fills + light text +
	// vivid hued fills alone (the brand pass handles the brand; status fills stay).
	// prop = 'bg' | 'border' | 'text'. Returns a class name or null.
	function classify( c, prop, el ) {
		if ( ! c || c.a <= 0 ) { return null; }
		// Translucent: a subtle border, or — only for backgrounds — a near-white
		// semi-opaque panel (modal / popover / tooltip wash) the original alpha
		// guard otherwise misses. ≥0.5 alpha + clearly light L still needs darkening.
		if ( c.a < 0.95 ) {
			if ( prop === 'border' ) { return C.bd; }
			if ( prop !== 'bg' ) { return null; }
			if ( c.a < 0.5 || hslL( c ) < 88 ) { return null; }
			// Fall through and classify like an opaque surface.
		}
		var L = hslL( c ), ch = chroma( c );
		var neutral = ch <= 12 || ( ch <= 24 && L < 90 );

		if ( neutral ) {
			if ( prop === 'text' ) {
				if ( L >= 88 ) { return null; }   // light text (on a fill) → leave
				if ( L < 28 ) { return C.heading; }
				// Heading-semantic tag → heading even when its painted colour would
				// classify as body/muted by lightness alone (a grey-painted <h2> is still
				// a heading).
				if ( el && ( HEADING_TAGS.test( el.tagName ) || ( el.getAttribute && el.getAttribute( 'role' ) === 'heading' ) ) ) { return C.heading; }
				if ( L < 46 ) { return C.text; }
				return C.muted;
			}
			if ( prop === 'border' ) {
				if ( L >= 80 ) { return C.bd; }
				if ( L >= 35 ) { return C.bdStrong; }
				return null;                      // near-black border → fine in dark
			}
			// background
			if ( tinyLeaf( el ) ) { return null; }
			if ( L >= 95 ) { return ( el && el.closest( NEST_SELECTOR ) ) ? C.elevated : C.surface; }
			if ( L >= 82 ) { return C.elevated; }
			return null;                          // medium/dark fill → leave
		}

		// hued — coloured borders that are PALE (close to white-ish) or MEDIUM and
		// only mildly saturated demote to neutral border tones — they'd read as out
		// of place on a dark surface. Vivid hued borders (focus rings, intentional
		// accents at chroma ≥ 50) keep their colour.
		if ( prop === 'border' ) {
			if ( L >= 75 ) { return C.bd; }
			if ( L >= 50 && ch < 50 ) { return C.bdStrong; }
			return null;
		}
		if ( prop !== 'bg' ) { return null; }     // hued text = link/status/brand → leave
		if ( tinyLeaf( el ) ) { return null; }
		if ( L >= 86 ) {                          // pale tint → subtle by hue
			var h = hue( c );
			if ( h >= 345 || h < 16 ) { return C.error; }
			if ( h >= 95 && h <= 168 ) { return C.success; }
			if ( h >= 16 && h < 50 ) { return C.warning; }
			if ( h >= 168 && h < 210 ) { return C.info; }
			return C.primarySub;
		}
		return null;                              // vivid hued fill → brand pass / leave
	}
	function tinyLeaf( el ) {
		return !! el && ! el.firstElementChild && el.offsetWidth < MIN_W && el.offsetHeight < MIN_H;
	}

	function classifyBorders( s ) {
		var sides = [ 'Top', 'Right', 'Bottom', 'Left' ], res = null;
		for ( var i = 0; i < 4; i++ ) {
			if ( parseFloat( s[ 'border' + sides[ i ] + 'Width' ] ) > 0 ) {
				var cls = classify( parse( s[ 'border' + sides[ i ] + 'Color' ] ), 'border', null );
				if ( cls === C.bdStrong ) { return C.bdStrong; } // strongest wins
				if ( cls === C.bd ) { res = C.bd; }
			}
		}
		return res;
	}
	// ── Brand detection — the colour that recurs across the host's buttons (links
	// second). Exclude colours already equal to --ak-primary; take the clear
	// plurality, else null (never guess). ──────────────────────────────────────
	var AKPRIMARY = null, BRAND = null, brandTries = 0;
	function resolveToken( name ) {
		var probe = document.createElement( 'span' );
		probe.style.cssText = 'color:var(' + name + ');position:absolute;left:-9999px;top:-9999px';
		try {
			document.body.appendChild( probe );
			return parse( window.getComputedStyle( probe ).color );
		} catch ( e ) { return null; } finally {
			if ( probe.parentNode ) { probe.parentNode.removeChild( probe ); }
		}
	}
	function brandCandidate( c ) {
		if ( ! c || c.a < 0.7 ) { return false; }
		var L = hslL( c );
		if ( chroma( c ) < 60 || L < 12 || L > 88 ) { return false; } // clearly hued, mid
		return ! ( AKPRIMARY && near( c, AKPRIMARY, 38 ) );
	}
	function quant( c ) { return ( c.r >> 4 ) + ',' + ( c.g >> 4 ) + ',' + ( c.b >> 4 ); }
	function detectBrand( root ) {
		try {
			var votes = {}, rep = {};
			var vote = function ( c, w ) {
				if ( ! brandCandidate( c ) ) { return; }
				var k = quant( c );
				votes[ k ] = ( votes[ k ] || 0 ) + w;
				if ( ! rep[ k ] ) { rep[ k ] = c; }
			};
			var btns = root.querySelectorAll( 'button, [type="submit"], .button-primary, a.button-primary, .MuiButton-contained, .MuiButton-containedPrimary, .components-button.is-primary, [class*="-primary"], [class*="-brand"]' );
			for ( var i = 0; i < btns.length; i++ ) { vote( parse( window.getComputedStyle( btns[ i ] ).backgroundColor ), 2 ); }
			// Coloured badges / pills / CTA-named elements often paint with the host's
			// primary brand — soft vote (weight 1) on their background. Capped at 200
			// to stay cheap on dense lists (e.g. WooCommerce product tables).
			var badges = root.querySelectorAll( '.badge, .tag, .label, .pill, [class*="-badge"], [class*="-cta"], [class*="-action"]' );
			for ( var b = 0; b < badges.length && b < 200; b++ ) { vote( parse( window.getComputedStyle( badges[ b ] ).backgroundColor ), 1 ); }
			var links = root.querySelectorAll( 'a' );
			for ( var j = 0; j < links.length && j < 400; j++ ) { vote( parse( window.getComputedStyle( links[ j ] ).color ), 1 ); }
			var bestK = null, best = 0;
			for ( var k in votes ) { if ( votes[ k ] > best ) { best = votes[ k ]; bestK = k; } }
			return ( bestK && best >= 3 ) ? rep[ bestK ] : null;
		} catch ( e ) { return null; }
	}
	function brandClasses( s, add ) {
		if ( ! BRAND ) { return; }
		var bg = parse( s.backgroundColor );
		if ( bg && bg.a >= 0.7 && near( bg, BRAND, 26 ) ) { add.push( C.brandBg ); }
		var col = parse( s.color );
		if ( col && col.a >= 0.7 && near( col, BRAND, 26 ) ) { add.push( C.brandFg ); }
		var sides = [ 'Top', 'Right', 'Bottom', 'Left' ];
		for ( var i = 0; i < 4; i++ ) {
			if ( parseFloat( s[ 'border' + sides[ i ] + 'Width' ] ) > 0 ) {
				var bc = parse( s[ 'border' + sides[ i ] + 'Color' ] );
				if ( bc && bc.a >= 0.5 && near( bc, BRAND, 26 ) ) { add.push( C.brandBd ); break; }
			}
		}
	}

	function hardSkip( el ) {
		if ( SKIP_TAGS.test( el.tagName ) ) { return true; }
		return !! ( el.closest && el.closest( HARD_SKIP ) );
	}

	function tag( el ) {
		if ( el.nodeType !== 1 || el.__akAuto ) { return; }
		el.__akAuto = 1;
		if ( hardSkip( el ) ) { return; }
		try {
			var s = window.getComputedStyle( el );
			var add = [];
			// Modal-like container root — additive tag for the elevated surface + lift
			// treatment in dark mode (see .ak-auto-modal in auto-theme.css). Descendants
			// still get classified normally by the rest of this function.
			if ( el.matches && el.matches( MODAL_SEL ) ) { add.push( C.modal ); }
			var bgCls = null;
			var btn = el.closest && el.closest( ACTION_BTN );
			var special = el.tagName === 'INPUT' && SPECIAL_TYPES.test( el.type || '' );

			// Background. Buttons & form fields are included now (so white secondary
			// buttons / inputs get themed), but a button only ever takes a NEUTRAL
			// surface — a vivid / tinted CTA fill is left for the brand pass.
			if ( ! special && s.backgroundImage === 'none' ) {
				bgCls = classify( parse( s.backgroundColor ), 'bg', el );
				if ( bgCls && btn && bgCls !== C.surface && bgCls !== C.elevated ) { bgCls = null; }
				if ( bgCls ) { add.push( bgCls ); }
			}
			// Text — on a button, only when we darkened its surface (keep CTA contrast).
			if ( ! btn || bgCls === C.surface || bgCls === C.elevated ) {
				var tc = classify( parse( s.color ), 'text', el );
				if ( tc ) { add.push( tc ); }
			}
			// Borders (neutral/light only — coloured borders are left by classify).
			var bd = classifyBorders( s );
			if ( bd ) { add.push( bd ); }
			// Any shadow on a darkened surface → drop the halo. In dark mode a light,
			// dark, or coloured drop all read wrong on the new low-lightness panel;
			// real elevation is signalled via --ak-shadow-elevated where we apply it.
			if ( ( bgCls === C.surface || bgCls === C.elevated ) && s.boxShadow && s.boxShadow !== 'none' ) { add.push( C.noshadow ); }
			// Brand unification (all elements, incl. buttons).
			if ( BRAND ) { brandClasses( s, add ); el.__akBrand = 1; }

			for ( var i = 0; i < add.length; i++ ) { el.classList.add( add[ i ] ); }
		} catch ( e ) { /* one odd element must never break the page or abort the scan */ }
	}

	// Brand-only re-pass, for when the brand is detected AFTER the first scan
	// already flagged everything (React apps mount their buttons late).
	function applyBrand( el ) {
		if ( el.nodeType !== 1 || el.__akBrand ) { return; }
		el.__akBrand = 1;
		if ( hardSkip( el ) ) { return; }
		try {
			var add = [];
			brandClasses( window.getComputedStyle( el ), add );
			for ( var i = 0; i < add.length; i++ ) { el.classList.add( add[ i ] ); }
		} catch ( e ) { /* never let one element break the brand pass */ }
	}
	function brandPass( root ) {
		if ( ! BRAND || ! root || root.nodeType !== 1 ) { return; }
		applyBrand( root );
		var els = root.querySelectorAll( '*' );
		for ( var i = 0; i < els.length; i++ ) { applyBrand( els[ i ] ); }
	}

	// Hover / focus discovery — pseudo-classes can't be read from a resting-state
	// scan (getComputedStyle ignores :hover etc.), so we walk the loaded stylesheets
	// once and find rules whose selector carries :hover/:focus/:active AND declares
	// a LIGHT background. Elements those rules match get tagged .ak-auto-hoverable-light;
	// the dark companion sheet overrides their interaction-state bg to --ak-hover-bg.
	// CORS-blocked sheets (cross-origin without CORS headers) silently skip. Invalid
	// selectors silently skip. One-shot at boot — ~50ms on a typical plugin page.
	function scanHoverRules() {
		try {
			for ( var i = 0; i < document.styleSheets.length; i++ ) {
				var rules = null;
				try { rules = document.styleSheets[ i ].cssRules; } catch ( e ) { continue; }
				if ( ! rules ) { continue; }
				for ( var j = 0; j < rules.length; j++ ) {
					var r = rules[ j ];
					if ( r.type !== 1 ) { continue; } // STYLE_RULE only
					var sel = r.selectorText || '';
					if ( ! /:hover|:focus|:focus-visible|:active/.test( sel ) ) { continue; }
					var bg = r.style && ( r.style.backgroundColor || r.style.background );
					if ( ! bg ) { continue; }
					var c = parse( bg );
					if ( ! c || c.a < 0.5 || hslL( c ) < 80 ) { continue; }
					var base = sel.replace( /:hover|:focus-visible|:focus|:active/g, '' ).trim();
					if ( ! base ) { continue; }
					try {
						var matches = document.querySelectorAll( base );
						for ( var k = 0; k < matches.length; k++ ) {
							matches[ k ].classList.add( C.hoverable );
						}
					} catch ( e2 ) { /* invalid selector — skip */ }
				}
			}
		} catch ( e ) { /* never block on this */ }
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

	// Standalone admin pages (setup wizards: Rank Math, WooCommerce…) render their
	// own full <body> and print only their own stylesheet — so AdminKit's token +
	// paint sheets never load, and body.adminkit is absent. The engine's JS DOES
	// load there (wizards print all head/footer scripts), so it self-injects the
	// two sheets it needs when they're missing. A no-op on normal pages (the
	// presence check finds the already-enqueued <link>). The theme attribute is set
	// by AdminKit's head pre-paint script, which those wizards also print.
	function ensureSheet( href, marker ) {
		if ( ! href || document.querySelector( 'link[rel="stylesheet"][href*="' + marker + '"]' ) ) { return; }
		try {
			var l = document.createElement( 'link' );
			l.rel = 'stylesheet';
			l.href = href;
			( document.head || document.documentElement ).appendChild( l );
		} catch ( e ) { /* nothing we can do */ }
	}

	function boot() {
		ensureSheet( D.tokensHref, 'assets/css/tokens.css' );
		ensureSheet( D.cssHref, 'assets/css/wp-core/auto-theme.css' );
		if ( BRAND_ON ) {
			AKPRIMARY = resolveToken( '--ak-primary' );
			BRAND = detectBrand( scope );
		}
		scanChunked( scope );
		scanHoverRules();
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
