<?php
/**
 * FluentForm integration — Vue SPA on Element UI (scoped to `*fluent_forms*` admin screens, widget & block editor).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluentform extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluentform';
	}

	/** @return string */
	public static function label() {
		return 'Fluent Forms';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'fluentform/fluentform.php', 'fluentformpro/fluentformpro.php' );
	}

	/** @return string */
	protected static function wordmark_menu_slug() {
		return 'fluent_forms';
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENTFORM_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENTFORM_VERSION' ) ? FLUENTFORM_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '6.2.2';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'fluent_forms' );
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluentform-dashboard',
			'src'       => 'inc/integrations/plugins/fluentform/css/dashboard.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => static function ( $screen ) { return $screen && 'dashboard' === $screen->id; },
		) );
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-fluentform-editor',
			'src'     => 'inc/integrations/plugins/fluentform/css/editor.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'editor',
		) );
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluentform-admin',
			'src'       => 'inc/integrations/plugins/fluentform/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/** @return bool */
	protected static function bails_core_forms() {
		return true;
	}

	/** @return string */
	protected static function menu_icon() {
		return 'email';
	}
}
