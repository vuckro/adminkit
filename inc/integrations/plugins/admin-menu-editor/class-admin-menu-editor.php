<?php
/**
 * Admin Menu Editor integration.
 *
 * AME (free + Pro) hardcodes light surfaces across several UIs that don't flip in
 * dark mode. This adapter ships a token map per surface, each loaded only where it
 * renders (see register_assets()):
 *
 *  - choices.css           — Choices.js "Other Roles" multi-select (AME settings
 *                            pages + user-edit / profile / user-new).
 *  - admin.css             — the menu-editor settings page chrome (builder boxes,
 *                            module tables, dialogs, the :target highlight),
 *                            scoped to settings_page_menu_editor.
 *  - quick-search.css      — the #ame-quick-search popup (AME Pro's admin-bar
 *                            search opens it on ANY admin page → loads admin-wide).
 *  - content-permissions.css — the Content Permissions (CPE) metabox on the post
 *                            editor (post.php / post-new.php).
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
			'src'       => 'inc/integrations/plugins/admin-menu-editor/css/choices.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_choices_screen' ),
		) );

		// Settings page chrome (menu builder boxes, module tables,
		// dialogs, …) — scoped to the AME page.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-ame-admin',
			'src'       => 'inc/integrations/plugins/admin-menu-editor/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );

		// Quick-search popup (#ame-quick-search) — AME Pro's admin-bar search opens
		// it on ANY admin page, so this one loads admin-wide (no screen condition).
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-ame-quick-search',
			'src'     => 'inc/integrations/plugins/admin-menu-editor/css/quick-search.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'admin',
		) );

		// Content Permissions (CPE) metabox — renders on the post editor
		// (post.php / post-new.php) for post types with AME's per-post access
		// control on. AME hardcodes light option-box surfaces; load the token map
		// there, scoped to $screen->base === 'post'.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-ame-content-permissions',
			'src'       => 'inc/integrations/plugins/admin-menu-editor/css/content-permissions.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => static function ( $screen ) {
				return $screen && 'post' === $screen->base;
			},
		) );
	}

	/**
	 * @return void
	 */
	protected static function boot() {
		// AME Pro's "Quick Search" admin-bar button (#wp-admin-bar-ame-quick-search-tb)
		// paints a stock dashicon magnifier on its `.ab-icon::before`, so it stands
		// out next to AdminKit's own toolbar glyphs. Map it onto the shared search
		// icon — AdminKit_Core_Menu_Icons then masks it (desktop + mobile) exactly
		// like the core nodes, but only when the icons feature is on.
		add_filter( 'adminkit/toolbar_icons', array( __CLASS__, 'toolbar_icons' ) );
	}

	/**
	 * Register AME's quick-search toolbar node for icon replacement. It renders an
	 * `.ab-icon` span (not a text-only node), so it needs no `ab_item_nodes` entry.
	 *
	 * @param array<string,string> $map
	 * @return array<string,string>
	 */
	public static function toolbar_icons( $map ) {
		$map['wp-admin-bar-ame-quick-search-tb'] = AdminKit_Icons::svg( 'search' );
		return $map;
	}
}
