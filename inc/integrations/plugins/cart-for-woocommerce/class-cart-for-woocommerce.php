<?php
/** FunnelKit Cart — scoped Tier B skin, verified against host 1.9.x. */
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 2 ) . '/trait-funnelkit-integration.php';

class AdminKit_Integration_Cart_For_Woocommerce extends AdminKit_Integration_Base {
	use AdminKit_FunnelKit_Integration;

	public static function slug() {
		return 'cart-for-woocommerce';
	}

	public static function label() {
		return 'FunnelKit Cart';
	}

	public static function host_files() {
		return array( 'cart-for-woocommerce/plugin.php' );
	}

	public static function is_active() {
		return defined( 'FKCART_VERSION' ) || class_exists( '\FKCart\Plugin', false ) || class_exists( '\FKCart\Admin\Admin_App', false );
	}

	/** @return string Verified host minor series; newer minors fall back to native UI. */
	protected static function max_tested_host_version() {
		return '1.9';
	}

	protected static function host_version() {
		return defined( 'FKCART_VERSION' ) ? (string) FKCART_VERSION : null;
	}

	protected static function wordmark_menu_slug() {
		return 'fkcart';
	}

	protected static function funnelkit_variant() {
		return 'cart';
	}

	protected static function funnelkit_menu_icon() {
		return 'shopping-cart';
	}

	public static function owns_screen( $screen ) {
		if ( ! is_object( $screen ) || empty( $screen->id ) ) {
			return false;
		}
		return (bool) preg_match( '/(?:^|_)fkcart(?:$|[-_&])/', (string) $screen->id );
	}
}
