/**
 * AdminKit — options-discussion.php: 2-tab split (Avatars + Comment settings).
 *
 *   Tab 1 (default):  Avatars            — show_avatars, avatar_rating, avatar_default
 *   Tab 2:           Comment settings    — every other .form-table on the page
 *
 * Mark-and-toggle pattern: we never MOVE elements out of the form. Each
 * top-level `<h2>` + `.form-table` pair gets a `data-ak-tab` attribute
 * naming the tab it belongs to; clicking a tab button just flips `hidden`
 * on every element whose `data-ak-tab` doesn't match. This keeps the
 * tables as direct children of `<form>` so the card-chrome rule in
 * `assets/css/wp-screens/options.css` (`.wrap > form > .form-table`)
 * applies without a nested override.
 *
 * Routing is by INPUT NAME (locale-proof). The tab strip uses WP-native
 * `.nav-tab-wrapper` + `.nav-tab` classes so it inherits the polished
 * tab styling from `assets/css/wp-core/chrome.css` automatically — same
 * visual as the Profile page tabs.
 *
 * Defensive: no anti-FOUC hide (the page renders WP-default first, then
 * we tag + insert the strip). The URL hash is only updated on user-
 * initiated activations, so landing on a bare URL never auto-scrolls.
 *
 * Strings ride via `window.AdminKitOptionsDiscussion`.
 */
(function () {
	'use strict';

	var form = document.querySelector( '.wrap > form[action="options.php"]' );
	if ( ! form || form.dataset.akTabbed ) { return; }
	form.dataset.akTabbed = '1';

	var S = window.AdminKitOptionsDiscussion || {};

	// A form-table is "avatar-related" when it contains any of the three
	// known avatar inputs. Survives translation (matched by name=).
	function hasAvatarInputs( table ) {
		return !! ( table.querySelector( 'input[name="show_avatars"]' ) ||
		            table.querySelector( 'input[name="avatar_rating"]' ) ||
		            table.querySelector( 'input[name="avatar_default"]' ) );
	}

	var tablesInForm = form.querySelectorAll( ':scope > table.form-table' );
	if ( ! tablesInForm.length ) { return; }

	// Tag each form-table (and its preceding `<h2>`, when present) with the
	// tab it belongs to. The marked elements stay in their original DOM
	// position — we never move anything.
	var marked = []; // { el, tab }
	Array.prototype.forEach.call( tablesInForm, function ( table ) {
		var tab = hasAvatarInputs( table ) ? 'avatars' : 'comments';
		table.dataset.akTab = tab;
		marked.push( { el: table, tab: tab } );
		var prev = table.previousElementSibling;
		if ( prev && prev.tagName === 'H2' ) {
			prev.dataset.akTab = tab;
			marked.push( { el: prev, tab: tab } );
		}
	} );

	// Avatars FIRST per UX request — focused single-purpose tab, opens
	// directly on the small avatar card. Comment settings is the long bucket.
	var TABS = [
		{ id: 'avatars',  title: S.avatars  || 'Avatars' },
		{ id: 'comments', title: S.comments || 'Comment settings' }
	];

	// Drop a tab if the page has no matching content (defensive: a plugin
	// could remove every avatar input, leaving Avatars empty).
	var made = TABS.filter( function ( t ) {
		return marked.some( function ( m ) { return m.tab === t.id; } );
	} );
	if ( ! made.length ) { return; }

	// Tab strip — WP-native classes inherit chrome.css's nav-tab styling.
	var strip = document.createElement( 'nav' );
	strip.className = 'nav-tab-wrapper';
	strip.setAttribute( 'role', 'tablist' );

	made.forEach( function ( t ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.dataset.target = t.id;
		btn.className = 'nav-tab';
		btn.setAttribute( 'role', 'tab' );
		btn.textContent = t.title;
		strip.appendChild( btn );
		t.btn = btn;
	} );

	// Place the strip right before the first tagged element (so it sits at
	// the top of the form's visible content, just after WP's hidden nonce
	// inputs). The strip itself isn't tagged — it stays visible on every tab.
	form.insertBefore( strip, marked[ 0 ].el );

	function activate( id, opts ) {
		made.forEach( function ( t ) {
			var on = t.id === id;
			t.btn.classList.toggle( 'nav-tab-active', on );
			t.btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		marked.forEach( function ( m ) {
			m.el.hidden = ( m.tab !== id );
		} );
		// Only touch the URL hash on user-initiated activations — never on
		// the initial mount with a bare URL, otherwise the browser would
		// auto-scroll to the matching anchor.
		if ( opts && opts.updateHash && location.hash.slice( 1 ) !== id ) {
			if ( history.replaceState ) {
				history.replaceState( null, '', '#' + id );
			} else {
				location.hash = id;
			}
		}
	}

	strip.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( 'button' );
		if ( b && b.dataset.target ) { activate( b.dataset.target, { updateHash: true } ); }
	} );

	function tabFor( slug ) {
		for ( var i = 0; i < made.length; i++ ) {
			if ( made[ i ].id === slug ) { return slug; }
		}
		return null;
	}

	// Initial activation — URL hash wins, else Avatars (first).
	var initialHash = location.hash.slice( 1 );
	var initialId   = tabFor( initialHash ) || made[ 0 ].id;
	activate( initialId, { updateHash: !! initialHash && initialHash === initialId } );

	window.addEventListener( 'hashchange', function () {
		var id = tabFor( location.hash.slice( 1 ) );
		if ( id ) { activate( id, { updateHash: false } ); }
	} );
})();
