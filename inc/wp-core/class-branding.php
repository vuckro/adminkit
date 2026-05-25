<?php
/**
 * Branding — the brand logo across AdminKit surfaces.
 *
 * The WordPress admin-bar logo (top-left), per the `wp_logo` setting:
 *   - `logo`    → the configured brand logo (Settings → Features → Branding,
 *                 light + dark), shown as an image so wide wordmarks fit;
 *   - `favicon` → the site icon, as a rounded chip;
 *   - `hide`    → removed.
 * `logo` falls back to `favicon`, then WordPress's own, when nothing is set; either
 * branded mode also hides the now-redundant site-name "house" glyph. Applied in
 * wp-admin AND on the front-end toolbar (logged-in users see it there too).
 *
 * The Bricks builder reads the SAME source (AdminKit_Settings::brand_logo()), so
 * one configuration drives the brand everywhere. The whole output is paused by
 * the "WordPress default UI" master switch via the adminkit/should_load filter.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Branding {

	/**
	 * Wire the hooks. The admin-bar logo treatment runs in BOTH wp-admin and on
	 * the front end (logged-in users see the toolbar there too).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'print_admin' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_frontend' ), 20 );
	}

	/**
	 * wp-admin: the admin-bar logo treatment.
	 * Skipped when AdminKit styling is paused (any adminkit/should_load veto).
	 *
	 * @return void
	 */
	public static function print_admin() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		self::print_css( self::admin_bar_logo_css() );
	}

	/**
	 * Front end: the SAME admin-bar logo treatment, for logged-in users who see
	 * the toolbar there — PLUS a front-end-only touch: the Site Icon shown as a
	 * small rounded chip next to the site-title node (see site_name_favicon_css()).
	 *
	 * @return void
	 */
	public static function print_frontend() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'frontend' ) ) {
			return;
		}
		self::print_css( self::admin_bar_logo_css() . self::site_name_favicon_css() );
	}

	/**
	 * Echo the collected branding CSS in a single <style>. URLs are escaped in
	 * css_url(); the rest is static.
	 *
	 * @param string $css
	 * @return void
	 */
	private static function print_css( $css ) {
		if ( '' !== $css ) {
			echo '<style id="adminkit-branding">' . $css . "</style>\n";
		}
	}

	/**
	 * The WordPress admin-bar logo treatment — identical in wp-admin and on the
	 * front-end toolbar. `hide` removes it; `logo` shows the configured brand logo;
	 * `favicon` shows the site icon. `logo` with nothing configured falls through to
	 * `favicon`, which itself no-ops (WP logo stays) when there's no Site Icon.
	 *
	 * @return string
	 */
	private static function admin_bar_logo_css() {
		$mode = AdminKit_Settings::get( 'wp_logo' );

		if ( 'hide' === $mode ) {
			return '#wpadminbar #wp-admin-bar-wp-logo{display:none}';
		}

		if ( 'logo' === $mode ) {
			$css = self::brand_logo_css();
			if ( '' !== $css ) {
				return $css;
			}
			$mode = 'favicon'; // no brand logo configured → fall back to the site icon
		}

		if ( 'favicon' === $mode ) {
			return self::favicon_css();
		}

		return '';
	}

	/**
	 * Brand logo in the admin-bar wp-logo slot — a RECTANGULAR wordmark: fixed at
	 * 24px tall, width auto (so a wide wordmark keeps its aspect ratio and is NEVER
	 * cropped), centred vertically in the 32px bar with a small rounding. Unlike the
	 * favicon chip this is borderless and uncropped — the logo IS the mark, so it
	 * must read in full. Light variant by default; dark variant under the dark flag.
	 * '' when no logo is set.
	 *
	 * Painted with `content:url()` (NOT a background): a content image is a REPLACED
	 * element, so `height:24px;width:auto` scales it to the bar height while keeping
	 * its intrinsic aspect ratio — exactly the auto-width behaviour a wordmark needs.
	 * `object-fit:contain` + a `max-width` cap guard against an oversized source ever
	 * overflowing. (The favicon case stays a background box: it's a fixed SQUARE, so
	 * it has no aspect ratio to preserve and `cover` is the right fit there.)
	 *
	 * @return string
	 */
	private static function brand_logo_css() {
		$light = self::css_url( AdminKit_Settings::brand_logo( 'light' ) );
		$dark  = self::css_url( AdminKit_Settings::brand_logo( 'dark' ) );
		if ( '' === $light && '' === $dark ) {
			return '';
		}
		if ( '' === $light ) {
			$light = $dark;
		}
		if ( '' === $dark ) {
			$dark = $light;
		}
		// Double `.ab-icon` → specificity (2,3,0), beating WP core's own wp-logo rule
		// `#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon` (2,2,0, loaded after
		// us) which would otherwise re-apply its height:20px/padding:6px 0 5px and
		// overflow the bar.
		$sel = '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon.ab-icon';
		// Neutralise WP core's padding/margin-right on this .ab-icon and FLEX-CENTRE
		// the wordmark in the 32px bar — no margin maths, no overflow.
		return $sel . '{display:flex;align-items:center;justify-content:center;width:auto;height:32px;padding:0;margin-right:0}'
			// content:url() makes ::before a replaced element so height:24px / width:auto
			// scales the wordmark by its own ratio (uncropped); object-fit:contain +
			// max-width are belt-and-braces against an oversized source. A 1px upward
			// nudge optically centres the wordmark in the 32px bar (it read a hair low);
			// purely visual, no layout shift, no cropping.
			. $sel . '::before{content:' . $light . ';display:block;box-sizing:border-box;'
			. 'width:auto;height:24px;max-width:180px;margin:0;top:0;'
			. 'transform:translateY(-1px);'
			. 'object-fit:contain;object-position:center;'
			. 'border-radius:var(--ak-radius-s,6px)}'
			. ':root[data-adminkit-theme="dark"] ' . $sel . '::before{content:' . $dark . '}'
			. self::hide_site_name_glyph();
	}

	/**
	 * Site icon (favicon) in the admin-bar wp-logo slot — painted onto the
	 * .ab-icon::BEFORE box (WP forces background-image:none on .ab-icon itself, but
	 * its ::before is exempt), as a 24px rounded chip painted `center/cover` and
	 * FLEX-centred in the 32px bar. '' when there's no Site Icon.
	 *
	 * @return string
	 */
	private static function favicon_css() {
		$favicon = self::css_url( get_site_icon_url( 96 ) );
		if ( '' === $favicon ) {
			return '';
		}
		// Double `.ab-icon` → specificity (2,3,0), beating WP core's own wp-logo rule
		// `#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon` (2,2,0, loaded after
		// us) which would otherwise re-apply its height:20px/padding:6px 0 5px and
		// overflow the bar.
		$sel = '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon.ab-icon';
		return $sel . '{display:flex;align-items:center;justify-content:center;width:auto;height:32px;padding:0;margin-right:0}'
			. $sel . '::before{content:"";display:block;box-sizing:border-box;width:24px;height:24px;margin:0;top:0;'
			. 'transform:translateY(-3px);'
			. 'border-radius:var(--ak-radius-s,5px);'
			. 'background:' . $favicon . ' center/cover no-repeat}'
			. self::hide_site_name_glyph();
	}

	/**
	 * FRONT END only: show the Site Icon (favicon) as a small rounded chip next to
	 * the site-title node (`#wp-admin-bar-site-name`), replacing WordPress's "house"
	 * glyph there. Gated exactly like the rest of the branding: nothing when the
	 * brand mode is `hide` (the user opted out of a brand mark), and nothing when no
	 * Site Icon is configured — so it degrades cleanly.
	 *
	 * Painted with `content:url()` (a REPLACED element), NOT a background: WP forces
	 * `background-image:none !important` on `.ab-item:before`, so a background chip
	 * would need its own `!important` to win — but `content` is exempt from that
	 * rule, so this stays !important-free. `width/height:18px` + `object-fit:cover`
	 * scale the square icon into a rounded 18px chip, vertically centred in the 32px
	 * bar (7px top/bottom), with a 6px gap before the site title. Same image in both
	 * themes (a Site Icon has no light/dark variant).
	 *
	 * The selector is front-end-scoped (`body:not(.wp-admin)`) and out-specifies
	 * hide_site_name_glyph()'s front-end `content:none` (2,1,1 → this is 2,2,1) so
	 * the chip wins over the hide; printed only by print_frontend(), so wp-admin is
	 * untouched. '' when gated off.
	 *
	 * @return string
	 */
	private static function site_name_favicon_css() {
		if ( 'hide' === AdminKit_Settings::get( 'wp_logo' ) ) {
			return ''; // user opted out of a brand mark entirely.
		}
		$favicon = self::css_url( get_site_icon_url( 96 ) );
		if ( '' === $favicon ) {
			return ''; // no Site Icon configured → leave the node as-is.
		}
		$sel = 'body:not(.wp-admin) #wpadminbar #wp-admin-bar-site-name > .ab-item::before';
		return $sel . '{content:' . $favicon . ';display:block;box-sizing:border-box;'
			. 'float:left;width:18px;height:18px;padding:0;margin:7px 6px 7px 0;top:0;'
			. 'object-fit:cover;object-position:center;'
			. 'border-radius:var(--ak-radius-s,5px)}';
	}

	/**
	 * Hide WordPress's "house"/site glyph next to the site name — redundant once the
	 * bar carries a brand mark. Two selectors: the .wp-admin-prefixed one beats WP's
	 * wp-admin rule; the plain one covers the front end.
	 *
	 * @return string
	 */
	private static function hide_site_name_glyph() {
		return '.wp-admin #wpadminbar #wp-admin-bar-site-name > .ab-item::before,'
			. '#wpadminbar #wp-admin-bar-site-name > .ab-item::before{content:none}';
	}

	/**
	 * Wrap a URL for safe use inside a CSS `url()` in a <style> block.
	 *
	 * `esc_url()` targets HTML and entity-encodes ampersands (`&` → `&#038;`),
	 * which a CSS parser does NOT decode — so a site-icon / logo URL carrying a
	 * query string (Jetpack/Photon resize, CDN params…) silently fails to load.
	 * `esc_url_raw()` keeps the URL clean and strips characters that could break
	 * out of `url("…")` (quotes, parentheses). Returns '' for an empty URL.
	 *
	 * @param string $url
	 * @return string  e.g. `url("https://…")`, or '' when empty.
	 */
	private static function css_url( $url ) {
		$url = esc_url_raw( (string) $url );
		return ( '' === $url ) ? '' : 'url("' . $url . '")';
	}
}
