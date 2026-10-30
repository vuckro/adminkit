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
 * The PHP module adds a pre-paint `.ak-options-pending` marker so the raw
 * stacked form does not flash before this footer script tags + activates the
 * tabbed view. The URL hash is only updated on user-initiated activations, so
 * landing on a bare URL never auto-scrolls.
 *
 * Strings ride via `window.AdminKitOptionsDiscussion`.
 */
(function () {
	'use strict';

	var root = document.documentElement;
	function reveal() {
		root.classList.remove( 'ak-options-pending' );
		root.classList.add( 'ak-options-ready' );
	}

	var form = document.querySelector( '.wrap > form[action="options.php"]' );
	if ( ! form || form.dataset.akTabbed ) { reveal(); return; }
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
	if ( ! tablesInForm.length ) { reveal(); return; }

	// Tag each form-table — plus every content sibling that precedes it
	// (h2 heading, descriptive <p>, etc.) — with the tab the section
	// belongs to. The walker stops at a hard boundary: another table
	// (the previous section starts there) or a hidden <input> (WP's
	// settings_fields nonce block — shared, leave untagged so it stays
	// in every form). Tags only — nothing moves.
	var marked = []; // { el, tab }
	function isBoundary( el ) {
		if ( el.tagName === 'TABLE' ) { return true; }
		if ( el.tagName === 'INPUT' && el.getAttribute( 'type' ) === 'hidden' ) { return true; }
		// `[data-ak-tab]` already tagged on an earlier pass — its OWN section
		// owns it, don't re-claim.
		if ( el.hasAttribute( 'data-ak-tab' ) ) { return true; }
		return false;
	}
	Array.prototype.forEach.call( tablesInForm, function ( table ) {
		var tab = hasAvatarInputs( table ) ? 'avatars' : 'comments';
		table.dataset.akTab = tab;
		marked.push( { el: table, tab: tab } );
		var prev = table.previousElementSibling;
		while ( prev && ! isBoundary( prev ) ) {
			prev.dataset.akTab = tab;
			marked.unshift( { el: prev, tab: tab } );
			prev = prev.previousElementSibling;
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
	if ( ! made.length ) { reveal(); return; }

	// Tab strip — WP-native classes inherit chrome.css's nav-tab styling.
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
		btn.textContent = t.title;
		strip.appendChild( btn );
		t.btn = btn;
	} );

	// Place the strip right before the FIRST tagged element in DOM order
	// (a query, not marked[0] — the marked array's insertion order doesn't
	// guarantee DOM order after preceding section headings were collected).
	// The strip itself isn't tagged so it stays visible on every tab.
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
	marked.forEach( function ( m ) {
		made.forEach( function ( t ) { if ( t.id === m.tab ) { t.panel.appendChild( m.el ); } } );
	} );


	// Group existing rows by stable input names. Keep unknown/plugin rows in
	// their original table; no control is cloned, renamed or disabled.
	var commentsPanel = form.querySelector('#ak-options-panel-comments');
	if (commentsPanel) {
		var groups = [
			{ key: 'participation', desc: S.participationDesc || '', title: S.participation || 'Participation', names: ['default_comment_status', 'require_name_email'] },
			{ key: 'display', desc: S.displayDesc || '', title: S.display || 'Display', names: ['page_comments'] },
			{ key: 'notifications', desc: S.notificationsDesc || '', title: S.notifications || 'Notifications', names: ['comments_notify'] },
			{ key: 'moderation', desc: S.moderationDesc || '', title: S.moderation || 'Moderation & filtering', names: ['comment_moderation', 'comment_max_links', 'disallowed_keys'] }
		];
		var cards = document.createElement('div');
		cards.className = 'ak-discussion-cards';
		var source = commentsPanel.querySelector('table.form-table');
		if (source) {
			commentsPanel.insertBefore(cards, source);
			groups.forEach(function (group) {
				var rows = [];
				group.names.forEach(function (name) {
					var input = source.querySelector('[name="' + name + '"]');
					var row = input && input.closest('tr');
					if (row && rows.indexOf(row) === -1) rows.push(row);
				});
				if (!rows.length) return;
				var section = document.createElement('section');
				section.className = 'ak-options-block ak-discussion-card';
				section.dataset.group = group.key;
				var heading = document.createElement('h2');
				heading.className = 'ak-options-block__title';
				heading.id = 'ak-discussion-' + group.key;
				heading.textContent = group.title;
				section.setAttribute('aria-labelledby', heading.id);
				section.appendChild(heading);
				if (group.desc) {
					var intro = document.createElement('p');
					intro.className = 'ak-options-block__desc';
					intro.textContent = group.desc;
					section.appendChild(intro);
				}
				var table = document.createElement('table');
				table.className = 'form-table';
				var tbody = document.createElement('tbody');
				rows.forEach(function (row) { tbody.appendChild(row); });
				table.appendChild(tbody);
				section.appendChild(table);
				cards.appendChild(section);
			});
			if (!source.querySelector('tbody > tr')) source.remove();
		}
	}


	function activate( id, opts ) {
		made.forEach( function ( t ) {
			var on = t.id === id;
			t.btn.classList.toggle( 'nav-tab-active', on );
			t.btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.btn.tabIndex = on ? 0 : -1;
			t.panel.hidden = ! on;
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

	// Initial activation — URL hash wins, else Avatars (first).
	var initialHash = location.hash.slice( 1 );
	var initialId   = tabFor( initialHash ) || made[ 0 ].id;
	activate( initialId, { updateHash: !! initialHash && initialHash === initialId } );

	window.addEventListener( 'hashchange', function () {
		var id = tabFor( location.hash.slice( 1 ) );
		if ( id ) { activate( id, { updateHash: false } ); }
	} );
	form.addEventListener( 'submit', function () {
		var ref = form.querySelector( 'input[name="_wp_http_referer"]' );
		if ( ref && location.hash ) { ref.value = ref.value.split( '#' )[ 0 ] + location.hash; }
	} );
	reveal();
})();
