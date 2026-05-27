/**
 * AdminKit — options-general.php: themed blocks + 2-tab navigation.
 *
 * Tab 1 — Général:
 *   • Site identity        — blogname, blogdescription, siteurl, home
 *   • Site Icon            — site_icon (media-picker widget)
 *   • Account & registration — admin_email, new_admin_email,
 *                              users_can_register, default_role
 *
 * Tab 2 — Language, date & time:
 *   • Locale               — WPLANG, timezone_string, date_format,
 *                            time_format, start_of_week
 *
 * Each block gets its own card (heading + one-line description + nested
 * `.form-table`). Blocks are tagged with `data-ak-tab` so the tab strip
 * (rendered as a WP-native `.nav-tab-wrapper` for chrome-coherence with
 * the Discussion page + Profile tabs) just flips `hidden` to switch
 * panels — same mark-and-toggle pattern as options-discussion.js.
 *
 * Defensive: no anti-FOUC hide (the page renders WP-default first, then
 * we reorganise). URL hash updates only on user-initiated clicks, so a
 * bare URL never auto-scrolls. If the page renders only one tab's worth
 * of content (e.g. WPLANG missing → locale empty), the tab strip is
 * skipped entirely and blocks render flat.
 *
 * Strings ride via `window.AdminKitOptionsGeneral`.
 */
(function () {
	'use strict';

	var form = document.querySelector( '.wrap > form[action="options.php"]' );
	if ( ! form || form.dataset.akGrouped ) { return; }
	form.dataset.akGrouped = '1';

	var S = window.AdminKitOptionsGeneral || {};

	// Field NAMES per block, in display order. `tab` picks the top-level
	// tab the block belongs to. Match by `input[name="…"]` so translated
	// labels never break detection. Missing rows (e.g. WPLANG on a site
	// without translations) are silently skipped.
	var BLOCKS = [
		{ id: 'site-identity', tab: 'general', title: S.identity || 'Site identity',          desc: S.identityDesc || '', rows: [ 'blogname', 'blogdescription', 'siteurl', 'home' ] },
		{ id: 'site-icon',     tab: 'general', title: S.siteIcon || 'Site Icon',              desc: S.siteIconDesc || '', rows: [ 'site_icon' ] },
		{ id: 'account',       tab: 'general', title: S.account  || 'Account & registration', desc: S.accountDesc  || '', rows: [ 'admin_email', 'new_admin_email', 'users_can_register', 'default_role' ] },
		{ id: 'locale',        tab: 'locale',  title: S.locale   || 'Language, date & time',  desc: S.localeDesc   || '', rows: [ 'WPLANG', 'timezone_string', 'date_format', 'time_format', 'start_of_week' ] }
	];

	var TABS = [
		{ id: 'general', title: S.tabGeneral || 'General' },
		{ id: 'locale',  title: S.locale     || 'Language, date & time' }
	];

	var sourceTable = form.querySelector( ':scope > .form-table' );
	if ( ! sourceTable ) { return; }

	// Build each block: heading + optional description + nested form-table
	// + scoop matching <tr>s. Track which blocks ended up populated so the
	// tab strip below can skip tabs whose blocks all turned up empty.
	var madeBlocks = []; // { el, tab }
	BLOCKS.forEach( function ( b ) {
		var section = document.createElement( 'section' );
		section.className = 'ak-options-block';
		section.id = b.id;
		section.dataset.akTab = b.tab;

		var heading = document.createElement( 'h2' );
		heading.className = 'ak-options-block__title';
		heading.textContent = b.title;
		section.appendChild( heading );

		if ( b.desc ) {
			var desc = document.createElement( 'p' );
			desc.className = 'ak-options-block__desc';
			desc.textContent = b.desc;
			section.appendChild( desc );
		}

		var table = document.createElement( 'table' );
		table.className = 'form-table';
		var tbody = document.createElement( 'tbody' );
		table.appendChild( tbody );
		section.appendChild( table );

		b.rows.forEach( function ( name ) {
			var input = form.querySelector( '[name="' + name + '"]' );
			if ( ! input ) { return; }
			var tr = input.closest( 'tr' );
			if ( ! tr || tr.parentNode === tbody ) { return; }
			tbody.appendChild( tr );
		} );

		if ( tbody.children.length > 0 ) {
			sourceTable.parentNode.insertBefore( section, sourceTable );
			madeBlocks.push( { el: section, tab: b.tab } );
		}
	} );

	// Remove the source table if every row was scooped. Otherwise leave it
	// in place — there may be third-party rows we don't know about.
	var sourceBody = sourceTable.querySelector( 'tbody' );
	if ( sourceBody && ! sourceBody.querySelector( 'tr' ) ) {
		sourceTable.parentNode.removeChild( sourceTable );
	}

	// Tab strip — only render when both tabs ended up populated. Otherwise
	// fall through to a flat stack of blocks (still functional, just no
	// switcher).
	var made = TABS.filter( function ( t ) {
		return madeBlocks.some( function ( b ) { return b.tab === t.id; } );
	} );
	if ( made.length < 2 ) { return; }

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

	// Place the strip before the first tagged block (so it sits at the top
	// of the form's visible content, just after WP's hidden nonce inputs).
	var firstTagged = form.querySelector( '[data-ak-tab]' );
	if ( firstTagged ) { form.insertBefore( strip, firstTagged ); }

	function activate( id, opts ) {
		made.forEach( function ( t ) {
			var on = t.id === id;
			t.btn.classList.toggle( 'nav-tab-active', on );
			t.btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		// Toggle both `[hidden]` and the class (see the matching note in
		// options-discussion.js — `[hidden]` alone is fragile against
		// author rules that set `display` elsewhere up the cascade).
		madeBlocks.forEach( function ( b ) {
			var hide = b.tab !== id;
			b.el.hidden = hide;
			b.el.classList.toggle( 'ak-tab-hidden', hide );
		} );
		// Only touch the URL hash on user-initiated activations — never on
		// the initial mount with a bare URL (would auto-scroll).
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

	var initialHash = location.hash.slice( 1 );
	var initialId   = tabFor( initialHash ) || made[ 0 ].id;
	activate( initialId, { updateHash: !! initialHash && initialHash === initialId } );

	window.addEventListener( 'hashchange', function () {
		var id = tabFor( location.hash.slice( 1 ) );
		if ( id ) { activate( id, { updateHash: false } ); }
	} );
})();
