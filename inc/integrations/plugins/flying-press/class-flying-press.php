<?php
/**
 * FlyingPress integration — React SPA with Tailwind utility remapping on `toplevel_page_flying-press`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Flying_Press extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'flying-press';
	}

	/** @return string */
	public static function label() {
		return 'FlyingPress';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'flying-press/flying-press.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLYING_PRESS_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLYING_PRESS_VERSION' ) ? FLYING_PRESS_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '5';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_flying-press' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-flying-press-admin',
			'src'       => 'inc/integrations/plugins/flying-press/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/** @return bool */
	protected static function bails_core_forms() {
		return true;
	}
}
