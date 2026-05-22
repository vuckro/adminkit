<?php
/**
 * Admin Menu Editor integration.
 *
 * AME (free + Pro) bundles Choices.js for its "Other Roles" multi-select
 * picker on user-edit.php. The library hardcodes `#f9f9f9` wrapper bg +
 * `#fff` search-input bg, neither of which flips in dark mode. We ship
 * one stylesheet that maps the `.choices.*` DOM to AdminKit tokens.
 *
 * Scoped to the screens where AME's Choices.js widget actually
 * renders: AME's own settings pages + user-edit / profile / user-new
 * (where the "Other Roles" picker shows up).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Admin_Menu_Editor extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'admin-menu-editor';
	}

	/**
	 * Detects both the free plugin and Pro — both instantiate
	 * `new WPMenuEditor(...)` at boot.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'WPMenuEditor' );
	}

	/**
	 * AME registers three top-level settings pages: the menu editor
	 * itself (and all its `?sub_section=…` tabs), the Easy Hide module,
	 * and the Admin Customizer. All three live under Settings → and
	 * share the `settings_page_` prefix.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		return in_array(
			$screen->id,
			array(
				'settings_page_menu_editor',
				'settings_page_ame-easy-hide',
				'settings_page_ame-admin-customizer',
			),
			true
		);
	}

	/**
	 * Screens where AME's Choices.js widget can render: the three AME
	 * settings pages above + user profile / edit / new (Role Editor's
	 * "Other Roles" picker).
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_choices_screen( $screen ) {
		if ( self::owns_screen( $screen ) ) {
			return true;
		}
		if ( ! $screen ) {
			return false;
		}
		// user-new.php reports screen id 'user' (WP strips the '-new' suffix in
		// class-wp-screen.php). Keep 'user-new' too as belt-and-suspenders.
		return in_array( $screen->id, array( 'user-edit', 'profile', 'user', 'user-new' ), true );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-ame-choices',
			'src'       => 'inc/integrations/admin-menu-editor/css/choices.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_choices_screen' ),
		) );

		// Settings page chrome (menu builder boxes, module tables,
		// dialogs, …) — scoped to the AME page.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-ame-admin',
			'src'       => 'inc/integrations/admin-menu-editor/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
