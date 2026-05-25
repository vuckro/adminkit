<?php
/**
 * Branding — the brand logo across AdminKit surfaces.
 *
 * Two things, both controllable:
 *   - Hides WordPress's own admin-bar logo (part of the chrome restyle).
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

		// Always: drop WordPress's own logo from the admin bar.
		$css = '#wpadminbar #wp-admin-bar-wp-logo{display:none}';

		// Optional: brand logo above the admin menu (light + dark), only when set.
		$light = esc_url( AdminKit_Settings::brand_logo( 'light' ) );
		$dark  = esc_url( AdminKit_Settings::brand_logo( 'dark' ) );
		if ( '' !== $light || '' !== $dark ) {
			if ( '' === $light ) {
				$light = $dark;
			}
			if ( '' === $dark ) {
				$dark = $light;
			}
			$css .= '#adminmenu::before{content:"";display:block;height:34px;margin:10px 14px 6px;background:url(' . $light . ') left center / auto 22px no-repeat}';
			$css .= ':root[data-adminkit-theme="dark"] #adminmenu::before{background-image:url(' . $dark . ')}';
			$css .= '.folded #adminmenu::before{margin-inline:0;background-position:center}';
		}

		echo '<style id="adminkit-branding">' . $css . "</style>\n"; // URLs escaped above; rest is static.
	}
}
