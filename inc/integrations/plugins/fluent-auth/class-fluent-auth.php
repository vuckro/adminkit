<?php
/**
 * FluentAuth admin app. Authentication and public login forms remain host-owned.
 *
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Auth extends AdminKit_Integration_Base {
	/** @return string */
	public static function slug() { return 'fluent-auth'; }
	/** @return string */
	public static function label() { return 'FluentAuth'; }
	/** @return string[] */
	public static function host_files() { return array( 'fluent-security/fluent-security.php' ); }
	/** @return bool */
	public static function is_active() { return defined( 'FLUENT_AUTH_VERSION' ); }
	/** @return string|null */
	protected static function host_version() { return defined( 'FLUENT_AUTH_VERSION' ) ? FLUENT_AUTH_VERSION : null; }
	/** @return string */
	protected static function max_tested_host_version() { return '3.1.1'; }
	/** @param WP_Screen|null $screen Current screen. @return bool */
	public static function owns_screen( $screen ) { return $screen && 'toplevel_page_fluent-auth' === $screen->id; }
	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle' => 'adminkit-fluent-auth-admin',
			'src' => 'inc/integrations/plugins/fluent-auth/css/admin.css',
			'deps' => array( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Assets::ELEMENT_PLUS_HANDLE ),
			'context' => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
	/** @return void */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms' ) );
	}
	/** @param bool $enqueue Whether native controls load. @return bool */
	public static function bail_forms( $enqueue ) {
		return self::owns_screen( get_current_screen() ) ? false : $enqueue;
	}
}
