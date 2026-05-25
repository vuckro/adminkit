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

	function canvasDoc() {
		var f = document.querySelector( 'iframe[name="editor-canvas"]' );
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
		if ( ! doc || ! doc.head || ! doc.documentElement ) {
			return;
		}
		var root = doc.documentElement;
		// Inject the stylesheets once per (re)mounted document.
		if ( ! root.hasAttribute( MARK ) ) {
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
		// Keep the canvas mode in sync with the parent (cheap, idempotent).
		root.setAttribute( ATTR, mode() );
	}

	// Instant flip when the parent toggle changes.
	new MutationObserver( tick ).observe(
		document.documentElement,
		{ attributes: true, attributeFilter: [ ATTR ] }
	);

	// Poll handles first mount + iframe re-creation; tick() is idempotent.
	setInterval( tick, 500 );
	tick();
}() );
