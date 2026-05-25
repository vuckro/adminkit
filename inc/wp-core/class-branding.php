<?php
/**
 * Branding — the brand logo across AdminKit surfaces.
 *
 * Two things, both controllable:
 *   - WordPress admin-bar logo: replaced with the site icon (favicon — which also
 *     hides the redundant "house" glyph next to the site name), hidden, or left
 *     as-is, per the `wp_logo` setting (Settings → Features → Branding). Applied in
 *     wp-admin AND on the front-end toolbar (logged-in users see the bar there too).
 *   - When a brand logo is configured (Settings → Features → Branding, light +
 *     dark), prints it as a contained card at the top of the admin menu (full
 *     width, with a background + border so most logos fit), switching per
 *     light/dark mode. With nothing configured, the menu is left untouched —
 *     nothing is added, and no asset is ever shipped.
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
	 * wp-admin: the admin-bar logo treatment + the admin-menu brand-logo card.
	 * Skipped when AdminKit styling is paused (any adminkit/should_load veto).
	 *
	 * @return void
	 */
	public static function print_admin() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		self::print_css( self::admin_bar_logo_css() . self::admin_menu_logo_css() );
	}

	/**
	 * Front end: the SAME admin-bar logo treatment, for logged-in users who see
	 * the toolbar there. (No admin menu on the front end, so no brand-logo card.)
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
	 * front-end toolbar. `hide` removes it; `favicon` swaps WordPress's glyph for
	 * the site icon (and hides the now-redundant site-name glyph in both contexts).
	 * 'default' (or favicon with no Site Icon) leaves the WP logo untouched.
	 *
	 * @return string
	 */
	private static function admin_bar_logo_css() {
		$mode = AdminKit_Settings::get( 'wp_logo' );

		if ( 'hide' === $mode ) {
			return '#wpadminbar #wp-admin-bar-wp-logo{display:none}';
		}

		if ( 'favicon' === $mode ) {
			$favicon = self::css_url( get_site_icon_url( 64 ) );
			if ( '' === $favicon ) {
				return '';
			}
			// Paint the site icon onto the .ab-icon::BEFORE box — NOT .ab-icon itself:
			// WP core forces `background-image:none !important` on .ab-icon (that was
			// the original bug), but its ::before is exempt. Clear WP's glyph
			// (content:""), size the box, drop the +2px glyph nudge.
			return '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon{width:22px}'
				. '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon::before{'
				. 'content:"";display:block;width:22px;height:22px;top:0;'
				. 'background:' . $favicon . ' center/contain no-repeat}'
				// Hide the now-redundant site-name glyph: once with the .wp-admin
				// prefix (to beat WP's wp-admin rule) and once plain (front end).
				. '.wp-admin #wpadminbar #wp-admin-bar-site-name > .ab-item::before,'
				. '#wpadminbar #wp-admin-bar-site-name > .ab-item::before{content:none}';
		}

		return '';
	}

	/**
	 * The brand-logo card above the admin menu (wp-admin only) — a contained card
	 * (background + border + radius) so most logos sit well whatever their shape or
	 * colour, in light + dark. Empty string when no logo is configured.
	 *
	 * @return string
	 */
	private static function admin_menu_logo_css() {
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
		// Full menu width, the logo contained within the card (auto width, 62% of
		// the card height) so any aspect ratio is centred with breathing room.
		return '#adminmenu::before{content:"";display:block;height:54px;margin:10px 8px;'
			. 'background:var(--ak-elevated) ' . $light . ' center/auto 62% no-repeat;'
			. 'border:1px solid var(--ak-border);border-radius:var(--ak-radius-m)}'
			. ':root[data-adminkit-theme="dark"] #adminmenu::before{background-image:' . $dark . '}'
			// Collapsed menu (folded / responsive auto-fold): a compact square card.
			. '.folded #adminmenu::before,.auto-fold.folded #adminmenu::before{height:34px;margin:8px 4px;background-size:auto 64%}';
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
