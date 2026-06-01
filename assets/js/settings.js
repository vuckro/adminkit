/**
 * AdminKit settings — a small, build-free single-page app.
 *
 * Mounts inside `<div id="adminkit-app">` which the AdminKit submenu page
 * (Settings → AdminKit) prints as its host. The SPA builds its own outer
 * chrome (`.ak-head` with title + save bar, `.ak-tabs` strip, `.ak-panels`
 * wrapper) and owns hash routing across three internal tabs:
 *   - Dashboard  — Brand card (logos + favicons + accent picker) + token
 *                  reference (read-only)
 *   - Features    — feature toggles (dark mode, post previews, …)
 *   - Plugins    — per-host adapter list + per-plugin opt-outs
 * All three persist through the same `adminkit/v1/settings` REST route.
 *
 * No framework, no build step — vanilla DOM.
 */
( function () {
	'use strict';

	var D = window.AdminKitData;
	if ( ! D ) {
		return;
	}
	var app = document.getElementById( 'adminkit-app' );
	if ( ! app ) {
		return;
	}
	var I = D.i18n || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	// Which surface this page mounts: 'main' (Settings SPA), 'menu' or 'stats'. The
	// PHP host sets it via data-screen; each focused page builds just its one panel
	// (no tab strip). Defaults to 'main' so the SPA still works standalone.
	var SCREEN = ( app.getAttribute && app.getAttribute( 'data-screen' ) ) || D.screen || 'main';

	var state = {
		dirty: false,
		saving: false,
		features: {},      // setting key -> bool
		integrations: {},  // integration slug -> bool (adapter enabled)
		logos: {           // setting key -> url string
			light: ( D.logos && D.logos.light ) || '',
			dark:  ( D.logos && D.logos.dark ) || ''
		},
		wpLogo: ( D.wpLogo === 'logo' ) ? 'logo' : 'favicon',       // admin-bar / site-name mark: logo | favicon
		loginLogo: ( D.loginLogo === 'logo' ) ? 'logo' : 'favicon', // login screen mark: logo | favicon
		brandAccent: D.brandAccent || '',    // user hex (only meaningful when accentSource === 'custom')
		// 'adminkit' = WP Blue (#3858E9), 'bricks' = Bricks provider --accent, 'custom' = brandAccent hex
		accentSource: D.accentSource || 'adminkit'
	};
	( D.features || [] ).forEach( function ( f ) {
		state.features[ f.key ] = !! f.value;
	} );
	// Per-plugin opt-out for generic plugins (no AdminKit adapter) — tracked as
	// a set of plugin file paths the user has flipped OFF. Default = themed;
	// posted as `generic_theming_off` array (see PHP gate_generic_theming()).
	state.genericThemingOff = {};
	( D.integrations || [] ).forEach( function ( i ) {
		if ( i.supported && i.slug ) {
			state.integrations[ i.slug ] = !! i.enabled;
			return;
		}
		// Generic plugin pre-seeded into the off-list when the schema says it
		// starts disabled. System / theme rows without a file are no-ops.
		if ( ! i.system && i.file && i.enabled === false ) {
			state.genericThemingOff[ i.file ] = true;
		}
	} );

	// Menu manager — module state + the working model built from the captured tree
	// and the saved layout. buildMenuModel() is hoisted from the menu-tab section.
	var MM = D.menuManager || { menu: [], config: { top: [], sub: {} }, icons: {} };
	var dragState = null;
	var openPicker = null;
	// The menu working-model is only needed on the Menu screen; skip the (non-trivial)
	// build elsewhere — other screens never ship a menu tree anyway.
	state.menu = ( SCREEN === 'menu' && MM.menu && MM.menu.length ) ? buildMenuModel( MM.menu, MM.config || { top: [], sub: {} } ) : [];
	state.menuDirty = false;

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
	// Print the page head (title + save bar) into the host. The submenu page's
	// `<h1>` is `screen-reader-text` so this visible H1 is the only one users see.
	app.removeAttribute( 'aria-busy' );
	app.textContent = '';
	// Statistics is read-only — no save bar. (Menu + Settings both save.)
	var showSave = ( SCREEN !== 'stats' );
	// Import / Export live only on the main Settings screen (where the values are).
	var actions = showSave ? [ statusEl ] : [];
	if ( SCREEN === 'main' ) {
		actions.push(
			el( 'button', { 'class': 'ak-btn', type: 'button', text: I.settingsExport || 'Export', title: I.settingsExportHint || 'Download these settings as a JSON file', onclick: exportSettings } ),
			el( 'button', { 'class': 'ak-btn', type: 'button', text: I.settingsImport || 'Import', title: I.settingsImportHint || 'Load settings from a JSON file', onclick: function () { importInput.click(); } } )
		);
	}
	if ( showSave ) { actions.push( saveBtn ); }
	var importInput = el( 'input', { type: 'file', accept: 'application/json,.json', hidden: 'hidden', onchange: function ( e ) { if ( e.target.files && e.target.files[0] ) { importSettings( e.target.files[0] ); } e.target.value = ''; } } );
	app.appendChild( el( 'div', { 'class': 'ak-head' }, [
		el( 'h1', { 'class': 'ak-title', text: 'AdminKit' } ),
		el( 'div', { 'class': 'ak-actions' }, actions.concat( SCREEN === 'main' ? [ importInput ] : [] ) )
	] ) );

	var ICONS = {
		brand: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".9" fill="currentColor" stroke="none"/><circle cx="17.5" cy="10.5" r=".9" fill="currentColor" stroke="none"/><circle cx="8.5" cy="7.5" r=".9" fill="currentColor" stroke="none"/><circle cx="6.5" cy="12.5" r=".9" fill="currentColor" stroke="none"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
		dashboard: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg>',
		features: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
		plugins: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v4M15 2v4M7 6h10a1 1 0 0 1 1 1v3a6 6 0 0 1-12 0V7a1 1 0 0 1 1-1zM12 16v6"/></svg>',
		menu: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
		stats: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx=".5"/><rect x="12.5" y="8" width="3" height="10" rx=".5"/><rect x="18" y="5" width="3" height="13" rx=".5"/></svg>',
		// Upload arrow-up-tray — shown in empty brand-slot zones in lieu of a "Drop" text.
		upload: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>'
	};

	// Tabs are assembled PER SCREEN. The Settings ("main") screen keeps the tabbed
	// SPA — Brand · Features · Plugins (they save together, so tabs belong here).
	// The Menu and Statistics screens are focused single-panel pages (the tab strip
	// is hidden below); each owns its own AdminKit submenu page.
	var tabs;
	if ( SCREEN === 'menu' ) {
		tabs = [ { id: 'menu', label: I.menu, icon: ICONS.menu, build: buildMenu } ];
	} else if ( SCREEN === 'stats' ) {
		tabs = [ { id: 'stats', label: I.statsTab || 'Statistics', icon: ICONS.stats, build: buildStats } ];
	} else {
		tabs = [
			{ id: 'brand', label: I.brandTitle, icon: ICONS.brand, build: buildDesign },
			{ id: 'features', label: I.features, icon: ICONS.features, build: buildFeatures },
			{ id: 'plugins', label: I.plugins, icon: ICONS.plugins, build: buildPlugins }
		];
	}
	var activeId = tabs[ 0 ].id;
	var panels = {};

	// Build each tab's content once. t.build() returns a DOM element we mount
	// into our own panel wrap below.
	tabs.forEach( function ( t ) {
		panels[ t.id ] = t.build();
	} );

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

	// Focused single-panel screens (Menu, Statistics) drop the tab strip entirely —
	// there's nothing to switch between, and the page reads cleaner without it.
	if ( tabs.length > 1 ) {
		app.appendChild( nav );
	}
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

	// URL hash reflects the active tab (#dashboard / #plugins).
	function go( id ) {
		if ( '#' + id === location.hash ) { selectTab( id ); }
		else { location.hash = id; } // triggers hashchange → applyHash
	}
	function applyHash() {
		var h = ( location.hash || '' ).replace( /^#/, '' );
		// Menu + Statistics moved to their own AdminKit submenu pages. Redirect any
		// lingering #menu / #stats deep-link on the Settings screen to the new page.
		if ( SCREEN === 'main' && ( h === 'menu' || h === 'stats' ) ) {
			window.location.href = 'admin.php?page=adminkit-' + h;
			return;
		}
		var valid = tabs.some( function ( t ) { return t.id === h; } );
		selectTab( valid ? h : tabs[ 0 ].id );
	}
	window.addEventListener( 'hashchange', applyHash );
	applyHash();
	updateBar();

	// --- panels --------------------------------------------------------------
	function intro( text ) { return el( 'p', { 'class': 'ak-intro', text: text } ); }

	// Brand card builder. Lays out one .ak-card with the four media slots
	// (Favicon Light · Logo Light · Favicon Dark · Logo Dark), the accent
	// picker and the Display segmented controls.
	function buildDesign() {
		var p = el( 'section', { 'class': 'ak-panel ak-panel--brand', role: 'tabpanel' } );

		// Assigned once the live-preview pane is built (below). Logo + display-mode
		// controls call it to re-skin the mock in real time. (Accent rides the
		// global --ak-primary the accent picker already updates, so it needs no
		// explicit refresh here.) Closures capture the var, so the stub is safe
		// until the real updater lands.
		var previewUpdate = function () {};

		// --- Common helpers used inside buildDesign() ---------------------------

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
		// or upload-arrow placeholder) + label + sub + Upload / Remove button.
		// `slotKey` is 'light' or 'dark' → the brand wordmark URL in `state.logos[key]`.
		function brandSlot( slotKey, label, sub ) {
			var preview = el( 'img', { 'class': 'ak-brand-slot__preview', alt: '' } );
			// Empty-state placeholder is an upload-arrow icon (the zone is a
			// click-to-upload shortcut alongside the Upload button below).
			var dropTxt = el( 'span', { 'class': 'ak-brand-slot__drop', 'aria-hidden': 'true' } );
			dropTxt.innerHTML = ICONS.upload;
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
				return state.logos[ slotKey ] || '';
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
			function setLogo( url ) {
				state.logos[ slotKey ] = url || '';
				syncPreview();
				markDirty();
				previewUpdate();
			}

			// Logos use the plain media frame (free-form aspect, no crop).
			var pick = openMedia;

			// Drop zone is always a "pick / replace" shortcut. The action button
			// branches on current state — clears when filled, picks when empty.
			zone.addEventListener( 'click', function () { pick( setLogo ); } );
			actionBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( currentUrl() ) {
					setLogo( '', null );
				} else {
					pick( setLogo );
				}
			} );

			syncPreview();

			// Label row (always present) + optional badge chip next to it.
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
					previewUpdate();
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
			// Bricks only appears in the segmented when the integration is connected
			// (theme active AND toggle on). If the user disables the integration the
			// pill silently drops; their stored `accentSource === 'bricks'` still
			// resolves server-side via accent_source(), but the UI no longer offers a
			// dead button. Hide rather than grey: a dead pill was confusing.
			var sources = [ { v: 'adminkit', label: I.accentSrcAdminKit || 'WordPress' } ];
			if ( D.bricksConnected ) {
				sources.push( { v: 'bricks', label: I.accentSrcBricks || 'Bricks' } );
			}
			sources.push( { v: 'custom', label: I.accentSrcCustom || 'Custom' } );

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

			// No inner "Accent" sub-label — the parent row already carries the
			// "Color" label on the left (same treatment as the Display row).
			return el( 'div', { 'class': 'ak-display-row__field ak-accent-inline' }, [
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
		// The card head used to carry an uppercase eyebrow above the title
		// (e.g. "BRAND" + "Logo, favicon & accent"). With the title now reading
		// simply "Marque", the eyebrow just duplicates it — removed.
		var cardHead = el( 'div', { 'class': 'ak-card__head' } );
		var headMain = el( 'div', { 'class': 'ak-card__head-main' }, [
			el( 'h2', { 'class': 'ak-card__title', text: I.brandTitle || 'Marque' } )
		] );
		// Bricks-sync status — visible ONLY when the integration is connected
		// (theme active AND toggle on). Green dot + label + (N tokens) when
		// available. Hidden entirely when disconnected — keeps the card clean
		// rather than showing a dead "Bricks not connected" sentence on every
		// site that doesn't use Bricks.
		if ( D.bricksConnected ) {
			var status = el( 'div', { 'class': 'ak-card__status is-connected' }, [
				el( 'span', { 'class': 'ak-card__status-dot', 'aria-hidden': 'true' } ),
				el( 'span', { 'class': 'ak-card__status-label', text: I.brandSyncStatus || 'Tokens synced with Bricks Builder' } )
			] );
			var n = parseInt( D.bricksTokenCount, 10 );
			if ( n > 0 ) {
				var fmt = I.brandSyncStatusCount || '%d tokens';
				status.appendChild( el( 'span', { 'class': 'ak-card__status-count', text: fmt.replace( '%d', String( n ) ) } ) );
			}
			headMain.appendChild( status );
		}
		cardHead.appendChild( headMain );

		var slotsRow = el( 'div', { 'class': 'ak-brand-slots' }, [
			brandSlot( 'light', I.slotLight || 'Logo Light Mode', I.slotLightSub || 'SVG · PNG ≥ 400×100' ),
			brandSlot( 'dark', I.slotDark || 'Logo Dark Mode', I.slotDarkSub || 'SVG · PNG ≥ 400×100' )
		] );

		// Display row — segmented controls for Admin bar + Login screen, and
		// the compact inline Accent picker all on the same line. Everything
		// the user can configure post-upload lives here, in one row, after
		// the brand slots. The derived-colours strip is intentionally gone —
		// the cascade derives Hover / Subtle / On-accent / Focus from --ak-primary
		// automatically through color-mix(), so showing those values added clutter
		// without a control. The Accent column anchors the right of the row so
		// the colour is always close to the controls it tints.
		// Admin bar: no `hide` option — picking Favicon when no Site Icon is set
		// already yields a bare title (favicon_chip_css returns ''), so the third
		// choice was redundant. Login screen keeps its `hide` (separate control).
		// Order: Favicon first (it's the schema default — see register_branding),
		// Logo second (the customisation step). Reads left-to-right as "starts
		// here, swap in your logo if you have one".
		var wpField = logoSeg( 'wpLogo', 'ak-wp-logo-label', I.wpLogoLabel || 'WordPress', [
			{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' },
			{ v: 'logo',    label: I.wpLogoBrand || 'Logo' }
		] );
		// Login screen mirrors the admin bar: no `hide` option. Favicon with no
		// Site Icon set collapses the WP login logo entirely (see class-login.php).
		var loginField = logoSeg( 'loginLogo', 'ak-login-logo-label', I.loginLogoLabel || 'Login', [
			{ v: 'favicon', label: I.wpLogoFavicon || 'Favicon' },
			{ v: 'logo',    label: I.wpLogoBrand || 'Logo' }
		] );
		// Two rows of identical chrome — same .ak-display-row, same row label
		// treatment — so Display and Color read as siblings, not as a row + a
		// pushed-right inline picker.
		var displayRow = el( 'div', { 'class': 'ak-display-row' }, [
			el( 'span', { 'class': 'ak-display-row__lbl', text: I.displayLabel || 'Display' } ),
			wpField, loginField
		] );
		var colorRow = el( 'div', { 'class': 'ak-display-row' }, [
			el( 'span', { 'class': 'ak-display-row__lbl', text: I.accentLabel || 'Color' } ),
			accentPicker()
		] );

		// --- Live preview pane ---------------------------------------------------
		// A self-contained mock of the admin chrome + login screen that re-skins as
		// you edit: the logo (light/dark variant), the favicon-vs-logo display mode,
		// and the accent (which rides the global --ak-primary the accent picker
		// already updates live). Its OWN light/dark toggle uses fixed backdrops —
		// like a swatch — so you see the brand in both themes regardless of the
		// admin's current theme. Decorative throughout: aria-hidden, buttons inert.
		function brandPreview() {
			var theme = ( document.documentElement.getAttribute( 'data-adminkit-theme' ) === 'dark' ) ? 'dark' : 'light';

			var barMark   = el( 'span', { 'class': 'ak-bp__mark' } );
			var loginMark = el( 'span', { 'class': 'ak-bp__login-mark' } );

			// Paint a brand mark (admin bar or login) for the current mode + theme:
			//   logo mode    → the logo image for the active preview theme
			//   favicon mode → the Site Icon (or an accent chip) + the site name
			//   no asset     → a "Your logo" placeholder
			function paintMark( markEl, mode ) {
				markEl.textContent = '';
				var logo = ( theme === 'dark' ? state.logos.dark : state.logos.light ) || state.logos.light || state.logos.dark || '';
				if ( 'favicon' === mode ) {
					if ( D.siteIcon ) {
						markEl.appendChild( el( 'img', { 'class': 'ak-bp__favicon', src: D.siteIcon, alt: '' } ) );
					} else {
						markEl.appendChild( el( 'span', { 'class': 'ak-bp__favicon ak-bp__favicon--empty' } ) );
					}
					markEl.appendChild( el( 'span', { 'class': 'ak-bp__site', text: D.siteName || '' } ) );
				} else if ( logo ) {
					markEl.appendChild( el( 'img', { 'class': 'ak-bp__logo', src: logo, alt: '' } ) );
				} else {
					markEl.appendChild( el( 'span', { 'class': 'ak-bp__logo-ph', text: I.brandPreviewLogo || 'Your logo' } ) );
				}
			}

			var bar = el( 'div', { 'class': 'ak-bp__bar' }, [
				barMark,
				el( 'span', { 'class': 'ak-bp__dots' }, [
					el( 'span', { 'class': 'ak-bp__dot' } ),
					el( 'span', { 'class': 'ak-bp__dot' } ),
					el( 'span', { 'class': 'ak-bp__dot' } )
				] )
			] );

			// Menu rail — five rows, the second active (painted in the accent).
			var menu = el( 'div', { 'class': 'ak-bp__menu' } );
			for ( var i = 0; i < 5; i++ ) {
				menu.appendChild( el( 'div', { 'class': 'ak-bp__nav' + ( 1 === i ? ' is-active' : '' ) }, [
					el( 'span', { 'class': 'ak-bp__nav-ic' } ),
					el( 'span', { 'class': 'ak-bp__nav-bar' } )
				] ) );
			}

			var content = el( 'div', { 'class': 'ak-bp__content' }, [
				el( 'div', { 'class': 'ak-bp__h' } ),
				el( 'div', { 'class': 'ak-bp__line' } ),
				el( 'div', { 'class': 'ak-bp__line ak-bp__line--short' } ),
				el( 'button', { type: 'button', tabindex: '-1', 'class': 'ak-bp__btn', text: I.brandPreviewButton || 'Button' } ),
				el( 'div', { 'class': 'ak-bp__login' }, [
					loginMark,
					el( 'div', { 'class': 'ak-bp__field' } ),
					el( 'div', { 'class': 'ak-bp__field' } ),
					el( 'button', { type: 'button', tabindex: '-1', 'class': 'ak-bp__btn ak-bp__btn--block', text: I.brandPreviewSignIn || 'Sign in' } )
				] )
			] );

			var frame = el( 'div', { 'class': 'ak-bp__frame', 'data-preview-theme': theme }, [
				bar,
				el( 'div', { 'class': 'ak-bp__layout' }, [ menu, content ] )
			] );

			function update() {
				frame.setAttribute( 'data-preview-theme', theme );
				paintMark( barMark, state.wpLogo );
				paintMark( loginMark, state.loginLogo );
			}

			var lightBtn = el( 'button', { type: 'button', 'class': 'ak-bp__theme' + ( 'light' === theme ? ' is-active' : '' ), text: I.brandPreviewLight || 'Light' } );
			var darkBtn  = el( 'button', { type: 'button', 'class': 'ak-bp__theme' + ( 'dark' === theme ? ' is-active' : '' ), text: I.brandPreviewDark || 'Dark' } );
			function setTheme( t ) {
				theme = t;
				lightBtn.classList.toggle( 'is-active', 'light' === t );
				darkBtn.classList.toggle( 'is-active', 'dark' === t );
				update();
			}
			lightBtn.addEventListener( 'click', function () { setTheme( 'light' ); } );
			darkBtn.addEventListener( 'click', function () { setTheme( 'dark' ); } );

			var head = el( 'div', { 'class': 'ak-bp__head' }, [
				el( 'span', { 'class': 'ak-bp__title', text: I.brandPreviewTitle || 'Live preview' } ),
				el( 'div', { 'class': 'ak-bp__themes', role: 'group' }, [ lightBtn, darkBtn ] )
			] );

			var root = el( 'div', { 'class': 'ak-brand-preview' }, [ head, frame ] );
			update();
			return { el: root, update: update };
		}

		// Studio: identity + controls on the left, the live preview on the right.
		var controls = el( 'div', { 'class': 'ak-brand-studio__controls' }, [ slotsRow, displayRow, colorRow ] );
		var preview  = brandPreview();
		previewUpdate = preview.update;

		var card = el( 'section', { 'class': 'ak-card ak-card--studio' }, [
			cardHead,
			el( 'div', { 'class': 'ak-brand-studio' }, [ controls, preview.el ] )
		] );
		p.appendChild( card );

		return p;
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
				byGroup[ label ] = { label: label, rows: el( 'div', { 'class': 'ak-rows' } ), keys: [], wrapEl: null };
				groups.push( byGroup[ label ] );
			}
			return byGroup[ label ].rows;
		}

		// Reflect a feature's state on its row: dim it ("is-off") when switched
		// off — the switch stays clickable so it can be turned back on — and,
		// for a child OR an unavailable feature (e.g. Bricks builder without
		// the Bricks theme), lock it ("is-locked": dimmed + the switch made
		// non-operable, both via CSS and the disabled input).
		// `available: false` is permanent for the session; `parent` locks/
		// unlocks live as the parent toggles.
		function refreshRow( f ) {
			var r = refs[ f.key ];
			if ( ! r ) { return; }
			if ( f.available === false ) {
				// Hard lock — prerequisite isn't met. Force the visual state to
				// OFF so it doesn't read as "on but blocked" (confusing — looks
				// like the feature is somehow half-active). The saved value in
				// state.features stays untouched: when the prerequisite returns,
				// the row re-renders with the user's last actual choice.
				r.input.disabled = true;
				r.input.checked  = false;
				r.row.classList.add( 'is-locked' );
				r.row.classList.add( 'is-off' );
				return;
			}
			r.row.classList.toggle( 'is-off', ! state.features[ f.key ] );
			r.input.checked = !! state.features[ f.key ];
			if ( f.parent ) {
				var parentOn = !! state.features[ f.parent ];
				r.input.disabled = ! parentOn;
				r.row.classList.toggle( 'is-locked', ! parentOn );
			}
		}

		// Flip every feature at once (the "enable / disable all" controls).
		// Rows flagged `bulk: false` OR `available: false` (prerequisite missing)
		// are left untouched — sweeping them would be nonsensical.
		function setAll( on ) {
			( D.features || [] ).forEach( function ( f ) {
				if ( f.bulk === false || f.available === false ) { return; }
				state.features[ f.key ] = on;
				if ( refs[ f.key ] ) { refs[ f.key ].input.checked = on; }
			} );
			( D.features || [] ).forEach( refreshRow );
			markDirty();
		}

		// Restore every feature to its registered schema default (passed in
		// `f.default` from boot_data). No confirm prompt — markDirty puts the
		// page in "unsaved" state so the user can still walk away without
		// committing by reloading or navigating off. Same skip rules as setAll.
		function resetAll() {
			( D.features || [] ).forEach( function ( f ) {
				if ( f.bulk === false || f.available === false ) { return; }
				var def = !! f.default;
				state.features[ f.key ] = def;
				if ( refs[ f.key ] ) { refs[ f.key ].input.checked = def; }
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
			var rowAttrs = { 'class': 'ak-row' + ( f.parent ? ' ak-row--child' : '' ) };
			// Show the prereq hint on hover when the feature can't run (e.g.
			// Bricks builder without the Bricks theme). refreshRow() applies the
			// is-locked + disabled styling; this just explains why.
			if ( f.available === false && f.unavailableHint ) {
				rowAttrs.title = f.unavailableHint;
			}
			var row = el( 'div', rowAttrs, [
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
			byGroup[ f.group || '' ].keys.push( f.key );
		} );

		( D.features || [] ).forEach( refreshRow ); // initial dim + dependency state

		// Search/filter — narrows the visible rows by label + description as you
		// type, hides groups left with nothing, and expands all while searching.
		var search = el( 'input', {
			type: 'search',
			'class': 'ak-search',
			placeholder: I.searchFeatures || 'Search features…',
			'aria-label': I.searchFeatures || 'Search features…'
		} );
		function filter() {
			var q = ( search.value || '' ).trim().toLowerCase();
			( D.features || [] ).forEach( function ( f ) {
				var r = refs[ f.key ];
				if ( ! r ) { return; }
				var hay = ( ( f.label || '' ) + ' ' + ( f.desc || '' ) ).toLowerCase();
				r.row.style.display = ( ! q || hay.indexOf( q ) !== -1 ) ? '' : 'none';
			} );
			groups.forEach( function ( g ) {
				if ( ! g.wrapEl ) { return; }
				var anyVisible = g.keys.some( function ( k ) {
					return refs[ k ] && refs[ k ].row.style.display !== 'none';
				} );
				g.wrapEl.style.display = anyVisible ? '' : 'none';
			} );
		}
		search.addEventListener( 'input', filter );
		p.appendChild( el( 'div', { 'class': 'ak-search-wrap' }, [ search ] ) );

		// Bulk controls — flip every feature on/off, or restore the registered
		// schema defaults (reuses the header's flex row + secondary buttons;
		// no new layout CSS).
		p.appendChild( el( 'div', { 'class': 'ak-actions ak-bulk' }, [
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.enableAll, onclick: function () { setAll( true ); } } ),
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.disableAll, onclick: function () { setAll( false ); } } ),
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.resetDefaults || 'Reset to defaults', onclick: resetAll } )
		] ) );

		// One titled block per group label (order = first-seen). Static heading —
		// the search field handles discovery; folding groups was visual noise.
		groups.forEach( function ( g ) {
			var wrap = el( 'div', { 'class': 'ak-group' }, [
				g.label ? el( 'h2', { 'class': 'ak-group__title', text: g.label } ) : null,
				g.rows
			] );
			g.wrapEl = wrap;
			p.appendChild( wrap );
		} );

		return p;
	}

	// Statistics tab — full drill-in for the cookieless tracker. Read only (no save
	// bar): two native date inputs (start + end) fetch the full payload over REST
	// and render metrics + a bar chart + the complete top-pages / top-sources lists.
	// Lazy: loads on first reveal, refetches when either date changes (debounced).
	function buildStats() {
		var st = ( D.stats || {} );
		var initial = st.state || {};
		var today   = st.today || '';
		var presets = ( st.presets && st.presets.length )
			? st.presets
			: [ { id: '30d', label: '30 days' }, { id: 'custom', label: 'Custom' } ];
		var current = {
			preset: initial.preset || '30d',
			start:  initial.start  || today,
			end:    initial.end    || today
		};
		var loaded = false;
		var pendingDates = null;
		// Live-view auto-refresh state. Cadence comes from PHP (LIVE_REFRESH_MS),
		// clamped to a sane floor. Timer is set up only when preset === 'live'.
		var liveTimer    = null;
		var liveInterval = ( st.liveRefresh && st.liveRefresh >= 2000 ) ? st.liveRefresh : 10000;

		var p = el( 'section', { 'class': 'ak-panel ak-stats', role: 'tabpanel' }, [ intro( I.statsIntro ) ] );

		// Header: active pill + preset chips + (revealed when Custom) date inputs.
		var active = el( 'span', { 'class': 'ak-stats__active', hidden: 'hidden' } );

		var presetsWrap = el( 'div', { 'class': 'ak-stats__presets', role: 'group' } );
		var presetBtns = {};
		presets.forEach( function ( per ) {
			var on   = per.id === current.preset;
			var live = ( per.id === 'live' );
			var cls  = 'ak-stats__preset' + ( on ? ' is-active' : '' ) + ( live ? ' ak-stats__preset--live' : '' );
			var attrs = {
				type: 'button',
				'class': cls,
				'aria-pressed': on ? 'true' : 'false',
				onclick: function () { onPresetClick( per.id ); }
			};
			// Live carries a pulse dot (calm by default; CSS only animates when active).
			var b = live
				? el( 'button', attrs, [
					el( 'span', { 'class': 'ak-stats__pulse', 'aria-hidden': 'true' } ),
					document.createTextNode( per.label )
				] )
				: el( 'button', ( attrs.text = per.label, attrs ) );
			presetBtns[ per.id ] = b;
			presetsWrap.appendChild( b );
		} );

		var startIn = el( 'input', {
			type: 'date',
			'class': 'ak-stats__date',
			value: current.start,
			max: today,
			'aria-label': I.statsRangeFrom || 'From'
		} );
		var endIn = el( 'input', {
			type: 'date',
			'class': 'ak-stats__date',
			value: current.end,
			max: today,
			'aria-label': I.statsRangeTo || 'To'
		} );
		startIn.addEventListener( 'change', scheduleDates );
		endIn.addEventListener( 'change', scheduleDates );

		var customWrap = el( 'div', { 'class': 'ak-stats__custom' }, [
			el( 'span', { 'class': 'ak-stats__range-lbl', text: I.statsRangeFrom || 'From' } ),
			startIn,
			el( 'span', { 'class': 'ak-stats__range-lbl', text: I.statsRangeTo || 'To' } ),
			endIn
		] );
		if ( current.preset !== 'custom' ) { customWrap.hidden = true; }

		var rangeWrap = el( 'div', {
			'class': 'ak-stats__range',
			role: 'group',
			'aria-label': I.statsRangeLabel || 'Date range',
			'data-ak-range-mode': current.preset === 'live'
				? 'live'
				: ( current.preset === 'custom' ? 'custom' : 'preset' )
		}, [ presetsWrap, customWrap ] );
		p.appendChild( el( 'div', { 'class': 'ak-stats__head' }, [ active, rangeWrap ] ) );

		// Body (swapped on each load).
		var body = el( 'div', { 'class': 'ak-stats__body' } );
		p.appendChild( body );

		function setPresetActive( id ) {
			Object.keys( presetBtns ).forEach( function ( key ) {
				var on = key === id;
				presetBtns[ key ].classList.toggle( 'is-active', on );
				presetBtns[ key ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			var isCustom = ( id === 'custom' );
			var isLive   = ( id === 'live' );
			customWrap.hidden = ! isCustom;
			rangeWrap.setAttribute( 'data-ak-range-mode', isLive ? 'live' : ( isCustom ? 'custom' : 'preset' ) );
		}

		function onPresetClick( id ) {
			// Leaving Live → kill the timer before doing anything else.
			if ( id !== 'live' ) { stopLive(); }
			if ( id === 'custom' ) {
				// Local reveal only; load() fires when dates actually change.
				setPresetActive( 'custom' );
				if ( startIn ) { startIn.focus(); }
				return;
			}
			current.preset = id;
			setPresetActive( id );
			load();
		}

		function scheduleDates() {
			if ( pendingDates ) { clearTimeout( pendingDates ); }
			pendingDates = setTimeout( function () {
				pendingDates = null;
				current.preset = 'custom';
				current.start  = startIn.value;
				current.end    = endIn.value;
				load();
			}, 200 );
		}

		function load( silent ) {
			if ( ! apiFetch || ! st.route ) {
				body.textContent = I.statsNone || 'No data';
				return;
			}
			if ( ! silent ) { p.classList.add( 'is-loading' ); }
			var base = st.route.charAt( 0 ) === '/' ? st.route : '/' + st.route;
			var path = base + '?preset=' + encodeURIComponent( current.preset );
			if ( current.preset === 'custom' ) {
				path += '&start=' + encodeURIComponent( current.start )
				     +  '&end='   + encodeURIComponent( current.end );
			}
			apiFetch( { path: path } )
				.then( function ( res ) {
					if ( res && res.start && res.end ) {
						// Server may clamp/swap — resync local state and the inputs.
						current.preset = res.preset || current.preset;
						current.start  = res.start;
						current.end    = res.end;
						startIn.value  = res.start;
						endIn.value    = res.end;
						setPresetActive( current.preset );
					}
					render( res || {} );
				} )
				.catch( function () { if ( ! silent ) { body.textContent = I.statsNone || 'No data'; } } )
				.then( function () {
					if ( ! silent ) { p.classList.remove( 'is-loading' ); loaded = true; }
					syncLivePolling();
				} );
		}

		// Polling lifecycle for Live mode. Three gates: preset, panel visibility,
		// tab visibility — drop any one and we stop the timer; re-meet them all
		// and a single scheduleLive() rearms us.
		function stopLive() {
			if ( liveTimer ) { clearTimeout( liveTimer ); liveTimer = null; }
		}
		function scheduleLive( delay ) {
			stopLive();
			liveTimer = setTimeout( tickLive, delay );
		}
		function syncLivePolling() {
			if ( current.preset !== 'live' || p.hidden || document.hidden ) {
				stopLive();
				return;
			}
			if ( liveTimer ) { return; }
			scheduleLive( liveInterval );
		}
		function tickLive() {
			liveTimer = null;
			if ( current.preset !== 'live' || p.hidden || document.hidden ) { return; }
			load( true );
		}
		document.addEventListener( 'visibilitychange', syncLivePolling );

		function num( n ) {
			try { return Number( n || 0 ).toLocaleString(); } catch ( e ) { return String( n || 0 ); }
		}

		// Last live count rendered, so each refresh can TICK from the old value to the
		// new one instead of snapping (the polling already happens — this is just the
		// in-place tween, no extra requests).
		var lastLiveCount = null;
		function animateCount( elNum, from, to ) {
			if ( ! elNum ) { return; }
			from = from | 0; to = to | 0;
			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			if ( from === to || reduce || ! window.requestAnimationFrame ) { elNum.textContent = num( to ); return; }
			var start = null, dur = 600;
			function step( ts ) {
				if ( null === start ) { start = ts; }
				var p = Math.min( 1, ( ts - start ) / dur );
				var e = 1 - Math.pow( 1 - p, 3 ); // easeOutCubic
				elNum.textContent = num( Math.round( from + ( to - from ) * e ) );
				if ( p < 1 ) { requestAnimationFrame( step ); }
			}
			requestAnimationFrame( step );
		}

		function render( res ) {
			body.innerHTML = '';

			// Live mode has its own shape (no totals, no series) — the big count IS
			// the active number, so the header pill would be redundant. Hide it here;
			// it shows on the PERIOD views instead, as live context.
			if ( res.preset === 'live' ) {
				active.hidden = true;
				renderLive( res );
				return;
			}

			// Period presets — surface the "active now" pill (live context next to
			// the historical totals).
			var a = res.active | 0;
			if ( a > 0 ) {
				active.hidden = false;
				active.innerHTML = '';
				active.appendChild( el( 'span', { 'class': 'ak-stats__active-dot' } ) );
				active.appendChild( document.createTextNode( num( a ) + ' ' + ( I.statsActive || 'active now' ) ) );
			} else {
				active.hidden = true;
			}

			var totals = res.totals || { visits: 0, pageviews: 0 };

			// Metrics — ALWAYS shown (a clear "0 / 0" beats a blank panel when a short
			// window like Today has no views yet). Visits / Page views, each with a
			// ▲/▼ trend vs the previous equal-length period, then extension tiles.
			var prev = res.previous || null;
			var tiles = [
				metric( num( totals.visits ), I.statsVisits || 'Visits', prev ? trendFrom( totals.visits, prev.visits ) : null ),
				metric( num( totals.pageviews ), I.statsPageviews || 'Page views', prev ? trendFrom( totals.pageviews, prev.pageviews ) : null )
			].concat( extraCards( res.cards || [] ) );
			body.appendChild( el( 'div', { 'class': 'ak-stats__metrics' }, tiles ) );

			if ( ! ( totals.pageviews > 0 ) ) {
				body.appendChild( el( 'p', { 'class': 'ak-stats__empty', text: I.statsNoData || 'No traffic data yet.' } ) );
				return;
			}

			// Line/area chart (page views per day). null for a single day (no line to
			// draw) — append only when present.
			var c = chart( res.series || [] );
			if ( c ) { body.appendChild( c ); }

			// Two full lists.
			body.appendChild( el( 'div', { 'class': 'ak-stats__cols' }, [
				pageList( res.pages || [] ),
				sourceList( res.sources || [] )
			] ) );
		}

		// Live-mode body: oversized active-now count, then "Currently viewing"
		// (pages) and "Coming from" (sources) lists scoped to the active visitors
		// in the last few minutes. Same list shell as the period view so the
		// visual rhythm is preserved.
		function renderLive( res ) {
			var count = res.active | 0;
			var numEl = el( 'span', { 'class': 'ak-stats__live-num' } );
			body.appendChild( el( 'div', { 'class': 'ak-stats__live-headline' }, [
				numEl,
				el( 'span', {
					'class': 'ak-stats__live-lbl',
					text: count === 1
						? ( I.statsLiveHeadOne  || 'visitor active right now' )
						: ( I.statsLiveHeadMany || 'visitors active right now' )
				} )
			] ) );
			// Tick from the previous count → the new one (count up from 0 on first load).
			animateCount( numEl, ( null === lastLiveCount ? 0 : lastLiveCount ), count );
			lastLiveCount = count;
			if ( 0 === count ) {
				body.appendChild( el( 'p', { 'class': 'ak-stats__live-empty', text: I.statsLiveEmpty || 'Nobody is on the site right now.' } ) );
				return;
			}
			body.appendChild( el( 'div', { 'class': 'ak-stats__cols' }, [
				liveList( I.statsLiveViewing || 'Currently viewing', res.pages || [], true ),
				liveList( I.statsLiveFrom    || 'Coming from',       res.sources || [], false )
			] ) );
		}

		function liveList( heading, rows, isPages ) {
			var box = listShell(
				heading,
				isPages ? ( I.statsColPage || 'Page' ) : ( I.statsColSource || 'Source' ),
				I.statsLiveColViewers || 'Viewers'
			);
			if ( ! rows.length ) {
				box.appendChild( el( 'p', { 'class': 'ak-stats__none', text: I.statsNone || 'No data' } ) );
				return box;
			}
			var ul = el( 'ul', { 'class': 'ak-stats__rows' } );
			rows.forEach( function ( r ) {
				var nameEl;
				if ( isPages ) {
					nameEl = el( 'a', { 'class': 'ak-stats__row-name', href: r.url || '#', target: '_blank', rel: 'noopener', title: r.path || '' }, [
						el( 'span', { 'class': 'ak-stats__row-title', text: r.title || r.path || '' } ),
						el( 'span', { 'class': 'ak-stats__row-sub',   text: r.path || '' } )
					] );
				} else {
					var label = ( r.name === 'direct' ) ? ( I.statsDirect || 'Direct' ) : ( r.name || '' );
					nameEl = el( 'span', { 'class': 'ak-stats__row-name', title: label, text: label } );
				}
				ul.appendChild( el( 'li', { 'class': 'ak-stats__row' }, [
					nameEl,
					el( 'span', { 'class': 'ak-stats__row-val', text: num( r.viewers ) } )
				] ) );
			} );
			box.appendChild( ul );
			return box;
		}

		function metric( value, label, trend ) {
			var top = [ el( 'span', { 'class': 'ak-stats__metric-num', text: value } ) ];
			if ( trend ) { top.push( trendBadge( trend ) ); }
			return el( 'div', { 'class': 'ak-stats__metric' }, [
				el( 'div', { 'class': 'ak-stats__metric-row' }, top ),
				el( 'span', { 'class': 'ak-stats__metric-lbl', text: label } )
			] );
		}

		// Build a trend descriptor from current vs previous totals. With a baseline,
		// it's a signed percentage. With NO baseline (previous = 0) we can't show a
		// percentage (÷0), so: new traffic → a plain ▲ (it IS up, vs nothing); still
		// nothing → no badge. This way the indicator shows on every range, not just
		// the ones whose prior window happens to have data.
		// Returns { dir: 'up'|'down'|'flat', text } or null.
		function trendFrom( cur, prev ) {
			cur = cur | 0; prev = prev | 0;
			if ( prev <= 0 ) {
				return cur > 0 ? { dir: 'up', text: '' } : null;
			}
			var pct = Math.round( ( ( cur - prev ) / prev ) * 100 );
			return {
				dir:  pct > 0 ? 'up' : ( pct < 0 ? 'down' : 'flat' ),
				text: ( pct > 0 ? '+' : '' ) + pct + '%'
			};
		}

		function trendBadge( trend ) {
			var arrow = trend.dir === 'up' ? '▲' : ( trend.dir === 'down' ? '▼' : '→' );
			// No baseline (text empty) → "New" instead of a bare arrow, so the badge
			// always reads as a labelled value (a % or "New").
			return el( 'span', {
				'class': 'ak-stats__trend ak-stats__trend--' + trend.dir,
				title: I.statsVsPrev || 'vs previous period',
				text: arrow + ' ' + ( trend.text || ( I.statsTrendNew || 'New' ) )
			} );
		}

		// Extension tiles (res.cards) — same visual shell as a native metric, with an
		// optional secondary line and the integration's own trend badge.
		function extraCards( cards ) {
			return ( cards || [] ).map( function ( c ) {
				var tile = metric( c.value || '', c.label || '', c.trend || null );
				if ( c.sub ) {
					tile.appendChild( el( 'span', { 'class': 'ak-stats__metric-sub', text: c.sub } ) );
				}
				return tile;
			} );
		}

		// A lightweight SVG line/area chart — page views over the period, drawn as a
		// single line with a soft accent fill beneath (same idiom as the dashboard
		// sparkline). Linear, not bars, so a long sparse range still reads as a
		// trend instead of empty blocks. DOM-built — values are plain numbers, no
		// markup injection; the line uses non-scaling-stroke (CSS) so it stays crisp
		// when the viewBox stretches to the container width.
		function chart( series ) {
			// Need ≥2 points for a line — a single day (Today) shows just the metrics
			// + lists, no chart card at all (null → caller skips it).
			if ( series.length < 2 ) { return null; }
			var wrap = el( 'div', { 'class': 'ak-stats__chart' } );
			var max = 1;
			series.forEach( function ( d ) { if ( ( d.pageviews | 0 ) > max ) { max = d.pageviews | 0; } } );

			var NS = 'http://www.w3.org/2000/svg';
			var W = 600, H = 120, px = 1, py = 8;
			var iw = W - 2 * px, ih = H - 2 * py, base = H - py, n = series.length;
			function xAt( i ) { return ( n === 1 ) ? ( px + iw / 2 ) : ( px + i * iw / ( n - 1 ) ); }
			function yAt( v ) { return py + ih * ( 1 - ( ( v | 0 ) / max ) ); }

			var line = '';
			var area = 'M' + xAt( 0 ).toFixed( 2 ) + ' ' + base.toFixed( 2 ) + ' ';
			series.forEach( function ( d, i ) {
				var x = xAt( i ).toFixed( 2 ), y = yAt( d.pageviews ).toFixed( 2 );
				line += ( i === 0 ? 'M' : 'L' ) + x + ' ' + y + ' ';
				area += 'L' + x + ' ' + y + ' ';
			} );
			area += 'L' + xAt( n - 1 ).toFixed( 2 ) + ' ' + base.toFixed( 2 ) + ' Z';

			var svg = document.createElementNS( NS, 'svg' );
			svg.setAttribute( 'class', 'ak-stats__chart-svg' );
			svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
			svg.setAttribute( 'preserveAspectRatio', 'none' );
			// Hairline y-reference gridlines (top = max, middle, bottom = 0).
			[ py, py + ih / 2, base ].forEach( function ( gy ) {
				var g = document.createElementNS( NS, 'line' );
				g.setAttribute( 'class', 'ak-stats__grid' );
				g.setAttribute( 'x1', '0' ); g.setAttribute( 'x2', String( W ) );
				g.setAttribute( 'y1', gy.toFixed( 2 ) ); g.setAttribute( 'y2', gy.toFixed( 2 ) );
				svg.appendChild( g );
			} );
			var a = document.createElementNS( NS, 'path' );
			a.setAttribute( 'class', 'ak-stats__area' );
			a.setAttribute( 'd', area.replace( /\s+$/, '' ) );
			var l = document.createElementNS( NS, 'path' );
			l.setAttribute( 'class', 'ak-stats__line' );
			l.setAttribute( 'd', line.replace( /\s+$/, '' ) );
			svg.appendChild( a );
			svg.appendChild( l );

			// Y axis (left): max / mid / 0, aligned to the three gridlines.
			var yax = el( 'div', { 'class': 'ak-stats__chart-y', 'aria-hidden': 'true' }, [
				el( 'span', { text: num( max ) } ),
				el( 'span', { text: num( Math.round( max / 2 ) ) } ),
				el( 'span', { text: '0' } )
			] );

			// X axis (bottom): first / middle / last day. 'Y-m-d' → 'DD/MM' by string
			// split (no Date object — cheap, no timezone surprises).
			function dm( d ) { var p = ( d || '' ).split( '-' ); return p.length === 3 ? ( p[ 2 ] + '/' + p[ 1 ] ) : ( d || '' ); }
			var xLabels = [ el( 'span', { text: dm( series[ 0 ].date ) } ) ];
			if ( n > 2 ) { xLabels.push( el( 'span', { text: dm( series[ Math.floor( ( n - 1 ) / 2 ) ].date ) } ) ); }
			if ( n > 1 ) { xLabels.push( el( 'span', { text: dm( series[ n - 1 ].date ) } ) ); }
			var xax = el( 'div', { 'class': 'ak-stats__chart-x', 'aria-hidden': 'true' }, xLabels );

			wrap.appendChild( el( 'div', { 'class': 'ak-stats__chart-axes' }, [
				yax,
				el( 'div', { 'class': 'ak-stats__chart-plot' }, [ svg, xax ] )
			] ) );
			return wrap;
		}

		function listShell( heading, colName, colVal ) {
			return el( 'div', { 'class': 'ak-stats__list' }, [
				el( 'div', { 'class': 'ak-stats__list-head' }, [
					el( 'span', { 'class': 'ak-stats__list-h', text: heading } ),
					el( 'span', { 'class': 'ak-stats__list-hv', text: colVal } )
				] )
			] );
		}

		function pageList( rows ) {
			var box = listShell( I.statsTopPages || 'Top pages', I.statsColPage, I.statsColViews || 'Views' );
			if ( ! rows.length ) {
				box.appendChild( el( 'p', { 'class': 'ak-stats__none', text: I.statsNone || 'No data' } ) );
				return box;
			}
			var ul = el( 'ul', { 'class': 'ak-stats__rows' } );
			rows.forEach( function ( r ) {
				var link = el( 'a', { 'class': 'ak-stats__row-name', href: r.url || '#', target: '_blank', rel: 'noopener', title: r.path || '' }, [
					el( 'span', { 'class': 'ak-stats__row-title', text: r.title || r.path || '' } ),
					el( 'span', { 'class': 'ak-stats__row-sub', text: r.path || '' } )
				] );
				ul.appendChild( el( 'li', { 'class': 'ak-stats__row' }, [
					link,
					el( 'span', { 'class': 'ak-stats__row-val', text: num( r.pageviews ) } )
				] ) );
			} );
			box.appendChild( ul );
			return box;
		}

		function sourceList( rows ) {
			var box = listShell( I.statsTopSources || 'Top sources', I.statsColSource, I.statsColVisits || 'Visits' );
			if ( ! rows.length ) {
				box.appendChild( el( 'p', { 'class': 'ak-stats__none', text: I.statsNone || 'No data' } ) );
				return box;
			}
			var ul = el( 'ul', { 'class': 'ak-stats__rows' } );
			rows.forEach( function ( r ) {
				var label = ( r.name === 'direct' ) ? ( I.statsDirect || 'Direct' ) : ( r.name || '' );
				ul.appendChild( el( 'li', { 'class': 'ak-stats__row' }, [
					el( 'span', { 'class': 'ak-stats__row-name', title: label, text: label } ),
					el( 'span', { 'class': 'ak-stats__row-val', text: num( r.visits ) } )
				] ) );
			} );
			box.appendChild( ul );
			return box;
		}

		// Kick off the first fetch. On the dedicated Statistics page (SCREEN===stats)
		// the panel IS the page — load immediately, no lazy-load gymnastics needed.
		// In the multi-tab SPA (SCREEN===main), defer until the Stats tab is shown:
		// a MutationObserver on the panel's `hidden` attribute triggers the first
		// load, and on every subsequent show, restarts the Live polling if active.
		if ( SCREEN === 'stats' ) {
			load();
		} else {
			function onPanelVisibility() {
				if ( p.hidden ) { stopLive(); return; }
				if ( ! loaded ) { load(); return; }
				syncLivePolling();
			}
			if ( window.MutationObserver ) {
				var mo = new MutationObserver( onPanelVisibility );
				mo.observe( p, { attributes: true, attributeFilter: [ 'hidden' ] } );
			}
			if ( ( location.hash || '' ).replace( /^#/, '' ) === 'stats' ) {
				load();
			}
		}

		return p;
	}

	// Plugins tab — every installed plugin (plus AdminKit's active theme adapters).
	// Supported plugins (a tuned adapter you can toggle per host) wear a "Native"
	// badge; the rest carry no badge — AdminKit's base styles theme them silently.
	function buildPlugins() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' }, [ intro( I.pluginsIntro ) ] );
		var list = D.integrations || [];
		if ( ! list.length ) { return p; }

		var inputs = []; // every toggle (native + generic), for the Reset button
		var rowsRef = []; // { row, text } per plugin row, for the search filter
		var groupsRef = []; // { wrap, rows[] } per section, to hide an emptied group

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

		// Keep the row's `.is-off` class in sync with the switch state so
		// every disabled row (native OR generic) reads as greyed-out, the
		// same treatment AdminKit's own System row gets.
		function syncOffClass( row, on ) {
			if ( on ) {
				row.classList.remove( 'is-off' );
			} else {
				row.classList.add( 'is-off' );
			}
		}

		function pluginRow( i ) {
			// Badge + name hug the left together. System (AdminKit itself) →
			// neutral System; supported → brand Native; any other installed
			// plugin → neutral Generic (no dedicated adapter).
			var badge = i.system ? systemBadge() : ( i.supported ? nativeBadge() : genericBadge() );
			var main  = el( 'div', { 'class': 'ak-row__main' }, [
				el( 'div', { 'class': 'ak-row__head' }, [
					badge,
					el( 'span', { 'class': 'ak-row__label', text: i.label } )
				] )
			] );

			// System (AdminKit itself) → locked row, switch reads ON but is dead.
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

			// Installed but not active in WP → muted row, no switch. AdminKit
			// can't act on a plugin that isn't loaded, so the toggle would be
			// noise — the dim treatment alone reads as "not actionable here".
			if ( false === i.active ) {
				return el( 'div', { 'class': 'ak-row is-muted' }, [ main ] );
			}

			// Active plugin (native or generic) → user-facing toggle.
			// `.is-off` from the start paints the row dim with no flash.
			var input = el( 'input', { type: 'checkbox', 'class': 'ak-switch__input' } );
			input.checked = !! i.enabled;
			var row = el( 'div', { 'class': 'ak-row' + ( i.enabled ? '' : ' is-off' ) }, [
				main,
				el( 'label', { 'class': 'ak-switch' }, [
					input,
					el( 'span', { 'class': 'ak-switch__track' } ),
					el( 'span', { 'class': 'ak-switch__knob' } )
				] )
			] );

			if ( i.supported && i.slug ) {
				// Native adapter → its `integration_{slug}_enabled` setting.
				input.addEventListener( 'change', function () {
					state.integrations[ i.slug ] = input.checked;
					syncOffClass( row, input.checked );
					markDirty();
				} );
				inputs.push( { kind: 'native', slug: i.slug, input: input, row: row } );
			} else if ( i.file ) {
				// Generic plugin → file path tracked in the off-list.
				input.addEventListener( 'change', function () {
					if ( input.checked ) {
						delete state.genericThemingOff[ i.file ];
					} else {
						state.genericThemingOff[ i.file ] = true;
					}
					syncOffClass( row, input.checked );
					markDirty();
				} );
				inputs.push( { kind: 'generic', file: i.file, input: input, row: row } );
			}
			return row;
		}

		// Reset every toggle to its "AdminKit handles this plugin" default,
		// which is ON across the board (native integrations' schema default,
		// generic plugins not in the opt-out list). markDirty so the user
		// can still walk away without committing.
		function resetAll() {
			inputs.forEach( function ( r ) {
				r.input.checked = true;
				if ( 'native' === r.kind ) {
					state.integrations[ r.slug ] = true;
				} else {
					delete state.genericThemingOff[ r.file ];
				}
				syncOffClass( r.row, true );
			} );
			markDirty();
		}

		// Build the sections first (this fills `inputs` + the search refs), then the
		// search field + bulk bar above them.
		var sections = [];
		[
			{ type: 'theme',  label: I.themesLabel || 'Themes' },
			{ type: 'plugin', label: I.plugins || 'Plugins' }
		].forEach( function ( sec ) {
			var items = list.filter( function ( i ) { return i.type === sec.type; } );
			if ( ! items.length ) { return; }
			var rows = el( 'div', { 'class': 'ak-rows' } );
			var groupRows = [];
			items.forEach( function ( i ) {
				var r = pluginRow( i );
				// Searchable by plugin name + its badge word (so "native" / "generic"
				// narrow to that kind). The badge tracks the adapter status.
				var badge = i.system ? ( I.system || 'System' ) : ( i.supported ? ( I.native || 'Native' ) : ( I.generic || 'Generic' ) );
				var ref = { row: r, text: ( ( i.label || '' ) + ' ' + badge ).toLowerCase() };
				rowsRef.push( ref );
				groupRows.push( ref );
				rows.appendChild( r );
			} );
			var wrap = el( 'div', { 'class': 'ak-group' }, [
				el( 'h2', { 'class': 'ak-group__title' }, [
					el( 'span', { text: sec.label } ),
					el( 'span', { 'class': 'ak-badge ak-group__count', text: String( items.length ) } )
				] ),
				rows
			] );
			groupsRef.push( { wrap: wrap, rows: groupRows } );
			sections.push( wrap );
		} );

		// Search — filters plugin rows by name / badge as you type, hiding groups
		// left with nothing (same idiom as the Features tab).
		var search = el( 'input', {
			type: 'search',
			'class': 'ak-search',
			placeholder: I.searchPlugins || 'Search plugins…',
			'aria-label': I.searchPlugins || 'Search plugins…'
		} );
		function filterPlugins() {
			var q = ( search.value || '' ).trim().toLowerCase();
			rowsRef.forEach( function ( r ) {
				r.row.style.display = ( ! q || r.text.indexOf( q ) !== -1 ) ? '' : 'none';
			} );
			groupsRef.forEach( function ( g ) {
				var any = g.rows.some( function ( r ) { return r.row.style.display !== 'none'; } );
				g.wrap.style.display = any ? '' : 'none';
			} );
		}
		search.addEventListener( 'input', filterPlugins );
		p.appendChild( el( 'div', { 'class': 'ak-search-wrap' }, [ search ] ) );

		// Single bulk control — Reset puts every toggle back to ON (the
		// "AdminKit handles this plugin" default for both native + generic).
		// Skipped when no row carries a toggle (e.g. every plugin inactive).
		if ( inputs.length ) {
			p.appendChild( el( 'div', { 'class': 'ak-actions ak-bulk' }, [
				el( 'button', { type: 'button', 'class': 'ak-btn', text: I.resetDefaults || 'Reset to defaults', onclick: resetAll } )
			] ) );
		}
		sections.forEach( function ( s ) { p.appendChild( s ); } );
		return p;
	}

	// --- menu manager tab ----------------------------------------------------
	// A drag-and-drop editor over the captured menu tree: reorder top items and
	// submenus, swap a top item's icon (AdminKit set), or hide entries. Edits mutate
	// state.menu; the single Save button posts the layout to its own route.

	// Working model: configured items first (saved order), then snapshot items the
	// saved layout doesn't have yet (new menus), appended in their natural order.
	function buildMenuModel( snapshot, config ) {
		// Global lookups so a submenu can be rebuilt under ANY parent (cross-section):
		// childTitle = every submenu slug -> its original title; claimed = every slug
		// any saved parent lists (so it leaves its origin and shows under its new parent).
		var snapBySlug = {}, childTitle = {};
		( snapshot || [] ).forEach( function ( m ) {
			snapBySlug[ m.slug ] = m;
			( m.children || [] ).forEach( function ( c ) { childTitle[ c.slug ] = c.title; } );
		} );
		var subCfg = ( config && config.sub ) || {};
		var claimed = {};
		Object.keys( subCfg ).forEach( function ( p ) {
			( subCfg[ p ] || [] ).forEach( function ( s ) { claimed[ s.slug ] = true; } );
		} );
		var seen = {}, model = [];
		( ( config && config.top ) || [] ).forEach( function ( t ) {
			var m = snapBySlug[ t.slug ];
			if ( m && m.type === 'separator' ) {
				seen[ t.slug ] = true;
				model.push( { type: 'separator', slug: t.slug, hidden: !! t.hidden } );
			} else if ( m ) {
				seen[ t.slug ] = true;
				model.push( makeTop( m, t, subCfg, childTitle, claimed ) );
			} else if ( t.type === 'link' || t.type === 'separator' ) {
				model.push( makeCustom( t ) );
			}
			// else: a real menu that no longer exists → drop it.
		} );
		// Place the snapshot items the saved layout didn't already position. Each is set
		// at its NATURAL snapshot spot (right after the previous placed item) so the
		// menu reproduces WordPress's default order — separators interspersed, not
		// clustered. EXCEPTION: when a saved layout EXISTS, a real menu it doesn't list
		// is a genuinely new plugin → defer it to the very bottom (ordered by first-seen
		// below). With NO saved layout (a reset / pristine view) nothing is "new", so
		// everything — separators AND menus — is placed naturally.
		var hasConfig = ( ( config && config.top ) || [] ).length > 0;
		var indexOfSlug = function ( slug ) {
			for ( var i = 0; i < model.length; i++ ) { if ( model[ i ].slug === slug ) { return i; } }
			return -1;
		};
		var pending = [];
		var prevSlug = null;
		( snapshot || [] ).forEach( function ( m ) {
			if ( seen[ m.slug ] ) { prevSlug = m.slug; return; }
			if ( hasConfig && m.type !== 'separator' ) {
				pending.push( m ); // new plugin (layout exists but omits it) → bottom; does NOT advance prevSlug
				return;
			}
			var node = ( m.type === 'separator' )
				? { type: 'separator', slug: m.slug, hidden: false }
				: makeTop( m, null, subCfg, childTitle, claimed );
			var at  = ( prevSlug !== null ) ? indexOfSlug( prevSlug ) : -1;
			var pos = ( at >= 0 ) ? at + 1 : ( null === prevSlug ? 0 : model.length );
			model.splice( pos, 0, node );
			prevSlug = m.slug;
		} );
		// New plugins (only when a layout exists) → appended at the very bottom, ordered
		// by the order AdminKit first saw them (newest last), mirroring order_top_level().
		var seenRank = {};
		( MM.seen || [] ).forEach( function ( slug, i ) { seenRank[ slug ] = i; } );
		pending.sort( function ( a, b ) {
			var ra = ( null != seenRank[ a.slug ] ) ? seenRank[ a.slug ] : 1e9;
			var rb = ( null != seenRank[ b.slug ] ) ? seenRank[ b.slug ] : 1e9;
			return ra - rb;
		} );
		pending.forEach( function ( m ) { model.push( makeTop( m, null, subCfg, childTitle, claimed ) ); } );
		return model;
	}

	function makeTop( m, topCfg, subCfg, childTitle, claimed ) {
		topCfg = topCfg || {};
		var children = [], seenC = {};
		// Configured children for this parent (may include children moved in from elsewhere).
		( subCfg[ m.slug ] || [] ).forEach( function ( s ) {
			if ( ! childTitle.hasOwnProperty( s.slug ) ) { return; } // child no longer exists → drop
			seenC[ s.slug ] = true;
			var ot = childTitle[ s.slug ];
			children.push( { slug: s.slug, otitle: ot, title: ( s.title && s.title !== '' ) ? s.title : ot, hidden: !! s.hidden } );
		} );
		// This parent's own snapshot children not claimed elsewhere (new / unmoved).
		( m.children || [] ).forEach( function ( c ) {
			if ( seenC[ c.slug ] || claimed[ c.slug ] ) { return; }
			children.push( { slug: c.slug, otitle: c.title, title: c.title, hidden: false } );
		} );
		return {
			type: 'item', slug: m.slug, otitle: m.title,
			title: ( topCfg.title && topCfg.title !== '' ) ? topCfg.title : m.title,
			dashicon: m.dashicon || '', icon: topCfg.icon || '', url: topCfg.url || '', hidden: !! topCfg.hidden,
			open: false, children: children
		};
	}

	// A custom (link / separator) working item rebuilt from its saved row.
	function makeCustom( t ) {
		if ( t.type === 'separator' ) {
			return { type: 'separator', slug: t.slug, hidden: !! t.hidden };
		}
		// Links store their destination URL as the slug.
		return { type: 'link', slug: t.slug, url: t.slug, title: t.title || '', icon: t.icon || '', hidden: !! t.hidden, newTab: !! t.new_tab };
	}

	// Flatten the model into rows for the POST (position = array index).
	function gatherMenu() {
		var items = [];
		state.menu.forEach( function ( top, ti ) {
			var type = top.type || 'item';
			if ( type === 'link' ) {
				var url = ( top.url || '' ).replace( /^\s+|\s+$/g, '' );
				if ( ! url ) { return; } // a link with no URL yet is dropped
				items.push( { parent: '', slug: url, position: ti, icon: top.icon || '', hidden: !! top.hidden, type: 'link', title: top.title || '', newTab: !! top.newTab } );
				return;
			}
			if ( type === 'separator' ) {
				items.push( { parent: '', slug: top.slug, position: ti, hidden: !! top.hidden, type: 'separator', title: '' } );
				return;
			}
			items.push( { parent: '', slug: top.slug, position: ti, icon: top.icon || '', url: top.url || '', hidden: !! top.hidden, type: 'item', title: titleOverride( top ) } );
			( top.children || [] ).forEach( function ( child, ci ) {
				items.push( { parent: top.slug, slug: child.slug, position: ci, hidden: !! child.hidden, title: titleOverride( child ) } );
			} );
		} );
		return items;
	}

	// A rename override is stored only when the label differs from the original; an
	// empty field or a value equal to the original = no override (WP's label returns).
	function titleOverride( it ) {
		var t = it.title || '';
		return ( '' !== t && t !== ( it.otitle || '' ) ) ? t : '';
	}

	function markMenuDirty() { state.menuDirty = true; markDirty(); }

	function buildMenu() {
		var p = el( 'section', { 'class': 'ak-panel ak-panel--menu', role: 'tabpanel' } );
		p.appendChild( intro( I.menuIntro ) );
		if ( ! MM.menu || ! MM.menu.length ) { return p; }
		var tree = el( 'div', { 'class': 'ak-menu-tree' } );

		// Import loads a layout into the editor and marks it dirty — the user reviews
		// it, then Saves (consistent with every other menu edit; no silent write).
		var importInput = el( 'input', { type: 'file', accept: 'application/json,.json', hidden: 'hidden', onchange: function ( e ) {
			if ( e.target.files && e.target.files[0] ) { importMenu( e.target.files[0], tree ); }
			e.target.value = '';
		} } );

		p.appendChild( el( 'div', { 'class': 'ak-actions ak-menu-toolbar' }, [
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.menuAddLink, onclick: function () { addCustom( 'link', tree ); } } ),
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.menuAddSeparator, onclick: function () { addCustom( 'separator', tree ); } } ),
			el( 'button', { type: 'button', 'class': 'ak-btn ak-btn--ghost', text: I.menuReset, onclick: function () {
				state.menu = buildMenuModel( MM.menu, { top: [], sub: {} } );
				renderTree( tree );
				markMenuDirty();
			} } ),
			el( 'button', { type: 'button', 'class': 'ak-btn ak-menu-toolbar__spacer', text: I.menuExport || 'Export', title: I.menuExportHint || '', onclick: exportMenu } ),
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.menuImport || 'Import', title: I.menuImportHint || '', onclick: function () { importInput.click(); } } ),
			importInput
		] ) );
		renderTree( tree );
		p.appendChild( tree );
		return p;
	}

	// Serialise the current layout (the same flat rows the Save button POSTs) into a
	// downloaded JSON file, wrapped with a small human-readable header.
	function exportMenu() {
		var payload = { _adminkit: 'menu', version: ( D.version || '1' ), exported: new Date().toISOString(), items: gatherMenu() };
		var blob = new Blob( [ JSON.stringify( payload, null, '\t' ) ], { type: 'application/json' } );
		var url  = URL.createObjectURL( blob );
		var a    = el( 'a', { href: url, download: 'adminkit-menu.json' } );
		document.body.appendChild( a );
		a.click();
		a.remove();
		URL.revokeObjectURL( url );
	}

	// Reshape exported flat rows ([{parent, slug, …}]) back into the {top, sub} config
	// shape buildMenuModel() consumes — the mirror of the server's own reshaping.
	function configFromItems( items ) {
		var top = [], sub = {};
		( items || [] ).forEach( function ( it ) {
			if ( it && it.parent ) {
				( sub[ it.parent ] = sub[ it.parent ] || [] ).push( { slug: it.slug, position: it.position, hidden: !! it.hidden, title: it.title || '' } );
			} else if ( it ) {
				top.push( { slug: it.slug, position: it.position, icon: it.icon || '', url: it.url || '', new_tab: !! it.newTab, hidden: !! it.hidden, type: it.type || 'item', title: it.title || '' } );
			}
		} );
		return { top: top, sub: sub };
	}

	// Import a menu layout from a JSON file: accept our export wrapper ({items:[…]})
	// or a bare items array, rebuild the working model against the LIVE snapshot (so
	// menus that no longer exist drop out and new ones still appear), then mark dirty.
	function importMenu( file, tree ) {
		var reader = new FileReader();
		reader.onload = function () {
			var data, items;
			try { data = JSON.parse( reader.result ); } catch ( e ) { data = null; }
			items = ( data && typeof data === 'object' && Array.isArray( data.items ) ) ? data.items
				: ( Array.isArray( data ) ? data : null );
			if ( ! items ) {
				setStatus( 'is-error', I.menuImportError || 'That file isn’t a valid AdminKit menu export.' );
				return;
			}
			state.menu = buildMenuModel( MM.menu, configFromItems( items ) );
			renderTree( tree );
			markMenuDirty(); // shows "Unsaved changes" — the user reviews, then Saves.
		};
		reader.readAsText( file );
	}

	// Append a new custom row (a separator, or a blank link to fill in).
	function addCustom( type, tree ) {
		var item = ( type === 'separator' )
			? { type: 'separator', slug: 'ak-sep-' + Date.now() + '-' + Math.floor( Math.random() * 1e6 ), hidden: false }
			: { type: 'link', slug: '', url: '', title: '', icon: '', hidden: false };
		state.menu.push( item );
		renderTree( tree );
		markMenuDirty();
	}

	function renderTree( tree ) {
		tree.textContent = '';
		state.menu.forEach( function ( top, ti ) {
			var t = top.type || 'item';
			if ( 'separator' === t ) { tree.appendChild( separatorRow( top, ti, tree ) ); }
			else if ( 'link' === t ) { tree.appendChild( linkRow( top, ti, tree ) ); }
			else { tree.appendChild( topGroup( top, ti, tree ) ); }
		} );
	}

	function separatorRow( item, ti, tree ) {
		var row = el( 'div', { 'class': 'ak-menu-row ak-menu-row--sep' + ( item.hidden ? ' is-hidden' : '' ) } );
		var h = handle();
		row.appendChild( h );
		row.appendChild( moveControl( moveTopBy( ti, tree ) ) );
		row.appendChild( el( 'span', { 'class': 'ak-menu-row__title ak-menu-seplabel', text: '— ' + I.menuSeparator + ' —' } ) );
		row.appendChild( hideBtn( item, tree ) );
		row.appendChild( removeBtn( ti, tree ) );
		dragSource( h, row, { kind: 'top', ti: ti } );
		dropZone( row, function ( ds ) { return ds.kind === 'top'; }, function ( ds ) {
			moveTop( ds.ti, ti ); renderTree( tree ); markMenuDirty();
		} );
		return el( 'div', { 'class': 'ak-menu-group' }, [ row ] );
	}

	function linkRow( item, ti, tree ) {
		var row = el( 'div', { 'class': 'ak-menu-row ak-menu-row--link' + ( item.hidden ? ' is-hidden' : '' ) } );
		var h = handle();
		row.appendChild( h );
		row.appendChild( moveControl( moveTopBy( ti, tree ) ) );
		row.appendChild( iconBtn( item, tree ) );
		var titleIn = el( 'input', { type: 'text', 'class': 'ak-menu-input', value: item.title || '', placeholder: I.menuLinkTitle } );
		titleIn.addEventListener( 'input', function () { item.title = titleIn.value; markMenuDirty(); } );
		var urlIn = el( 'input', { type: 'text', 'class': 'ak-menu-input ak-menu-input--url', value: item.url || '', placeholder: I.menuLinkUrl } );
		urlIn.addEventListener( 'input', function () { item.url = urlIn.value; markMenuDirty(); } );
		row.appendChild( titleIn );
		row.appendChild( urlIn );
		row.appendChild( newTabBtn( item, tree ) );
		row.appendChild( hideBtn( item, tree ) );
		row.appendChild( removeBtn( ti, tree ) );
		// Drag from the handle only, so the URL/title inputs stay text-selectable.
		dragSource( h, row, { kind: 'top', ti: ti } );
		dropZone( row, function ( ds ) { return ds.kind === 'top'; }, function ( ds ) {
			moveTop( ds.ti, ti ); renderTree( tree ); markMenuDirty();
		} );
		return el( 'div', { 'class': 'ak-menu-group' }, [ row ] );
	}

	function removeBtn( ti, tree ) {
		return el( 'button', { type: 'button', 'class': 'ak-menu-remove', title: I.menuRemove, 'aria-label': I.menuRemove, text: '×', onclick: function () {
			state.menu.splice( ti, 1 );
			renderTree( tree );
			markMenuDirty();
		} } );
	}

	function topGroup( top, ti, tree ) {
		var row = el( 'div', { 'class': 'ak-menu-row ak-menu-row--top' + ( top.hidden ? ' is-hidden' : '' ) } );
		var h = handle();
		row.appendChild( h );
		row.appendChild( moveControl( moveTopBy( ti, tree ) ) );
		row.appendChild( iconBtn( top, tree ) );
		row.appendChild( titleInput( top ) );
		if ( top.children.length ) {
			var caret = el( 'span', { 'class': 'ak-menu-row__caret', 'aria-hidden': 'true' } );
			caret.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';
			row.appendChild( el( 'button', {
				type: 'button',
				'class': 'ak-menu-row__expand' + ( top.open ? ' on' : '' ),
				'aria-expanded': top.open ? 'true' : 'false',
				title: I.menuSubmenusToggle,
				onclick: function () { top.open = ! top.open; renderTree( tree ); }
			}, [ caret, el( 'span', { text: top.children.length + ' ' + I.menuSubmenus } ) ] ) );
		}
		row.appendChild( urlBtn( top, tree ) );
		if ( top.slug !== MM.self ) {
			row.appendChild( hideBtn( top, tree ) );
		}
		dragSource( h, row, { kind: 'top', ti: ti } );
		// Reorder tops, and accept a submenu dragged from any section (nest it here).
		dropZone( row, function () { return true; }, function ( ds ) {
			if ( ds.kind === 'top' ) { moveTop( ds.ti, ti ); }
			else { moveChildToTop( ds.ti, ds.ci, ti ); }
			renderTree( tree ); markMenuDirty();
		} );

		var group = el( 'div', { 'class': 'ak-menu-group' }, [ row ] );
		if ( top.children.length && top.open ) {
			var kids = el( 'div', { 'class': 'ak-menu-children' } );
			top.children.forEach( function ( child, ci ) { kids.appendChild( childRow( ti, child, ci, tree ) ); } );
			// Dropping into the open children area (not onto a row) appends here.
			dropZone( kids, function ( ds ) { return ds.kind === 'sub'; }, function ( ds ) {
				moveChildToTop( ds.ti, ds.ci, ti ); renderTree( tree ); markMenuDirty();
			} );
			group.appendChild( kids );
		}
		return group;
	}

	function childRow( ti, child, ci, tree ) {
		var row = el( 'div', { 'class': 'ak-menu-row ak-menu-row--child' + ( child.hidden ? ' is-hidden' : '' ) } );
		var h = handle();
		row.appendChild( h );
		row.appendChild( moveControl( moveChildBy( ti, ci, tree ) ) );
		row.appendChild( titleInput( child ) );
		row.appendChild( hideBtn( child, tree ) );
		dragSource( h, row, { kind: 'sub', ti: ti, ci: ci } );
		dropZone( row, function ( ds ) { return ds.kind === 'sub'; }, function ( ds ) {
			moveChild( ds.ti, ds.ci, ti, ci ); renderTree( tree ); markMenuDirty();
		} );
		return row;
	}

	function handle() {
		var h = el( 'span', { 'class': 'ak-menu-handle', title: I.menuDragHint, 'aria-hidden': 'true' } );
		h.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.6"/><circle cx="15" cy="5" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="19" r="1.6"/><circle cx="15" cy="19" r="1.6"/></svg>';
		return h;
	}

	// Up/down reorder control next to the drag handle — a keyboard/click-accessible
	// alternative to dragging (drag-and-drop alone isn't accessible). `move(-1)` =
	// up, `move(+1)` = down; a no-op at the list edges.
	function moveControl( move ) {
		var up = el( 'button', { type: 'button', 'class': 'ak-menu-move__btn', title: I.menuMoveUp || 'Move up', 'aria-label': I.menuMoveUp || 'Move up', onclick: function () { move( -1 ); } } );
		up.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
		var down = el( 'button', { type: 'button', 'class': 'ak-menu-move__btn', title: I.menuMoveDown || 'Move down', 'aria-label': I.menuMoveDown || 'Move down', onclick: function () { move( 1 ); } } );
		down.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
		return el( 'span', { 'class': 'ak-menu-move' }, [ up, down ] );
	}

	// Swap a top-level item by ±1 (the move() callback for moveControl).
	function moveTopBy( ti, tree ) {
		return function ( dir ) {
			var to = ti + dir, m = state.menu;
			if ( to < 0 || to >= m.length ) { return; }
			var tmp = m[ ti ]; m[ ti ] = m[ to ]; m[ to ] = tmp;
			renderTree( tree ); markMenuDirty();
		};
	}

	// Swap a child within its parent's list by ±1.
	function moveChildBy( ti, ci, tree ) {
		return function ( dir ) {
			var kids = state.menu[ ti ].children, to = ci + dir;
			if ( to < 0 || to >= kids.length ) { return; }
			var tmp = kids[ ci ]; kids[ ci ] = kids[ to ]; kids[ to ] = tmp;
			renderTree( tree ); markMenuDirty();
		};
	}

	// Hide/Show toggle, a compact eye icon (saves the room the old text button took).
	// Flips the item's `hidden` flag and reflects it on the row in place — no full
	// tree rebuild (cheaper, and no scroll jump / flicker).
	function hideBtn( item ) {
		var btn = el( 'button', { type: 'button', 'class': 'ak-menu-hide' } );
		paintHide( btn, item );
		btn.addEventListener( 'click', function () {
			item.hidden = ! item.hidden;
			paintHide( btn, item );
			var row = btn.closest && btn.closest( '.ak-menu-row' );
			if ( row ) { row.classList.toggle( 'is-hidden', !! item.hidden ); }
			markMenuDirty();
		} );
		return btn;
	}

	// Eye / eye-off glyphs, inlined like the other row buttons (handle, new-tab, url)
	// so they're always in scope — a module-level `var` would be `undefined` at the
	// first menu render, which paints the literal string "undefined" in the button.
	function paintHide( btn, item ) {
		var eye    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S5.5 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
		var eyeOff = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 6.1A9.6 9.6 0 0 1 12 6c6.5 0 9.5 6 9.5 6a16.2 16.2 0 0 1-2.3 3.1M6.6 6.6A16 16 0 0 0 2.5 12s3 6 9.5 6a9.3 9.3 0 0 0 4-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
		var lbl = item.hidden ? ( I.menuShow || 'Show' ) : ( I.menuHide || 'Hide' );
		btn.innerHTML = item.hidden ? eyeOff : eye;
		btn.setAttribute( 'aria-pressed', item.hidden ? 'true' : 'false' );
		btn.setAttribute( 'title', lbl );
		btn.setAttribute( 'aria-label', lbl );
	}

	// Icon button + popover picker (top-level only — WordPress submenus have no icon).
	function iconBtn( top, tree ) {
		var btn = el( 'button', { type: 'button', 'class': 'ak-menu-icon', title: I.menuIconPick, 'aria-label': I.menuIconPick } );
		paintIcon( btn, top );
		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			// Clicking the same icon button while its picker is open closes it (toggle).
			if ( openPicker && openPicker.__akAnchor === btn ) { closeIconPicker(); return; }
			openIconPicker( btn, top, tree );
		} );
		return btn;
	}

	function paintIcon( btn, top ) {
		btn.textContent = '';
		var g;
		if ( top.icon === 'wp-default' ) {
			// Show the item's original WordPress dashicon in the picker button.
			g = el( 'span', { 'class': 'dashicons ' + ( top.dashicon || 'dashicons-admin-settings' ) } );
		} else if ( top.icon && top.icon.indexOf( 'data:' ) === 0 ) {
			g = el( 'span', { 'class': 'ak-menu-icon__g ak-menu-icon__mask' } );
			g.style.webkitMaskImage = 'url("' + top.icon + '")';
			g.style.maskImage = 'url("' + top.icon + '")';
		} else if ( top.icon && MM.icons[ top.icon ] ) {
			g = el( 'span', { 'class': 'ak-menu-icon__g' } );
			g.innerHTML = MM.icons[ top.icon ];
		} else if ( top.dashicon ) {
			g = el( 'span', { 'class': 'dashicons ' + top.dashicon } );
		} else {
			g = el( 'span', { 'class': 'ak-menu-icon__g' } );
			g.innerHTML = MM.icons.app || '';
		}
		btn.appendChild( g );
	}

	// The icon picker stays OPEN as you browse: selecting an icon repaints just the
	// anchor button (live preview) and moves the highlight, instead of rebuilding the
	// whole menu tree — which used to destroy the anchor the popup hangs off, slamming
	// it shut on every click. Close happens on an outside click or a re-click of the
	// icon button (toggle).
	function openIconPicker( anchor, top, tree ) {
		closeIconPicker();
		var pop = el( 'div', { 'class': 'ak-icon-pop' } );
		var choices = []; // { el, val } — every selectable swatch, for the highlight sweep.

		// Repaint highlight + the anchor button. `commit` also flags the menu dirty;
		// the initial paint (on open) must NOT, or merely opening the picker would
		// mark unsaved changes.
		function paint() {
			choices.forEach( function ( c ) { c.el.classList.toggle( 'on', top.icon === c.val ); } );
			paintIcon( anchor, top );
		}
		function choose( val ) { top.icon = val; paint(); markMenuDirty(); }

		// "Default" = the item's own original WordPress dashicon (icon = ''). The
		// separate "WordPress icon" choice was removed — it resolved to the same glyph
		// and only confused the choice. Pick Default, an AdminKit icon, or paste one.
		var defBar = el( 'div', { 'class': 'ak-icon-pop__defbar' } );
		var noneBtn = el( 'button', { type: 'button', 'class': 'ak-icon-pop__def', text: I.menuIconNone, onclick: function () { choose( '' ); } } );
		choices.push( { el: noneBtn, val: '' } );
		defBar.appendChild( noneBtn );
		pop.appendChild( defBar );

		var grid = el( 'div', { 'class': 'ak-icon-pop__grid' } );
		Object.keys( MM.icons || {} ).forEach( function ( name ) {
			var sw = el( 'button', { type: 'button', 'class': 'ak-icon-pop__sw', title: name, 'aria-label': name, onclick: function () { choose( name ); } } );
			sw.innerHTML = MM.icons[ name ];
			choices.push( { el: sw, val: name } );
			grid.appendChild( sw );
		} );
		pop.appendChild( grid );

		// Custom icon — one line: a leading image glyph (the affordance) + a field to
		// paste raw <svg> markup or a data:image/svg+xml URI (base64 or encoded) + Apply.
		var customIc = el( 'span', { 'class': 'ak-icon-pop__customic', 'aria-hidden': 'true' } );
		customIc.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg>';
		var ta = el( 'input', { type: 'text', 'class': 'ak-icon-pop__ta', placeholder: I.menuIconCustom } );
		var applyBtn = el( 'button', { type: 'button', 'class': 'ak-btn ak-btn--small', text: I.menuIconApply, onclick: function () {
			var v = ( ta.value || '' ).replace( /^\s+|\s+$/g, '' );
			if ( ! v ) { return; }
			if ( v.indexOf( '<svg' ) === 0 ) { v = 'data:image/svg+xml,' + encodeURIComponent( v ); }
			if ( v.indexOf( 'data:image/svg+xml' ) !== 0 ) { return; }
			choose( v ); ta.value = '';
		} } );
		ta.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); applyBtn.click(); } } );
		pop.appendChild( el( 'div', { 'class': 'ak-icon-pop__custom' }, [ customIc, ta, applyBtn ] ) );

		anchor.parentNode.appendChild( pop );
		positionPopover( pop, anchor );
		pop.__akAnchor = anchor;
		openPicker = pop;
		paint(); // seed the highlight (no dirty flag).
		setTimeout( function () { document.addEventListener( 'mousedown', onDocDown, true ); }, 0 );
	}

	// Place a popover directly under its anchor button, clamped to the row so it
	// never spills past the right edge — fixes the picker opening at the row's far
	// left for right-hand buttons (URL / hide). Row is position:relative.
	function positionPopover( pop, anchor ) {
		var row  = anchor.parentNode;
		var rowW = row.offsetWidth;
		var popW = pop.offsetWidth || 264;
		var left = anchor.offsetLeft;
		if ( left + popW > rowW ) { left = Math.max( 0, rowW - popW ); }
		pop.style.left  = left + 'px';
		pop.style.right = 'auto';
		pop.style.top   = ( anchor.offsetTop + anchor.offsetHeight + 4 ) + 'px';
	}

	function onDocDown( e ) {
		if ( openPicker && ! openPicker.contains( e.target ) ) { closeIconPicker(); }
	}

	function closeIconPicker() {
		if ( openPicker && openPicker.parentNode ) { openPicker.parentNode.removeChild( openPicker ); }
		openPicker = null;
		document.removeEventListener( 'mousedown', onDocDown, true );
	}

	// "Open in new tab" toggle for custom links — adds target="_blank" at apply time.
	function newTabBtn( item, tree ) {
		var btn = el( 'button', {
			type: 'button',
			'class': 'ak-menu-newtab' + ( item.newTab ? ' on' : '' ),
			'aria-pressed': item.newTab ? 'true' : 'false',
			title: I.menuNewTab, 'aria-label': I.menuNewTab,
			onclick: function () { item.newTab = ! item.newTab; renderTree( tree ); markMenuDirty(); }
		} );
		btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M10 14L20 4"/><path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>';
		return btn;
	}

	// Custom-URL button + popover (top-level items). A set URL repoints the top item;
	// its submenu keeps working. Reuses the icon picker's open/close machinery.
	function urlBtn( top, tree ) {
		var on = !! ( top.url && top.url !== '' );
		var btn = el( 'button', { type: 'button', 'class': 'ak-menu-url' + ( on ? ' on' : '' ), title: I.menuUrlTitle, 'aria-label': I.menuUrlTitle } );
		btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
		btn.addEventListener( 'click', function ( e ) { e.stopPropagation(); openUrlPicker( btn, top, tree ); } );
		return btn;
	}

	function openUrlPicker( anchor, top, tree ) {
		closeIconPicker();
		var pop = el( 'div', { 'class': 'ak-icon-pop ak-url-pop' } );
		pop.appendChild( el( 'div', { 'class': 'ak-icon-pop__customlbl', text: I.menuUrlLabel } ) );

		// Show where this item points right now — the custom override if set, else
		// its native WordPress destination (the slug). Read-only, so you SEE the link
		// before changing it.
		var current = top.url || top.slug || '';
		if ( current ) {
			pop.appendChild( el( 'div', { 'class': 'ak-url-pop__current' }, [
				el( 'span', { 'class': 'ak-url-pop__current-lbl', text: ( I.menuUrlCurrent || 'Current' ) + ' : ' } ),
				el( 'code', { 'class': 'ak-url-pop__current-val', title: current, text: current } )
			] ) );
		}

		// Pre-fill with the custom override; the placeholder is the native slug so an
		// empty field still hints the current destination.
		var inp = el( 'input', { type: 'text', 'class': 'ak-menu-input', value: top.url || '', placeholder: top.slug || I.menuLinkUrl } );
		// Apply / Clear reflect the URL state on the anchor button in place (its `on`
		// class) and close — no full tree rebuild (which would destroy the anchor the
		// popover hangs off, same trap the icon picker had).
		var apply = el( 'button', { type: 'button', 'class': 'ak-btn ak-btn--small', text: I.menuIconApply, onclick: function () {
			top.url = ( inp.value || '' ).replace( /^\s+|\s+$/g, '' );
			anchor.classList.toggle( 'on', !! top.url );
			markMenuDirty(); closeIconPicker();
		} } );
		var clear = el( 'button', { type: 'button', 'class': 'ak-btn ak-btn--small ak-btn--ghost', text: I.menuUrlClear, onclick: function () {
			top.url = ''; anchor.classList.remove( 'on' ); markMenuDirty(); closeIconPicker();
		} } );
		pop.appendChild( el( 'div', { 'class': 'ak-icon-pop__custom ak-url-pop__row' }, [ inp, apply, clear ] ) );
		anchor.parentNode.appendChild( pop );
		positionPopover( pop, anchor );
		pop.__akAnchor = anchor;
		openPicker = pop;
		setTimeout( function () { document.addEventListener( 'mousedown', onDocDown, true ); }, 0 );
	}

	// Tree drag-and-drop. A drag carries { kind:'top', ti } or { kind:'sub', ti, ci }.
	// dragSource makes a handle draggable; dropZone accepts a drag (by kind) and runs the
	// move. Top rows accept tops (reorder) AND subs (nest, cross-section); child rows accept
	// subs (insert here, from any section); a parent's open children area accepts subs (append).
	function dragSource( handle, row, info ) {
		handle.setAttribute( 'draggable', 'true' );
		handle.addEventListener( 'dragstart', function ( e ) {
			e.stopPropagation();
			dragState = info;
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData( 'text/plain', '1' ); } catch ( err ) {}
			row.classList.add( 'is-dragging' );
		} );
		handle.addEventListener( 'dragend', function () { row.classList.remove( 'is-dragging' ); } );
	}

	function dropZone( zone, accepts, onDrop ) {
		zone.addEventListener( 'dragover', function ( e ) {
			if ( ! dragState || ! accepts( dragState ) ) { return; }
			e.preventDefault();
			e.stopPropagation();
			e.dataTransfer.dropEffect = 'move';
			zone.classList.add( 'is-drop' );
		} );
		zone.addEventListener( 'dragleave', function () { zone.classList.remove( 'is-drop' ); } );
		zone.addEventListener( 'drop', function ( e ) {
			if ( ! dragState || ! accepts( dragState ) ) { return; }
			e.preventDefault();
			e.stopPropagation();
			zone.classList.remove( 'is-drop' );
			var ds = dragState;
			dragState = null;
			onDrop( ds );
		} );
	}

	function moveTop( from, to ) {
		var m = state.menu.splice( from, 1 )[ 0 ];
		state.menu.splice( to, 0, m );
	}

	function moveChild( fromTi, fromCi, toTi, toCi ) {
		var c = state.menu[ fromTi ].children.splice( fromCi, 1 )[ 0 ];
		if ( fromTi === toTi && fromCi < toCi ) { toCi--; }
		if ( ! state.menu[ toTi ].children ) { state.menu[ toTi ].children = []; }
		state.menu[ toTi ].children.splice( toCi, 0, c );
		state.menu[ toTi ].open = true;
	}

	function moveChildToTop( fromTi, fromCi, toTi ) {
		var c = state.menu[ fromTi ].children.splice( fromCi, 1 )[ 0 ];
		if ( ! state.menu[ toTi ].children ) { state.menu[ toTi ].children = []; }
		state.menu[ toTi ].children.push( c );
		state.menu[ toTi ].open = true;
	}

	// A label that looks like plain text until focused; editing it renames the entry.
	// Empty (or back to the original) = no override (WP's own label returns).
	function titleInput( item ) {
		var inp = el( 'input', { type: 'text', 'class': 'ak-menu-input ak-menu-title', value: item.title || '', placeholder: item.otitle || item.slug || '', title: I.menuRename, 'aria-label': I.menuRename } );
		inp.addEventListener( 'input', function () { item.title = inp.value; markMenuDirty(); } );
		return inp;
	}

	// --- save ----------------------------------------------------------------
	// Interactive controls (Features toggles, Plugins toggles, Branding logos)
	// post to REST; the Tokens tab is a read-only reference.
	// Export the current settings as a downloaded JSON file. Wrapped with a small
	// header (signature + timestamp) for humans; only `values` matters on import.
	function exportSettings() {
		var payload = { _adminkit: 'settings', version: ( D.version || '1' ), exported: new Date().toISOString(), values: gather() };
		var blob = new Blob( [ JSON.stringify( payload, null, '\t' ) ], { type: 'application/json' } );
		var url  = URL.createObjectURL( blob );
		var a    = el( 'a', { href: url, download: 'adminkit-settings.json' } );
		document.body.appendChild( a );
		a.click();
		a.remove();
		URL.revokeObjectURL( url );
	}

	// Import settings from a JSON file. Accepts either our export wrapper (with a
	// `values` object) or a bare values object. The file is POSTed through the SAME
	// save route, so the server's sanitize() keeps only known keys — an import can
	// never inject anything. On success we reload so every control reflects the
	// imported state from fresh boot data.
	function importSettings( file ) {
		var reader = new FileReader();
		reader.onload = function () {
			var data, values;
			try { data = JSON.parse( reader.result ); } catch ( e ) { data = null; }
			values = ( data && typeof data === 'object' && data.values && typeof data.values === 'object' ) ? data.values
				: ( data && typeof data === 'object' ? data : null );
			if ( ! values || ! apiFetch ) {
				setStatus( 'is-error', I.settingsImportError || 'That file isn’t a valid AdminKit settings export.' );
				return;
			}
			state.saving = true; updateBar();
			apiFetch( { path: D.route, method: 'POST', data: { values: values } } )
				.then( function () { window.location.reload(); } )
				.catch( function () { state.saving = false; setStatus( 'is-error', I.error ); updateBar(); } );
		};
		reader.readAsText( file );
	}

	function gather() {
		var v = {};
		Object.keys( state.features ).forEach( function ( k ) { v[ k ] = !! state.features[ k ]; } );
		Object.keys( state.integrations ).forEach( function ( slug ) {
			v[ 'integration_' + slug + '_enabled' ] = !! state.integrations[ slug ];
		} );
		// Per-plugin opt-out for generic plugins (no native adapter). POST as a
		// flat array of plugin file paths the user has turned theming off for.
		v.generic_theming_off = Object.keys( state.genericThemingOff );
		v.logo_light    = state.logos.light;
		v.logo_dark     = state.logos.dark;
		v.wp_logo       = state.wpLogo;
		v.login_logo    = state.loginLogo;
		v.brand_accent  = state.brandAccent;
		v.accent_source = state.accentSource;
		return v;
	}

	function save() {
		if ( state.saving || ! state.dirty ) { return; }
		if ( ! apiFetch ) { setStatus( 'is-error', I.error ); return; }
		state.saving = true;
		updateBar();
		// Each screen posts ONLY its own data: the Settings screen posts the settings
		// values; the Menu screen posts the layout to its own route. (Never POST the
		// settings values from the Menu screen — gather() there is empty and would
		// clear stored settings.)
		var jobs = [];
		if ( SCREEN === 'main' ) {
			var path = D.route.charAt( 0 ) === '/' ? D.route : '/' + D.route;
			jobs.push( apiFetch( { path: path, method: 'POST', data: { values: gather() } } ) );
		}
		// The menu layout posts to its own route, only when it changed.
		if ( state.menuDirty && D.menuManager && D.menuManager.route ) {
			var mpath = D.menuManager.route.charAt( 0 ) === '/' ? D.menuManager.route : '/' + D.menuManager.route;
			jobs.push( apiFetch( { path: mpath, method: 'POST', data: { items: gatherMenu() } } ) );
		}
		if ( ! jobs.length ) { state.saving = false; state.dirty = false; updateBar(); return; }
		Promise.all( jobs )
			.then( function () {
				state.saving = false;
				state.dirty = false;
				state.menuDirty = false;
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
