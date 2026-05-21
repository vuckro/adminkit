<?php
/**
 * WooCommerce integration — stub.
 *
 * Detection signature when implementing:
 *   class_exists( 'WooCommerce' )  ||  defined( 'WC_VERSION' )
 *
 * Planned scope:
 *   - Modernize Orders / Products / Coupons list-table polish
 *   - Replace WC dashboard stats with custom widgets via
 *     `AdminKit_Dashboard::register_widget()`
 *   - Restyle the Settings tabs surface
 *
 * CSS files will land under `inc/integrations/woocommerce/css/` and
 * register via `AdminKit_Assets::register()` with conditions matching
 * `$screen->parent_base === 'woocommerce'`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Woocommerce extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'woocommerce';
	}

	/**
	 * Returns false until the host detection + CSS payload are in.
	 * Flipping this to `class_exists( 'WooCommerce' )` activates the
	 * integration with no other code changes elsewhere.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return false;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// TODO: register CSS files under inc/integrations/woocommerce/css/
		// with `condition` matching WooCommerce admin screens.
	}

	/**
	 * @return void
	 */
	protected static function boot() {
		// TODO: register dashboard widgets via
		// AdminKit_Dashboard::register_widget() and any WC-specific filters.
	}
}
