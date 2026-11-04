<?php
/**
 * FluentHub replacement shell: shared palette and native theme bridge.
 *
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Toolkit extends AdminKit_Integration_Base {
	/** @return string */
	public static function slug() { return 'fluent-toolkit'; }
	/** @return string */
	public static function label() { return 'FluentHub'; }
	/** @return string[] */
	public static function host_files() { return array( 'fluent-toolkit/fluent-toolkit.php' ); }
	/** @return bool */
	public static function is_active() { return defined( 'FLUENT_TOOLKIT_VERSION' ); }
	/** @return string|null */
	protected static function host_version() { return defined( 'FLUENT_TOOLKIT_VERSION' ) ? FLUENT_TOOLKIT_VERSION : null; }
	/** @return string */
	protected static function max_tested_host_version() { return '2.1'; }
	/** @param \WP_Screen|null $screen Screen. @return bool */
	public static function owns_screen( $screen ) { return $screen && 'toplevel_page_fluent-toolkit' === $screen->id; }
	/** Only mount alongside the host's replacement shell. @return bool */
	public static function has_shell() { return wp_style_is( 'fluent_unified_ui', 'enqueued' ) || wp_style_is( 'fluent_unified_ui', 'done' ); }
	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle' => 'adminkit-fluent-toolkit',
			'src' => 'inc/integrations/plugins/fluent-toolkit/css/admin.css',
			'deps' => array( AdminKit_Assets::TOKENS_HANDLE, 'fluent_unified_ui' ),
			'context' => 'admin',
			'condition' => array( __CLASS__, 'has_shell' ),
		) );
	}
	/** @return void */
	protected static function boot() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_bridge' ), 10001 );
	}
	/** @return void */
	public static function enqueue_bridge() {
		if ( ! self::has_shell() || ! apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) { return; }
		$data = array(
			'attr' => AdminKit_Theme_Toggle::attribute(),
			'key' => AdminKit_Theme_Toggle::storage_key(),
			'enabled' => (bool) AdminKit_Settings::get( 'theme_toggle_enabled' ),
			'label' => __( 'Toggle light / dark mode', 'adminkit' ),
		);
		AdminKit_Assets::enqueue_script( 'adminkit-fluent-toolkit-theme', 'inc/integrations/plugins/fluent-toolkit/js/theme.js', array(), 'window.AdminKitFluentHub=' . wp_json_encode( $data ) . ';' );
	}
}
