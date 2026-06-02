<?php
/**
 * FunnelKit Automation (Autonami) integration — Tier-B-by-selector adapter.
 *
 * FunnelKit Automation's admin is a React app with REAL webpack-extracted
 * stylesheets (admin/frontend/dist/main-{ver}.css + ~57 lazy route chunks), NOT
 * Emotion CSS-in-JS — every surface is a hardcoded `.bwf-*` literal, so this is
 * Tier-B by selector. The one variable lever is WP's `--wp-admin-theme-color`
 * (+ darker-10/-20) + `--current-checkbox-color`, used thousands of times for the
 * accent; css/admin.css remaps those to --ak-primary (a large accent swath in a
 * few lines) and then overrides the `.bwf-*` literals for surfaces/text/borders.
 *
 * PRO adds NO admin app bundle — it patches the free React bundle's feature flags
 * at runtime, same `autonami` slug / same `#bwfcrm-page` mount / same screens. So
 * this ONE adapter (scoped to the autonami screen) covers free + pro. Pro's only
 * extra admin chrome is an admin-bar search widget rendered on EVERY page, so its
 * styles ship in a tiny always-on css/adminbar.css.
 *
 * The automation editor is React Flow — its nodes/edges/handles/controls/minimap
 * are CSS-themable (admin.css handles them); the minimap's JS-set node colours +
 * any chart.js fills are the only out-of-CSS-reach bits.
 *
 * Load order: FunnelKit enqueues at prio 99, AdminKit at 9999 → AdminKit wins the
 * entry stylesheet by source order; the lazy route chunks inject AFTER us, so
 * selectors they reassert need !important (Tier-B, like FluentBooking).
 *
 * Tier-B safety: version-gated on the major; drops to FunnelKit's native UI past
 * the tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Funnelkit_Automation extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'funnelkit-automation';
	}

	/**
	 * FunnelKit Automation registers its menu under the slug `autonami`, not the
	 * adapter slug, so the base wordmark resolver needs it explicitly.
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return 'autonami';
	}

	/**
	 * The free plugin defines BWFAN_VERSION in its bootstrap; pro requires free,
	 * so this one constant detects the whole product.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'BWFAN_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'BWFAN_VERSION' ) ? BWFAN_VERSION : null;
	}

	/**
	 * Verified against FunnelKit Automation 3.8.x. A new MAJOR may rename the
	 * `.bwf-*` classes / reshuffle the literals this adapter targets, so gate on
	 * the major — register_assets() falls back to native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '3.8.1.1';
	}

	/**
	 * The React SPA keeps the `autonami` slug across every hash/route sub-page
	 * (dashboard, contacts, automations, broadcasts, …) plus the legacy v1 builder
	 * screen (`autonami_page_autonami-automations`), so one substring covers them.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'autonami' );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FunnelKit's native UI
		// shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		// The main skin — only on the autonami screen.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-funnelkit-automation-admin',
			'src'       => 'inc/integrations/plugins/funnelkit-automation/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
		// Pro's admin-bar contact-search widget renders on EVERY admin page, so its
		// tiny rule set loads always (no screen condition); harmless when pro is off
		// (no matching elements). Only emitted at all because the adapter inited.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-funnelkit-automation-adminbar',
			'src'       => 'inc/integrations/plugins/funnelkit-automation/css/adminbar.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => null,
		) );
	}

	/**
	 * @return void
	 */
	protected static function boot() {
		// Opt out of AdminKit's form primitives on the FunnelKit screen — the React
		// app ships its own input/button styling; our components/*.css would
		// double-style its widgets. admin.css themes the `.bwf-*` surfaces directly.
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_autonami' ) );
		// Menu icon swap runs on every admin page (the menu shows everywhere).
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_autonami( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FunnelKit's menu icon for a "bolt" glyph (automation/workflow).
	 *
	 * FunnelKit paints its menu icon as a `background-image` SVG with `!important`
	 * (change_autonami_menu_icon()), so AdminKit_Core_Menu_Icons (dashicon-keyed)
	 * can't reach it. Drop the image (matching `!important`) and mask the glyph into
	 * the icon box ::before — same technique as the FluentForm/FluentAffiliate
	 * adapters, so it tracks the menu foreground colour in light/dark/hover/current.
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
		// AdminKit icon set — "bolt" (marketing automation = triggered workflows).
		$svg  = AdminKit_Icons::svg( 'bolt' );
		$box  = '#adminmenu #toplevel_page_autonami .wp-menu-image';
		// FunnelKit sets the icon with !important, so the reset needs it too.
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-funnelkit-automation-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
