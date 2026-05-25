/**
 * Gutenberg canvas theming bridge.
 *
 * The block-editor canvas is a separate <iframe> document — the editor-chrome
 * CSS and the page's `data-adminkit-theme` attribute don't reach it. This script
 * (running in the parent) reaches into the iframe to:
 *   1. inject AdminKit's token stylesheets + canvas.css as <link>s in its <head>,
 *      so `--ak-*` resolve inside the canvas;
 *   2. mirror the parent's theme attribute onto the iframe <html>, so the same
 *      `:root[data-adminkit-theme="dark"]` token block flips the canvas.
 *
 * Config comes from `window.AdminKitCanvas` (printed by class-gutenberg.php):
 *   { attr: 'data-adminkit-theme', styles: [ '…/waaskit-tokens.css?ver=…', … ] }
 *
 * Only loaded when the "Gutenberg" feature (editor_content_theme) is ON.
 * Reads the parent attribute, never writes it — no loop with the toggle handler.
 * The poll covers first mount + Gutenberg re-creating the iframe (device preview,
 * code-editor toggle); inject is idempotent via a marker on the iframe <html>.
 */
( function () {
	var cfg   = window.AdminKitCanvas || {};
	var ATTR  = cfg.attr || 'data-adminkit-theme';
	var HREFS = cfg.styles || [];
	var MARK  = 'data-adminkit-canvas'; // marks an iframe document we've themed

	function mode() {
		return document.documentElement.getAttribute( ATTR ) === 'dark' ? 'dark' : 'light';
	}

	// Resolve the parent's current --ak-bg to a concrete colour, so the canvas can
	// paint it SYNCHRONOUSLY (inline) before the injected token stylesheets finish
	// loading — that async gap is what flashed white in dark mode. Cached per mode.
	var bgCache = {};
	function darkBg() {
		if ( bgCache.dark ) {
			return bgCache.dark;
		}
		var probe = document.createElement( 'div' );
		probe.style.cssText = 'position:absolute;visibility:hidden;background-color:var(--ak-bg)';
		document.documentElement.appendChild( probe );
		var c = getComputedStyle( probe ).backgroundColor;
		probe.remove();
		// Only cache once it resolves to a real colour (tokens loaded in the parent).
		if ( c && 'rgba(0, 0, 0, 0)' !== c && 'transparent' !== c ) {
			bgCache.dark = c;
		}
		return c;
	}

	function canvasFrame() {
		return document.querySelector( 'iframe[name="editor-canvas"]' );
	}
	function canvasDoc() {
		var f = canvasFrame();
		if ( ! f ) {
			return null;
		}
		try {
			return f.contentDocument || null; // same-origin (srcdoc/about:blank)
		} catch ( e ) {
			return null;
		}
	}

	function tick() {
		var doc = canvasDoc();
		if ( ! doc || ! doc.documentElement ) {
			return;
		}
		var root = doc.documentElement;

		// 1) Paint the canvas background IMMEDIATELY (inline, synchronous) in dark
		//    mode, so nothing flashes white while the injected stylesheets load.
		//    Cleared in light mode — the canvas stays light, like the front end.
		var bg = ( 'dark' === mode() ) ? darkBg() : '';
		root.style.backgroundColor = bg;
		if ( doc.body ) {
			doc.body.style.backgroundColor = bg;
		}

		// 2) Mirror the mode attribute — drives the --ak-* dark block once tokens load.
		root.setAttribute( ATTR, mode() );

		// 3) Inject the token + canvas stylesheets once per (re)mounted document.
		if ( doc.head && ! root.hasAttribute( MARK ) ) {
			HREFS.forEach( function ( href ) {
				if ( ! href ) {
					return;
				}
				var link = doc.createElement( 'link' );
				link.rel = 'stylesheet';
				link.href = href;
				link.setAttribute( MARK, '' );
				doc.head.appendChild( link );
			} );
			root.setAttribute( MARK, '' );
		}

		// 4) Tag the iframe <body> so `body.adminkit …` rules apply inside the canvas:
		// the --wp-* accent remap (tokens.css) + the @wordpress/components theming, so
		// block placeholders, buttons and inputs match the chrome, not WP blue.
		if ( doc.body && ! doc.body.classList.contains( 'adminkit' ) ) {
			doc.body.classList.add( 'adminkit' );
		}
	}

	// Instant flip when the parent toggle changes.
	new MutationObserver( tick ).observe(
		document.documentElement,
		{ attributes: true, attributeFilter: [ ATTR ] }
	);

	// Catch the iframe being (re)created — first mount, device preview, code-editor
	// toggle — as early as possible, and bind its `load` so we paint before it shows.
	var bound;
	function watch() {
		var f = canvasFrame();
		if ( f && f !== bound ) {
			bound = f;
			f.addEventListener( 'load', tick );
		}
		tick();
	}
	new MutationObserver( function () {
		var f = canvasFrame();
		if ( f && f !== bound ) {
			watch();
		}
	} ).observe( document.body || document.documentElement, { childList: true, subtree: true } );

	// Fallback poll (idempotent) for anything the observers miss.
	setInterval( watch, 500 );
	watch();
}() );
