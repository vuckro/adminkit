/**
 * AdminKit settings — a small, build-free single-page app.
 *
 * Renders three tabs (Dashboard / Design system / Features) into
 * #adminkit-app from the data PHP hands over in window.AdminKitData. Tabs are
 * pill buttons driven by the URL hash (#design / #features / …).
 *
 * The Design system tab is a STATIC reference for now: it lists every semantic
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
		features: {}       // setting key -> bool
	};
	( D.features || [] ).forEach( function ( f ) {
		state.features[ f.key ] = !! f.value;
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
		features: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="10" rx="5"/><circle cx="8" cy="12" r="2.6"/></svg>'
	};

	var tabs = [
		{ id: 'dashboard', label: I.dashboard, icon: ICONS.dashboard, build: buildDashboard },
		{ id: 'design', label: I.design, icon: ICONS.colours, build: buildDesign },
		{ id: 'features', label: I.features, icon: ICONS.features, build: buildFeatures }
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

	// URL hash reflects the active tab (#design / #features).
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

	// Overview tab. Renders the data-driven card list from D.dashboard; a card
	// with a `tab` becomes a shortcut button to that tab.
	function buildDashboard() {
		var dd = D.dashboard || {};
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( dd.intro || '' ) ] );
		var grid = el( 'div', { 'class': 'ak-stat-grid' } );
		( dd.cards || [] ).forEach( function ( c ) {
			// Visual chip: a colour swatch (design system / accent) or an icon.
			var chip;
			if ( c.swatch ) {
				chip = el( 'span', { 'class': 'ak-stat__chip ak-stat__chip--swatch', style: 'background:var(' + c.swatch + ')' } );
			} else {
				chip = el( 'span', { 'class': 'ak-stat__chip' } );
				chip.innerHTML = ICONS[ c.icon ] || '';
			}
			var body = el( 'div', { 'class': 'ak-stat__body' }, [
				el( 'span', { 'class': 'ak-stat__label', text: c.label } ),
				el( 'span', { 'class': 'ak-stat__value', text: c.value } ),
				c.hint ? el( 'span', { 'class': 'ak-stat__hint', text: c.hint } ) : null
			] );
			var inner = el( 'div', { 'class': 'ak-stat__row' }, [ chip, body ] );
			grid.appendChild(
				c.tab
					? el( 'button', { type: 'button', 'class': 'ak-stat ak-stat--link', onclick: function () { go( c.tab ); } }, [ inner ] )
					: el( 'div', { 'class': 'ak-stat' }, [ inner ] )
			);
		} );
		p.appendChild( el( 'div', { 'class': 'ak-group' }, [
			dd.overviewLabel ? el( 'h2', { 'class': 'ak-group__title', text: dd.overviewLabel } ) : null,
			grid
		] ) );

		// What's next — a light roadmap teaser so the page hints at the rest of
		// the SPA. Reuses the row + Soon-pill styles; data comes from D.dashboard.
		if ( dd.next && dd.next.length ) {
			var rows = el( 'div', { 'class': 'ak-rows' } );
			dd.next.forEach( function ( n ) {
				rows.appendChild( el( 'div', { 'class': 'ak-row' }, [
					el( 'div', { 'class': 'ak-row__main' }, [
						el( 'span', { 'class': 'ak-row__label', text: n.label } ),
						n.hint ? el( 'span', { 'class': 'ak-row__desc', text: n.hint } ) : null
					] ),
					el( 'span', { 'class': 'ak-pill ak-pill--soon', text: I.soon } )
				] ) );
			} );
			p.appendChild( el( 'div', { 'class': 'ak-group' }, [
				dd.nextLabel ? el( 'h2', { 'class': 'ak-group__title', text: dd.nextLabel } ) : null,
				rows
			] ) );
		}

		if ( dd.version ) {
			p.appendChild( el( 'p', { 'class': 'ak-dash__ver', text: 'AdminKit ' + dd.version } ) );
		}
		return p;
	}

	// Design system tab — STATIC reference (no generators / no token writes for
	// now). One table per group: swatch · role (+ AdminKit badge) · the --ak
	// token and the provider var / primitive it maps to.
	function buildDesign() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' } );
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
			[ '--ak-text-m', 'Body' ],
			[ '--ak-text-s', 'Small' ],
			[ '--ak-text-xs', 'Caption' ]
		].forEach( function ( s ) {
			scale.appendChild( el( 'div', { 'class': 'ak-type__row' }, [
				el( 'span', { 'class': 'ak-type__sample', style: 'font-size:var(' + s[ 0 ] + ')', text: 'The quick brown fox jumps over the lazy dog' } ),
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
		var rows = el( 'div', { 'class': 'ak-rows' } );
		var refs = {}; // key -> { input, row }

		// A child feature (e.g. mShots under Post previews) is only meaningful
		// while its parent is on; disable + mute it otherwise.
		function applyDep( f ) {
			if ( ! f.parent || ! refs[ f.key ] ) { return; }
			var on = !! state.features[ f.parent ];
			refs[ f.key ].input.disabled = ! on;
			refs[ f.key ].row.classList.toggle( 'is-disabled', ! on );
		}

		( D.features || [] ).forEach( function ( f ) {
			var input = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
			input.checked = !! state.features[ f.key ];
			input.addEventListener( 'change', function () {
				state.features[ f.key ] = input.checked;
				( D.features || [] ).forEach( function ( c ) { if ( c.parent === f.key ) { applyDep( c ); } } );
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
			rows.appendChild( row );
		} );

		( D.features || [] ).forEach( applyDep ); // initial dependency state
		p.appendChild( rows );
		return p;
	}

	// --- save ----------------------------------------------------------------
	// Only the Features toggles are interactive for now; the Design system tab
	// writes nothing.
	function gather() {
		var v = {};
		Object.keys( state.features ).forEach( function ( k ) { v[ k ] = !! state.features[ k ]; } );
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
				// Module toggles take effect on the next visit to those screens.
				// No reload here.
			} )
			.catch( function () {
				state.saving = false;
				updateBar();
				setStatus( 'is-error', I.error );
			} );
	}
}() );
