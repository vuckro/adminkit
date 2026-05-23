<?php
/**
 * WooCommerce integration — Tier B adapter (wc-admin React app).
 *
 * WooCommerce's modern admin (Home, Onboarding, Analytics, Marketing, …) is a
 * React SPA built on @wordpress/components plus WooCommerce's own
 * `.woocommerce-*` classes, all painted with hardcoded hex (#fff surfaces,
 * #1e1e1e / #757575 text, #e0e0e0 / #bbb borders) that don't flip in dark
 * mode. The app exposes no themeable CSS-variable layer, so this adapter
 * overrides those selectors with AdminKit tokens directly.
 *
 * Everything is scoped to WooCommerce's own `.woocommerce-admin-page` body
 * class, which `Loader::add_admin_body_classes()` puts on <body> for every
 * wc-admin screen (including the full-screen onboarding profiler). Because the
 * scope sits on <body>, @wordpress/components overlays that portal to the
 * document root (popovers, select listboxes) inherit the skin too. The classic
 * WC screens (Settings, list tables) carry no such class and get their own
 * pass later.
 *
 * Tier B → version-gated on the major: a new WooCommerce major is the release
 * most likely to rename the component classes this adapter targets, so past
 * the tested major the skin drops and WooCommerce's native UI shows instead of
 * a half-broken one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Woocommerce extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'woocommerce';
	}

	/**
	 * WooCommerce defines WC_VERSION in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'WC_VERSION' );
	}

	/**
	 * The wc-admin React app. Every React page is registered under the
	 * `wc-admin` page slug (PageController::PAGE_ROOT) — Home, Analytics and the
	 * onboarding profiler all resolve to a screen id containing `wc-admin`
	 * (`woocommerce_page_wc-admin`) — so a substring match covers the whole SPA.
	 * The CSS is further confined by the `.woocommerce-admin-page` body scope.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'wc-admin' );
	}

	/**
	 * The classic (non-React) WooCommerce screens: product / coupon / order
	 * editors and everything under the WooCommerce admin menu (Settings, Status,
	 * Extensions, Reports). The full wc-admin React app is excluded — it owns
	 * wc-admin.css — via the `wc-admin` screen-id bail.
	 *
	 * Note the Settings / Status / Extensions / Reports pages are "connected" to
	 * wc-admin: WooCommerce re-parents them under the wc-admin menu (so their
	 * `parent_base` is `wc-admin`, NOT `woocommerce`) and wraps their classic PHP
	 * body in the wc-admin embed chrome (adding `woocommerce-admin-page` +
	 * `woocommerce-embed-page` to <body>). Their screen id stays
	 * `woocommerce_page_wc-<x>` — no `wc-admin` substring, so they don't hit the
	 * bail — and they need the classic skin for their body (status tables,
	 * settings forms) plus the shared chrome rules (e.g. hiding the embed header).
	 * The `woocommerce_page_wc-` prefix catches them without depending on the
	 * re-parented `parent_base`.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_classic_screen( $screen ) {
		if ( ! $screen || false !== strpos( $screen->id, 'wc-admin' ) ) {
			return false;
		}
		$post_types = array( 'product', 'shop_coupon', 'shop_order' );
		if ( isset( $screen->post_type ) && in_array( $screen->post_type, $post_types, true ) ) {
			return true;
		}
		if ( isset( $screen->parent_base ) && 'woocommerce' === $screen->parent_base ) {
			return true;
		}
		return isset( $screen->id ) && 0 === strpos( $screen->id, 'woocommerce_page_wc-' );
	}

	/**
	 * WooCommerce exposes its version via the WC_VERSION constant.
	 *
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'WC_VERSION' ) ? WC_VERSION : null;
	}

	/**
	 * Verified against WooCommerce 10.x. A new major may reshuffle the component
	 * classes this Tier B adapter overrides, so gate on the major.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '10';
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so WooCommerce's native
		// UI shows instead of a half-broken one (see host_within_tested_range).
		if ( ! self::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-woocommerce-wc-admin',
			'src'       => 'inc/integrations/woocommerce/css/wc-admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-woocommerce-admin',
			'src'       => 'inc/integrations/woocommerce/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_classic_screen' ),
		) );
	}
}
