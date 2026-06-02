<?php
/**
 * FluentBoards integration — Tier B-leaning adapter (Vue 3 SPA on Element Plus).
 *
 * FluentBoards renders its kanban admin as a Vue 3 SPA on the same Fluent
 * Framework shell as FluentBooking (`.warp.fconnector_app` / `.fframe_*`), themed
 * through THREE layers of CSS custom properties:
 *
 *   1. its own --primary-color (#6268F1) / --primary-purple roots — the brand,
 *      consumed via var() in ~280 places across the app + SFC bundle;
 *   2. the Element Plus --el-* layer (left at EP's stock blue #136bf5 for the
 *      primary ramp; surfaces / text / borders / fills NOT declared, so EP's
 *      stock greys leak on dark) — set directly here, as in the FluentBooking
 *      and FluentCRM adapters;
 *   3. a self-contained --gantt-* token layer (the timeline view) defined in
 *      :root for light + `.gantt-root[data-theme=dark]` for dark — remap the
 *      :root roots so the Gantt surfaces/borders/text flip with AdminKit.
 *
 * Remapping those roots cascades through the whole UI — surfaces, text, borders,
 * every Element Plus component, the Gantt timeline — and because --ak-* flip with
 * AdminKit's dark mode FluentBoards gets a dark mode the main app never shipped
 * (only the Gantt has one of its own, internal). That is §1.
 *
 * The remaining ~20% are the brand HEX literals FluentBoards hard-codes past its
 * own vars — #6b3ceb (nav/popovers/calendar), #6268f1 (onboarding/time-tracker/
 * file-upload/sort) and the #8b5cf6 table-view fallbacks. Those can't be reached
 * by the var remap, so §3 catches them grouped by property with !important.
 *
 * NO theme sync. The main app ships no dark mode of its own (no class, no toggle
 * control) and the Gantt drives its own `data-theme` internally, so there is
 * nothing to slave to AdminKit's switch — the --ak-* remap alone carries both
 * light and dark.
 *
 * Loaded only on toplevel_page_fluent-boards (the SPA owns every sub-route via
 * hash routing under that one WP page).
 *
 * Tier B safety: FluentBoards bakes its brand literals + Element Plus defaults
 * into compiled CSS, which a new major may reshuffle, so the skin is version-gated
 * on the major and drops to FluentBoards' native UI past the tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Boards extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-boards';
	}

	/**
	 * FluentBoards defines `FLUENT_BOARDS_PLUGIN_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_BOARDS_PLUGIN_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_BOARDS_PLUGIN_VERSION' ) ? FLUENT_BOARDS_PLUGIN_VERSION : null;
	}

	/**
	 * Verified against FluentBoards 1.x. A new major may rename the --primary-* /
	 * --el-* / --gantt-* tokens or reshuffle the brand literals this adapter
	 * targets, so gate on the major — register_assets() falls back to native UI
	 * past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '1.95';
	}

	/**
	 * FluentBoards registers a top-level menu with slug `fluent-boards` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluent-boards`.
	 * Every sub-page (Boards / Reports / Settings) is a hash route under the same
	 * screen, so one id covers the entire SPA. Because the menu slug equals
	 * slug(), no wordmark_menu_slug() override is needed.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-boards' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentBoards' native UI
		// shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-boards-admin',
			'src'       => 'inc/integrations/plugins/fluent-boards/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentBoards screen — Element
	 * Plus ships its own input/select/textarea styling, so our components/*.css
	 * would double-border its widgets (a native border inside the
	 * `.el-input__wrapper` that already has one). admin.css themes the Element
	 * Plus surfaces directly via the token remap instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fboards' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the FluentCRM adapter.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fboards( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentBoards' menu icon for a kanban (columns) glyph.
	 *
	 * FluentBoards registers its top-level menu with a base64 data-URI icon, so
	 * its `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop the inline background-image and
	 * mask a "view-columns" Heroicon (kanban boards) into the icon box — same
	 * technique menu_css() uses, so it tracks the menu foreground colour in
	 * light/dark/hover/current. Inlined here so the adapter owns its own mark.
	 * Gated exactly like the FluentCRM adapter: only when the icon toggle is on
	 * AND the global should_load pause hasn't disabled AdminKit. The menu shows on
	 * every admin page, so there is no screen gate.
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
		// Heroicons (solid) view-columns — a kanban board reads as parallel
		// columns. Fill is irrelevant (painted as a mask; the visible colour is
		// currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path d="M15 3.75H9v16.5h6V3.75ZM16.5 20.25h3.375c1.035 0 1.875-.84 1.875-1.875V5.625c0-1.036-.84-1.875-1.875-1.875H16.5v16.5ZM4.125 3.75H7.5v16.5H4.125a1.875 1.875 0 0 1-1.875-1.875V5.625c0-1.036.84-1.875 1.875-1.875Z"/></svg>';
		$box  = '#adminmenu #toplevel_page_fluent-boards .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-boards-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
