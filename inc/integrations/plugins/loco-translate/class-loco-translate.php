<?php
/**
 * Loco Translate integration — Tier-B-by-selector adapter.
 *
 * Loco Translate is NOT a Vue/Element-Plus SPA: it's classic server-rendered
 * admin with its OWN stylesheet, `pub/css/admin.css` (~63KB), enqueued on EVERY
 * Loco screen. Every surface is a hardcoded literal (#fff cards, #ddd/#ccc
 * borders, #aaa/#444/#666/#999 ink, #0073aa accent) and Loco scopes its rules
 * with the wrapper ID `#loco-admin.wrap` (specificity 1,1,0), which outranks
 * AdminKit's wp-core theming. So this adapter overrides with `body.adminkit
 * #loco-admin …` (1,2,0) — winning the ID battle, mostly without !important
 * (Loco itself adds !important only on a few selected/disabled states).
 *
 * Loco ships its own light/dark "skins" (pub/css/skins/blue.css + midnight.css)
 * but they're user-selected and AdminKit's dark never triggers them, so Loco
 * renders its DEFAULT light skin → leaks white on AdminKit dark. css/admin.css
 * maps it to --ak-* so it flips with AdminKit's mode.
 *
 * Menu icon: Loco's menu uses the stock `dashicons-translation`, which is
 * already in AdminKit_Core_Menu_Icons::menu_icon_map() → auto-themed; no
 * print_menu_icon() needed. Forms: Loco isn't Element Plus and only sets a
 * border+colour on native inputs, so AdminKit's form primitives are LEFT on
 * (no enqueue_forms bail) and css/admin.css re-points that one input rule.
 *
 * Tier-B safety: Loco bakes its palette into one big stylesheet a new major may
 * reshuffle, so the skin is version-gated on the major and drops to Loco's
 * native UI past the tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Loco_Translate extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'loco-translate';
	}

	/**
	 * Loco's menu slug is `loco` (screen `toplevel_page_loco`), not the adapter
	 * slug, so the base wordmark resolver needs it explicitly.
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return 'loco';
	}

	/**
	 * loco_plugin_version() is defined unconditionally in loco.php's bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'loco_plugin_version' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return function_exists( 'loco_plugin_version' ) ? loco_plugin_version() : null;
	}

	/**
	 * Verified against Loco Translate 2.8.x. A new major may rename the classes
	 * or reshuffle the literals this adapter targets, so gate on the major —
	 * register_assets() falls back to Loco's native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '3.0';
	}

	/**
	 * Loco spans several wp-admin pages (the menu slug `loco` + sub-pages whose
	 * screen ids carry a `page_loco` fragment, e.g. `loco-translate_page_loco-*`).
	 * One substring check covers them all; every page wraps its body in
	 * `#loco-admin`, which the CSS scopes to.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'page_loco' );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so Loco's native UI shows
		// instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-loco-translate-admin',
			'src'       => 'inc/integrations/plugins/loco-translate/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
