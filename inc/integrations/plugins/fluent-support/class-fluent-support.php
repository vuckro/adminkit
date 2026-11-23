<?php
/**
 * Fluent Support (`fluent-support` + `fluent-support-pro`) integration — Tier A adapter.
 *
 * Fluent Support 2.5+ runs on `@fluent-theme-toolkit` (`fluent_theme_mode` /
 * `fluent_theme_dark`) with `--fs-*` semantic tokens and Element Plus (`--el-*`).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Support extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-support';
	}

	/** @return string */
	public static function label() {
		return 'Fluent Support';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'fluent-support/fluent-support.php', 'fluent-support-pro/fluent-support-pro.php', 'fluent-support-pro/fluent-support-pro-boot.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENT_SUPPORT_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENT_SUPPORT_VERSION' ) ? FLUENT_SUPPORT_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '2';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-support' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-support-admin',
			'src'       => 'inc/integrations/plugins/fluent-support/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Assets::ELEMENT_PLUS_HANDLE ),
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
		return 'life-buoy';
	}

	/** @return void */
	protected static function boot() {
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ), 0 );
	}

	/** @return void */
	public static function sync_theme() {
		if ( ! self::owns_screen( get_current_screen() ) ) {
			return;
		}
		AdminKit_Fluent_Theme::print_spa_sync( array(
			'id'           => 'adminkit-fluent-support-theme',
			'storage_key'  => 'fs-theme',
			'dark_classes' => array( 'fluent_theme_dark', 'dark' ),
		) );
	}
}
