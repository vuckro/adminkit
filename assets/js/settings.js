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
		accentSource: D.accentSource || 'adminkit',
		// LIGHT favicon — bidirectional binding to WP's native `site_icon` option.
		// The light-favicon slot in the Brand card reads + writes through THIS;
		// changes propagate to every WP surface that consumes site_icon (browser
		// tab, login fallback, Open Graph). WP's own Site Icon row on Settings →
		// General edits the same option, so the two surfaces stay in sync on
		// next page load.
		siteIcon: {
			id:  ( D.siteIcon && D.siteIcon.id ) || 0,
			url: ( D.siteIcon && D.siteIcon.url ) || ''
		},
		// DARK-mode favicon — AdminKit-owned (WP has no equivalent). Stored as
		// a URL string and printed in `<head>` with `media="(prefers-color-scheme:
		// dark)"` so browsers swap automatically (incl. the Bricks editor tab).
		faviconDark: D.faviconDark || ''
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
		close: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
		// Upload arrow-up-tray — shown in empty brand-slot zones in lieu of a "Drop" text.
		upload: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>'
	};

	// The Dashboard tab hosts the full branding UI (Brand card + tokens
	// reference). Old `#design` hashes fall back to the dashboard via
	// applyHash().
	var tabs = [
		{ id: 'dashboard', label: I.dashboard, icon: ICONS.dashboard, build: buildDashboard },
		{ id: 'features', label: I.features, icon: ICONS.features, build: buildFeatures },
		{ id: 'plugins', label: I.plugins, icon: ICONS.plugins, build: buildPlugins }
	];
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

	// URL hash reflects the active tab (#dashboard / #features / #plugins).
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

	// Dashboard tab — currently just the Brand card (logos, favicons, accent
	// picker, display row). Thin wrapper so the tab registry stays uniform.
	function buildDashboard() {
		return buildDesign();
	}

	// Brand card builder. Lays out one .ak-card with the four media slots
	// (Favicon Light · Logo Light · Favicon Dark · Logo Dark), the accent
	// picker and the Display segmented controls.
	function buildDesign() {
		var p = el( 'section', { 'class': 'ak-panel', role: 'tabpanel' } );

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

		// Open WP's Site Icon picker (Library + SiteIconCropper) — same UX as
		// Settings → General → Site Icon: pick an image, then if it isn't
		// already 512×512 square, WP shows its native cropper dialog
		// ("Recadrer l'image") so we end up with a clean square favicon.
		//
		// Mirrors the flow in wp-admin/js/site-icon.js (WP 6.5+): the cropper
		// transition is MANUAL — we listen for `select`, compare the picked
		// attachment's dimensions to the target size, and call `setState('cropper')`
		// when it doesn't match. `cropped` fires with the NEW cropped attachment;
		// `skippedcrop` fires when the source was already square.
		//
		// SiteIconCropper lives in media-views.js (loaded by wp_enqueue_media() —
		// no extra script needed). Older WP / missing controller → openMedia()
		// fallback, which preserves the previous (uncropped) behaviour.
		function openSiteIcon( onPick ) {
			if ( ! window.wp || ! wp.media || ! wp.media.controller || ! wp.media.controller.SiteIconCropper ) {
				openMedia( onPick );
				return;
			}
			var size = 512;

			// Initial crop selection — copied from wp-admin/js/site-icon.js so the
			// dialog opens with the largest square that fits the source, centred.
			function imgSelectOptions( attachment ) {
				var w = attachment.get( 'width' ),
					h = attachment.get( 'height' ),
					xInit = size,
					yInit = size,
					ratio = xInit / yInit,
					x1, y1;
				if ( w / h > ratio ) { yInit = h; xInit = yInit * ratio; }
				else                 { xInit = w; yInit = xInit / ratio; }
				x1 = ( w - xInit ) / 2;
				y1 = ( h - yInit ) / 2;
				return {
					aspectRatio: xInit + ':' + yInit,
					handles: true, keys: true, instance: true, persistent: true,
					imageWidth: w, imageHeight: h,
					minWidth:  size > xInit ? xInit : size,
					minHeight: size > yInit ? yInit : size,
					x1: x1, y1: y1, x2: xInit + x1, y2: yInit + y1
				};
			}

			var frame = wp.media( {
				button: { text: I.mediaSiteIconButton || 'Set as Site Icon', close: false },
				states: [
					new wp.media.controller.Library( {
						title:           I.mediaSiteIconTitle || 'Choose a Site Icon',
						library:         wp.media.query( { type: 'image' } ),
						multiple:        false,
						date:            false,
						suggestedWidth:  size,
						suggestedHeight: size
					} ),
					new wp.media.controller.SiteIconCropper( {
						control: { params: { width: size, height: size } },
						imgSelectOptions: imgSelectOptions
					} )
				]
			} );

			// User picked an image. Square at the target size? Use it as-is.
			// Otherwise transition to the cropper state — same dance as WP core.
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().attributes;
				if ( att.width === size && att.height === size ) {
					onPick( att.url || '', att );
					frame.close();
				} else {
					frame.setState( 'cropper' );
				}
			} );

			// Cropped → WP saved a NEW cropped attachment.
			frame.on( 'cropped', function ( att ) {
				onPick( ( att && att.url ) || '', att );
				frame.close();
			} );

			// User clicked "Skip cropping" → use the source as-is.
			frame.on( 'skippedcrop', function ( att ) {
				var data = ( att && att.attributes ) || att || {};
				onPick( data.url || '', data );
				frame.close();
			} );

			frame.open();
		}

		// One brand slot — a dashed card with a fixed-backdrop drop zone (preview
		// or upload-arrow placeholder) + label + sub + Upload / Remove button.
		// `slotKey` is one of:
		//   'light' / 'dark'  → brand wordmark URLs in `state.logos[key]`
		//   'favicon'         → LIGHT favicon, proxies WP's native `site_icon`
		//                       (an attachment ID). Reading + writing routes
		//                       through `state.siteIcon`; the REST save converts
		//                       the id back to a `site_icon` update_option().
		//   'favicon-dark'    → AdminKit-owned dark-mode favicon URL in
		//                       `state.faviconDark`. Printed in <head> with
		//                       `media="(prefers-color-scheme: dark)"` so the
		//                       browser swaps it automatically.
		// Both favicon variants share the SiteIconCropper UX (square 512×512);
		// logo slots use the plain media frame (free-form aspect, no crop).
		function brandSlot( slotKey, label, sub ) {
			var isSiteIcon    = ( slotKey === 'favicon' );
			var isFaviconDark = ( slotKey === 'favicon-dark' );
			var isFavicon     = isSiteIcon || isFaviconDark;
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
				if ( isSiteIcon )    { return state.siteIcon.url || ''; }
				if ( isFaviconDark ) { return state.faviconDark || ''; }
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
			function setLogo( url, att ) {
				if ( isSiteIcon ) {
					state.siteIcon.url = url || '';
					state.siteIcon.id  = ( att && att.id ) ? parseInt( att.id, 10 ) : 0;
				} else if ( isFaviconDark ) {
					state.faviconDark = url || '';
				} else {
					state.logos[ slotKey ] = url || '';
				}
				syncPreview();
				markDirty();
			}

			// Both favicon slots route through WP's Site Icon picker (Library +
			// Cropper) so non-square uploads get cropped to a square 512×512
			// before save — same UX as the legacy Settings → General → Site Icon
			// for the light one; same cropper for the dark one even though we
			// store its URL ourselves. Wordmark logos use the plain media frame.
			var pick = isFavicon ? openSiteIcon : openMedia;

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

		// Brand slots — 4 slots laid out as a 2×2 grid (paired by mode):
		//   Row 1:  Favicon Light · Logo Light
		//   Row 2:  Favicon Dark  · Logo Dark
		// The light favicon proxies WP's native `site_icon` (WP's own Site
		// Icon row on Settings → General edits the same option; both surfaces
		// stay in sync on next page load).
		var slotsRow = el( 'div', { 'class': 'ak-brand-slots' }, [
			brandSlot( 'favicon', I.slotFavicon || 'Favicon Light Mode', I.slotFaviconSub || 'PNG · 512×512 · cropped' ),
			brandSlot( 'light', I.slotLight || 'Logo Light Mode', I.slotLightSub || 'SVG · PNG ≥ 400×100' ),
			brandSlot( 'favicon-dark', I.slotFaviconDark || 'Favicon Dark Mode', I.slotFaviconDarkSub || 'Auto-swap via prefers-color-scheme' ),
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

		// Card stack: identity (slots) → display row → color row. Mounted
		// directly under the panel — no wrapping intro text, the title on
		// the card head already explains what this is.
		var card = el( 'section', { 'class': 'ak-card' }, [
			cardHead, slotsRow, displayRow, colorRow
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
				byGroup[ label ] = { label: label, rows: el( 'div', { 'class': 'ak-rows' } ) };
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
		} );

		( D.features || [] ).forEach( refreshRow ); // initial dim + dependency state

		// Bulk controls — flip every feature on/off, or restore the registered
		// schema defaults (reuses the header's flex row + secondary buttons;
		// no new layout CSS).
		p.appendChild( el( 'div', { 'class': 'ak-actions ak-bulk' }, [
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.enableAll, onclick: function () { setAll( true ); } } ),
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.disableAll, onclick: function () { setAll( false ); } } ),
			el( 'button', { type: 'button', 'class': 'ak-btn', text: I.resetDefaults || 'Reset to defaults', onclick: resetAll } )
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

		var inputs = []; // every toggle (native + generic), for the Reset button

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

	// --- save ----------------------------------------------------------------
	// Interactive controls (Features toggles, Plugins toggles, Branding logos)
	// post to REST; the Tokens tab is a read-only reference.
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
		// LIGHT favicon — proxies WP's native `site_icon` option through the
		// REST route (PHP rest_save() consumes site_icon_id and calls
		// update_option('site_icon', $id)). One source of truth for the WP
		// option, edited through the Brand card's first slot.
		v.site_icon_id  = state.siteIcon.id;
		// AdminKit-owned DARK favicon URL (no WP equivalent).
		v.favicon_dark  = state.faviconDark;
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
