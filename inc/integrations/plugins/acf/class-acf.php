<?php
/**
 * Advanced Custom Fields (ACF / ACF Pro) integration — Tier B adapter.
 *
 * ACF 6.x ships a fully custom admin UI (toolbar, headerbar, field-group
 * editor, the post-type / taxonomy / options-page builders, Tools) painted
 * with hardcoded Untitled-UI hex. It exposes no CSS variables, so there is no
 * variable layer to remap — this adapter overrides those hardcoded colors with
 * AdminKit tokens directly. Everything is scoped to ACF's own `.acf-admin-page`
 * body class (present on every ACF screen, list + editor), so the skin only
 * bites on ACF and inherits AdminKit's light/dark flip for free. ACF's own
 * dark stylesheet only loads via the separate "Dark Mode" plugin's
 * `doing_dark_mode` action, which AdminKit never fires, so the two never clash.
 *
 * The dark `.acf-admin-toolbar` masthead keeps ACF's own background on purpose:
 * its logo is a fixed two-tone SVG (navy + white) that would lose its white
 * parts on a light bar, so only the toolbar's light flyout menu is tokenized.
 *
 * Tier B → version-gated. A new ACF MAJOR is the release most likely to rename
 * the component classes this adapter targets; past the tested major the whole
 * skin is dropped so ACF's native UI shows instead of a half-broken one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Acf extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'acf';
	}

	/**
	 * ACF roots its admin under the field-group CPT, so its top-level menu slug
	 * is the CPT list URL. Point the wordmark at that menu title (falls back to
	 * the CSS literal if ACF's menu layout differs).
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return 'edit.php?post_type=acf-field-group';
	}

	/**
	 * Both ACF (free) and ACF Pro define ACF_VERSION in their bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'ACF_VERSION' );
	}

	/**
	 * Every ACF admin screen. The four ACF UI post types (Field Groups,
	 * Post Types, Taxonomies, Options Pages) cover both their list and editor
	 * screens via `$screen->post_type`; the standalone submenu pages (Tools,
	 * Settings, Updates) are parented to the field-group menu and so resolve to
	 * `*_page_acf*` screen ids.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		$types = array( 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' );
		if ( isset( $screen->post_type ) && in_array( $screen->post_type, $types, true ) ) {
			return true;
		}
		return false !== strpos( $screen->id, '_page_acf' );
	}

	/**
	 * ACF exposes its version via the ACF_VERSION constant.
	 *
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'ACF_VERSION' ) ? ACF_VERSION : null;
	}

	/**
	 * Verified against ACF 6.x. A new major may reshuffle the component
	 * classes this Tier B adapter overrides, so gate on the major.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '6';
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the whole skin so ACF's native
		// UI shows instead of a half-broken one (see host_within_tested_range).
		if ( ! self::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-acf-admin',
			'src'       => 'inc/integrations/plugins/acf/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Wire the admin-menu icon swap. The menu shows on EVERY admin page, so the
	 * swap runs on `admin_head` (no screen gate) at priority 21 — exactly like the
	 * FluentAffiliate / FluentCRM adapters and AdminKit_Core_Menu_Icons.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * Swap ACF's admin-menu icon for an AdminKit "id" glyph (a field-record card —
	 * ACF is custom fields).
	 *
	 * ACF registers its top-level menu with the stock dashicon
	 * `dashicons-welcome-widgets-menus` (admin.php → add_menu_page), which is NOT in
	 * AdminKit_Core_Menu_Icons' dashicon map, so its `.wp-menu-image` keeps ACF's
	 * native glyph. Override it the same way menu_css() does: mask an AdminKit SVG
	 * into the icon box and blank the dashicon font on `::before` so it tracks the
	 * menu foreground colour in light/dark/hover/current. Inlined here so the
	 * adapter owns its own mark, gated exactly like the FluentAffiliate adapter
	 * (icon toggle on AND the global should_load pause hasn't disabled AdminKit).
	 *
	 * The box id: ACF's menu slug is the CPT list URL `edit.php?post_type=acf-field-group`,
	 * so the menu hookname is `toplevel_page_edit?post_type=acf-field-group` and WP
	 * (menu-header.php) sanitises every char outside [A-Za-z0-9_:.] to a dash for the
	 * DOM id → `#toplevel_page_edit-post_type-acf-field-group`. The two ids in the
	 * selector (#adminmenu + #toplevel_…) out-specify WP's dashicon `::before` rules
	 * (`#adminmenu div.wp-menu-image:before`, even the current/hover variants), so the
	 * `content:""` blanks ACF's glyph without `!important`.
	 *
	 * @return void
	 */
	public static function print_menu_icon() {
		if ( ! class_exists( 'AdminKit_Settings' ) || ! AdminKit_Settings::get( 'replace_icons_enabled' ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		if ( ! class_exists( 'AdminKit_Icons' ) ) {
			return;
		}
		// AdminKit icon set — "id" (a field-record card; ACF = custom fields).
		$svg = AdminKit_Icons::svg( 'id' );
		$box  = '#adminmenu #toplevel_page_edit-post_type-acf-field-group .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-acf-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
