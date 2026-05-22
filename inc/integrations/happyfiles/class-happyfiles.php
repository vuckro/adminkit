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
	 * Screens where HappyFiles mounts its folders sidebar (`#happyfiles-sidebar`):
	 * every edit.php list (Posts, Pages, CPTs incl. Bricks templates +
	 * fonts), the Media library (upload.php), Plugins, and Users.
	 * `edit-*` covers any post-type list automatically — no need to
	 * enumerate Bricks CPT IDs by hand.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_sidebar_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		if ( 0 === strpos( $screen->id, 'edit-' ) ) {
			return true;
		}
		return in_array( $screen->id, array( 'upload', 'plugins', 'users' ), true );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-happyfiles-admin',
			'src'       => 'inc/integrations/happyfiles/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );

		// Folders sidebar (#happyfiles-sidebar) mounted on list-table
		// screens. Loaded on every screen where the sidebar can mount;
		// the selectors are #happyfiles-sidebar-prefixed so they're
		// no-ops where HappyFiles isn't rendering anything.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-happyfiles-sidebar',
			'src'       => 'inc/integrations/happyfiles/css/sidebar.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_sidebar_screen' ),
		) );
	}
}
