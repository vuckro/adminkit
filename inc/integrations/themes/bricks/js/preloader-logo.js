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
		if ( pre.querySelector( '.ak-preloader-logo' ) ) {
			return true; // already done
		}
		// Append straight to #bricks-preloader (the full-screen splash), NOT
		// .bricks-loading-inner — Bricks positions that wrapper off-centre, which
		// pushed the logo right. The CSS centres #bricks-preloader itself.
		var img = document.createElement( 'img' );
		img.className = 'ak-preloader-logo';
		img.alt = '';
		img.src = cfg.logo;
		pre.appendChild( img );
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
