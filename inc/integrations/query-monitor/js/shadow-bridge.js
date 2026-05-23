/* Query Monitor renders its panel inside an OPEN shadow root (QM 4.0+), which a
   normally-enqueued stylesheet can't reach. This bridge injects AdminKit's token
   remap (css/admin.css) as a <link> into that shadow root — CSS custom
   properties (--ak-*) inherit across the boundary, so the remap resolves and
   flips with AdminKit's dark mode. It also mirrors AdminKit's light/dark mode
   onto the panel's data-theme so QM's own color-scheme (native scrollbars + the
   SQL value tint left on QM's light-dark()) follows. One-way: AdminKit → QM. */
( function () {
	var cfg = window.adminkitQM;
	if ( ! cfg || ! cfg.cssUrl ) {
		return;
	}
	var root = document.documentElement;

	function syncTheme( shadow ) {
		var panel = shadow.getElementById( 'query-monitor-main' );
		if ( ! panel ) {
			return false;
		}
		panel.setAttribute(
			'data-theme',
			'dark' === root.getAttribute( 'data-adminkit-theme' ) ? 'dark' : 'light'
		);
		return true;
	}

	function start( shadow ) {
		// 1. Inject the remap as a <link> (applies to #query-monitor-main
		//    whenever QM builds it; persists across QM panel re-renders).
		if ( ! shadow.querySelector( 'link[data-adminkit]' ) ) {
			var link = document.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = cfg.cssUrl;
			link.setAttribute( 'data-adminkit', '1' );
			shadow.appendChild( link );
		}

		// 2. Mirror AdminKit's mode onto the panel. The panel may not be built
		//    yet when the shadow root first attaches, so retry until it is.
		if ( ! syncTheme( shadow ) ) {
			var mo = new MutationObserver( function () {
				if ( syncTheme( shadow ) ) {
					mo.disconnect();
				}
			} );
			mo.observe( shadow, { childList: true, subtree: true } );
		}

		// 3. Follow AdminKit's dark-mode toggle for the life of the page.
		new MutationObserver( function () {
			syncTheme( shadow );
		} ).observe( root, { attributes: true, attributeFilter: [ 'data-adminkit-theme' ] } );
	}

	function boot() {
		var host = document.getElementById( 'query-monitor-container' );
		if ( ! host || ! host.shadowRoot ) {
			return false;
		}
		start( host.shadowRoot );
		return true;
	}

	// QM attaches its shadow root from a deferred module script, so it may not
	// exist yet. Poll briefly (attachShadow doesn't fire mutation observers).
	if ( ! boot() ) {
		var tries = 0;
		var iv = setInterval( function () {
			if ( boot() || ++tries > 150 ) {
				clearInterval( iv );
			}
		}, 100 );
	}
}() );
