/**
 * AdminKit — Notification Center (client half).
 *
 * The PHP half output-buffers every notice hook and re-emits it wrapped in
 * #adminkit-nc-origin (live, complete). This script reads that marker — plus
 * standard-class notices anywhere — categorizes each, and MOVES the groupable ones
 * into a toolbar-bell drawer with appendChild, keeping the LIVE node (its dismiss
 * button, AJAX nonce, and any plugin JS) intact. Moved notices are tagged `.inline`
 * so WP core's common.js relocation can't pull them back onto the page. A
 * MutationObserver catches notices injected after load.
 *
 * Policy: group EVERY notice EXCEPT success confirmations (`.notice-success` /
 * `.updated.notice`), WP "render in place" `.inline` / `.below-h2` notices, and
 * notices buried in page UI (metabox / form / list table / settings app). Forced
 * overrides (data-ak-nc-group / -keep, the js_allow / js_deny filters) win first.
 *
 * Strings + config ride in window.AdminKitNotificationCenter (set in PHP). No jQuery;
 * vanilla, footer-loaded, DOMContentLoaded-gated, wrapped in try/catch so it can
 * never leave the page with notices stuck hidden.
 */
(function () {
	var C = window.AdminKitNotificationCenter;
	if ( ! C ) { return; }

	// Standard WP notice classes (matched anywhere in the content area) + Freemius
	// (.fs-notice), the SDK behind a large share of plugin nags.
	var NOTICE_SEL = '.notice, .updated, .update-nag, div.error, .fs-notice';
	// Banner-ish class token — for custom plugin banners that skip the .notice class
	// (e.g. Akismet's .akismet-alert). Only applied to DIRECT children of the notice
	// area (see collect()), so it can't reach nested in-app UI.
	var BANNERISH  = /(?:^|[\s_-])(?:notice|notices|alert|alerts|nag|message)(?:[\s_-]|$)/i;
	var PROCESSED  = 'data-ak-nc';
	var doc        = document.documentElement;

	var backdrop, drawer, listEl, emptyEl, badgeEl, bell, lastFocus;
	var isOpen = false;

	/* ───────── categorization ─────────
	   Policy: group EVERYTHING into the panel EXCEPT success confirmations (which
	   stay inline, where the user just triggered the action). Forced overrides win
	   first; WP's "render in place" .inline notices and anything buried inside page
	   UI (metabox / form / list table / settings app) are treated as contextual and
	   left alone — they aren't top-of-page admin notices. */

	function matchesAny( el, selectors ) {
		if ( ! selectors || ! selectors.length ) { return false; }
		for ( var i = 0; i < selectors.length; i++ ) {
			try { if ( el.matches( selectors[ i ] ) ) { return true; } } catch ( e ) {}
		}
		return false;
	}

	// A genuine success confirmation: WP's modern `.notice-success` (settings_errors()
	// "Settings saved") or the legacy combo `.updated.notice` ("Plugin activated" =
	// class="updated notice is-dismissible"). A BARE `.updated` is NOT success —
	// plugins use it for green NAGS too (e.g. Akismet's setup prompt
	// `<div class="updated" id="akismet-setup-prompt">`), which should be grouped.
	function isSuccess( el ) {
		if ( el.classList.contains( 'notice-success' ) ) { return true; }
		return el.classList.contains( 'updated' ) && el.classList.contains( 'notice' );
	}

	// Page-UI notices (NOT the top-of-page notice area): WP's "render in place"
	// .inline notices, and anything nested in a metabox / form / list table / the
	// settings app / our own drawer.
	var SKIP_CONTAINERS = '.postbox, .inside, .form-table, .wp-list-table, .widget, .widgets-holder-wrap, .adminkit-app, .ak-nc-drawer, .components-notice-list';
	function isContextual( el ) {
		// `.inline` / `.below-h2` are WP's "render in place" markers (same exclusion
		// Clearfy uses); plus anything nested in page UI (skip containers).
		return el.classList.contains( 'inline' ) || el.classList.contains( 'below-h2' ) || !! el.closest( SKIP_CONTAINERS );
	}

	// True ⇒ group this notice into the bell. Success confirmations + page-UI
	// notices stay inline; forced overrides (attrs / js_allow / js_deny) win first.
	function groupable( el ) {
		if ( matchesAny( el, C.deny ) || el.hasAttribute( 'data-ak-nc-keep' ) ) { return false; }
		if ( matchesAny( el, C.allow ) || el.hasAttribute( 'data-ak-nc-group' ) ) { return true; }
		if ( isContextual( el ) ) { return false; }   // page UI, not a banner alert
		if ( isSuccess( el ) ) { return false; }       // success confirmations stay inline
		return true;                                    // every other alert → the panel
	}

	/* ───────── relocation ───────── */

	function eligibleRoot() {
		return document.getElementById( 'wpbody-content' ) || document.body;
	}
	function isBannerish( el ) {
		return typeof el.className === 'string' && BANNERISH.test( el.className );
	}
	// A custom-class banner is only grabbed if it actually looks like a banner — not a
	// whole settings panel. Skip if it embeds a form/table or has many children.
	function looksLikeBanner( el ) {
		return ! el.querySelector( 'table, form' ) && el.children.length <= 8;
	}
	// Candidates = standard-class notices anywhere in the content area, PLUS banner-ish
	// DIRECT children of the notice area (.wrap / #wpbody-content) — catches custom
	// banners (Akismet alerts, etc.) without reaching nested plugin UI.
	function collect() {
		var root = eligibleRoot(), out = [], seen = [];
		function add( el ) { if ( el && el.nodeType === 1 && seen.indexOf( el ) === -1 ) { seen.push( el ); out.push( el ); } }
		var std = root.querySelectorAll( NOTICE_SEL ), i;
		for ( i = 0; i < std.length; i++ ) { add( std[ i ] ); }
		var areas = [ root ], wraps = root.querySelectorAll( '.wrap' ), w;
		for ( w = 0; w < wraps.length; w++ ) { areas.push( wraps[ w ] ); }
		for ( var a = 0; a < areas.length; a++ ) {
			var kids = areas[ a ].children, k;
			for ( k = 0; k < kids.length; k++ ) {
				var kid = kids[ k ];
				if ( ! kid.matches( NOTICE_SEL ) && isBannerish( kid ) && looksLikeBanner( kid ) ) { add( kid ); }
			}
		}
		// The server wraps EVERY notice-hook output in #adminkit-nc-origin, so its
		// children are the authoritative, complete notice set — including custom
		// banners that match no selector. (common.js may have pulled the standard
		// ones out into .wrap; those are already caught by NOTICE_SEL above.)
		var origin = document.getElementById( 'adminkit-nc-origin' );
		if ( origin ) {
			var ok = origin.children, oi;
			for ( oi = 0; oi < ok.length; oi++ ) { add( ok[ oi ] ); }
		}
		return out;
	}
	function judge( el ) {
		if ( ! el || el.nodeType !== 1 || el.hasAttribute( PROCESSED ) ) { return; }
		if ( el.closest( '.ak-nc-drawer' ) ) { return; } // already relocated
		el.setAttribute( PROCESSED, '1' );
		if ( groupable( el ) ) {
			// Tag with WP's "leave in place" marker BEFORE moving, so core's common.js
			// notice relocation —
			//   $('div.updated,div.error,div.notice').not('.inline,.below-h2').insertAfter('.wp-header-end')
			// — won't yank the node back out of our panel into .wrap (the tug-of-war
			// that left notices on the page). groupable() already ran, so adding the
			// class now doesn't change its verdict (and PROCESSED blocks re-judging).
			el.classList.add( 'inline' );
			listEl.appendChild( el ); // move the LIVE node — keeps dismiss + JS
		}
	}
	function sweep() {
		var nodes = collect(), i;
		for ( i = 0; i < nodes.length; i++ ) { judge( nodes[ i ] ); }
		refresh();
	}

	/* ───────── badge + empty state ───────── */

	function grouped() {
		// Every element child of the list is a notice we moved here (standard OR
		// custom-class), so count them all — filtering by NOTICE_SEL would miss the
		// custom banners (e.g. Akismet's .akismet-alert) and undercount the badge.
		return Array.prototype.slice.call( listEl.children ).filter( function ( n ) {
			return n.nodeType === 1;
		} );
	}
	function setBadge( n ) {
		if ( n > 0 ) {
			badgeEl.textContent = n > 9 ? '9+' : String( n );
			badgeEl.hidden = false;
			if ( bell ) { bell.setAttribute( 'aria-label', C.openLabel + ' (' + n + ')' ); }
		} else {
			badgeEl.hidden = true;
			if ( bell ) { bell.setAttribute( 'aria-label', C.openLabel ); }
		}
	}
	function refresh() {
		var n = grouped().length;
		emptyEl.hidden = n > 0;
		listEl.hidden  = n === 0;
		setBadge( n ); // the badge always shows the live count of notices in the center
	}

	/* ───────── open / close + focus trap ───────── */

	var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
	function focusables() {
		return Array.prototype.slice.call( drawer.querySelectorAll( FOCUSABLE ) ).filter( function ( el ) {
			return el.offsetParent !== null;
		} );
	}
	function onKeydown( e ) {
		if ( e.key === 'Escape' ) { e.preventDefault(); close(); return; }
		if ( e.key !== 'Tab' ) { return; }
		var f = focusables();
		if ( ! f.length ) { return; }
		var first = f[ 0 ], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}
	function open() {
		if ( isOpen ) { return; }
		isOpen = true;
		lastFocus = document.activeElement;
		backdrop.classList.add( 'is-open' );
		drawer.classList.add( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'false' );
		if ( bell ) { bell.setAttribute( 'aria-expanded', 'true' ); }
		var closeBtn = drawer.querySelector( '.ak-nc-close' );
		if ( closeBtn ) { closeBtn.focus(); }
		document.addEventListener( 'keydown', onKeydown, true );
	}
	function close() {
		if ( ! isOpen ) { return; }
		isOpen = false;
		backdrop.classList.remove( 'is-open' );
		drawer.classList.remove( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'true' );
		if ( bell ) { bell.setAttribute( 'aria-expanded', 'false' ); }
		document.removeEventListener( 'keydown', onKeydown, true );
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
		refresh();
	}
	function toggle() { if ( ! drawer ) { return; } if ( isOpen ) { close(); } else { open(); } }

	/* ───────── mount ───────── */

	function el( tag, cls, attrs ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( attrs ) { for ( var k in attrs ) { if ( attrs.hasOwnProperty( k ) ) { n.setAttribute( k, attrs[ k ] ); } } }
		return n;
	}
	function mount() {
		backdrop = el( 'div', 'ak-nc-backdrop', { 'data-ak-nc-close': '' } );

		drawer = el( 'aside', 'ak-nc-drawer', {
			role: 'dialog', 'aria-modal': 'true', 'aria-label': C.title, 'aria-hidden': 'true', tabindex: '-1'
		} );

		var head = el( 'header', 'ak-nc-head' );
		var txt  = el( 'div', 'ak-nc-head__text' );
		var h2   = el( 'h2', 'ak-nc-title' ); h2.textContent = C.title;
		var sub  = el( 'p', 'ak-nc-subtitle' ); sub.textContent = C.subtitle;
		txt.appendChild( h2 ); txt.appendChild( sub );
		var closeBtn = el( 'button', 'ak-nc-close', { type: 'button', 'data-ak-nc-close': '', 'aria-label': C.close } );
		closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
		head.appendChild( txt ); head.appendChild( closeBtn );

		var body = el( 'div', 'ak-nc-body' );
		listEl  = el( 'div', 'ak-nc-list', { hidden: '' } );
		emptyEl = el( 'div', 'ak-nc-empty' );
		emptyEl.innerHTML = '<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 4 16 16"/><path d="M6 8a6 6 0 0 1 9.2-5"/><path d="M18 8c0 4.5 1.4 6 2.7 7.3A1 1 0 0 1 20 17H8"/><path d="M10.3 21a2 2 0 0 0 3.4 0"/></svg>';
		var et = el( 'p', 'ak-nc-empty__title' ); et.textContent = C.empty;
		var ed = el( 'p', 'ak-nc-empty__desc' ); ed.textContent = C.emptyDesc;
		emptyEl.appendChild( et ); emptyEl.appendChild( ed );
		body.appendChild( listEl ); body.appendChild( emptyEl );

		drawer.appendChild( head ); drawer.appendChild( body );
		document.body.appendChild( backdrop );
		document.body.appendChild( drawer );

		var node = document.getElementById( 'wp-admin-bar-' + C.nodeId );
		badgeEl = node ? node.querySelector( '.ak-nc-badge' ) : null;
		bell    = node ? node.querySelector( 'a' ) : null;
		if ( ! badgeEl ) { badgeEl = el( 'span', 'ak-nc-badge', { hidden: '' } ); } // defensive
		if ( bell ) { bell.setAttribute( 'aria-haspopup', 'dialog' ); bell.setAttribute( 'aria-expanded', 'false' ); }

		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest && e.target.closest( '[data-ak-nc-close]' ) ) { e.preventDefault(); close(); }
		} );
	}

	/* ───────── observe late notices + dismissals ───────── */

	function observe() {
		var queued = false, schedule = window.requestAnimationFrame || window.setTimeout;
		var moRoot = new MutationObserver( function ( muts ) {
			var hit = false;
			muts.forEach( function ( m ) {
				for ( var i = 0; i < m.addedNodes.length; i++ ) {
					var n = m.addedNodes[ i ];
					if ( n.nodeType !== 1 ) { continue; }
					if ( ( n.matches && ( n.matches( NOTICE_SEL ) || isBannerish( n ) ) ) || ( n.querySelector && n.querySelector( NOTICE_SEL ) ) ) { hit = true; }
				}
			} );
			if ( hit && ! queued ) { queued = true; schedule( function () { queued = false; sweep(); } ); }
		} );
		var root = eligibleRoot();
		if ( root ) { moRoot.observe( root, { childList: true, subtree: true } ); }

		new MutationObserver( function () { refresh(); } ).observe( listEl, { childList: true } );
	}

	/* ───────── boot ───────── */

	function boot() {
		// Authoritative toggle — delegated, capture phase (robust to admin-bar re-render).
		document.addEventListener( 'click', function ( e ) {
			var a = e.target.closest && e.target.closest( '#wp-admin-bar-' + C.nodeId + ' a' );
			if ( ! a ) { return; }
			e.preventDefault();
			toggle();
		}, true );

		try {
			mount();
			sweep();   // initial relocation
			observe(); // late notices + dismissals
		} catch ( err ) {
			if ( window.console && console.error ) { console.error( '[AdminKit Notification Center]', err ); }
		}
		// ALWAYS reveal + show the bell — even on error — so the page is never left
		// with notices stuck hidden or the bell missing.
		doc.classList.remove( C.pendingClass );
		doc.classList.add( C.activeClass );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
