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
	 * the front end (logged-in users see the toolbar there too); the admin-menu
	 * brand-logo card is wp-admin only.
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
	 * the toolbar there.
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
		self::print_css( self::admin_bar_logo_css() );
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
	 * Brand logo in the admin-bar wp-logo slot — a 22px rounded chip (same footprint
	 * as the favicon, for consistency) with the logo CONTAINED so any mark fits
	 * without overflowing. Light variant by default; dark variant under the dark
	 * flag. '' when no logo is set.
	 *
	 * Painted as a BACKGROUND, not `content:url()`: a pseudo-element's content image
	 * does not resize reliably (it rendered at full intrinsic size — the "too big"
	 * bug), whereas background-size:contain always fits the box.
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
		$sel = '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon';
		return $sel . '{width:22px}'
			. $sel . '::before{content:"";display:block;width:22px;height:22px;top:0;'
			. 'border-radius:var(--ak-radius-s,5px);'
			. 'background:' . $light . ' center/contain no-repeat}'
			. ':root[data-adminkit-theme="dark"] ' . $sel . '::before{background-image:' . $dark . '}'
			. self::hide_site_name_glyph();
	}

	/**
	 * Site icon (favicon) in the admin-bar wp-logo slot — painted onto the
	 * .ab-icon::BEFORE box (WP forces background-image:none on .ab-icon itself, but
	 * its ::before is exempt), as a rounded chip. '' when there's no Site Icon.
	 *
	 * @return string
	 */
	private static function favicon_css() {
		$favicon = self::css_url( get_site_icon_url( 64 ) );
		if ( '' === $favicon ) {
			return '';
		}
		return '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon{width:22px}'
			. '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon::before{'
			. 'content:"";display:block;width:22px;height:22px;top:0;'
			. 'border-radius:var(--ak-radius-s,5px);'
			. 'background:' . $favicon . ' center/contain no-repeat}'
			. self::hide_site_name_glyph();
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
