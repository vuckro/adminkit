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
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fcrm( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}
}
