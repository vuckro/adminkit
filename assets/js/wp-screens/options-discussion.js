/**
 * AdminKit — options-discussion.php: 2-tab split (Avatars + Comment settings).
 *
 *   Tab 1 (default):  Avatars            — show_avatars, avatar_rating, avatar_default
 *   Tab 2:           Comment settings    — every other .form-table on the page
 *
 * Routing is by INPUT NAME (locale-proof). Each `<h2>` + `.form-table` pair
 * gets moved into the matching tab panel as a unit. Anything outside a
 * recognised pair (e.g. hidden nonce inputs) stays in place — the form
 * itself isn't restructured, so submission still posts every field.
 *
 * Defensive: no anti-FOUC hide. The page renders WP-default first, then we
 * reorganise. The URL hash gets updated only when the user actively clicks
 * a tab (or arrives with a hash already set), so landing on the page with
 * a bare URL does NOT trigger an auto-scroll.
 *
 * Strings ride via `window.AdminKitOptionsDiscussion`.
 */
(function () {
	'use strict';

	var form = document.querySelector( '.wrap > form[action="options.php"]' );
	if ( ! form || form.dataset.akTabbed ) { return; }
	form.dataset.akTabbed = '1';

	var S = window.AdminKitOptionsDiscussion || {};

	// Walk every top-level form-table. A table is "avatar-related" when it
	// contains one of the three known avatar inputs; everything else routes
	// to the Comment settings tab.
	function hasAvatarInputs( table ) {
		return !! ( table.querySelector( 'input[name="show_avatars"]' ) ||
		            table.querySelector( 'input[name="avatar_rating"]' ) ||
		            table.querySelector( 'input[name="avatar_default"]' ) );
	}

	var tablesInForm = form.querySelectorAll( ':scope > table.form-table' );
	if ( ! tablesInForm.length ) { return; }

	// Build the tab strip + panels container, then insert it before the
	// first table so anything WP printed earlier (hidden settings_fields
	// inputs) stays in place above us.
	var box      = document.createElement( 'div' );
	box.className = 'ak-options-tabs';
	var tabsEl   = document.createElement( 'div' );
	tabsEl.className = 'ak-options-tabs__strip';
	tabsEl.setAttribute( 'role', 'tablist' );
	var panelsEl = document.createElement( 'div' );
	panelsEl.className = 'ak-options-tabs__panels';
	box.appendChild( tabsEl );
	box.appendChild( panelsEl );
	tablesInForm[ 0 ].parentNode.insertBefore( box, tablesInForm[ 0 ] );

	// Avatars FIRST — that's the focused single-purpose tab, opens directly
	// on a single small card. Comment settings is the long bucket.
	var TABS = [
		{ id: 'avatars',  title: S.avatars  || 'Avatars' },
		{ id: 'comments', title: S.comments || 'Comment settings' }
	];

	TABS.forEach( function ( t ) {
		var panel = document.createElement( 'div' );
		panel.id = t.id;
		panel.className = 'ak-options-tabs__panel';
		panel.setAttribute( 'role', 'tabpanel' );
		panelsEl.appendChild( panel );
		t.panel = panel;
	} );

	// Move each table + its preceding `<h2>` (if any) into the right panel.
	Array.prototype.forEach.call( tablesInForm, function ( table ) {
		var target = hasAvatarInputs( table ) ? 'avatars' : 'comments';
		var panel  = TABS.filter( function ( t ) { return t.id === target; } )[ 0 ].panel;

		// Optional title — the closest preceding `<h2>` sibling, if there's
		// no other table-shaped thing in between. WP ships one
		// (`<h2>Avatars</h2>` before the avatars table); the comment-settings
		// table has no preceding h2.
		var prev = table.previousElementSibling;
		while ( prev ) {
			if ( prev.tagName === 'H2' ) { panel.appendChild( prev ); break; }
			if ( prev.tagName === 'TABLE' || prev.tagName === 'INPUT' ) { break; }
			prev = prev.previousElementSibling;
		}
		panel.appendChild( table );
	} );

	// Drop any panel that ended up empty (e.g. a site whose plugins removed
	// every avatar input).
	var made = TABS.filter( function ( t ) { return t.panel.children.length > 0; } );
	if ( ! made.length ) {
		box.parentNode.removeChild( box );
		return;
	}

	// Build the tab buttons. Single text label per button — no icons, no
	// roving tabindex; this is a 2-tab strip, not a navigation epic.
	made.forEach( function ( t ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.dataset.target = t.id;
		btn.className = 'ak-options-tabs__btn';
		btn.setAttribute( 'role', 'tab' );
		btn.textContent = t.title;
		tabsEl.appendChild( btn );
		t.btn = btn;
	} );

	function activate( id, opts ) {
		made.forEach( function ( t ) {
			var on = t.id === id;
			t.btn.classList.toggle( 'is-active', on );
			t.btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.panel.hidden = ! on;
		} );
		// Only touch the URL hash when the activation came from a user
		// click / hashchange — never from the initial mount with a bare URL,
		// otherwise the browser auto-scrolls to the matching anchor on load.
		if ( opts && opts.updateHash && location.hash.slice( 1 ) !== id ) {
			if ( history.replaceState ) {
				history.replaceState( null, '', '#' + id );
			} else {
				location.hash = id;
			}
		}
	}

	tabsEl.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( 'button' );
		if ( b && b.dataset.target ) { activate( b.dataset.target, { updateHash: true } ); }
	} );

	function tabFor( slug ) {
		for ( var i = 0; i < made.length; i++ ) {
			if ( made[ i ].id === slug ) { return slug; }
		}
		return null;
	}

	// Initial activation: a URL hash wins (deep link from docs / bookmark),
	// otherwise Avatars (first). When there's no hash, DON'T update the
	// URL — that's what prevents the auto-scroll.
	var initialHash = location.hash.slice( 1 );
	var initialId   = tabFor( initialHash ) || made[ 0 ].id;
	activate( initialId, { updateHash: !! initialHash && initialHash === initialId } );

	window.addEventListener( 'hashchange', function () {
		var id = tabFor( location.hash.slice( 1 ) );
		if ( id ) { activate( id, { updateHash: false } ); }
	} );
})();
