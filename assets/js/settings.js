/**
 * AdminKit settings — a small, build-free single-page app.
 *
 * Renders four tabs (Dashboard / Design / Features / Plugins) into
 * #adminkit-app from the data PHP hands over in window.AdminKitData. Tabs are
 * pill buttons driven by the URL hash (#design / #settings / …).
 *
 * The Design tab leads with the interactive brand controls (light/dark logo
 * upload + where the mark shows), then a STATIC colour/type reference: every
 * semantic colour role (swatch + the --ak token and the provider var / primitive
 * it maps to), read-only. Features holds the module on/off toggles. Both save via REST.
 *
 * No framework, no build step — vanilla DOM.
 */
( function () {
	'use strict';

	var D = window.AdminKitData;
	var app = document.getElementById( 'adminkit-app' );
	if ( ! D || ! app ) {
		return;
	}
	var I = D.i18n || {};
	var apiFetch = window.wp && window.wp.apiFetch;

	var state = {
		dirty: false,
		saving: false,
		features: {},      // setting key -> bool
		integrations: {},  // integration slug -> bool (adapter enabled)
		// Plugins tab — global switch that gates AdminKit's auto-theming on
		// admin pages belonging to plugins without a dedicated adapter (see
		// PHP gate_generic_theming()). Off → those pages keep WP native UI.
		genericThemingEnabled: D.genericThemingEnabled !== false,
		logos: {           // setting key -> url string
			light: ( D.logos && D.logos.light ) || '',
			dark:  ( D.logos && D.logos.dark ) || ''
		},
		wpLogo: D.wpLogo || 'favicon',       // admin-bar / site-name mark: logo | favicon | hide
		loginLogo: D.loginLogo || 'favicon', // login screen mark: logo | favicon | hide (legacy '' inherit → favicon)
		brandAccent: D.brandAccent || '',    // user hex (only meaningful when accentSource === 'custom')
		// 'adminkit' = WP Blue (#3858E9), 'bricks' = Bricks provider --accent, 'custom' = brandAccent hex
		accentSource: D.accentSource || 'adminkit',
		// Bidirectional binding to WP's native `site_icon` option — see PHP
		// bootstrap. The favicon slot in the Brand card reads + writes through
		// THIS, not state.logos, so a change here propagates to Settings →
		// General and vice-versa (page reload pulls the latest).
		siteIcon: {
			id:  ( D.siteIcon && D.siteIcon.id ) || 0,
			url: ( D.siteIcon && D.siteIcon.url ) || ''
		}
	};
	( D.features || [] ).forEach( function ( f ) {
		state.features[ f.key ] = !! f.value;
	} );
	( D.integrations || [] ).forEach( function ( i ) {
		// Only native integrations carry a per-host toggle; generic plugins have
		// no adapter to switch, so they stay out of the saved state.
		if ( i.supported && i.slug ) { state.integrations[ i.slug ] = !! i.enabled; }
	} );

	// Shared read of the current accent source. sourcePill() lives at module
	// scope (outside buildDesign() where the `state` closure is defined), so it
	// reads the source via this tiny accessor that the picker writes into.
	// Initialised from the bootstrap, kept in sync by accentPicker's setSource().
	// MUST be declared before buildDesign() runs (it's called during tab build).
	var accentState = { source: ( D.accentSource || 'adminkit' ) };

	// Registry of every Source <td> in the token-map table along with its
	// original token object. Populated by refRow() each time the disclosure
	// builds the table; refreshAllPills() walks it after any source change.
	// Cheaper + cleaner than data-attributes since we keep the full token in scope.
	// MUST be declared before buildDesign() runs (refRow / refreshAllPills read it).
	var pillCells = [];

	// --- tiny DOM helper -----------------------------------------------------
	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( v == null ) { return; }
				if ( k === 'class' ) { n.className = v; }
				else if ( k === 'text' ) { n.textContent = v; }
				else if ( k.slice( 0, 2 ) === 'on' && typeof v === 'function' ) { n.addEventListener( k.slice( 2 ), v ); }
				else { n.setAttribute( k, v ); }
			} );
		}
		( kids || [] ).forEach( function ( c ) {
			if ( c == null ) { return; }
			n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return n;
	}

	// --- color resolver -------------------------------------------------------
	// Resolve a CSS custom property to its CONCRETE value (rgb/rgba or hex). Used
	// by the Design tab's token reference so each row shows the actual painted
	// colour next to its name — handy when the token cascades through several
	// fallback layers (provider → baseline → built-in default).
	//
	// getComputedStyle() on a var name returns the variable's *source* (e.g.
	// "var(--neutral-l-1)"), so we paint a hidden probe and read back its
	// resolved background-color, then convert opaque rgb() to a short hex.
	function resolveColor( cssVar ) {
		var probe = document.createElement( 'span' );
		probe.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;background:var(' + cssVar + ')';
		document.body.appendChild( probe );
		var rgb = getComputedStyle( probe ).backgroundColor;
		document.body.removeChild( probe );
		return rgbToHex( rgb );
	}

	function rgbToHex( s ) {
		// Keep rgba() (alpha < 1) as-is — hex notation hides the alpha.
		var m = s.match( /^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)\s*(?:[,/]\s*([\d.]+))?\s*\)$/ );
		if ( ! m ) { return s; }
		if ( m[4] != null && parseFloat( m[4] ) < 1 ) { return s; }
		function h( n ) { return ( '0' + parseInt( n, 10 ).toString( 16 ) ).slice( -2 ); }
		return '#' + h( m[1] ) + h( m[2] ) + h( m[3] );
	}

	// When the dark-mode flip happens (class-theme-toggle flips
	// [data-adminkit-theme] on <html>), re-resolve every token swatch's hex so the
	// reference stays accurate. Idempotent: walks any [data-ak-token] in the DOM
	// (the Design tab may not be mounted yet — that's fine, the observer is cheap).
	function refreshHexes() {
		var nodes = document.querySelectorAll( '[data-ak-token]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].textContent = resolveColor( nodes[ i ].getAttribute( 'data-ak-token' ) );
		}
	}
	if ( window.MutationObserver ) {
		new MutationObserver( refreshHexes ).observe(
			document.documentElement,
			{ attributes: true, attributeFilter: [ 'data-adminkit-theme' ] }
		);
	}

	// --- disclosure (expand/collapse) ----------------------------------------
	// One button (caret rotates on open) + a hidden content panel. The content is
	// built LAZILY on first open via the supplied builder — handy for big chunks
	// like the 25-row tokens reference table that shouldn't churn the DOM until
	// asked for. Returns { btn, panel } so the caller can place them where they
	// want (some live inline with their label, others on a separate row).
	function disclosure( labelClosed, labelOpen, build, options ) {
		options = options || {};
		var isOpen = false;
		var panel = el( 'div', { 'class': 'ak-disclose__panel' + ( options.panelClass ? ' ' + options.panelClass : '' ) } );
		panel.setAttribute( 'hidden', '' );
		var label = el( 'span', { 'class': 'ak-disclose__lbl', text: labelClosed } );
		var caret = el( 'span', { 'class': 'ak-disclose__caret', 'aria-hidden': 'true', text: '▾' } );
		var btn = el( 'button', {
			type: 'button',
			'class': 'ak-disclose__btn' + ( options.btnClass ? ' ' + options.btnClass : '' ),
			'aria-expanded': 'false'
		}, [ label, caret ] );
		btn.addEventListener( 'click', function () {
			isOpen = ! isOpen;
			if ( isOpen ) {
				if ( ! panel.dataset.built ) {
					build( panel );
					panel.dataset.built = '1';
				}
				panel.removeAttribute( 'hidden' );
				btn.classList.add( 'is-open' );
				btn.setAttribute( 'aria-expanded', 'true' );
				label.textContent = labelOpen;
			} else {
				panel.setAttribute( 'hidden', '' );
				btn.classList.remove( 'is-open' );
				btn.setAttribute( 'aria-expanded', 'false' );
				label.textContent = labelClosed;
			}
		} );
		return { btn: btn, panel: panel };
	}

	// --- Actions menu (dropdown) ---------------------------------------------
	// Items: [{ label, onClick, disabled?, separator?, danger? }]. Outside-click
	// and Escape close it; the menu's z-index keeps it above the sticky header.
	function actionsMenu( items ) {
		var open = false;
		var btn = el( 'button', {
			type: 'button', 'class': 'ak-actions__btn',
			'aria-haspopup': 'menu', 'aria-expanded': 'false'
		}, [
			el( 'span', { text: I.actionsLabel || 'Actions' } ),
			el( 'span', { 'class': 'ak-actions__caret', 'aria-hidden': 'true', text: '▾' } )
		] );
		var menu = el( 'div', { 'class': 'ak-actions__menu', role: 'menu' } );
		menu.setAttribute( 'hidden', '' );
		items.forEach( function ( it ) {
			if ( it.separator ) {
				menu.appendChild( el( 'hr', { 'class': 'ak-actions__sep' } ) );
				return;
			}
			var attrs = {
				type: 'button',
				'class': 'ak-actions__item' + ( it.danger ? ' is-danger' : '' ),
				role: 'menuitem',
				text: it.label
			};
			if ( it.disabled ) {
				attrs.disabled = '';
				attrs.title = it.disabledHint || ( I.comingSoon || 'Coming soon' );
			}
			var mi = el( 'button', attrs );
			if ( ! it.disabled ) {
				mi.addEventListener( 'click', function () { close(); it.onClick(); } );
			}
			menu.appendChild( mi );
		} );
		function close() {
			open = false;
			menu.setAttribute( 'hidden', '' );
			btn.classList.remove( 'is-open' );
			btn.setAttribute( 'aria-expanded', 'false' );
		}
		function show() {
			open = true;
			menu.removeAttribute( 'hidden' );
			btn.classList.add( 'is-open' );
			btn.setAttribute( 'aria-expanded', 'true' );
		}
		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			if ( open ) { close(); } else { show(); }
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( open && ! menu.contains( e.target ) && e.target !== btn ) { close(); }
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( open && e.key === 'Escape' ) { e.preventDefault(); close(); btn.focus(); }
		} );
		return el( 'div', { 'class': 'ak-actions' }, [ btn, menu ] );
	}

	// --- header chrome -------------------------------------------------------
	var statusEl = el( 'span', { 'class': 'ak-status', 'aria-live': 'polite' } );
	var saveBtn = el( 'button', { 'class': 'ak-btn ak-btn--primary', type: 'button', text: I.save, onclick: save } );

	function setStatus( cls, text ) {
		statusEl.className = 'ak-status' + ( cls ? ' ' + cls : '' );
		statusEl.textContent = text || '';
	}
	function updateBar() {
		saveBtn.disabled = state.saving || ! state.dirty;
		if ( state.saving ) { setStatus( 'is-saving', I.saving ); }
		else if ( state.dirty ) { setStatus( 'is-dirty', I.unsaved ); }
	}
	function markDirty() { state.dirty = true; updateBar(); }

	// --- build ---------------------------------------------------------------
	app.removeAttribute( 'aria-busy' );
	app.textContent = '';

	app.appendChild( el( 'div', { 'class': 'ak-head' }, [
		el( 'h1', { 'class': 'ak-title', text: 'AdminKit' } ),
		el( 'div', { 'class': 'ak-actions' }, [ statusEl, saveBtn ] )
	] ) );

	var ICONS = {
		dashboard: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg>',
		colours: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.8c3.4 3.9 5.4 6.5 5.4 9.2a5.4 5.4 0 0 1-10.8 0c0-2.7 2-5.3 5.4-9.2z"/></svg>',
		features: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
		plugins: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v4M15 2v4M7 6h10a1 1 0 0 1 1 1v3a6 6 0 0 1-12 0V7a1 1 0 0 1 1-1zM12 16v6"/></svg>',
		sun: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>',
		moon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
		close: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>'
	};

	var tabs = [
		{ id: 'dashboard', label: I.dashboard, icon: ICONS.dashboard, build: buildDashboard },
		{ id: 'design', label: I.design, icon: ICONS.colours, build: buildDesign },
		{ id: 'settings', label: I.features, icon: ICONS.features, build: buildFeatures },
		{ id: 'plugins', label: I.plugins, icon: ICONS.plugins, build: buildPlugins }
	];
	var activeId = tabs[ 0 ].id;
	var panels = {};
	var nav = el( 'div', { 'class': 'ak-tabs', role: 'tablist', 'aria-label': 'AdminKit' } );
	var panelWrap = el( 'div', { 'class': 'ak-panels' } );

	tabs.forEach( function ( t ) {
		var ic = el( 'span', { 'class': 'ic' } );
		ic.innerHTML = t.icon;
		t.btn = el( 'button', {
			type: 'button',
			role: 'tab',
			id: 'ak-tab-' + t.id,
			'aria-selected': 'false',
			tabindex: '-1',
			onclick: function () { go( t.id ); }
		}, [ ic, el( 'span', { 'class': 'tx', text: t.label } ) ] );
		nav.appendChild( t.btn );
		panels[ t.id ] = t.build();
		panels[ t.id ].setAttribute( 'aria-labelledby', 'ak-tab-' + t.id );
		panelWrap.appendChild( panels[ t.id ] );
	} );

	// Roving-tabindex keyboard nav (arrows / Home / End).
	nav.addEventListener( 'keydown', function ( e ) {
		var i = -1;
		tabs.forEach( function ( t, n ) { if ( t.id === activeId ) { i = n; } } );
		if ( i < 0 ) { return; }
		if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) { i = ( i + 1 ) % tabs.length; }
		else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) { i = ( i - 1 + tabs.length ) % tabs.length; }
		else if ( e.key === 'Home' ) { i = 0; }
		else if ( e.key === 'End' ) { i = tabs.length - 1; }
		else { return; }
		e.preventDefault();
		go( tabs[ i ].id );
		tabs[ i ].btn.focus();
	} );

	app.appendChild( nav );
	app.appendChild( panelWrap );

	function selectTab( id ) {
		activeId = id;
		tabs.forEach( function ( t ) {
			var on = t.id === id;
			t.btn.classList.toggle( 'on', on );
			t.btn.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.btn.setAttribute( 'tabindex', on ? '0' : '-1' );
			panels[ t.id ].hidden = ! on;
		} );
	}

	// URL hash reflects the active tab (#design / #settings).
	function go( id ) {
		if ( '#' + id === location.hash ) { selectTab( id ); }
		else { location.hash = id; } // triggers hashchange → applyHash
	}
	function applyHash() {
		var h = ( location.hash || '' ).replace( /^#/, '' );
		var valid = tabs.some( function ( t ) { return t.id === h; } );
		selectTab( valid ? h : tabs[ 0 ].id );
	}
	window.addEventListener( 'hashchange', applyHash );
	applyHash();
	updateBar();

	// --- panels --------------------------------------------------------------
	function intro( text ) { return el( 'p', { 'class': 'ak-intro', text: text } ); }

	// --- roadmap detail modal ------------------------------------------------
	// One reusable, accessible dialog (built lazily). A roadmap card click opens
	// it with that item's title / lede / detail / bullets; ESC, the close button
	// and a backdrop click dismiss it, and focus returns to the card.
	var roadmapModal = null;
	var roadmapTrigger = null;

	function buildRoadmapModal() {
		var closeBtn = el( 'button', { 'class': 'ak-modal__close', type: 'button', 'aria-label': I.close || 'Close', onclick: closeRoadmapModal, text: '×' } );
		var chip   = el( 'span', { 'class': 'ak-modal__chip' } );
		var title  = el( 'h2', { 'class': 'ak-modal__title', id: 'ak-roadmap-modal-title' } );
		var lede   = el( 'p', { 'class': 'ak-modal__lede' } );
		var detail = el( 'p', { 'class': 'ak-modal__detail' } );
		var list   = el( 'ul', { 'class': 'ak-modal__list' } );
		var dialog = el( 'div', { 'class': 'ak-modal__dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'ak-roadmap-modal-title' }, [ closeBtn, chip, title, lede, detail, list ] );
		var root   = el( 'div', { 'class': 'ak-modal', hidden: 'hidden' }, [ dialog ] );
		root.addEventListener( 'click', function ( e ) { if ( e.target === root ) { closeRoadmapModal(); } } );
		document.body.appendChild( root );
		roadmapModal = { root: root, dialog: dialog, chip: chip, title: title, lede: lede, detail: detail, list: list, close: closeBtn };
		return roadmapModal;
	}

	function openRoadmapModal( item, status ) {
		var m = roadmapModal || buildRoadmapModal();
		roadmapTrigger = document.activeElement;
		m.chip.textContent = status || '';
		m.chip.style.display = status ? '' : 'none';
		m.title.textContent = item.label || '';
		m.lede.textContent = item.desc || '';
		m.lede.style.display = item.desc ? '' : 'none';
		m.detail.textContent = item.detail || '';
		m.detail.style.display = item.detail ? '' : 'none';
		m.list.textContent = '';
		( item.bullets || [] ).forEach( function ( b ) { m.list.appendChild( el( 'li', { text: b } ) ); } );
		m.list.style.display = ( item.bullets && item.bullets.length ) ? '' : 'none';
		m.root.removeAttribute( 'hidden' );
		requestAnimationFrame( function () { m.root.classList.add( 'is-open' ); } );
		document.addEventListener( 'keydown', onModalKey );
		m.close.focus();
	}

	function closeRoadmapModal() {
		if ( ! roadmapModal ) { return; }
		roadmapModal.root.classList.remove( 'is-open' );
		roadmapModal.root.setAttribute( 'hidden', 'hidden' );
		document.removeEventListener( 'keydown', onModalKey );
		if ( roadmapTrigger && roadmapTrigger.focus ) { roadmapTrigger.focus(); }
		roadmapTrigger = null;
	}

	function onModalKey( e ) {
		if ( e.key === 'Escape' ) { e.preventDefault(); closeRoadmapModal(); return; }
		if ( e.key === 'Tab' && roadmapModal ) {
			var f = roadmapModal.dialog.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
			if ( ! f.length ) { return; }
			var first = f[ 0 ], last = f[ f.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
			else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
		}
	}

	// Overview tab. Renders the data-driven card list from D.dashboard; a card
	// with a `tab` becomes a shortcut button to that tab.
	function buildDashboard() {
		var dd = D.dashboard || {};
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( dd.intro || '' ) ] );
		// Overview — a tight strip of bordered cells (provider / modules / mode).
		// Data-driven from dd.cards; a card with a `tab` becomes a shortcut. The
		// provider card carries a `swatch` rendered inline before its value.
		var hero = el( 'div', { 'class': 'ak-hero' } );
		( dd.cards || [] ).forEach( function ( c ) {
			hero.appendChild( el( c.tab ? 'button' : 'div', {
				type: c.tab ? 'button' : null,
				'class': 'ak-hero__cell' + ( c.tab ? ' ak-hero__cell--link' : '' ),
				onclick: c.tab ? function () { go( c.tab ); } : null
			}, [
				el( 'span', { 'class': 'ak-hero__k', text: c.label } ),
				el( 'span', { 'class': 'ak-hero__v' }, [
					c.swatch ? el( 'span', { 'class': 'ak-hero__swatch', style: 'background:var(' + c.swatch + ')' } ) : null,
					c.value
				] ),
				c.hint ? el( 'span', { 'class': 'ak-hero__sub', text: c.hint } ) : null
			] ) );
		} );

		// Mode cell — reflects the live theme and updates when the admin-bar
		// toggle flips it (reads the attribute, never writes it).
		var modeV = el( 'span', { 'class': 'ak-hero__v' } );
		function paintMode() {
			var dark = document.documentElement.getAttribute( 'data-adminkit-theme' ) === 'dark';
			modeV.textContent = dark ? ( I.dark || 'Dark' ) : ( I.light || 'Light' );
		}
		paintMode();
		new MutationObserver( paintMode ).observe( document.documentElement, { attributes: true, attributeFilter: [ 'data-adminkit-theme' ] } );
		hero.appendChild( el( 'div', { 'class': 'ak-hero__cell' }, [
			el( 'span', { 'class': 'ak-hero__k', text: I.mode || 'Mode' } ),
			modeV
		] ) );

		p.appendChild( el( 'div', { 'class': 'ak-group' }, [
			dd.overviewLabel ? el( 'h2', { 'class': 'ak-group__title', text: dd.overviewLabel } ) : null,
			hero
		] ) );

		// Roadmap — three columns (Planned / Next / In progress), Bricks-style cards.
		// Data-driven from dd.roadmap (the single source in class-settings-page.php).
		if ( dd.roadmap && dd.roadmap.length ) {
			var board = el( 'div', { 'class': 'ak-roadmap' } );
			dd.roadmap.forEach( function ( col ) {
				var kids = [ el( 'h3', { 'class': 'ak-roadmap__head', text: col.title } ) ];
				( col.items || [] ).forEach( function ( it ) {
					// A card with a detail / bullets becomes a focusable button that
					// opens the detail modal; otherwise it stays a plain div. A card
					// flagged `verify` (done, awaiting confirmation before removal)
					// gets an accent modifier + a small "To verify" badge.
					var hasDetail = !! ( it.detail || ( it.bullets && it.bullets.length ) );
					kids.push( el( hasDetail ? 'button' : 'div', {
						type: hasDetail ? 'button' : null,
						'class': 'ak-roadmap__card' + ( hasDetail ? ' ak-roadmap__card--link' : '' ) + ( it.verify ? ' ak-roadmap__card--verify' : '' ),
						onclick: hasDetail ? function () { openRoadmapModal( it, col.title ); } : null
					}, [
						it.verify ? el( 'span', { 'class': 'ak-roadmap__flag', title: I.roadmapVerifyHint || '', text: I.roadmapVerifyLabel || 'To verify' } ) : null,
						el( 'span', { 'class': 'ak-roadmap__titlerow' }, [
							el( 'span', { 'class': 'ak-roadmap__title', text: it.label } ),
							it.star ? el( 'span', { 'class': 'ak-roadmap__star', title: I.roadmapStarHint || '', text: '★', 'aria-label': I.roadmapStarHint || 'Game-changer' } ) : null
						] ),
						it.desc ? el( 'span', { 'class': 'ak-roadmap__desc', text: it.desc } ) : null
					] ) );
				} );
				board.appendChild( el( 'div', { 'class': 'ak-roadmap__col' }, kids ) );
			} );
			// Heading row: the "Roadmap" title with status badges on the right —
			// the version + the last-updated date, so the plan reads as current.
			var badges = el( 'div', { 'class': 'ak-roadmap__meta' }, [
				dd.version ? el( 'span', { 'class': 'ak-badge ak-badge--brand', text: dd.version } ) : null,
				dd.updated ? el( 'span', { 'class': 'ak-badge', text: ( dd.updatedLabel || 'Updated' ) + ' ' + dd.updated } ) : null
			] );
			p.appendChild( el( 'div', { 'class': 'ak-group' }, [
				el( 'div', { 'class': 'ak-roadmap__head-row' }, [
					dd.roadmapLabel ? el( 'h2', { 'class': 'ak-group__title', text: dd.roadmapLabel } ) : null,
					( dd.version || dd.updated ) ? badges : null
				] ),
				I.roadmapHint ? el( 'p', { 'class': 'ak-roadmap__hint', text: I.roadmapHint } ) : null,
				board
			] ) );
		}

		if ( dd.version ) {
			p.appendChild( el( 'p', { 'class': 'ak-dash__ver', text: 'AdminKit ' + dd.version } ) );
		}
		return p;
	}

	// Design tab — final layout (Phase A). Leads with one interactive Brand card
	// (logo / favicon slots · accent picker · derived strip · display segmented
	// controls · Actions menu), then a "View all N tokens" CTA that reveals the
	// read-only token reference table inline. Branding controls live HERE — not
	// on Features — so the design surface is the one obvious place to brand.
	function buildDesign() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' } );

		// --- Common helpers used inside the Design tab ---------------------------

		// Open the WP media frame and call back with the chosen attachment URL +
		// the full attachment object (so callers can grab `id` for site_icon-style
		// pipelines that need the attachment ID, not just the URL).
		function openMedia( onPick ) {
			if ( ! window.wp || ! wp.media ) { return; }
			var frame = wp.media( {
				title: I.mediaTitle || 'Select a logo',
				button: { text: I.mediaButton || 'Use this image' },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = ( att && att.url ) || '';
				onPick( url, att );
			} );
			frame.open();
		}

		// One brand slot — a dashed card with a fixed-backdrop drop zone (preview
		// or "DROP" placeholder) + label + sub + Upload / Media library buttons.
		// `slotKey` is one of 'light' | 'dark' | 'favicon'. The first two store
		// URLs in `state.logos[key]`; favicon is special — it proxies WP's native
		// `site_icon` option (an attachment ID), so reading + writing routes
		// through `state.siteIcon` instead. Picking an image here updates the WP
		// Site Icon on save, and a change in Settings → General shows up here on
		// next reload — one source of truth, in lockstep both ways.
		function brandSlot( slotKey, label, sub ) {
			var isSiteIcon = ( slotKey === 'favicon' );
			var preview = el( 'img', { 'class': 'ak-brand-slot__preview', alt: '' } );
			var dropTxt = el( 'span', { 'class': 'ak-brand-slot__drop', text: I.slotDrop || 'Drop' } );
			var zone = el( 'div', { 'class': 'ak-brand-slot__zone' }, [ preview, dropTxt ] );

			// One button per slot, label + action toggle on filled state:
			//   empty   → "↑ Upload"  → opens the WP media frame
			//   filled  → "Remove"    → clears the slot (sets it to empty)
			// Clicking the drop zone is a separate shortcut that ALWAYS opens
			// the media frame (to replace whatever is there).
			var actionBtn = el( 'button', {
				type: 'button', 'class': 'ak-brand-slot__btn'
			} );

			function currentUrl() {
				return isSiteIcon ? ( state.siteIcon.url || '' ) : ( state.logos[ slotKey ] || '' );
			}
			function syncPreview() {
				var url = currentUrl();
				if ( url ) {
					preview.src = url;
					preview.style.display = '';
					dropTxt.style.display = 'none';
					actionBtn.textContent = I.slotRemove || 'Remove';
					actionBtn.classList.add( 'is-remove' );
				} else {
					preview.removeAttribute( 'src' );
					preview.style.display = 'none';
					dropTxt.style.display = '';
					actionBtn.textContent = '↑ ' + ( I.slotUpload || 'Upload' );
					actionBtn.classList.remove( 'is-remove' );
				}
			}
			function setLogo( url, att ) {
				if ( isSiteIcon ) {
					state.siteIcon.url = url || '';
					state.siteIcon.id  = ( att && att.id ) ? parseInt( att.id, 10 ) : 0;
				} else {
					state.logos[ slotKey ] = url || '';
				}
				syncPreview();
				markDirty();
			}

			// Drop zone is always a "pick / replace" shortcut. The action button
			// branches on current state — clears when filled, picks when empty.
			zone.addEventListener( 'click', function () { openMedia( setLogo ); } );
			actionBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( currentUrl() ) {
					setLogo( '', null );
				} else {
					openMedia( setLogo );
				}
			} );

			syncPreview();

			return el( 'div', { 'class': 'ak-brand-slot ak-brand-slot--' + slotKey }, [
				zone,
				el( 'div', { 'class': 'ak-brand-slot__body' }, [
					el( 'div', { 'class': 'ak-brand-slot__label', text: label } ),
					sub ? el( 'div', { 'class': 'ak-brand-slot__sub', text: sub } ) : null,
					el( 'div', { 'class': 'ak-brand-slot__btns' }, [ actionBtn ] )
				] )
			] );
		}

		// Segmented control reused for the Display row (Admin bar + Login screen).
		// Same DOM as the rest of the SPA (.ak-seg / .ak-seg__opt) so the existing
		// styling kicks in.
		function logoSeg( stateKey, labelId, label, opts ) {
			var btns = [];
			var seg = el( 'div', { 'class': 'ak-seg', role: 'radiogroup', 'aria-labelledby': labelId } );
			opts.forEach( function ( o ) {
				var active = state[ stateKey ] === o.v;
				var b = el( 'button', {
					type: 'button',
					'class': 'ak-seg__opt' + ( active ? ' is-active' : '' ),
					role: 'radio', 'aria-checked': active ? 'true' : 'false',
					title: o.label, text: o.label
				} );
				b._v = o.v;
				b.addEventListener( 'click', function () {
					if ( state[ stateKey ] === o.v ) { return; }
					state[ stateKey ] = o.v;
					btns.forEach( function ( x ) {
						var on = x._v === o.v;
						x.classList.toggle( 'is-active', on );
						x.setAttribute( 'aria-checked', on ? 'true' : 'false' );
					} );
					markDirty();
				} );
				btns.push( b );
				seg.appendChild( b );
			} );
			return el( 'div', { 'class': 'ak-display-row__field' }, [
				el( 'span', { 'class': 'ak-display-row__field-lbl', id: labelId, text: label } ),
				seg
			] );
		}

		// Accent picker — a segmented control that switches between three sources
		// feeding `--ak-primary`:
		//
		//   • AdminKit (default) — D.adminkitBlue (#3858E9, WordPress Blue)
		//   • Bricks              — the Bricks provider --accent (no override)
		//   • Custom              — the user's `brand_accent` hex
		//
		// The hex input only appears when source = 'custom'. The swatch always
		// shows the resolved colour (clicking it pops the OS-native picker, which
		// also auto-switches the source to 'custom').
		//
		// Live preview writes / removes a `<style id="ak-accent-preview">` node
		// so the whole accent family (hover, subtle, on-accent, focus ring) and
		// the token-map pills update without a reload. The node is REMOVED for
		// 'bricks' so the cascade picks the provider's --accent naturally;
		// REMOVED for 'custom' with an invalid/empty hex so the cascade takes back;
		// otherwise WRITTEN with the right value.
		function accentPicker() {
			var bricksAvailable = !! D.bricksDetected;
			var sources = [
				{ v: 'adminkit', label: I.accentSrcAdminKit || 'AdminKit' },
				{ v: 'bricks',   label: I.accentSrcBricks   || 'Bricks',
				  disabled: ! bricksAvailable,
				  title: bricksAvailable ? null : ( I.accentSrcBricksHint || 'Bricks not detected' ) },
				{ v: 'custom',   label: I.accentSrcCustom   || 'Custom' }
			];

			var btns = [];
			var seg = el( 'div', { 'class': 'ak-seg', role: 'radiogroup', 'aria-label': I.accentLabel || 'Accent' } );
			sources.forEach( function ( o ) {
				var active = state.accentSource === o.v;
				var attrs = {
					type: 'button',
					'class': 'ak-seg__opt' + ( active ? ' is-active' : '' ),
					role: 'radio', 'aria-checked': active ? 'true' : 'false',
					text: o.label
				};
				if ( o.title ) { attrs.title = o.title; }
				if ( o.disabled ) { attrs.disabled = ''; attrs[ 'aria-disabled' ] = 'true'; }
				var b = el( 'button', attrs );
				b._v = o.v;
				if ( ! o.disabled ) {
					b.addEventListener( 'click', function () {
						if ( state.accentSource === o.v ) { return; }
						setSource( o.v );
					} );
				}
				btns.push( b );
				seg.appendChild( b );
			} );

			var swatch = el( 'button', {
				type: 'button', 'class': 'ak-accent-inline__sw',
				'aria-label': I.accentLabel || 'Accent'
			} );
			var hexInput = el( 'input', {
				type: 'text', 'class': 'ak-accent-inline__hex',
				placeholder: '#3858E9', spellcheck: 'false',
				maxlength: '7'
			} );
			hexInput.value = state.brandAccent || '';
			// Both swatch and hex hide outside Custom mode — the segmented alone
			// is the affordance for AdminKit / Bricks (no per-pixel choice to make).
			if ( state.accentSource !== 'custom' ) {
				hexInput.setAttribute( 'hidden', '' );
				swatch.setAttribute( 'hidden', '' );
			}
			var native = el( 'input', { type: 'color', 'class': 'ak-accent-inline__native' } );
			native.value = isValidHex( state.brandAccent ) ? state.brandAccent : ( D.adminkitBlue || '#3858E9' );

			// Update the inline <style id="ak-accent-preview"> for live preview.
			// Mirrors AdminKit_Assets::inject_accent_family() in PHP, so what the
			// browser shows BEFORE save == what the server emits AFTER save.
			//
			//   • 'adminkit' → emit dual block with D.adminkitBlue (#3858E9). The
			//                  cascade in tokens.css would otherwise leak WaasKit
			//                  yellow via var(--accent, …) — this inline override
			//                  loads after tokens.css and wins on cascade ties
			//                  (light) AND on specificity (dark, :root[data-…]).
			//   • 'custom'   → same dual block, with user hex.
			//   • 'bricks'   → no inline rule. Bricks's stylesheet handles its own
			//                  --accent + dark mode via the cascade.
			//
			// Dark-mode tweaks vs light: hover lightens (mix with #fff) so it's
			// readable on #2c2c2c surfaces, and subtle bumps to 22% mix so the
			// pale tint reads against the darker substrate (same proportions as
			// wp-baseline.css's dark surface scheme).
			//
			// Live-preview limitation: switching between source ∈ {adminkit,
			// custom} ↔ bricks doesn't load/unload wp-baseline.css client-side
			// (that's a server-side enqueue decision). Surface-level palette
			// swaps still require save+reload. The accent itself updates
			// immediately in both modes thanks to this dual-block emission.
			function applyPreview() {
				var id = 'ak-accent-preview';
				var existing = document.getElementById( id );

				var hex = null;
				if ( state.accentSource === 'adminkit' ) {
					hex = D.adminkitBlue || '#3858E9';
				} else if ( state.accentSource === 'custom' && isValidHex( state.brandAccent ) ) {
					hex = state.brandAccent;
				}

				if ( hex ) {
					var on = bestOnAccent( hex );
					var css = ':root{'
						+ '--ak-primary:' + hex + ';'
						+ '--ak-primary-hover:color-mix(in srgb,' + hex + ' 82%,#000);'
						+ '--ak-primary-subtle:color-mix(in srgb,' + hex + ' 12%,var(--ak-surface));'
						+ '--ak-on-accent:' + on + ';'
						+ '--ak-focus:color-mix(in srgb,' + hex + ' 27%,transparent)'
						+ '}'
						+ ':root[data-adminkit-theme="dark"]{'
						+ '--ak-primary:' + hex + ';'
						+ '--ak-primary-hover:color-mix(in srgb,' + hex + ' 82%,#fff);'
						+ '--ak-primary-subtle:color-mix(in srgb,' + hex + ' 22%,var(--ak-surface));'
						+ '--ak-on-accent:' + on + ';'
						+ '--ak-focus:color-mix(in srgb,' + hex + ' 27%,transparent)'
						+ '}';
					if ( ! existing ) {
						existing = document.createElement( 'style' );
						existing.id = id;
						document.head.appendChild( existing );
					}
					existing.textContent = css;
				} else if ( existing ) {
					// bricks (or empty/invalid custom) → no inline override needed.
					existing.parentNode.removeChild( existing );
				}
				swatch.style.background = 'var(--ak-primary)';
				refreshHexes();
				refreshAllPills();
			}

			function syncSeg() {
				btns.forEach( function ( b ) {
					var on = b._v === state.accentSource;
					b.classList.toggle( 'is-active', on );
					b.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				} );
				if ( state.accentSource === 'custom' ) {
					hexInput.removeAttribute( 'hidden' );
					swatch.removeAttribute( 'hidden' );
				} else {
					hexInput.setAttribute( 'hidden', '' );
					swatch.setAttribute( 'hidden', '' );
				}
			}

			function setSource( newSrc ) {
				state.accentSource = newSrc;
				accentState.source = newSrc; // mirror into module-scope for sourcePill()
				syncSeg();
				applyPreview();
				markDirty();
			}

			function setHex( raw ) {
				var v = ( raw || '' ).trim().toLowerCase();
				if ( v && v.charAt( 0 ) !== '#' ) { v = '#' + v; }
				state.brandAccent = v;
				if ( hexInput.value !== v ) { hexInput.value = v; }
				applyPreview();
				markDirty();
			}

			hexInput.addEventListener( 'input', function () { setHex( hexInput.value ); } );
			// Clicking the swatch ALWAYS opens the native picker AND auto-switches
			// the source to Custom — the picker only makes sense in custom mode.
			swatch.addEventListener( 'click', function () {
				if ( state.accentSource !== 'custom' ) { setSource( 'custom' ); }
				native.click();
			} );
			native.addEventListener( 'input', function () { setHex( native.value ); } );

			applyPreview();

			return el( 'div', { 'class': 'ak-display-row__field ak-accent-inline' }, [
				el( 'span', { 'class': 'ak-display-row__field-lbl', text: I.accentLabel || 'Accent' } ),
				seg, swatch, hexInput, native
			] );
		}

		// Compact hex validator — same shape as `sanitize_hex_color` PHP-side:
		// #abc or #aabbcc only, no rgba / hsl.
		function isValidHex( v ) {
			return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( ( v || '' ).trim() );
		}

		// Pick the most-readable foreground for an accent hex. WCAG relative-
		// luminance with the sRGB linearisation curve; threshold 0.55 leans
		// slightly toward white past mid-grey to match WP / Material practice.
		// Mirrors PHP `AdminKit_Assets::contrast_text_for()` byte-for-byte so
		// the live preview and the post-save inline style agree.
		function bestOnAccent( hex ) {
			if ( ! isValidHex( hex ) ) { return '#ffffff'; }
			var h = hex.replace( '#', '' );
			if ( h.length === 3 ) {
				h = h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ];
			}
			function lin( byte ) {
				var c = byte / 255;
				return ( c <= 0.03928 ) ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
			}
			var L = 0.2126 * lin( parseInt( h.slice( 0, 2 ), 16 ) )
			      + 0.7152 * lin( parseInt( h.slice( 2, 4 ), 16 ) )
			      + 0.0722 * lin( parseInt( h.slice( 4, 6 ), 16 ) );
			return ( L < 0.55 ) ? '#ffffff' : '#1d2327';
		}

		// --- Brand card ----------------------------------------------------------
		var cardHead = el( 'div', { 'class': 'ak-card__head' } );
		var headMain = el( 'div', { 'class': 'ak-card__head-main' }, [
			el( 'div', { 'class': 'ak-card__eyebrow', text: I.brandEyebrow || 'Brand' } ),
			el( 'h2', { 'class': 'ak-card__title', text: I.brandTitle || 'Logo, favicon & accent' } )
		] );
		if ( D.bricksDetected ) {
			headMain.appendChild( el( 'div', { 'class': 'ak-card__status', text: I.brandSyncStatus || 'Tokens synced with Bricks Builder' } ) );
		}
		cardHead.appendChild( headMain );

		// Actions menu — Reset + Re-sync wired; Export + Auto-generate present
		// but disabled (Phase A — see plan).
		var menuItems = [];
		if ( D.bricksDetected ) {
			menuItems.push( {
				label: I.actionResync || 'Re-sync from Bricks Builder',
				onClick: function () { doResync(); }
			} );
		}
		menuItems.push( {
			label: I.actionAutogen || 'Auto-generate appearance',
			disabled: true
		} );
		if ( D.bricksDetected ) {
			menuItems.push( {
				label: I.actionExport || 'Export to Bricks',
				disabled: true
			} );
		}
		menuItems.push( { separator: true } );
		menuItems.push( {
			label: I.actionReset || 'Reset to AdminKit defaults',
			danger: true,
			onClick: function () { doReset(); }
		} );
		cardHead.appendChild( actionsMenu( menuItems ) );

		// Brand slots row — light / dark / favicon. Light + dark persist URLs in
		// state.logos[key]; favicon proxies WP's native `site_icon` option
		// through state.siteIcon (see brandSlot for the routing detail).
		var slotsRow = el( 'div', { 'class': 'ak-brand-slots' }, [
			brandSlot( 'light', I.slotLight || 'Light-mode logo', I.slotLightSub || 'Shown on light surfaces' ),
			brandSlot( 'dark', I.slotDark || 'Dark-mode logo', I.slotDarkSub || 'Shown on dark surfaces' ),
			brandSlot( 'favicon', I.slotFavicon || 'Favicon', I.slotFaviconSub || 'SVG · or 32×32 PNG' )
		] );

		// Display row — segmented controls for Admin bar + Login screen, and
		// the compact inline Accent picker all on the same line. Everything
		// the user can configure post-upload lives here, in one row, after
		// the brand slots. The derived-colours strip is intentionally gone —
		// the cascade derives Hover / Subtle / On-accent / Focus from --ak-primary
		// automatically through color-mix(), so showing those values added clutter
		// without a control. The Accent column anchors the right of the row so
		// the colour is always close to the controls it tints.
		var wpField = logoSeg( 'wpLogo', 'ak-wp-logo-label', I.wpLogoLabel || 'Admin bar', [
			{ v: 'logo',    label: I.wpLogoBrand || 'Logo' },
			{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' },
			{ v: 'hide',    label: I.wpLogoHide || 'Hide' }
		] );
		var loginField = logoSeg( 'loginLogo', 'ak-login-logo-label', I.loginLogoLabel || 'Login screen', [
			{ v: 'logo',    label: I.wpLogoBrand || 'Logo' },
			{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' },
			{ v: 'hide',    label: I.wpLogoHide || 'Hide' }
		] );
		var displayRow = el( 'div', { 'class': 'ak-display-row' }, [
			el( 'span', { 'class': 'ak-display-row__lbl', text: I.displayLabel || 'Display' } ),
			wpField, loginField, accentPicker()
		] );

		// Card stack: identity (slots) → placement + accent (one row).
		var card = el( 'section', { 'class': 'ak-card' }, [
			cardHead, slotsRow, displayRow
		] );

		// Intro text above the card.
		p.appendChild( el( 'p', { 'class': 'ak-design-intro', text: I.designIntro || '' } ) );
		p.appendChild( card );

		// --- Tokens CTA + revealed reference (lazy build) ------------------------
		// One disclosure ("Want to dig in? · View all N tokens") gates the entire
		// read-only reference area. Token map first (it's what the CTA copy
		// promises — "Browse every token AdminKit exposes"), then the
		// typography card below as a smaller secondary reference. Both are
		// built lazily on first open and live in the same panel so a single
		// click reveals the whole reference, single click hides it.
		var totalTokens = ( D.colors || [] ).reduce( function ( n, g ) {
			return n + ( ( g.tokens || [] ).length );
		}, 0 );
		var ctaLabel = ( I.tokensCtaBtnFmt || 'View all %d tokens' ).replace( '%d', totalTokens );
		var refDisc = disclosure(
			ctaLabel,
			I.tokensRefHide || 'Hide',
			function ( panel ) {
				panel.appendChild( tokensReference() );
				panel.appendChild( typeSection() );
			},
			{ btnClass: 'ak-tokens-cta__btn' }
		);

		var cta = el( 'div', { 'class': 'ak-tokens-cta' }, [
			el( 'div', {}, [
				el( 'div', { 'class': 'ak-tokens-cta__title', text: I.tokensCtaTitle || 'Want to dig in?' } ),
				el( 'div', { 'class': 'ak-tokens-cta__sub', text: I.tokensCtaSub || 'Browse every token AdminKit exposes — read-only reference.' } )
			] ),
			refDisc.btn
		] );
		p.appendChild( cta );
		p.appendChild( refDisc.panel );

		return p;
	}

	// Read-only token reference — a 4-column table (Token / Cascade / Value /
	// Source pill). Section headers (SURFACES / BORDERS / …) are full-width
	// rows with a single colspan'd cell. Built once per disclosure-open and
	// kept in the DOM after that (the MutationObserver picks up dark-mode flips
	// even while hidden).
	function tokensReference() {
		// Reset the pill-cell registry on each build so refreshAllPills only
		// walks the cells that are currently in the DOM.
		pillCells.length = 0;

		var thead = el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: I.colToken || 'Token' } ),
			el( 'th', { text: I.colCascade || 'Cascade' } ),
			el( 'th', { text: I.colValue || 'Value' } ),
			el( 'th', { text: I.colSource || 'Source' } )
		] ) ] );
		var tbody = el( 'tbody' );
		( D.colors || [] ).forEach( function ( g ) {
			// Section header row.
			tbody.appendChild( el( 'tr', { 'class': 'ak-tokens-ref__section' }, [
				el( 'td', { colspan: '4', text: ( g.label || '' ).toUpperCase() } )
			] ) );
			( g.tokens || [] ).forEach( function ( t ) { tbody.appendChild( refRow( t ) ); } );
		} );

		// Same .ak-card shell as the Brand + Typography cards above — identical
		// padding / border / radius so the three read as a coherent family.
		// Uses h2 (not h3) so the heading style matches the others byte-for-byte
		// without depending on UA defaults.
		return el( 'section', { 'class': 'ak-card ak-tokens-ref' }, [
			el( 'div', { 'class': 'ak-card__head' }, [
				el( 'div', { 'class': 'ak-card__head-main' }, [
					el( 'div', { 'class': 'ak-card__eyebrow', text: I.tokensRefEyebrow || 'Reference' } ),
					el( 'h2', { 'class': 'ak-card__title', text: I.tokensRefTitle || 'Token map' } ),
					el( 'p', { 'class': 'ak-card__sub', text: I.tokensRefSub || '' } )
				] )
			] ),
			el( 'table', { 'class': 'ak-tokens-ref__table' }, [ thead, tbody ] )
		] );
	}

	// Source pill for a single token row. The pill describes what's ACTUALLY
	// feeding the token at runtime, taking the current `accent_source` into
	// account (which decides which baseline is on the cascade):
	//
	//   • own (AdminKit-defined token, e.g. --ak-secondary) → ADMINKIT pill
	//     regardless of source.
	//   • accent-family (--ak-primary*, --ak-on-accent, --ak-focus) → follows
	//     `accentState.source`: CUSTOM (when source=custom), BRICKS (when
	//     source=bricks AND Bricks is actually detected), else ADMINKIT.
	//   • non-accent, source=bricks AND token has a `bricks` mapping AND
	//     Bricks detected → BRICKS pill (Bricks's stylesheet is providing it).
	//   • non-accent, source IN {adminkit, custom} → ADMINKIT pill (the
	//     wp-baseline.css file is providing it).
	//   • else → AUTO pill (cascade safety net, shouldn't normally show).
	function sourcePill( t ) {
		if ( t.own ) {
			return el( 'span', { 'class': 'ak-pill ak-pill--adminkit', text: I.sourceAdminKit || 'AdminKit' } );
		}
		if ( t.accent_family ) {
			if ( accentState.source === 'custom' ) {
				return el( 'span', { 'class': 'ak-pill ak-pill--custom', text: I.sourceCustom || 'Custom' } );
			}
			if ( accentState.source === 'bricks' && D.bricksDetected ) {
				return el( 'span', { 'class': 'ak-pill ak-pill--bricks', text: I.sourceBricks || 'Bricks' } );
			}
			return el( 'span', { 'class': 'ak-pill ak-pill--adminkit', text: I.sourceAdminKit || 'AdminKit' } );
		}
		// Non-accent tokens — which baseline is currently providing them.
		if ( accentState.source === 'bricks' && t.bricks && D.bricksDetected ) {
			return el( 'span', { 'class': 'ak-pill ak-pill--bricks', text: I.sourceBricks || 'Bricks' } );
		}
		if ( accentState.source === 'adminkit' || accentState.source === 'custom' ) {
			return el( 'span', { 'class': 'ak-pill ak-pill--adminkit', text: I.sourceAdminKit || 'AdminKit' } );
		}
		return el( 'span', { 'class': 'ak-pill ak-pill--auto', text: I.sourceAuto || 'Auto' } );
	}

	// `accentState` + `pillCells` declared up top with the rest of module state
	// (sourcePill / refRow / refreshAllPills all read them at first-render time,
	// so they have to exist before buildDesign() runs).

	// Re-render every Source pill in the token map to reflect the current
	// accent source. Called by accentPicker.applyPreview() — covers BOTH
	// accent-family tokens (the obvious case) AND the rest (because they may
	// also flip baseline when source changes between bricks ↔ adminkit/custom).
	function refreshAllPills() {
		for ( var i = 0; i < pillCells.length; i++ ) {
			var ref = pillCells[ i ];
			ref.td.innerHTML = '';
			ref.td.appendChild( sourcePill( ref.token ) );
		}
	}

	// One token row in the read-only reference table. Cascade reads source →
	// bricks-semantic → token (the same flow direction we render elsewhere).
	// The Source <td> is registered in `pillCells` so refreshAllPills() can
	// re-render it in place when the accent source flips — no full table
	// rebuild required, no data-attribute juggling.
	function refRow( t ) {
		var cascadeBits = [];
		if ( t.source ) { cascadeBits.push( t.source ); }
		if ( t.bricks ) { cascadeBits.push( t.bricks ); }
		var cascade = cascadeBits.length ? cascadeBits.join( ' → ' ) : '—';

		var pillCell = el( 'td', {}, [ sourcePill( t ) ] );
		pillCells.push( { td: pillCell, token: t } );

		return el( 'tr', {}, [
			el( 'td', {}, [
				el( 'span', { 'class': 'ak-tokens-ref__sw', style: 'background: var(' + t.token + ')' } ),
				el( 'code', { 'class': 'ak-tokens-ref__token', text: t.token } )
			] ),
			el( 'td', {}, [ el( 'code', { 'class': 'ak-tokens-ref__cascade', text: cascade } ) ] ),
			el( 'td', {}, [ el( 'code', { 'class': 'ak-tokens-ref__value', 'data-ak-token': t.token, text: resolveColor( t.token ) } ) ] ),
			pillCell
		] );
	}

	// --- Actions handlers (Reset / Re-sync) ------------------------------------
	// Both are tied to the same state model: the SPA reloads the page on success
	// so server-side gating + asset cache-busting picks up the change.

	function doReset() {
		if ( ! window.confirm( I.confirmReset || 'Reset all settings?' ) ) { return; }
		if ( ! apiFetch ) { setStatus( 'is-error', I.error ); return; }
		state.saving = true;
		updateBar();
		var path = D.route.charAt( 0 ) === '/' ? D.route : '/' + D.route;
		apiFetch( { path: path, method: 'POST', data: { reset: true } } )
			.then( function () {
				setStatus( 'is-saved', I.statusReset || 'Defaults restored' );
				setTimeout( function () { location.reload(); }, 600 );
			} )
			.catch( function () {
				state.saving = false;
				updateBar();
				setStatus( 'is-error', I.error );
			} );
	}

	function doResync() {
		if ( ! apiFetch ) { setStatus( 'is-error', I.error ); return; }
		var path = D.resyncRoute && D.resyncRoute.charAt( 0 ) === '/' ? D.resyncRoute : '/' + ( D.resyncRoute || 'adminkit/v1/actions/resync' );
		state.saving = true;
		updateBar();
		apiFetch( { path: path, method: 'POST', data: { provider: 'bricks' } } )
			.then( function () {
				setStatus( 'is-saved', I.statusResync || 'Bricks tokens re-synced' );
				setTimeout( function () { location.reload(); }, 600 );
			} )
			.catch( function () {
				state.saving = false;
				updateBar();
				setStatus( 'is-error', I.error );
			} );
	}

	// Typography — static reference, laid out in the same card pattern as the
	// Brand card up top (eyebrow + title + sub, then rows). The body font
	// follows the provider (Bricks --font-base) when set, else Inter; the scale
	// is AdminKit's px admin sizes. Each row reads sample-left, token-right
	// with a `justify-content: space-between` flex layout — same rhythm as the
	// derived strip chips in the Brand card.
	function typeSection() {
		// Hero row — the "Ag" sample sits big at the top, paired with the
		// body-font token. Same span/code shape as the scale rows so it lines up.
		var hero = el( 'div', { 'class': 'ak-type-row ak-type-row--hero' }, [
			el( 'span', { 'class': 'ak-type-row__sample ak-type-row__sample--hero', text: 'Ag' } ),
			el( 'code', { 'class': 'ak-type-row__token', text: '--ak-font-body' } )
		] );

		var scale = el( 'div', { 'class': 'ak-type-scale' } );
		[
			{ token: '--ak-text-m', label: I.typeBody || 'Body' },
			{ token: '--ak-text-s', label: I.typeSmall || 'Small' },
			{ token: '--ak-text-xs', label: I.typeCaption || 'Caption' }
		].forEach( function ( s ) {
			scale.appendChild( el( 'div', { 'class': 'ak-type-row' }, [
				el( 'span', { 'class': 'ak-type-row__lbl', text: s.label } ),
				el( 'span', {
					'class': 'ak-type-row__sample',
					style: 'font-size:var(' + s.token + ')',
					text: I.pangram || 'The quick brown fox jumps over the lazy dog'
				} ),
				el( 'code', { 'class': 'ak-type-row__token', text: s.token } )
			] ) );
		} );

		return el( 'section', { 'class': 'ak-card' }, [
			el( 'div', { 'class': 'ak-card__head' }, [
				el( 'div', { 'class': 'ak-card__head-main' }, [
					el( 'div', { 'class': 'ak-card__eyebrow', text: I.typography || 'Typography' } ),
					el( 'h2', { 'class': 'ak-card__title', text: I.typeTitle || 'Font & sizes' } ),
					el( 'p', { 'class': 'ak-card__sub', text: I.typographyDesc || 'Body font follows Bricks (--font-base) when set, otherwise Inter.' } )
				] )
			] ),
			hero,
			scale
		] );
	}

	function buildFeatures() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( I.featuresIntro ) ] );

		var refs = {};     // key -> { input, row }
		var groups = [];   // [{ label, rows }] in first-seen order
		var byGroup = {};
		// Bucket each feature row under its `group` label (identical labels share
		// a block); keeps a child in the same group as its parent.
		function rowsFor( label ) {
			if ( ! byGroup[ label ] ) {
				byGroup[ label ] = { label: label, rows: el( 'div', { 'class': 'ak-rows' } ) };
				groups.push( byGroup[ label ] );
			}
			return byGroup[ label ].rows;
		}

		// Reflect a feature's state on its row: dim it ("is-off") when switched off
		// — the switch stays clickable so it can be turned back on — and, for a
		// child (e.g. mShots under Post previews), lock it ("is-locked": dimmed +
		// the switch made non-operable, both via CSS and the disabled input) while
		// its parent is off, snapping back the moment the parent is on again.
		function refreshRow( f ) {
			var r = refs[ f.key ];
			if ( ! r ) { return; }
			r.row.classList.toggle( 'is-off', ! state.features[ f.key ] );
			if ( f.parent ) {
				var parentOn = !! state.features[ f.parent ];
				r.input.disabled = ! parentOn;
				r.row.classList.toggle( 'is-locked', ! parentOn );
			}
		}

		// Flip every feature at once (the "enable / disable all" controls).
		// Rows flagged `bulk: false` (e.g. the WordPress-default master pause) are
		// left untouched — sweeping them would be nonsensical.
		function setAll( on ) {
			( D.features || [] ).forEach( function ( f ) {
				if ( f.bulk === false ) { return; }
				state.features[ f.key ] = on;
				if ( refs[ f.key ] ) { refs[ f.key ].input.checked = on; }
			} );
			( D.features || [] ).forEach( refreshRow );
			markDirty();
		}

		( D.features || [] ).forEach( function ( f ) {
			var input = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
			input.checked = !! state.features[ f.key ];
			input.addEventListener( 'change', function () {
				state.features[ f.key ] = input.checked;
				( D.features || [] ).forEach( refreshRow );
				markDirty();
			} );
			var row = el( 'div', { 'class': 'ak-row' + ( f.parent ? ' ak-row--child' : '' ) }, [
				el( 'div', { 'class': 'ak-row__main' }, [
					el( 'span', { 'class': 'ak-row__label', text: f.label } ),
					el( 'span', { 'class': 'ak-row__desc', text: f.desc } )
				] ),
				el( 'label', { 'class': 'ak-switch' }, [
					input,
					el( 'span', { 'class': 'ak-switch__track' } ),
					el( 'span', { 'class': 'ak-switch__knob' } )
				] )
			] );
			refs[ f.key ] = { input: input, row: row };
			rowsFor( f.group || '' ).appendChild( row );
		} );

		( D.features || [] ).forEach( refreshRow ); // initial dim + dependency state

		// Bulk controls — flip every feature on/off in one click (reuses the
		// header's flex row + secondary buttons; no new layout CSS).
		p.appendChild( el( 'div', { 'class': 'ak-actions ak-bulk' }, [
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.enableAll, onclick: function () { setAll( true ); } } ),
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.disableAll, onclick: function () { setAll( false ); } } )
		] ) );

		// One titled .ak-rows block per group label (order = first-seen).
		groups.forEach( function ( g ) {
			p.appendChild( el( 'div', { 'class': 'ak-group' }, [
				g.label ? el( 'h2', { 'class': 'ak-group__title', text: g.label } ) : null,
				g.rows
			] ) );
		} );

		return p;
	}

	// Plugins tab — every installed plugin (plus AdminKit's active theme adapters).
	// Supported plugins (a tuned adapter you can toggle per host) wear a "Native"
	// badge; the rest carry no badge — AdminKit's base styles theme them silently.
	function buildPlugins() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( I.pluginsIntro ) ] );
		var list = D.integrations || [];
		if ( ! list.length ) { return p; }

		var inputs = []; // native-integration toggles, for the bulk controls

		// Brand "Native" chip, left of the plugin name — supported plugins only.
		function nativeBadge() {
			return el( 'span', {
				'class': 'ak-badge ak-badge--brand',
				title: I.nativeHint || '',
				text: I.native || 'Native'
			} );
		}

		// Neutral "System" chip for AdminKit's own row — it's listed but locked.
		function systemBadge() {
			return el( 'span', {
				'class': 'ak-badge',
				title: I.systemHint || '',
				text: I.system || 'System'
			} );
		}

		// Neutral "Generic" chip for installed plugins with no adapter — the base
		// layer themes them automatically, so it shares the System badge's look.
		function genericBadge() {
			return el( 'span', {
				'class': 'ak-badge',
				title: I.genericHint || '',
				text: I.generic || 'Generic'
			} );
		}

		// Neutral "Inactive" chip for installed-but-not-active plugins. Pairs
		// with `.is-muted` on the row for an across-the-board dim treatment.
		function inactiveBadge() {
			return el( 'span', {
				'class': 'ak-badge',
				title: I.inactiveHint || '',
				text: I.inactive || 'Inactive'
			} );
		}

		function pluginRow( i ) {
			// Badge + name hug the left together (.ak-row__head), badge first; the
			// switch (native only) is pushed right by .ak-row__main's flex:1.
			// System (AdminKit itself) → neutral System; supported → brand Native;
			// any other installed plugin → neutral Generic (no dedicated adapter).
			var badge = i.system ? systemBadge() : ( i.supported ? nativeBadge() : genericBadge() );
			var head  = [ badge, el( 'span', { 'class': 'ak-row__label', text: i.label } ) ];
			// Installed but not active → add an "Inactive" chip after the name.
			if ( ! i.system && i.active === false ) {
				head.push( inactiveBadge() );
			}
			var main = el( 'div', { 'class': 'ak-row__main' }, [
				el( 'div', { 'class': 'ak-row__head' }, head )
			] );
			// Class hook for the muted/dim treatment when the plugin is inactive.
			var rowClass = ( ! i.system && i.active === false ) ? 'ak-row is-muted' : 'ak-row';

			// System row (AdminKit itself) → greyed + locked: a switch that reads ON
			// but can't be operated (.is-locked dims it and kills pointer events).
			if ( i.system ) {
				var lock = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
				lock.checked = true;
				lock.disabled = true;
				return el( 'div', { 'class': 'ak-row is-locked' }, [
					main,
					el( 'label', { 'class': 'ak-switch' }, [
						lock,
						el( 'span', { 'class': 'ak-switch__track' } ),
						el( 'span', { 'class': 'ak-switch__knob' } )
					] )
				] );
			}

			// Generic plugin → no adapter to switch (Generic badge, no toggle).
			if ( ! i.supported || ! i.slug ) {
				return el( 'div', { 'class': rowClass }, [ main ] );
			}

			var input = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
			input.checked = !! state.integrations[ i.slug ];
			input.addEventListener( 'change', function () {
				state.integrations[ i.slug ] = input.checked;
				markDirty();
			} );
			inputs.push( { slug: i.slug, input: input } );
			return el( 'div', { 'class': rowClass }, [
				main,
				el( 'label', { 'class': 'ak-switch' }, [
					input,
					el( 'span', { 'class': 'ak-switch__track' } ),
					el( 'span', { 'class': 'ak-switch__knob' } )
				] )
			] );
		}

		// Global "Theme generic plugins" toggle row — sits above the lists.
		// When off, the PHP `gate_generic_theming` filter suppresses the
		// adminkit body class on non-native plugin admin pages, so they fall
		// back to WordPress's native UI.
		function genericThemingRow() {
			var input = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
			input.checked = !! state.genericThemingEnabled;
			input.addEventListener( 'change', function () {
				state.genericThemingEnabled = input.checked;
				markDirty();
			} );
			return el( 'div', { 'class': 'ak-row' }, [
				el( 'div', { 'class': 'ak-row__main' }, [
					el( 'div', { 'class': 'ak-row__head' }, [
						el( 'span', { 'class': 'ak-row__label', text: I.genericThemingLabel || 'Theme generic plugins' } )
					] ),
					el( 'p', { 'class': 'ak-row__desc', text: I.genericThemingDesc || '' } )
				] ),
				el( 'label', { 'class': 'ak-switch' }, [
					input,
					el( 'span', { 'class': 'ak-switch__track' } ),
					el( 'span', { 'class': 'ak-switch__knob' } )
				] )
			] );
		}

		// Flip every native integration in one click (generic rows have no toggle).
		function setAll( on ) {
			inputs.forEach( function ( r ) {
				r.input.checked = on;
				state.integrations[ r.slug ] = on;
			} );
			markDirty();
		}

		// Build the sections first (this fills `inputs`), then prepend the bulk bar.
		var sections = [];
		[
			{ type: 'theme',  label: I.themesLabel || 'Themes' },
			{ type: 'plugin', label: I.plugins || 'Plugins' }
		].forEach( function ( sec ) {
			var items = list.filter( function ( i ) { return i.type === sec.type; } );
			if ( ! items.length ) { return; }
			var rows = el( 'div', { 'class': 'ak-rows' } );
			items.forEach( function ( i ) { rows.appendChild( pluginRow( i ) ); } );
			sections.push( el( 'div', { 'class': 'ak-group' }, [
				el( 'h2', { 'class': 'ak-group__title' }, [
					el( 'span', { text: sec.label } ),
					el( 'span', { 'class': 'ak-badge ak-group__count', text: String( items.length ) } )
				] ),
				rows
			] ) );
		} );

		// Global generic-theming switch sits in its own group at the top —
		// affects every Generic row below, so the header position reads as
		// "this is the master switch for what's listed".
		p.appendChild( el( 'div', { 'class': 'ak-group' }, [
			el( 'div', { 'class': 'ak-rows' }, [ genericThemingRow() ] )
		] ) );

		// Bulk controls — only when there's at least one active integration to flip.
		if ( inputs.length ) {
			p.appendChild( el( 'div', { 'class': 'ak-actions ak-bulk' }, [
				el( 'button', { type: 'button', 'class': 'ak-btn', text: I.enableAll, onclick: function () { setAll( true ); } } ),
				el( 'button', { type: 'button', 'class': 'ak-btn', text: I.disableAll, onclick: function () { setAll( false ); } } )
			] ) );
		}
		sections.forEach( function ( s ) { p.appendChild( s ); } );
		return p;
	}

	// --- save ----------------------------------------------------------------
	// Interactive controls (Features toggles, Plugins toggles, Branding logos)
	// post to REST; the Tokens tab is a read-only reference.
	function gather() {
		var v = {};
		Object.keys( state.features ).forEach( function ( k ) { v[ k ] = !! state.features[ k ]; } );
		Object.keys( state.integrations ).forEach( function ( slug ) {
			v[ 'integration_' + slug + '_enabled' ] = !! state.integrations[ slug ];
		} );
		v.logo_light   = state.logos.light;
		v.logo_dark    = state.logos.dark;
		v.wp_logo      = state.wpLogo;
		v.login_logo   = state.loginLogo;
		v.brand_accent = state.brandAccent;
		v.accent_source = state.accentSource;
		v.generic_theming_enabled = !! state.genericThemingEnabled;
		// WP-native option proxy — see PHP rest_save() for the round-trip.
		v.site_icon_id = state.siteIcon.id;
		return v;
	}

	function save() {
		if ( state.saving || ! state.dirty ) { return; }
		if ( ! apiFetch ) { setStatus( 'is-error', I.error ); return; }
		state.saving = true;
		updateBar();
		var path = D.route.charAt( 0 ) === '/' ? D.route : '/' + D.route;
		apiFetch( { path: path, method: 'POST', data: { values: gather() } } )
			.then( function () {
				state.saving = false;
				state.dirty = false;
				updateBar();
				setStatus( 'is-saved', I.saved );
				// The toggles gate asset loading SERVER-side, so reflecting them
				// without a reload would mean duplicating that gating in JS (bloat).
				// Instead reload automatically, just after the "Saved" flash, so the
				// change shows with no manual refresh. The #hash keeps the active tab.
				setTimeout( function () { location.reload(); }, 600 );
			} )
			.catch( function () {
				state.saving = false;
				updateBar();
				setStatus( 'is-error', I.error );
			} );
	}
}() );
