/**
 * AdminKit dashboard — drag-to-arrange the cards (per user).
 *
 * Progressive enhancement over the server-rendered grid: each card header gets a
 * grip handle; dragging a card reorders it within or across the two columns and
 * the new layout is saved per-user via admin-ajax. No dependency — Pointer Events
 * give one code path for mouse, touch and pen. With JS off the dashboard is just
 * a normal static grid.
 */
( function () {
	'use strict';

	var cfg  = window.AdminKitDash || {};
	var grid = document.querySelector( '[data-ak-dash-grid]' );
	if ( ! grid ) {
		return;
	}
	var cols = [].slice.call( grid.querySelectorAll( '[data-ak-col]' ) );
	if ( ! cols.length ) {
		return;
	}

	var GRIP = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
		+ '<circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/>'
		+ '<circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/>'
		+ '<circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>';

	var REORDER = ( cfg.i18n && cfg.i18n.reorder ) || 'Drag to reorder';
	var drag    = null; // active drag state, or null

	// Give a card a grip handle inside its header (the only drag affordance, so
	// links in the header stay clickable). The grip is absolutely positioned, so it
	// never disturbs the header's flex layout. Idempotent — safe to call again on a
	// card re-inserted by the Customize "show" path.
	function enhanceCard( wrap ) {
		var head = wrap.querySelector( '.ak-card__head' );
		if ( ! head || head.querySelector( '.ak-dash__grip' ) ) {
			return;
		}
		var grip = document.createElement( 'button' );
		grip.type      = 'button';
		grip.className = 'ak-dash__grip';
		grip.title     = REORDER;
		grip.setAttribute( 'aria-label', REORDER );
		grip.innerHTML = GRIP;
		head.classList.add( 'ak-card__head--grip' );
		head.appendChild( grip );
		grip.addEventListener( 'pointerdown', function ( e ) {
			onDown( e, wrap, grip );
		} );
	}

	grid.querySelectorAll( '.ak-dash__card-wrap' ).forEach( enhanceCard );

	updateFill();

	function onDown( e, wrap, grip ) {
		if ( drag ) {
			return; // one drag at a time (ignore a second pointer)
		}
		if ( e.button && e.button !== 0 ) {
			return; // primary button (or touch/pen) only
		}
		e.preventDefault();
		try { grip.setPointerCapture( e.pointerId ); } catch ( err ) {}
		drag = {
			wrap: wrap,
			grip: grip,
			id: e.pointerId,
			startX: e.clientX,
			startY: e.clientY,
			offX: 0,
			offY: 0,
			ph: null,
			lifted: false,
			before: snapshot()
		};
		grip.addEventListener( 'pointermove', onMove );
		grip.addEventListener( 'pointerup', onUp );
		grip.addEventListener( 'pointercancel', onUp );
	}

	// Lift the card out of flow once the pointer has moved past a small threshold
	// (so a plain tap on the grip is a no-op, not a drag).
	function lift( e ) {
		var wrap = drag.wrap;
		var rect = wrap.getBoundingClientRect();
		drag.offX = drag.startX - rect.left;
		drag.offY = drag.startY - rect.top;

		drag.ph = document.createElement( 'div' );
		drag.ph.className     = 'ak-dash__card-ph';
		drag.ph.style.height  = rect.height + 'px';
		wrap.parentNode.insertBefore( drag.ph, wrap );

		wrap.style.setProperty( '--ak-drag-w', rect.width + 'px' );
		wrap.classList.add( 'is-dragging' );

		grid.classList.add( 'is-sorting' );
		grid.removeAttribute( 'data-ak-fill' );
		cols.forEach( function ( c ) { c.classList.remove( 'is-empty' ); } );

		drag.lifted = true;
		moveTo( e.clientX, e.clientY );
	}

	function moveTo( x, y ) {
		drag.wrap.style.left = ( x - drag.offX ) + 'px';
		drag.wrap.style.top  = ( y - drag.offY ) + 'px';
	}

	function onMove( e ) {
		if ( ! drag ) {
			return;
		}
		if ( ! drag.lifted ) {
			if ( Math.abs( e.clientX - drag.startX ) + Math.abs( e.clientY - drag.startY ) < 5 ) {
				return;
			}
			lift( e );
		}
		e.preventDefault();
		moveTo( e.clientX, e.clientY );

		var col = columnAt( e.clientX, e.clientY );
		if ( ! col ) {
			return;
		}
		// Insert the placeholder before the first sibling whose vertical midpoint is
		// below the pointer; past the last → append. Deterministic, gap-proof.
		var ref   = null;
		var items = col.querySelectorAll( '.ak-dash__card-wrap:not(.is-dragging)' );
		for ( var i = 0; i < items.length; i++ ) {
			var r = items[ i ].getBoundingClientRect();
			if ( e.clientY < r.top + r.height / 2 ) {
				ref = items[ i ];
				break;
			}
		}
		col.insertBefore( drag.ph, ref );
	}

	function onUp() {
		if ( ! drag ) {
			return;
		}
		var grip = drag.grip;
		grip.removeEventListener( 'pointermove', onMove );
		grip.removeEventListener( 'pointerup', onUp );
		grip.removeEventListener( 'pointercancel', onUp );
		try { grip.releasePointerCapture( drag.id ); } catch ( err ) {}

		if ( drag.lifted ) {
			var wrap = drag.wrap;
			wrap.classList.remove( 'is-dragging' );
			wrap.style.left = '';
			wrap.style.top  = '';
			wrap.style.removeProperty( '--ak-drag-w' );

			// Settle the card into the placeholder slot — but if the placeholder is
			// exactly where the card already sits, just drop it (avoids needlessly
			// re-parenting, which would reload the preview iframe).
			if ( drag.ph && drag.ph.parentNode ) {
				if ( drag.ph.nextSibling === wrap && drag.ph.parentNode === wrap.parentNode ) {
					drag.ph.parentNode.removeChild( drag.ph );
				} else {
					drag.ph.parentNode.replaceChild( wrap, drag.ph );
				}
			}

			grid.classList.remove( 'is-sorting' );
			updateFill();
			if ( snapshot() !== drag.before ) {
				save();
			}
		}
		drag = null;
	}

	// The column under a point: the one whose box contains it, else the
	// horizontally-nearest (so a drop in the gutter still resolves to a column).
	function columnAt( x, y ) {
		var best   = null;
		var bestDx = Infinity;
		for ( var i = 0; i < cols.length; i++ ) {
			var r = cols[ i ].getBoundingClientRect();
			if ( x >= r.left && x <= r.right && y >= r.top - 40 && y <= r.bottom + 40 ) {
				return cols[ i ];
			}
			var dx = Math.abs( x - ( r.left + r.width / 2 ) );
			if ( dx < bestDx ) {
				bestDx = dx;
				best   = cols[ i ];
			}
		}
		return best;
	}

	// Collapse an empty column to one full-width track (no orphan gutter). Only
	// when exactly one of the two columns is empty.
	function updateFill() {
		var empty = cols.filter( function ( c ) {
			return ! c.querySelector( '.ak-dash__card-wrap' );
		} );
		cols.forEach( function ( c ) { c.classList.remove( 'is-empty' ); } );
		grid.removeAttribute( 'data-ak-fill' );
		if ( 2 === cols.length && 1 === empty.length ) {
			empty[ 0 ].classList.add( 'is-empty' );
			var full = cols[ 0 ] === empty[ 0 ] ? cols[ 1 ] : cols[ 0 ];
			grid.setAttribute( 'data-ak-fill', full.getAttribute( 'data-ak-col' ) );
		}
	}

	// A compact string of the current order, for cheap change-detection.
	function snapshot() {
		return cols.map( function ( col ) {
			return col.getAttribute( 'data-ak-col' ) + ':' + keysIn( col ).join( ',' );
		} ).join( '|' );
	}

	function keysIn( col ) {
		return [].slice.call( col.querySelectorAll( '.ak-dash__card-wrap' ) ).map( function ( w ) {
			return w.getAttribute( 'data-ak-card' );
		} );
	}

	function save() {
		if ( ! cfg.ajaxUrl || ! cfg.action ) {
			return;
		}
		var layout = {};
		cols.forEach( function ( col ) {
			layout[ col.getAttribute( 'data-ak-col' ) ] = keysIn( col );
		} );
		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( '_ajax_nonce', cfg.nonce || '' );
		body.set( 'layout', JSON.stringify( layout ) );
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).catch( function () {} );
	}

	/* ───────────────────────── Customize panel ─────────────────────────
	   Per-user card visibility. Hiding a card removes it from the DOM live;
	   showing one fetches its freshly-rendered markup over AJAX and inserts
	   it — no full page reload either way. */
	( function customize() {
		var tools = document.querySelector( '[data-ak-dash-tools]' );
		if ( ! tools ) {
			return;
		}
		var btn   = tools.querySelector( '[data-ak-customize]' );
		var panel = tools.querySelector( '[data-ak-panel]' );
		if ( ! btn || ! panel ) {
			return;
		}

		function open() {
			panel.hidden = false;
			btn.setAttribute( 'aria-expanded', 'true' );
			document.addEventListener( 'click', onOutside, true );
			document.addEventListener( 'keydown', onKey );
		}
		function close() {
			panel.hidden = true;
			btn.setAttribute( 'aria-expanded', 'false' );
			document.removeEventListener( 'click', onOutside, true );
			document.removeEventListener( 'keydown', onKey );
		}
		function onOutside( e ) {
			if ( ! tools.contains( e.target ) ) {
				close();
			}
		}
		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				close();
				btn.focus();
			}
		}

		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			if ( panel.hidden ) {
				open();
			} else {
				close();
			}
		} );

		// The set of currently-hidden keys = every unchecked toggle.
		function hiddenSet() {
			var out = [];
			panel.querySelectorAll( '[data-ak-card-toggle]' ).forEach( function ( cb ) {
				if ( ! cb.checked ) {
					out.push( cb.getAttribute( 'data-ak-card-toggle' ) );
				}
			} );
			return out;
		}

		function saveHidden() {
			if ( ! cfg.ajaxUrl || ! cfg.hiddenAction ) {
				return Promise.resolve();
			}
			var body = new URLSearchParams();
			body.set( 'action', cfg.hiddenAction );
			body.set( '_ajax_nonce', cfg.hiddenNonce || '' );
			body.set( 'hidden', JSON.stringify( hiddenSet() ) );
			return fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).catch( function () {} );
		}

		// Fetch a freshly-rendered card and insert it at the end of its default
		// column (most natural drop spot; the user can drag it anywhere after).
		function showCard( key, cb ) {
			if ( ! cfg.ajaxUrl || ! cfg.cardAction ) {
				window.location.reload();
				return;
			}
			var body = new URLSearchParams();
			body.set( 'action', cfg.cardAction );
			body.set( '_ajax_nonce', cfg.cardNonce || '' );
			body.set( 'card', key );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data || ! res.data.html ) {
						window.location.reload();
						return;
					}
					var tmp = document.createElement( 'div' );
					tmp.innerHTML = res.data.html;
					var wrap = tmp.firstElementChild;
					if ( ! wrap ) { return; }
					var col = grid.querySelector( '[data-ak-col="' + ( res.data.col || 'main' ) + '"]' )
						|| cols[ 0 ];
					col.appendChild( wrap );
					// Give the new card its drag grip + any inline behaviour.
					enhanceCard( wrap );
					updateFill();
					if ( cb ) { cb(); }
				} )
				.catch( function () { window.location.reload(); } );
		}

		panel.addEventListener( 'change', function ( e ) {
			var cb = e.target;
			if ( ! cb || ! cb.hasAttribute || ! cb.hasAttribute( 'data-ak-card-toggle' ) ) {
				return;
			}
			var key = cb.getAttribute( 'data-ak-card-toggle' );
			if ( ! cb.checked ) {
				// Hide live: drop the card from the DOM, persist, no reload.
				var wrap = grid.querySelector( '.ak-dash__card-wrap[data-ak-card="' + key + '"]' );
				if ( wrap && wrap.parentNode ) {
					wrap.parentNode.removeChild( wrap );
				}
				updateFill();
				saveHidden();
			} else {
				// Show live: persist, then fetch + insert the rendered card.
				saveHidden();
				showCard( key );
			}
		} );
	}() );

	/* ───────────────────────── Stats card range picker ─────────────────────
	   Preset chips (Live / Today / 30d / 90d / YTD / Custom) re-render the card
	   body via AJAX. Clicking "Custom" reveals two date inputs locally without
	   an AJAX (the dates haven't changed yet); editing either input fires the
	   AJAX with the new pair. Live is special: after rendering, the card holds
	   `[data-ak-stats-live]` and we auto-refresh on a timer — paused while the
	   tab is hidden (Page Visibility), restarted instantly when it returns.
	   Delegated so it all survives the card being re-inserted by the
	   Customize "show" path. */
	( function statsRange() {
		var pendingDates = null;
		var liveTimer    = null;
		var liveCard     = null;
		var liveInterval = 10000;

		// Tick the live count from its old value to the new one after a refresh
		// (the poll already happens — this is just the in-place tween). Reads/writes
		// the displayed number; reduced-motion + no-rAF fall back to a snap.
		function parseCount( txt ) {
			var n = parseInt( ( txt || '' ).replace( /[^\d-]/g, '' ), 10 );
			return isNaN( n ) ? null : n;
		}
		function tickCount( elNum, from, to ) {
			if ( ! elNum || null === to ) { return; }
			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			if ( null === from || from === to || reduce || ! window.requestAnimationFrame ) { return; }
			var start = null, dur = 600;
			function step( ts ) {
				if ( null === start ) { start = ts; }
				var p = Math.min( 1, ( ts - start ) / dur );
				var e = 1 - Math.pow( 1 - p, 3 );
				var v = Math.round( from + ( to - from ) * e );
				try { elNum.textContent = Number( v ).toLocaleString(); } catch ( err ) { elNum.textContent = String( v ); }
				if ( p < 1 ) { requestAnimationFrame( step ); }
			}
			requestAnimationFrame( step );
		}

		// Whether `card` is currently rendered in Live mode (server marker).
		function isLiveBody( card ) {
			return !! ( card && card.querySelector( '[data-ak-stats-live]' ) );
		}

		// After a card swap: if we're now in live mode, ensure the polling loop
		// is running for THIS card; if we left live mode, stop any prior loop.
		function syncLive( card ) {
			if ( isLiveBody( card ) ) {
				var node = card.querySelector( '[data-ak-stats-live]' );
				var attr = node && parseInt( node.getAttribute( 'data-ak-stats-live-interval' ), 10 );
				liveInterval = ( attr && attr >= 2000 ) ? attr : 10000;
				liveCard = card;
				scheduleLive( liveInterval );
			} else if ( liveCard === card ) {
				stopLive();
				liveCard = null;
			}
		}

		function stopLive() {
			if ( liveTimer ) { clearTimeout( liveTimer ); liveTimer = null; }
		}

		function scheduleLive( delay ) {
			stopLive();
			liveTimer = setTimeout( tickLive, delay );
		}

		// One auto-refresh tick. Silent: no `is-loading` flicker, no chip change.
		// Skips the network entirely when the tab is hidden — visibilitychange
		// re-arms us as soon as the user comes back.
		function tickLive() {
			liveTimer = null;
			if ( ! liveCard || ! document.body.contains( liveCard ) || ! isLiveBody( liveCard ) ) {
				liveCard = null;
				return;
			}
			if ( document.hidden ) {
				return; // Stay parked; visibilitychange handler restarts us.
			}
			if ( ! cfg.ajaxUrl || ! cfg.statsAction ) { return; }
			var body = new URLSearchParams();
			body.set( 'preset', 'live' );
			body.set( 'action', cfg.statsAction );
			body.set( '_ajax_nonce', cfg.statsNonce || '' );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( liveCard && isLiveBody( liveCard ) && res && res.success && res.data && typeof res.data.html === 'string' ) {
						// Capture the old count, swap the HTML, then tick to the new one.
						var prevEl = liveCard.querySelector( '.ak-dash__stats-live-num' );
						var prev   = prevEl ? parseCount( prevEl.textContent ) : null;
						liveCard.innerHTML = res.data.html;
						var nextEl = liveCard.querySelector( '.ak-dash__stats-live-num' );
						if ( nextEl ) { tickCount( nextEl, prev, parseCount( nextEl.textContent ) ); }
					}
					if ( liveCard && isLiveBody( liveCard ) ) {
						scheduleLive( liveInterval );
					}
				} )
				.catch( function () {
					// Soft backoff on error so a flaky network doesn't hammer.
					if ( liveCard && isLiveBody( liveCard ) ) {
						scheduleLive( liveInterval * 2 );
					}
				} );
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden && liveCard && isLiveBody( liveCard ) ) {
				scheduleLive( 0 );
			}
		} );

		// Shared POST → swap card body. User-driven (chip click, date change),
		// so we DO flash `is-loading` for feedback.
		function fetchAndSwap( card, params ) {
			if ( ! cfg.ajaxUrl || ! cfg.statsAction ) { return; }
			card.classList.add( 'is-loading' );
			params.set( 'action', cfg.statsAction );
			params.set( '_ajax_nonce', cfg.statsNonce || '' );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: params } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success && res.data && typeof res.data.html === 'string' ) {
						card.innerHTML = res.data.html;
					}
					card.classList.remove( 'is-loading' );
					syncLive( card );
				} )
				.catch( function () { card.classList.remove( 'is-loading' ); } );
		}

		// Boot: a server-rendered Live card needs the polling loop kicked off.
		var initial = document.querySelector( '[data-ak-stats]' );
		if ( initial ) { syncLive( initial ); }

		// Preset click: rolling preset / live → AJAX; "custom" → just reveal the
		// inputs (no AJAX yet, the dates haven't changed).
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target && e.target.closest
				? e.target.closest( '[data-ak-stats-preset]' )
				: null;
			if ( ! btn ) { return; }
			var card = btn.closest( '[data-ak-stats]' );
			if ( ! card ) { return; }
			var preset = btn.getAttribute( 'data-ak-stats-preset' );

			if ( 'custom' === preset ) {
				// Local-only UI flip: reveal inputs, mark Custom button as active.
				stopLive(); liveCard = null;
				var range = card.querySelector( '.ak-dash__stats-range' );
				if ( range ) { range.setAttribute( 'data-ak-range-mode', 'custom' ); }
				var custom = card.querySelector( '.ak-dash__stats-custom' );
				if ( custom ) { custom.hidden = false; }
				card.querySelectorAll( '[data-ak-stats-preset]' ).forEach( function ( b ) {
					var on = b === btn;
					b.classList.toggle( 'is-active', on );
					b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				} );
				var sIn = card.querySelector( '[data-ak-stats-date="start"]' );
				if ( sIn ) { sIn.focus(); }
				return;
			}

			var body = new URLSearchParams();
			body.set( 'preset', preset );
			fetchAndSwap( card, body );
		} );

		// Date input change → only when Custom is active (the inputs are hidden
		// otherwise). Debounce so spinner increments don't all fire.
		document.addEventListener( 'change', function ( e ) {
			var input = e.target;
			if ( ! input || ! input.hasAttribute || ! input.hasAttribute( 'data-ak-stats-date' ) ) { return; }
			var card = input.closest( '[data-ak-stats]' );
			if ( ! card ) { return; }
			var sIn = card.querySelector( '[data-ak-stats-date="start"]' );
			var eIn = card.querySelector( '[data-ak-stats-date="end"]' );
			if ( ! sIn || ! eIn ) { return; }

			if ( pendingDates ) { clearTimeout( pendingDates ); }
			pendingDates = setTimeout( function () {
				pendingDates = null;
				var body = new URLSearchParams();
				body.set( 'preset', 'custom' );
				body.set( 'start',  sIn.value );
				body.set( 'end',    eIn.value );
				fetchAndSwap( card, body );
			}, 200 );
		} );
	}() );
}() );
