/**
 * AdminKit — unified Tools navigation.
 *
 * Renders ONE pill tab strip across the built-in Tools screens (Available Tools,
 * Import, Export, Site Health, and the personal-data Export / Erase pages) so the
 * whole Tools section reads as a single tabbed app. Each tab is a plain link to
 * its native screen — NO content is moved or fetched, so every screen keeps its
 * own markup, forms and handlers. Robust + progressive: with JS off, the native
 * pages are completely unchanged.
 *
 * The tab list (id, label, url), the current screen id and the aria-label arrive
 * via `window.AdminKitTools` (an inline bootstrap from AdminKit_Core_Chrome). The
 * strip is inserted right after the screen's <h1>. Footer script, loaded only on
 * the Tools screens.
 */
( function () {
	var D = window.AdminKitTools || {};
	var tabs = D.tabs || [];
	var current = D.current || '';
	if ( tabs.length < 2 ) { return; }

	var wrap = document.querySelector( '.wrap' );
	if ( ! wrap ) { return; }

	var nav = document.createElement( 'nav' );
	nav.className = 'ak-tabs ak-tools-tabs';
	nav.setAttribute( 'aria-label', D.nav || 'Tools' );

	tabs.forEach( function ( t ) {
		var a = document.createElement( 'a' );
		var on = t.id === current;
		a.className = 'ak-tools-tab' + ( on ? ' on' : '' );
		a.href = t.url;
		a.textContent = t.label;
		if ( on ) { a.setAttribute( 'aria-current', 'page' ); }
		nav.appendChild( a );
	} );

	// Insert just after the screen's heading so the native page body is untouched.
	var h1 = wrap.querySelector( 'h1' );
	if ( h1 ) { h1.insertAdjacentElement( 'afterend', nav ); }
	else { wrap.insertBefore( nav, wrap.firstChild ); }
}() );
