<?php
/**
 * FluentCRM integration — Tier A-leaning adapter (Vue 3 SPA on Element Plus).
 *
 * FluentCRM renders its admin as a Vue 3 SPA. Unlike FluentCart (which bakes its
 * palette into Tailwind utilities), FluentCRM is variable-driven: it declares its
 * own `--fc-*` design tokens in `:root` AND aliases the Element Plus `--el-*`
 * layer onto them. So remapping the roots to AdminKit `--ak-*` cascades through
 * the whole UI — surfaces, text, borders, every Element Plus component — and
 * because `--ak-*` flip with AdminKit's dark mode, FluentCRM gets a dark mode it
 * never shipped (it has none of its own). That is the bulk of this adapter (§1).
 *
 * The remaining ~20% are literals FluentCRM hard-codes past its own vars — the
 * near-black #0e121b on automation-funnel block text, a 10%-tinted #222530 on
 * hover/badge fills, and brand box-shadows. Those can't be reached by the var
 * remap, so §3 catches the ones that hurt dark-mode legibility.
 *
 * NO theme sync. FluentCRM ships no dark mode of its own (no class, no storage
 * key), so there is nothing to slave to AdminKit's switch — the `--ak-*` remap
 * alone carries both light and dark. fluentcampaign-pro extends the same screen,
 * so this one adapter covers it too.
 *
 * Loaded only on toplevel_page_fluentcrm-admin (the SPA owns every sub-route via
 * hash routing under that one WP page).
 *
 * Tier B safety: FluentCRM bakes its brand literals + Element Plus defaults into
 * compiled CSS, which a new major may reshuffle, so the skin is version-gated on
 * the major and drops to FluentCRM's native UI past the tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Crm extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-crm';
	}

	/**
	 * FluentCRM registers its top-level menu under `fluentcrm-admin` (not the
	 * slug), so point the wordmark at that menu title.
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return 'fluentcrm-admin';
	}

	/**
	 * FluentCRM defines `FLUENTCRM_PLUGIN_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENTCRM_PLUGIN_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENTCRM_PLUGIN_VERSION' ) ? FLUENTCRM_PLUGIN_VERSION : null;
	}

	/**
	 * Verified against FluentCRM 3.x. A new major may rename the `--fc-*` /
	 * `--el-*` tokens or reshuffle the brand literals this adapter targets, so
	 * gate on the major — register_assets() falls back to native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '3.1.0';
	}

	/**
	 * FluentCRM registers a top-level menu with slug `fluentcrm-admin` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluentcrm-admin`.
	 * Every sub-page (and fluentcampaign-pro's screens) is a hash route under
	 * the same screen, so one id covers the entire SPA.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluentcrm-admin' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentCRM's native UI
		// shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-crm-admin',
			'src'       => 'inc/integrations/plugins/fluent-crm/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentCRM screen — Element
	 * Plus ships its own input/select/textarea styling, so our components/*.css
	 * would double-border its widgets (a native border inside the
	 * `.el-input__wrapper` that already has one). admin.css themes the Element
	 * Plus surfaces directly via the token remap instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fcrm' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the FluentCart adapter.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fcrm( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentCRM's menu icon for an envelope glyph.
	 *
	 * FluentCRM registers its top-level menu with a base64 data-URI icon, so its
	 * `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop the inline background-image and
	 * mask an envelope Heroicon (FluentCRM is email marketing) into the icon box —
	 * same technique menu_css() uses, so it tracks the menu foreground colour in
	 * light/dark/hover/current. Inlined here so the adapter owns its own mark.
	 * Gated exactly like the FluentCart adapter: only when the icon toggle is on
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
		// Heroicons (solid) envelope — fill is irrelevant (painted as a mask; the
		// visible colour is currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path d="M1.5 8.67v8.58a3 3 0 0 0 3 3h15a3 3 0 0 0 3-3V8.67l-8.928 5.493a3 3 0 0 1-3.144 0L1.5 8.67Z"/><path d="M22.5 6.908V6.75a3 3 0 0 0-3-3h-15a3 3 0 0 0-3 3v.158l9.714 5.978a1.5 1.5 0 0 0 1.572 0L22.5 6.908Z"/></svg>';
		$box  = '#adminmenu #toplevel_page_fluentcrm-admin .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-crm-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
