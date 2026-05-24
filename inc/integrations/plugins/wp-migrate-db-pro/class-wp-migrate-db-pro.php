<?php
/**
 * WP Migrate (Pro) integration.
 *
 * WP Migrate ships a React SPA at Tools > Migrate that bundles its
 * own Tailwind-style utility classes (.bg-primary, .bg-brand-dark,
 * .bg-success, …) and components. Every surface, border and accent
 * is hex-hardcoded — nothing flips with dark mode. Route the most
 * visible pieces (chrome shell, primary CTA, nav, accordions, form
 * inputs, switches) through AdminKit tokens. Loaded only on the
 * tools_page_wp-migrate-db-pro screen.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Wp_Migrate_Db_Pro extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'wp-migrate-db-pro';
	}

	/**
	 * WP Migrate (Pro) defines `WPMDBPRO_FILE` in its bootstrap.
	 * The free WP Migrate ships under the same plugin slug too —
	 * the same selectors apply since both bundle the same SPA.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'WPMDBPRO_FILE' );
	}

	/**
	 * WP Migrate exposes no version constant, so read it from the plugin
	 * header — the same source `get_plugin_data()` uses — keyed off the
	 * bootstrap file in WPMDBPRO_FILE.
	 *
	 * @return string|null
	 */
	protected static function host_version() {
		if ( ! defined( 'WPMDBPRO_FILE' ) ) {
			return null;
		}
		$data = get_file_data( WPMDBPRO_FILE, array( 'Version' => 'Version' ) );
		return empty( $data['Version'] ) ? null : $data['Version'];
	}

	/**
	 * Verified against WP Migrate 2.x. admin.css overrides the React
	 * SPA's hardcoded utility classes by selector, which a new major
	 * could restructure — register_assets() falls back to the native UI
	 * until the skin is re-checked and this is bumped.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '2.7.7';
	}

	/**
	 * The plugin registers under Tools → Migrate, which gives the
	 * screen ID `tools_page_wp-migrate-db-pro`.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'tools_page_wp-migrate-db-pro' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Hardcoded utility classes are overridden by selector; a new WP
		// Migrate major could restructure them. Fall back to the native UI
		// until re-verified.
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-wp-migrate-db-pro-admin',
			'src'       => 'inc/integrations/plugins/wp-migrate-db-pro/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
