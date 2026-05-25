/* AdminKit — brand the Bricks builder preloader with the configured logo.

   Why a real <img> (not a CSS background): a background on a fixed-size box can never
   hug a logo of unknown aspect — it always leaves letterbox gutters (contain) or
   crops (cover). A real <img> with a fixed HEIGHT + width:auto takes the logo's
   NATURAL aspect, so the box hugs the logo exactly — no surrounding space, no
   padding/margin — and border-radius (builder-essentials.css) rounds the logo itself.

   The preloader is server-rendered, so we may run before it's in the DOM (or it may
   already be there). Try immediately, then poll briefly until it appears. */
( function () {
	var cfg = window.AdminKitBricksPreloader;
	if ( ! cfg || ! cfg.logo ) {
		return;
	}

	function brand() {
		var pre = document.getElementById( 'bricks-preloader' );
		if ( ! pre ) {
			return false;
		}
		var box = pre.querySelector( '.bricks-loading-inner' ) || pre;
		if ( box.querySelector( '.ak-preloader-logo' ) ) {
			return true; // already done
		}
		var img = document.createElement( 'img' );
		img.className = 'ak-preloader-logo';
		img.alt = '';
		img.src = cfg.logo;
		box.appendChild( img );
		return true;
	}

	if ( brand() ) {
		return;
	}
	var tries = 0;
	var timer = setInterval( function () {
		if ( brand() || ++tries > 80 ) {
			clearInterval( timer );
		}
	}, 25 );
}() );
