/**
 * AdminKit — options-general.php: themed blocks + grouped tab navigation.
 *
 * General: site identity and site icon.
 * Account & registration: administration email, membership and default role.
 * Language, date & time: language/timezone and display formats in separate cards.
 *
 * Each block gets its own card (heading + one-line description + nested
 * `.form-table`). Blocks are tagged with `data-ak-tab` so the tab strip
 * (rendered as a WP-native `.nav-tab-wrapper` for chrome-coherence with
 * the Discussion page + Profile tabs) just flips `hidden` to switch
 * panels — same mark-and-toggle pattern as options-discussion.js.
 *
 * The PHP module adds a pre-paint `.ak-options-pending` marker so the raw
 * one-table layout does not flash before this footer script reorganises it.
 * URL hash updates only on user-initiated clicks, so a bare URL never
 * auto-scrolls. If the page renders only one tab's worth of content (e.g.
 * WPLANG missing → locale empty), the tab strip is skipped and blocks render
 * flat.
 *
 * Strings ride via `window.AdminKitOptionsGeneral`.
 */
(function () {
	'use strict';

	var root = document.documentElement;
	function reveal() {
		root.classList.remove( 'ak-options-pending' );
		root.classList.add( 'ak-options-ready' );
	}

	var form = document.querySelector( '.wrap > form[action="options.php"]' );
	if ( ! form || form.dataset.akGrouped ) { reveal(); return; }
	form.dataset.akGrouped = '1';

	var S = window.AdminKitOptionsGeneral || {};

	// Field NAMES per block, in display order. `tab` picks the top-level
	// tab the block belongs to. Match by `input[name="…"]` so translated
	// labels never break detection. Missing rows (e.g. WPLANG on a site
	// without translations) are silently skipped.
	var BLOCKS = [
		{ id: 'site-identity', tab: 'general', title: S.identity,          desc: S.identityDesc || '', rows: [ 'blogname', 'blogdescription', 'siteurl', 'home' ] },
		{ id: 'site-icon',     tab: 'general', title: S.siteIcon,              desc: S.siteIconDesc || '', rows: [ 'site_icon' ] },
		{ id: 'admin-logo', tab: 'general', title: S.logo, desc: S.logoDesc, rows: [ 'adminkit_general_logo_url', 'adminkit_settings[logo_url]' ] },
		{ id: 'account',       tab: 'account', title: S.account, desc: S.accountDesc  || '', rows: [ 'admin_email', 'new_admin_email', 'users_can_register', 'default_role' ] },
		{ id: 'locale',        tab: 'locale',  title: S.languageZone, desc: S.languageZoneDesc || '', rows: [ 'WPLANG', 'timezone_string' ] },
		{ id: 'formats',       tab: 'locale',  title: S.formats, desc: S.formatsDesc || '', rows: [ 'date_format', 'time_format', 'start_of_week' ] }
	];

	var TABS = [
		{ id: 'general', title: S.tabGeneral },
		{ id: 'locale',  title: S.tabLocale },
		{ id: 'account', title: S.tabAccount },
		{ id: 'other',   title: S.tabOther }
	];

	// Leading tab icons (Lucide-style, currentColor) so this strip reads like the
	// AdminKit settings tabs. Styled via `.nav-tab .ak-nav-tab-ic` in chrome.css.
	var ICONS = {
		general: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
		locale:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>'
	};

	ICONS.account = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/></svg>';
	ICONS.other = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>';

	var sourceTable = form.querySelector( ':scope > .form-table' );
	if ( ! sourceTable ) { reveal(); return; }

	// Build each block: heading + optional description + nested form-table
	// + scoop matching <tr>s. Track which blocks ended up populated so the
	// tab strip below can skip tabs whose blocks all turned up empty.
	var madeBlocks = []; // { el, tab }
	BLOCKS.forEach( function ( b ) {
		var section = document.createElement( 'section' );
		section.className = 'ak-options-block';
		// Namespace the panel id so a tab #hash (e.g. #locale) has no element to
		// scroll to — the hash drives the tab, it must not jump the page.
		section.id = 'ak-opt-' + b.id;
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

	// Wrap any leftover rows in sourceTable + any third-party `add_settings_section`
	// tables (`<h2>` + `<table class="form-table">`) into clean `.ak-options-block`
	// cards under the `other` tab so they never float ungrouped beneath every tab.
	var sourceBody = sourceTable.querySelector( 'tbody' );
	if ( sourceBody && ! sourceBody.querySelector( 'tr' ) ) {
		sourceTable.parentNode.removeChild( sourceTable );
	} else if ( sourceBody && sourceBody.querySelector( 'tr' ) ) {
		var defaultExtra = document.createElement( 'section' );
		defaultExtra.className = 'ak-options-block';
		defaultExtra.id = 'ak-opt-other-default';
		defaultExtra.dataset.akTab = 'other';
		var defaultHeading = document.createElement( 'h2' );
		defaultHeading.className = 'ak-options-block__title';
		defaultHeading.textContent = S.tabOther;
		defaultExtra.appendChild( defaultHeading );
		sourceTable.parentNode.insertBefore( defaultExtra, sourceTable );
		defaultExtra.appendChild( sourceTable );
		madeBlocks.push( { el: defaultExtra, tab: 'other' } );
	}

	var extraIdx = 0;
	Array.prototype.slice.call( form.querySelectorAll( ':scope > table.form-table' ) ).forEach( function ( tbl ) {
		if ( ! tbl.querySelector( 'tr' ) ) { return; }
		var card = document.createElement( 'section' );
		card.className = 'ak-options-block';
		card.id = 'ak-opt-other-' + ( ++extraIdx );
		card.dataset.akTab = 'other';
		var before = [];
		var prev = tbl.previousElementSibling;
		while ( prev && prev.tagName !== 'TABLE' && ! prev.hasAttribute( 'data-ak-tab' ) && ! ( prev.tagName === 'INPUT' && prev.getAttribute( 'type' ) === 'hidden' ) && ! prev.classList.contains( 'submit' ) ) {
			before.unshift( prev );
			prev = prev.previousElementSibling;
		}
		var hasHeading = before.some( function ( node ) {
			if ( node.tagName === 'H2' || node.tagName === 'H3' ) {
				node.classList.add( 'ak-options-block__title' );
				return true;
			}
			if ( node.tagName === 'P' ) {
				node.classList.add( 'ak-options-block__desc' );
			}
			return false;
		} );
		if ( ! hasHeading ) {
			var fallbackH2 = document.createElement( 'h2' );
			fallbackH2.className = 'ak-options-block__title';
			fallbackH2.textContent = S.tabOther;
			card.appendChild( fallbackH2 );
		}
		tbl.parentNode.insertBefore( card, tbl );
		before.forEach( function ( node ) { card.appendChild( node ); } );
		card.appendChild( tbl );
		madeBlocks.push( { el: card, tab: 'other' } );
	} );

	// Tab strip — only render when both tabs ended up populated. Otherwise
	// fall through to a flat stack of blocks (still functional, just no
	// switcher).
	var made = TABS.filter( function ( t ) {
		return madeBlocks.some( function ( b ) { return b.tab === t.id; } );
	} );
	if ( made.length < 2 ) { reveal(); return; }

	var strip = document.createElement( 'nav' );
	strip.className = 'nav-tab-wrapper';
	strip.setAttribute( 'role', 'tablist' );
	strip.setAttribute( 'aria-label', document.title.split( ' ‹ ' )[ 0 ] );
	made.forEach( function ( t ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.dataset.target = t.id;
		btn.className = 'nav-tab';
		btn.setAttribute( 'role', 'tab' );
		btn.id = 'ak-options-tab-' + t.id;
		btn.setAttribute( 'aria-controls', 'ak-options-panel-' + t.id );
		if ( ICONS[ t.id ] ) {
			var ic = document.createElement( 'span' );
			ic.className = 'ak-nav-tab-ic';
			ic.setAttribute( 'aria-hidden', 'true' );
			ic.innerHTML = ICONS[ t.id ];
			btn.appendChild( ic );
		}
		btn.appendChild( document.createTextNode( t.title ) );
		strip.appendChild( btn );
		t.btn = btn;
	} );

	// Place the strip before the first tagged block (so it sits at the top
	// of the form's visible content, just after WP's hidden nonce inputs).
	var firstTagged = form.querySelector( '[data-ak-tab]' );
	if ( firstTagged ) { form.insertBefore( strip, firstTagged ); }
	// Group each existing tab's nodes once; native fields stay inside the form.
	made.forEach( function ( t ) {
		t.panel = document.createElement( 'div' );
		t.panel.className = 'ak-options-panel';
		t.panel.id = 'ak-options-panel-' + t.id;
		t.panel.setAttribute( 'role', 'tabpanel' );
		t.panel.setAttribute( 'aria-labelledby', t.btn.id );
		form.insertBefore( t.panel, firstTagged );
	} );
	madeBlocks.forEach( function ( b ) {
		made.forEach( function ( t ) { if ( t.id === b.tab ) { t.panel.appendChild( b.el ); } } );
	} );


	function activate( id, opts ) {
		made.forEach( function ( t ) {
			var on = t.id === id;
			t.btn.classList.toggle( 'nav-tab-active', on );
			t.btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.btn.tabIndex = on ? 0 : -1;
			t.panel.hidden = ! on;
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


	strip.addEventListener( 'keydown', function ( e ) {
		if ( ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf( e.key ) === -1 ) { return; }
		var i = made.findIndex( function ( t ) { return t.btn === document.activeElement; } );
		if ( i < 0 ) { return; }
		var next = e.key === 'Home' ? 0 : e.key === 'End' ? made.length - 1 :
			( i + ( e.key === 'ArrowRight' ? 1 : made.length - 1 ) ) % made.length;
		e.preventDefault();
		activate( made[ next ].id, { updateHash: true } );
		made[ next ].btn.focus();
	} );
	// Native validation runs before submit; reveal only the first invalid field.
	var revealingInvalid = false;
	form.addEventListener( 'invalid', function ( e ) {
		if ( revealingInvalid ) { return; }
		revealingInvalid = true;
		setTimeout( function () { revealingInvalid = false; }, 0 );
		var panel = e.target.closest( '[data-ak-tab]' );
		if ( panel ) { activate( panel.dataset.akTab, { updateHash: true } ); }
	}, true );

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

	// Stay on the same tab after Save. The #hash never reaches the server, so WP
	// would redirect to the bare page (first tab). Carry the active hash on the
	// referer field; options.php re-appends it (add_query_arg keeps the fragment),
	// landing back on the same tab.
	form.addEventListener( 'submit', function () {
		var ref = form.querySelector( 'input[name="_wp_http_referer"]' );
		if ( ref && location.hash ) {
			ref.value = ref.value.split( '#' )[ 0 ] + location.hash;
		}
	} );

	reveal();
})();

(function () {
	'use strict';

	var input = document.getElementById( 'ak-general-logo-url' );
	if ( ! input ) { return; }
	var preview = document.getElementById( 'ak-general-logo-preview' );
	var remove = document.getElementById( 'ak-general-logo-remove' );

	function sync() {
		var url = input.value.trim();
		preview.classList.toggle( 'ak-logo-dynamic', /\.svg(?:[?#]|$)/i.test( url ) );
		preview.hidden = ! url;
		remove.disabled = ! url;
		if ( url ) { preview.src = url; }
		else { preview.removeAttribute( 'src' ); }
	}

	input.addEventListener( 'change', sync );
	remove.addEventListener( 'click', function () { input.value = ''; sync(); } );
	var uploadBtn = document.getElementById( 'ak-general-logo-upload' );
	uploadBtn.addEventListener( 'click', function () {
		if ( ! window.wp || ! wp.media ) { return; }
		var uploadNonce = uploadBtn.getAttribute( 'data-upload-nonce' ) || '';
		var frameOpts = { multiple: false, library: { type: 'image' } };
		if ( uploadNonce ) {
			frameOpts.uploader = { params: { adminkit_logo_upload: '1', _ak_logo_nonce: uploadNonce } };
		}
		var frame = wp.media( frameOpts );
		frame.on( 'open', function () {
			if ( uploadNonce && frame.uploader && frame.uploader.uploader && frame.uploader.uploader.param ) {
				frame.uploader.uploader.param( 'adminkit_logo_upload', '1' );
				frame.uploader.uploader.param( '_ak_logo_nonce', uploadNonce );
			}
		} );
		frame.on( 'select', function () {
			var selected = frame.state().get( 'selection' ).first();
			if ( selected ) { input.value = selected.toJSON().url; sync(); }
		} );
		frame.open();
	} );
	sync();
})();
