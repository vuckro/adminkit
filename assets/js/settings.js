/**
 * AdminKit settings — a small, build-free single-page app.
 *
 * Renders four tabs (Dashboard / Design system / Features / Integrations) into
 * #adminkit-app from the data PHP hands over in window.AdminKitData. Tabs are
 * pill buttons driven by the URL hash (#design / #features / …).
 *
 * The Design system tab shows every semantic colour role read-only, and offers
 * three global generators — Palette (neutral ⇄ branded), a Radius preset and
 * Randomise — that write the underlying settings, preview live via an inline
 * <style>, and POST to the REST route on save.
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
		colors: {},        // setting key -> hex string ('' = follow provider)
		sizes: {},         // setting key -> length string ('' = default)
		features: {},      // setting key -> bool
		integrations: {}   // slug -> bool (enabled)
	};
	var sizeMeta = {};     // setting key -> --ak-* token

	var colorMeta = {}; // setting key -> { token, bucket: 'light' | 'dark' | 'both' }
	( D.colors || [] ).forEach( function ( g ) {
		( g.tokens || [] ).forEach( function ( t ) {
			if ( t.edit === 'agnostic' ) {
				state.colors[ t.key ] = t.value || '';
				colorMeta[ t.key ] = { token: t.token, bucket: 'both' };
			} else if ( t.edit === 'dual' ) {
				state.colors[ t.keyLight ] = t.valueLight || '';
				state.colors[ t.keyDark ] = t.valueDark || '';
				colorMeta[ t.keyLight ] = { token: t.token, bucket: 'light' };
				colorMeta[ t.keyDark ] = { token: t.token, bucket: 'dark' };
			}
		} );
	} );
	( D.features || [] ).forEach( function ( f ) {
		state.features[ f.key ] = !! f.value;
	} );
	( D.integrations || [] ).forEach( function ( it ) {
		state.integrations[ it.slug ] = it.enabled !== false;
	} );
	( D.sizing || [] ).forEach( function ( g ) {
		( g.tokens || [] ).forEach( function ( t ) {
			state.sizes[ t.key ] = t.value || '';
			sizeMeta[ t.key ] = t.token;
		} );
	} );
	// Palette mode (neutral | branded) — UI state for the segmented control; the
	// actual tints live in the surface/border/text colour keys.
	state.palette = ( D.palette === 'branded' ) ? 'branded' : 'neutral';

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

	// Live preview: mirror PHP inline_tokens() into a <style> so light + dark
	// overrides resolve correctly per the page's current theme (toggled from the
	// admin bar). Rebuilt from state on every colour change.
	var liveStyle = document.getElementById( 'adminkit-live-tokens' );
	if ( ! liveStyle ) {
		liveStyle = document.createElement( 'style' );
		liveStyle.id = 'adminkit-live-tokens';
		document.head.appendChild( liveStyle );
	}
	function applyLive() {
		var light = '', dark = '';
		Object.keys( state.colors ).forEach( function ( k ) {
			var v = state.colors[ k ];
			if ( ! v ) { return; }
			var m = colorMeta[ k ];
			if ( ! m ) { return; }
			var decl = m.token + ':' + v + ';';
			if ( m.bucket === 'light' ) { light += decl; }
			else if ( m.bucket === 'dark' ) { dark += decl; }
			else { light += decl; dark += decl; }
		} );
		Object.keys( state.sizes ).forEach( function ( k ) {
			var v = state.sizes[ k ];
			if ( ! v || ! sizeMeta[ k ] ) { return; }
			var decl = sizeMeta[ k ] + ':' + v + ';';
			light += decl; dark += decl;
		} );
		// Branded palette: mirror PHP inline_tokens() — remap surfaces + borders
		// onto the provider primary ramp (var(primary, var(neutral))). Appended
		// last so it wins, exactly like the server.
		if ( state.palette === 'branded' && D.brandedMap ) {
			[ 'light', 'dark' ].forEach( function ( bk ) {
				var m = D.brandedMap[ bk ] || {};
				Object.keys( m ).forEach( function ( tok ) {
					var decl = tok + ':var(' + m[ tok ][ 0 ] + ', var(' + m[ tok ][ 1 ] + '));';
					if ( bk === 'light' ) { light += decl; } else { dark += decl; }
				} );
			} );
		}
		liveStyle.textContent =
			( light ? ':root{' + light + '}' : '' ) +
			( dark ? ':root[data-adminkit-theme="dark"]{' + dark + '}' : '' );
	}

	function rgbToHex( rgb ) {
		var m = ( rgb || '' ).match( /\d+/g );
		if ( ! m || m.length < 3 ) { return ''; }
		return '#' + m.slice( 0, 3 ).map( function ( n ) {
			var h = parseInt( n, 10 ).toString( 16 );
			return h.length === 1 ? '0' + h : h;
		} ).join( '' );
	}

	// Resolve a token's actual colour for a given mode — the hex shown in each
	// design-table row. Briefly flips <html>'s theme attribute to read the value;
	// synchronous, so no repaint happens between flip and restore.
	var _probe = null;
	function resolvedHex( token, mode ) {
		var html = document.documentElement;
		var prev = html.getAttribute( 'data-adminkit-theme' );
		html.setAttribute( 'data-adminkit-theme', mode );
		if ( ! _probe ) {
			_probe = document.createElement( 'span' );
			_probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
			( document.body || html ).appendChild( _probe );
		}
		_probe.style.color = 'var(' + token + ')';
		var hex = rgbToHex( getComputedStyle( _probe ).color );
		if ( prev === null ) { html.removeAttribute( 'data-adminkit-theme' ); }
		else { html.setAttribute( 'data-adminkit-theme', prev ); }
		return hex || '#888888';
	}

	// --- palette + radius generators -----------------------------------------
	function hslToHex( h, s, l ) {
		s /= 100; l /= 100;
		var a = s * Math.min( l, 1 - l );
		var f = function ( n ) {
			var k = ( n + h / 30 ) % 12;
			var c = l - a * Math.max( -1, Math.min( k - 3, Math.min( 9 - k, 1 ) ) );
			var x = Math.round( 255 * c ).toString( 16 );
			return x.length === 1 ? '0' + x : x;
		};
		return '#' + f( 0 ) + f( 8 ) + f( 4 );
	}
	function onAccent( hex ) {
		var m = ( hex || '' ).match( /[0-9a-f]{2}/gi );
		if ( ! m ) { return '#ffffff'; }
		var lum = 0.2126 * parseInt( m[ 0 ], 16 ) + 0.7152 * parseInt( m[ 1 ], 16 ) + 0.0722 * parseInt( m[ 2 ], 16 );
		return lum > 150 ? '#111111' : '#ffffff';
	}
	// Radius presets → the two radius size tokens. 'default' clears them so the
	// stylesheet defaults (6 / 10px) win.
	var RADIUS_PRESETS = {
		none:    { radius_s: '0px',  radius_m: '0px' },
		default: { radius_s: '',     radius_m: '' },
		rounded: { radius_s: '10px', radius_m: '16px' }
	};

	// Write a colour / size map into state (only keys that already exist).
	function applyColors( map ) {
		Object.keys( map ).forEach( function ( k ) {
			if ( state.colors.hasOwnProperty( k ) ) { state.colors[ k ] = map[ k ]; }
		} );
	}
	function applySizes( map ) {
		Object.keys( map ).forEach( function ( k ) {
			if ( state.sizes.hasOwnProperty( k ) ) { state.sizes[ k ] = map[ k ]; }
		} );
	}

	// Randomise a brand accent (+ a readable on-accent and a complementary
	// secondary) and a radius preset. Surfaces are NOT touched — they follow the
	// Neutral/Branded toggle, which maps them onto the provider primitives.
	function randomize() {
		var h = Math.floor( Math.random() * 360 );
		var accent = hslToHex( h, 68, 48 );
		applyColors( {
			primary_color: accent,
			on_accent_color: onAccent( accent ),
			secondary_color: hslToHex( ( h + 150 ) % 360, 58, 50 )
		} );
		var keys = Object.keys( RADIUS_PRESETS );
		applySizes( RADIUS_PRESETS[ keys[ Math.floor( Math.random() * keys.length ) ] ] );
		applyLive(); markDirty(); renderDesign(); syncControls();
	}

	// Neutral ⇄ Branded — a global structural switch, no stored hexes: Branded
	// maps surfaces + borders onto the provider PRIMARY ramp (see applyLive() +
	// PHP branded_surface_map()); Neutral returns them to the neutral ramp.
	function setPalette( mode ) {
		state.palette = ( mode === 'branded' ) ? 'branded' : 'neutral';
		applyLive(); markDirty(); renderDesign(); syncControls();
	}

	// Set the corner radius from a preset and reflect it on the segmented control.
	function setRadius( preset ) {
		applySizes( RADIUS_PRESETS[ preset ] || {} );
		applyLive(); markDirty(); syncControls();
	}
	// Which preset the current radius matches (drives the active segment).
	function radiusKeyFromState() {
		var s = state.sizes.radius_s || '', m = state.sizes.radius_m || '';
		var keys = Object.keys( RADIUS_PRESETS );
		for ( var i = 0; i < keys.length; i++ ) {
			var p = RADIUS_PRESETS[ keys[ i ] ];
			if ( ( p.radius_s || '' ) === s && ( p.radius_m || '' ) === m ) { return keys[ i ]; }
		}
		return '';
	}
	function shuffleIcon() {
		var s = el( 'span', { 'class': 'ic' } );
		s.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M21 3 13 11"/><path d="M16 21h5v-5"/><path d="m21 21-7-7"/><path d="M3 8l5-5"/><path d="M3 16l8 0"/></svg>';
		return s;
	}

	// --- header chrome -------------------------------------------------------
	var statusEl = el( 'span', { 'class': 'ak-status', 'aria-live': 'polite' } );
	var resetBtn = el( 'button', { 'class': 'ak-btn', type: 'button', text: I.resetAll, onclick: resetAll } );
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

	// Reset every AdminKit setting to its default (clears the stored option),
	// then reload so the SPA re-renders from defaults.
	function resetAll() {
		if ( ! apiFetch ) { setStatus( 'is-error', I.error ); return; }
		if ( ! window.confirm( I.resetConfirm ) ) { return; }
		var path = D.route.charAt( 0 ) === '/' ? D.route : '/' + D.route;
		apiFetch( { path: path, method: 'POST', data: { reset: true } } )
			.then( function () { window.location.reload(); } )
			.catch( function () { setStatus( 'is-error', I.error ); } );
	}

	// --- build ---------------------------------------------------------------
	app.removeAttribute( 'aria-busy' );
	app.textContent = '';

	app.appendChild( el( 'div', { 'class': 'ak-head' }, [
		el( 'h1', { 'class': 'ak-title', text: 'AdminKit' } ),
		el( 'div', { 'class': 'ak-actions' }, [ statusEl, resetBtn, saveBtn ] )
	] ) );

	var ICONS = {
		dashboard: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg>',
		colours: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.8c3.4 3.9 5.4 6.5 5.4 9.2a5.4 5.4 0 0 1-10.8 0c0-2.7 2-5.3 5.4-9.2z"/></svg>',
		features: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="10" rx="5"/><circle cx="8" cy="12" r="2.6"/></svg>',
		integrations: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9.6 13.4 7 16a3.7 3.7 0 0 1-5.2-5.2l2.6-2.6"/><path d="M14.4 10.6 17 8a3.7 3.7 0 0 1 5.2 5.2l-2.6 2.6"/><path d="M9 15l6-6"/></svg>'
	};

	var tabs = [
		{ id: 'dashboard', label: I.dashboard, icon: ICONS.dashboard, build: buildDashboard },
		{ id: 'design', label: I.design, icon: ICONS.colours, build: buildDesign },
		{ id: 'features', label: I.features, icon: ICONS.features, build: buildFeatures },
		{ id: 'integrations', label: I.integrations, icon: ICONS.integrations, build: buildIntegrations }
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

	// URL hash reflects the active tab (#colours / #features / #integrations).
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
	applyLive();

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

	var designWrap = null; // role groups (re-rendered on each generate)
	var paletteSeg = null; // { neutral, branded } buttons
	var radiusSeg  = null; // { none, default, rounded } buttons

	// Design system tab: a short explainer, the generator toolbar, then every
	// semantic role shown read-only.
	function buildDesign() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( I.designIntro ) ] );
		if ( I.cascade ) { p.appendChild( el( 'p', { 'class': 'ak-cascade', text: I.cascade } ) ); }
		p.appendChild( designToolbar() );
		designWrap = el( 'div', { 'class': 'ak-color-groups' } );
		p.appendChild( designWrap );
		renderDesign();
		return p;
	}

	// Toolbar: Palette (Neutral/Branded) + Radius (None/Default/Rounded) on the
	// left, Randomise on the right. Each segmented control reflects state.
	function designToolbar() {
		function seg( store, opts, onpick, aria ) {
			var wrap = el( 'div', { 'class': 'ak-seg', role: 'group', 'aria-label': aria } );
			opts.forEach( function ( o ) {
				var b = el( 'button', { type: 'button', 'class': 'ak-seg__btn', onclick: function () { onpick( o[ 0 ] ); } }, [ el( 'span', { text: o[ 1 ] } ) ] );
				store[ o[ 0 ] ] = b;
				wrap.appendChild( b );
			} );
			return wrap;
		}
		paletteSeg = {};
		radiusSeg = {};
		var pal = seg( paletteSeg, [ [ 'neutral', I.paletteNeutral ], [ 'branded', I.paletteBranded ] ], setPalette, I.palette );
		var rad = seg( radiusSeg, [ [ 'none', I.radiusNone ], [ 'default', I.radiusDefault ], [ 'rounded', I.radiusRounded ] ], setRadius, I.radius );
		var random = el( 'button', { type: 'button', 'class': 'ak-btn ak-btn--ghost', onclick: randomize, title: I.randomizeHint || '' },
			[ shuffleIcon(), el( 'span', { text: I.randomize } ) ] );
		var bar = el( 'div', { 'class': 'ak-toolbar' }, [
			el( 'div', { 'class': 'ak-toolbar__group', title: I.paletteHint || '' }, [ el( 'span', { 'class': 'ak-toolbar__label', text: I.palette } ), pal ] ),
			el( 'div', { 'class': 'ak-toolbar__group' }, [ el( 'span', { 'class': 'ak-toolbar__label', text: I.radius } ), rad ] ),
			el( 'div', { 'class': 'ak-toolbar__spacer' } ),
			random
		] );
		syncControls();
		return bar;
	}

	// Reflect state on the segmented controls (active button + aria-pressed).
	function syncControls() {
		function mark( store, active ) {
			if ( ! store ) { return; }
			Object.keys( store ).forEach( function ( k ) {
				var on = k === active;
				store[ k ].classList.toggle( 'on', on );
				store[ k ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		}
		mark( paletteSeg, state.palette );
		mark( radiusSeg, radiusKeyFromState() );
	}

	// Render every semantic role as a row in a clean, column-aligned table — one
	// table per group: swatch · role (+ AdminKit badge) · mapping (--ak token ←
	// provider semantic · primitive) · resolved hex. Re-run after each generator.
	function renderDesign() {
		if ( ! designWrap ) { return; }
		designWrap.textContent = '';
		var mode = document.documentElement.getAttribute( 'data-adminkit-theme' ) === 'dark' ? 'dark' : 'light';
		( D.colors || [] ).forEach( function ( g ) {
			var tbl = el( 'div', { 'class': 'ak-tbl' } );
			( g.tokens || [] ).forEach( function ( t ) { roleRow( tbl, t, mode ); } );
			designWrap.appendChild( el( 'div', { 'class': 'ak-group' }, [
				el( 'h2', { 'class': 'ak-group__title', text: g.label } ),
				g.desc ? el( 'p', { 'class': 'ak-group__desc', text: g.desc } ) : null,
				tbl
			] ) );
		} );
	}

	// Append one row (four aligned grid cells) to a group table. Cells are direct
	// grid children so columns line up across rows; CSS draws the separators.
	function roleRow( tbl, t, mode ) {
		tbl.appendChild( el( 'span', { 'class': 'ak-tbl__swc' }, [
			el( 'span', { 'class': 'ak-tbl__sw', style: 'background:var(' + t.token + ')', title: t.token } )
		] ) );
		tbl.appendChild( el( 'span', { 'class': 'ak-tbl__role' }, [
			el( 'span', { 'class': 'ak-tbl__name', text: t.label } ),
			t.own ? el( 'span', { 'class': 'ak-tbl__badge', title: I.ownHint || '', text: I.own } ) : null
		] ) );
		tbl.appendChild( el( 'span', { 'class': 'ak-tbl__map' }, [
			el( 'code', { 'class': 'ak-tbl__tok', text: t.token } ),
			t.bricks ? el( 'code', { 'class': 'ak-tbl__from', text: '← ' + t.bricks } ) : null,
			t.source ? el( 'code', { 'class': 'ak-tbl__prim', text: t.source } ) : null
		] ) );
		tbl.appendChild( el( 'span', { 'class': 'ak-tbl__val', text: resolvedHex( t.token, mode ) } ) );
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

	function buildIntegrations() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( I.integrationsIntro ) ] );
		if ( ! D.integrations || ! D.integrations.length ) {
			p.appendChild( el( 'p', { 'class': 'ak-muted', text: I.none } ) );
			return p;
		}
		[ { type: 'plugin', label: I.plugins }, { type: 'theme', label: I.themes } ].forEach( function ( grp ) {
			var items = D.integrations.filter( function ( it ) { return it.type === grp.type; } );
			if ( ! items.length ) { return; }
			var rows = el( 'div', { 'class': 'ak-rows' } );
			items.forEach( function ( it ) { rows.appendChild( integrationRow( it ) ); } );
			p.appendChild( el( 'div', { 'class': 'ak-group' }, [
				el( 'h2', { 'class': 'ak-group__title', text: grp.label } ),
				rows
			] ) );
		} );
		return p;
	}

	// pill = host detected; toggle = AdminKit applies its skin (off as an escape
	// hatch for conflicts). The toggle is locked when the host isn't present.
	function integrationRow( it ) {
		var input = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
		input.checked = state.integrations[ it.slug ] !== false;
		input.disabled = ! it.active;
		input.addEventListener( 'change', function () { state.integrations[ it.slug ] = input.checked; markDirty(); } );
		return el( 'div', { 'class': 'ak-row ak-row--int' + ( it.active ? '' : ' is-disabled' ) }, [
			el( 'span', { 'class': 'ak-pill ' + ( it.active ? 'ak-pill--on' : 'ak-pill--off' ), text: it.active ? I.active : I.inactive } ),
			el( 'span', { 'class': 'ak-row__label', text: it.label } ),
			el( 'label', { 'class': 'ak-switch' }, [
				input,
				el( 'span', { 'class': 'ak-switch__track' } ),
				el( 'span', { 'class': 'ak-switch__knob' } )
			] )
		] );
	}

	// --- save ----------------------------------------------------------------
	function gather() {
		var v = {};
		Object.keys( state.colors ).forEach( function ( k ) { v[ k ] = state.colors[ k ] || ''; } );
		Object.keys( state.sizes ).forEach( function ( k ) { v[ k ] = state.sizes[ k ] || ''; } );
		Object.keys( state.features ).forEach( function ( k ) { v[ k ] = !! state.features[ k ]; } );
		Object.keys( state.integrations ).forEach( function ( s ) { v[ 'integration_' + s + '_enabled' ] = !! state.integrations[ s ]; } );
		v.palette_mode = state.palette || 'neutral';
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
				// No reload — colours/sizes already preview live via the inline
				// <style>, and module/integration toggles take effect on the next
				// visit to those screens. Fluid, no flash.
			} )
			.catch( function () {
				state.saving = false;
				updateBar();
				setStatus( 'is-error', I.error );
			} );
	}
}() );
