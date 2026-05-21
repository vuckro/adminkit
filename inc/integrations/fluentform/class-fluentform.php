<?php
/**
 * FluentForm integration — stub.
 *
 * Detection signature when implementing:
 *   defined( 'FLUENTFORM_VERSION' )
 *   || function_exists( 'wpFluentForm' )
 *
 * Planned scope:
 *   - Map FluentForm admin chrome to AdminKit tokens
 *   - Bail `adminkit/enqueue_forms` on FluentForm screens (their UI
 *     ships its own input/button styling)
 *
 * CSS files will land under `inc/integrations/fluentform/css/`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluentform extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluentform';
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
		// TODO: register CSS files under inc/integrations/fluentform/css/
		// with `condition` matching FluentForm admin screens.
	}

	/**
	 * @return void
	 */
	protected static function boot() {
		// TODO: opt out of forms.css on FluentForm screens via
		// add_filter( 'adminkit/enqueue_forms', ... ).
	}
}
