<?php
/**
 * FluentCart integration — stub.
 *
 * Detection signature when implementing:
 *   defined( 'FLUENT_CART_PLUGIN_VERSION' )
 *   || class_exists( 'FluentCart\\App\\App' )
 *
 * Planned scope:
 *   - Map FluentCart admin chrome to AdminKit tokens
 *   - Bail `adminkit/enqueue_forms` on FluentCart screens (their UI
 *     ships its own input/button styling) — see the existing comment
 *     in forms.css that anticipates this
 *
 * CSS files will land under `inc/integrations/fluentcart/css/`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluentcart extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluentcart';
	}

	/**
	 * @return bool
	 */
	public static function is_active() {
		return false;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// TODO: register CSS files under inc/integrations/fluentcart/css/
		// with `condition` matching FluentCart admin screens.
	}

	/**
	 * @return void
	 */
	protected static function boot() {
		// TODO: opt out of forms.css on FluentCart screens via
		// add_filter( 'adminkit/enqueue_forms', ... ).
	}
}
