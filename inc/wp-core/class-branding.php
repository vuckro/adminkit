<?php
/**
 * Branding — the brand logo across AdminKit surfaces.
 *
 * Two things, both controllable:
 *   - WordPress admin-bar logo: replaced with the site icon (favicon), hidden, or
 *     left as-is, per the `wp_logo` setting (Settings → Features → Branding).
 *   - When a brand logo is configured (Settings → Features → Branding, light +
 *     dark), prints it at the top of the admin menu, above the Dashboard item,
 *     switching per light/dark mode. With nothing configured, the menu is left
 *     untouched — nothing is added, and no asset is ever shipped.
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
	 * Wire the hook. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'print_styles' ), 20 );
	}

	/**
	 * Print the branding CSS. Skipped when AdminKit styling is paused (the
	 * "WordPress default UI" switch / any adminkit/should_load veto).
	 *
	 * @return void
	 */
	public static function print_styles() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		$css = '';

		// WordPress admin-bar logo — favicon (default) / hide / leave as-is.
		$mode    = AdminKit_Settings::get( 'wp_logo' );
		$favicon = esc_url( (string) get_site_icon_url( 64 ) );
		if ( 'hide' === $mode ) {
			$css .= '#wpadminbar #wp-admin-bar-wp-logo{display:none}';
		} elseif ( 'favicon' === $mode && '' !== $favicon ) {
			// Swap the WordPress glyph for the site icon, a touch larger.
			$css .= '#wpadminbar #wp-admin-bar-wp-logo .ab-icon{background:url(' . $favicon . ') center/contain no-repeat;width:24px;height:24px}';
			$css .= '#wpadminbar #wp-admin-bar-wp-logo .ab-icon::before{content:""}';
		}
		// 'default' (or favicon with no site icon set) → leave the WP logo untouched.

		// Brand logo above the admin menu (light + dark), only when configured.
		$light = esc_url( AdminKit_Settings::brand_logo( 'light' ) );
		$dark  = esc_url( AdminKit_Settings::brand_logo( 'dark' ) );
		if ( '' !== $light || '' !== $dark ) {
			if ( '' === $light ) {
				$light = $dark;
			}
			if ( '' === $dark ) {
				$dark = $light;
			}
			$css .= '#adminmenu::before{content:"";display:block;height:44px;margin:12px 14px 6px;background:url(' . $light . ') left center / auto 30px no-repeat}';
			$css .= ':root[data-adminkit-theme="dark"] #adminmenu::before{background-image:url(' . $dark . ')}';
			$css .= '.folded #adminmenu::before{margin-inline:0;background-position:center}';
		}

		if ( '' !== $css ) {
			echo '<style id="adminkit-branding">' . $css . "</style>\n"; // URLs escaped above; rest is static.
		}
	}
}
