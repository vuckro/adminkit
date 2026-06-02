<?php
/**
 * FluentSupport integration — Tier B-leaning adapter (Vue 3 SPA on Element Plus).
 *
 * FluentSupport renders its helpdesk admin as a Vue 3 SPA. Like FluentCRM (and
 * unlike FluentBooking, which leaves Element Plus at stock hex), it is
 * VARIABLE-DRIVEN: it declares its own --fs-* design tokens in :root, ships a
 * full dark mode of its own (a body.fs-dark-mode block that redeclares those
 * --fs-* tokens AND aliases the Element Plus --el-* layer onto them). So this
 * adapter is mostly a token remap (§1) — point the --fs-* roots and the --el-*
 * layer at AdminKit --ak-* and the whole UI (surfaces, text, borders, every
 * Element Plus component) follows, in both modes. That is the bulk of the work.
 *
 * NO theme sync. FluentSupport drives its own dark via a body.fs-dark-mode class
 * (localStorage `fs-theme`), but the --ak-* remap already carries AdminKit's dark
 * — and our :root, body.adminkit.toplevel_page_fluent-support block (0,2,1 for
 * the body arm) outranks the host's body.fs-dark-mode (0,1,1), so our values win
 * whether or not the host class is on. There is nothing to slave to AdminKit's
 * switch; instead we just hide the host's redundant in-app dark TOGGLE in
 * AdminKit-dark (§4). Hence no theme-sync JS here.
 *
 * The remaining literals FluentSupport hard-codes past its own vars — the leftover
 * stock Element Plus blue #409eff on a few buttons/links the brand never reached —
 * can't be touched by the var remap, so §3 catches them.
 *
 * Loaded only on toplevel_page_fluent-support (the SPA owns every sub-route via
 * hash routing under that one WP page).
 *
 * Tier B safety: FluentSupport bakes its brand literals + Element Plus defaults
 * into compiled CSS, which a new major may reshuffle, so the skin is version-gated
 * on the major and drops to FluentSupport's native UI past the tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Support extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-support';
	}

	/**
	 * FluentSupport defines `FLUENT_SUPPORT_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_SUPPORT_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_SUPPORT_VERSION' ) ? FLUENT_SUPPORT_VERSION : null;
	}

	/**
	 * Verified against FluentSupport 2.2.1. A new major may rename the --fs-* /
	 * --el-* tokens or reshuffle the brand literals this adapter targets, so gate
	 * on the major — register_assets() falls back to native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '2.2.1';
	}

	/**
	 * FluentSupport registers a top-level menu with slug `fluent-support` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluent-support`.
	 * Every sub-page is a hash route under the same screen, so one id covers the
	 * entire SPA. (The menu slug equals slug(), so no wordmark_menu_slug()
	 * override is needed — the base print_menu_wordmark() resolves the title.)
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-support' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentSupport's native
		// UI shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-support-admin',
			'src'       => 'inc/integrations/plugins/fluent-support/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentSupport screen — Element
	 * Plus ships its own input/select/textarea styling, so our components/*.css
	 * would double-border its widgets (a native border inside the
	 * `.el-input__wrapper` that already has one). admin.css themes the Element
	 * Plus surfaces directly via the token remap instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fs' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the FluentCRM adapter.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fs( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentSupport's menu icon for a life-buoy glyph (helpdesk/support).
	 *
	 * FluentSupport registers its top-level menu with a base64 data-URI icon, so
	 * its `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop the inline background-image and
	 * mask a life-buoy Heroicon into the icon box — same technique menu_css()
	 * uses, so it tracks the menu foreground colour in light/dark/hover/current.
	 * Inlined here so the adapter owns its own mark. Gated exactly like the
	 * FluentCRM adapter: only when the icon toggle is on AND the global should_load
	 * pause hasn't disabled AdminKit. The menu shows on every admin page, so there
	 * is no screen gate.
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
		// Heroicons (solid) life-buoy — a helpdesk is "support/rescue", so the
		// life-buoy reads truer than a generic chat bubble. Fill is irrelevant
		// (painted as a mask; the visible colour is currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" clip-rule="evenodd" d="M19.449 8.44818L16.3878 10.9992C16.5374 11.6574 16.5374 12.3426 16.3878 13.0008L19.449 15.5518C20.517 13.3118 20.517 10.6882 19.449 8.44818ZM15.5518 19.449L13.0008 16.3878C12.3426 16.5374 11.6574 16.5374 10.9992 16.3878L8.44818 19.449C10.6882 20.517 13.3118 20.517 15.5518 19.449ZM4.55102 15.5518L7.6122 13.0008C7.4626 12.3426 7.4626 11.6574 7.6122 10.9992L4.55102 8.44818C3.48299 10.6882 3.48299 13.3118 4.55102 15.5518ZM8.44818 4.55102L10.9992 7.6122C11.6574 7.4626 12.3426 7.4626 13.0008 7.6122L15.5518 4.55102C13.3118 3.48299 10.6882 3.48299 8.44818 4.55102ZM17.1055 3.6912C17.7424 4.08325 18.3435 4.55493 18.8943 5.10571C19.4451 5.65649 19.9167 6.25755 20.3088 6.89448C22.2304 10.0163 22.2304 13.9837 20.3088 17.1055C19.9167 17.7424 19.4451 18.3435 18.8943 18.8943C18.3435 19.4451 17.7424 19.9167 17.1055 20.3088C13.9837 22.2304 10.0163 22.2304 6.89448 20.3088C6.25755 19.9167 5.65649 19.4451 5.10571 18.8943C4.55493 18.3435 4.08325 17.7424 3.6912 17.1055C1.7696 13.9837 1.7696 10.0163 3.6912 6.89448C4.08325 6.25755 4.55493 5.65649 5.10571 5.10571C5.65649 4.55493 6.25755 4.08325 6.89448 3.6912C10.0163 1.7696 13.9837 1.7696 17.1055 3.6912ZM14.1213 9.87868C13.7958 9.55313 13.4158 9.31907 13.0115 9.17471C12.359 8.94176 11.641 8.94176 10.9886 9.17471C10.5842 9.31907 10.2042 9.55313 9.87868 9.87868C9.55313 10.2042 9.31907 10.5842 9.17471 10.9885C8.94176 11.641 8.94176 12.359 9.17471 13.0114C9.31907 13.4158 9.55313 13.7958 9.87868 14.1213C10.2042 14.4469 10.5842 14.6809 10.9886 14.8253C11.641 15.0582 12.359 15.0582 13.0115 14.8253C13.4158 14.6809 13.7958 14.4469 14.1213 14.1213C14.4469 13.7958 14.6809 13.4158 14.8253 13.0115C15.0582 12.359 15.0582 11.641 14.8253 10.9885C14.6809 10.5842 14.4469 10.2042 14.1213 9.87868Z"/></svg>';
		$box  = '#adminmenu #toplevel_page_fluent-support .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-support-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
