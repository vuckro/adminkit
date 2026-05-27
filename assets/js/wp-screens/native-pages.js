/**
 * AdminKit — native WP-admin pages: a11y polish for nav-tab-wrapper.
 *
 * WordPress emits the page-level tab strip on options-permalink.php (and a
 * few other native screens) as a plain `<a class="nav-tab">` list inside a
 * `<h2 class="nav-tab-wrapper">`. There's no `role="tablist"` / `role="tab"`
 * / `aria-selected` plumbing — screen readers announce it as "heading +
 * links," which is fine but a step down from the segmented-control semantics
 * AdminKit uses everywhere else.
 *
 * We don't alter behaviour. WordPress's native server-side tab switching
 * (`?tab=…`) stays the source of truth: clicking a link reloads with the
 * matching query string and WP re-renders with the active class. We just
 * pin the right ARIA attributes on top of the existing markup so the
 * keyboard / AT story matches the visual pills the CSS draws.
 */
( function () {
	'use strict';

	function annotate() {
		var lists = document.querySelectorAll( '.adminkit-native-page .nav-tab-wrapper' );
		for ( var i = 0; i < lists.length; i++ ) {
			var list = lists[ i ];
			if ( list.dataset.akA11y === '1' ) { continue; }
			list.setAttribute( 'role', 'tablist' );
			var tabs = list.querySelectorAll( '.nav-tab' );
			for ( var j = 0; j < tabs.length; j++ ) {
				var t = tabs[ j ];
				t.setAttribute( 'role', 'tab' );
				t.setAttribute( 'aria-selected', t.classList.contains( 'nav-tab-active' ) ? 'true' : 'false' );
			}
			list.dataset.akA11y = '1';
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', annotate );
	} else {
		annotate();
	}
}() );
