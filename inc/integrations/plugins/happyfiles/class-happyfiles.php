<?php
/**
 * HappyFiles integration.
 *
 * HappyFiles renders its settings under Settings → HappyFiles. The
 * page uses a tabbed `<table>` layout that hardcodes white surfaces,
 * #ccd0d4 grid borders, a `crimson` delete button, and a light-blue
 * `.happyfiles-info-box` callout. Route the settings page chrome
 * through AdminKit tokens. Loaded only on the
 * settings_page_happyfiles_settings screen.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Happyfiles extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'happyfiles';
	}

	/**
	 * HappyFiles defines `HAPPYFILES_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'HAPPYFILES_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'HAPPYFILES_VERSION' ) ? HAPPYFILES_VERSION : null;
	}

	/**
	 * Targets HappyFiles 1.x — admin.css / sidebar.css override its
	 * hardcoded surfaces by selector, which a new major could restructure.
	 * (Not installed on this site; pinned to the major the skin targets.)
	 * register_assets() falls back to the native UI on a newer major until
	 * the skin is re-checked and this is bumped.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '1.8';
	}

	/**
	 * Settings page registered with `add_options_page( ...,
	 * HAPPYFILES_SETTINGS_GROUP, ... )` where the group constant
	 * resolves to "happyfiles_settings" — screen ID becomes
	 * `settings_page_happyfiles_settings`.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'settings_page_happyfiles_settings' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Hardcoded surfaces are overridden by selector; a new HappyFiles
		// major could restructure them. Fall back to the native UI (both the
		// settings page and the folders sidebar) until re-verified.
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-happyfiles-admin',
			'src'       => 'inc/integrations/plugins/happyfiles/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );

		// Folders sidebar (#happyfiles-sidebar) — mounts on list-table
		// screens AND inside the Media modal, which HappyFiles injects on
		// almost any screen (Featured image, Site Icon, …). Load globally;
		// the selectors are #happyfiles-sidebar-prefixed, so they're no-ops
		// where HappyFiles isn't rendering anything.
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-happyfiles-sidebar',
			'src'     => 'inc/integrations/plugins/happyfiles/css/sidebar.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'admin',
		) );
	}
}
