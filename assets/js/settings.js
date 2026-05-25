/**
 * AdminKit settings — a small, build-free single-page app.
 *
 * Renders three tabs (Dashboard / Tokens / Features) into
 * #adminkit-app from the data PHP hands over in window.AdminKitData. Tabs are
 * pill buttons driven by the URL hash (#apparence / #settings / …).
 *
 * The Tokens tab is a STATIC reference for now: it lists every semantic
 * colour role (swatch + the --ak token and the provider var / primitive it maps
 * to), read-only, with no generators and no live token manipulation. Features
 * holds the only interactive controls (toggles), saved via REST.
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
		logos: {           // setting key -> url string
			light: ( D.logos && D.logos.light ) || '',
			dark:  ( D.logos && D.logos.dark ) || ''
		},
		wpLogo: D.wpLogo || 'favicon',  // admin-bar / site-name mark: logo | favicon | hide
		loginLogo: D.loginLogo || ''    // login screen mark: '' inherit | logo | favicon
	};
	( D.features || [] ).forEach( function ( f ) {
		state.features[ f.key ] = !! f.value;
	} );
	( D.integrations || [] ).forEach( function ( i ) {
		state.integrations[ i.slug ] = !! i.enabled;
	} );

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
		{ id: 'apparence', label: I.design, icon: ICONS.colours, build: buildDesign },
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

	// URL hash reflects the active tab (#apparence / #settings).
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
					// opens the detail modal; otherwise it stays a plain div.
					var hasDetail = !! ( it.detail || ( it.bullets && it.bullets.length ) );
					kids.push( el( hasDetail ? 'button' : 'div', {
						type: hasDetail ? 'button' : null,
						'class': 'ak-roadmap__card' + ( hasDetail ? ' ak-roadmap__card--link' : '' ),
						onclick: hasDetail ? function () { openRoadmapModal( it, col.title ); } : null
					}, [
						el( 'span', { 'class': 'ak-roadmap__title', text: it.label } ),
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

	// Tokens tab — STATIC reference (no generators / no token writes for
	// now). One table per group: swatch · role (+ AdminKit badge) · the --ak
	// token and the provider var / primitive it maps to.
	function buildDesign() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' } );
		// Legend — explains the read-only mapping notation up front.
		p.appendChild( el( 'div', { 'class': 'ak-cascade' }, [
			el( 'strong', { text: I.designLegendTitle || 'Live colour reference' } ),
			el( 'span', { text: ' ' + ( I.designLegend || 'Each row shows a live colour preview, the role, then its AdminKit token ← the WaasKit semantic it reads · the primitive it resolves from. Read-only — the palette is driven by your tokens.' ) } )
		] ) );
		( D.colors || [] ).forEach( function ( g ) {
			var tbl = el( 'div', { 'class': 'ak-tbl' } );
			( g.tokens || [] ).forEach( function ( t ) { roleRow( tbl, t ); } );
			p.appendChild( el( 'div', { 'class': 'ak-group' }, [
				el( 'h2', { 'class': 'ak-group__title', text: g.label } ),
				g.desc ? el( 'p', { 'class': 'ak-group__desc', text: g.desc } ) : null,
				tbl
			] ) );
		} );
		p.appendChild( typeSection() );
		return p;
	}

	// Typography — static reference. The body font follows the provider (Bricks
	// --font-base) when set, else Inter; the scale is AdminKit's px admin sizes.
	// Samples render in --ak-font-body (inherited from .adminkit-app).
	function typeSection() {
		var scale = el( 'div', { 'class': 'ak-type' } );
		[
			[ '--ak-text-m', I.typeBody || 'Body' ],
			[ '--ak-text-s', I.typeSmall || 'Small' ],
			[ '--ak-text-xs', I.typeCaption || 'Caption' ]
		].forEach( function ( s ) {
			scale.appendChild( el( 'div', { 'class': 'ak-type__row' }, [
				el( 'span', { 'class': 'ak-type__sample', style: 'font-size:var(' + s[ 0 ] + ')', text: I.pangram || 'The quick brown fox jumps over the lazy dog' } ),
				el( 'code', { 'class': 'ak-tbl__prim', text: s[ 0 ] } )
			] ) );
		} );
		return el( 'div', { 'class': 'ak-group' }, [
			el( 'h2', { 'class': 'ak-group__title', text: I.typography || 'Typography' } ),
			el( 'p', { 'class': 'ak-group__desc', text: I.typographyDesc || 'Body font follows Bricks (--font-base) when set, otherwise Inter.' } ),
			el( 'div', { 'class': 'ak-type-hero' }, [
				el( 'span', { 'class': 'ak-type-hero__aa', text: 'Ag' } ),
				el( 'code', { 'class': 'ak-tbl__prim', text: '--ak-font-body' } )
			] ),
			scale
		] );
	}

	// Append one row (three aligned grid cells) to a group table. Cells are
	// direct grid children so columns line up across rows; CSS draws the dividers.
	function roleRow( tbl, t ) {
		tbl.appendChild( el( 'span', { 'class': 'ak-tbl__swc' }, [
			el( 'span', { 'class': 'ak-tbl__sw', style: '--sw: var(' + t.token + ')', title: t.token } )
		] ) );
		tbl.appendChild( el( 'span', { 'class': 'ak-tbl__role' }, [
			el( 'span', { 'class': 'ak-tbl__name', text: t.label } ),
			t.own ? el( 'span', { 'class': 'ak-tbl__badge', title: I.ownHint || '', text: I.own } ) : null
		] ) );
		tbl.appendChild( el( 'span', { 'class': 'ak-tbl__map' }, [
			el( 'code', { 'class': 'ak-tbl__tok', text: t.token } ),
			t.bricks ? el( 'code', { 'class': 'ak-tbl__from', text: '← ' + t.bricks } ) : null,
			t.source ? el( 'code', { 'class': 'ak-tbl__prim', text: ( t.bricks ? '· ' : '← ' ) + t.source } ) : null
		] ) );
	}

	function buildFeatures() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( I.featuresIntro ) ] );

		// --- Branding (top) — light/dark logo. The PREVIEW itself is the picker:
		// clicking it opens the WP media frame; a small × clears the logo. A hidden
		// URL field stays in sync so a logo can also be typed/pasted. The label is the
		// localized text only ("Light Mode" / "Dark Mode"), no leading icon.
		function openMedia( slot, onChange ) {
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
				state.logos[ slot ] = url;
				if ( onChange ) { onChange(); }
				markDirty();
			} );
			frame.open();
		}
		function logoField( slot, label, textLabel ) {
			var id = 'ak-logo-' + slot;
			var preview = el( 'img', { 'class': 'ak-logo-pick__img', alt: '' } );
			// Empty-state placeholder shown inside the picker when no logo is set.
			var ph = el( 'span', { 'class': 'ak-logo-pick__ph', text: I.logoPick || 'Choose a logo' } );
			var input = el( 'input', {
				id: id, type: 'url', 'class': 'ak-field__input', value: state.logos[ slot ],
				placeholder: I.logoPlaceholder || '', spellcheck: 'false'
			} );
			var clear = el( 'button', {
				type: 'button', 'class': 'ak-logo-pick__clear',
				'aria-label': I.logoRemove || 'Remove logo', title: I.logoRemove || 'Remove logo'
			} );
			clear.innerHTML = ICONS.close;
			var pick = el( 'button', {
				type: 'button', 'class': 'ak-logo-pick',
				'aria-label': I.logoChange || 'Change logo'
			}, [ preview, ph ] );
			function syncPreview() {
				var url = state.logos[ slot ];
				pick.classList.toggle( 'is-set', !! url );
				pick.setAttribute( 'title', url ? ( I.logoChange || 'Change logo' ) : ( I.logoPick || 'Choose a logo' ) );
				clear.hidden = ! url;
				if ( url ) {
					preview.src = url;
					preview.style.display = '';
				} else {
					preview.removeAttribute( 'src' );
					preview.style.display = 'none';
				}
			}
			input.addEventListener( 'input', function () {
				state.logos[ slot ] = input.value.trim();
				syncPreview();
				markDirty();
			} );
			pick.addEventListener( 'click', function () {
				openMedia( slot, function () { input.value = state.logos[ slot ]; syncPreview(); } );
			} );
			clear.addEventListener( 'click', function () {
				state.logos[ slot ] = '';
				input.value = '';
				syncPreview();
				markDirty();
			} );
			syncPreview();
			// Label = the localized TEXT only ("Light Mode" / "Dark Mode"); no leading
			// icon. The full label stays the title for assistive tech.
			var lbl = el( 'label', { 'class': 'ak-field__label', 'for': id, title: label }, [
				el( 'span', { 'class': 'ak-field__label-tx', text: textLabel || label } )
			] );
			return el( 'div', { 'class': 'ak-field' }, [
				lbl,
				el( 'div', { 'class': 'ak-field__control' }, [
					el( 'div', { 'class': 'ak-logo-pick__wrap' }, [ pick, clear ] ),
					input
				] )
			] );
		}
		// Per-location brand-mark controls — one segmented control each for the admin
		// bar and the login screen (favicon = square, logo = rectangle); the login one
		// adds "Inherit" (= follow the admin bar). Bricks reads brand_logo directly, so
		// it has no control. Reusable builder so both stay visually identical.
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
			return el( 'div', { 'class': 'ak-field ak-field--inline' }, [
				el( 'label', { 'class': 'ak-field__label', id: labelId, text: label } ),
				seg
			] );
		}

		var wpField = logoSeg( 'wpLogo', 'ak-wp-logo-label', I.wpLogoLabel || 'Admin bar', [
			{ v: 'logo',    label: I.wpLogoBrand || 'Logo' },
			{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' },
			{ v: 'hide',    label: I.wpLogoHide || 'Hide' }
		] );
		// When no Site Icon is set, the favicon option can't show anything — note it.
		if ( ! D.hasSiteIcon && I.wpLogoNoIcon ) {
			wpField.appendChild( el( 'p', { 'class': 'ak-field__hint', text: I.wpLogoNoIcon } ) );
		}
		var loginField = logoSeg( 'loginLogo', 'ak-login-logo-label', I.loginLogoLabel || 'Login screen', [
			{ v: '',        label: I.wpLogoInherit || 'Inherit' },
			{ v: 'logo',    label: I.wpLogoBrand || 'Logo' },
			{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' }
		] );

		p.appendChild( el( 'div', { 'class': 'ak-group' }, [
			el( 'h2', { 'class': 'ak-group__title', text: I.branding } ),
			I.logoHint ? el( 'p', { 'class': 'ak-group__desc', text: I.logoHint } ) : null,
			el( 'div', { 'class': 'ak-rows' }, [
				wpField,
				loginField,
				logoField( 'light', I.logoLight, I.logoLightMode ),
				logoField( 'dark', I.logoDark, I.logoDarkMode )
			] )
		] ) );

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

	// Plugins tab — every supported integration. Toggle AdminKit's adapter per
	// host; rows whose host isn't active are dimmed + locked (the adapter can't
	// run anyway), but still listed so you can see what's supported.
	function buildPlugins() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( I.pluginsIntro ) ] );
		var list = D.integrations || [];
		if ( ! list.length ) { return p; }

		var inputs = []; // active-integration toggles, for the bulk controls

		function pluginRow( i ) {
			// Inactive host → the adapter can't run, so there's no toggle at all:
			// the row shows an "Inactive" pill in the control slot (right) instead.
			// The stored value is left untouched, so the toggle returns when the
			// host is back active.
			if ( ! i.active ) {
				return el( 'div', { 'class': 'ak-row is-inactive' }, [
					el( 'div', { 'class': 'ak-row__main' }, [
						el( 'span', { 'class': 'ak-row__label', text: i.label } )
					] ),
					el( 'span', { 'class': 'ak-pill ak-pill--off ak-row__state', text: I.inactive } )
				] );
			}
			var input = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
			input.checked = !! state.integrations[ i.slug ];
			input.addEventListener( 'change', function () {
				state.integrations[ i.slug ] = input.checked;
				markDirty();
			} );
			inputs.push( { slug: i.slug, input: input } );
			return el( 'div', { 'class': 'ak-row' }, [
				el( 'div', { 'class': 'ak-row__main' }, [
					el( 'span', { 'class': 'ak-row__label', text: i.label } )
				] ),
				el( 'label', { 'class': 'ak-switch' }, [
					input,
					el( 'span', { 'class': 'ak-switch__track' } ),
					el( 'span', { 'class': 'ak-switch__knob' } )
				] )
			] );
		}

		// Flip every ACTIVE integration in one click (locked/inactive rows ignored).
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
				el( 'h2', { 'class': 'ak-group__title', text: sec.label } ),
				rows
			] ) );
		} );

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
		v.logo_light  = state.logos.light;
		v.logo_dark   = state.logos.dark;
		v.wp_logo     = state.wpLogo;
		v.login_logo  = state.loginLogo;
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
