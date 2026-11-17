<?php
/**
 * FluentCart integration — Tier B adapter (Vue 3 SPA on Tailwind + Element Plus).
 *
 * Syncs `fluent_theme_dark` to AdminKit's mode on the SPA and `fluent-products`
 * screens, while admin.css + vendor/element-plus.css cover Element Plus surfaces.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Cart extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-cart';
	}

	/** @return string */
	public static function label() {
		return 'FluentCart';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'fluent-cart/fluent-cart.php', 'fluent-cart-pro/fluent-cart-pro.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENTCART_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENTCART_VERSION' ) ? FLUENTCART_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '1.3.28';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-cart' === $screen->id;
	}

	/**
	 * Classic `fluent-products` CPT list, editor, and taxonomy screens carrying FC's top bar.
	 *
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function is_product_screen( $screen ) {
		return $screen && ! empty( $screen->post_type ) && 'fluent-products' === $screen->post_type;
	}

	/**
	 * All screens rendering FluentCart admin chrome.
	 *
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_chrome( $screen ) {
		return self::owns_screen( $screen ) || self::is_product_screen( $screen );
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-cart-admin',
			'src'       => 'inc/integrations/plugins/fluent-cart/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Assets::ELEMENT_PLUS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_chrome' ),
		) );
	}

	/** @return bool */
	protected static function bails_core_forms() {
		return true;
	}

	/** @return string */
	protected static function menu_icon() {
		// Heroicons solid shopping-bag — painted as a CSS mask.
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M7.5 6v.75H5.513c-.96 0-1.764.724-1.865 1.679l-1.263 12A1.875 1.875 0 0 0 4.25 22.5h15.5a1.875 1.875 0 0 0 1.865-2.071l-1.263-12a1.875 1.875 0 0 0-1.865-1.679H16.5V6a4.5 4.5 0 1 0-9 0ZM12 3a3 3 0 0 0-3 3v.75h6V6a3 3 0 0 0-3-3Zm-3 8.25a3 3 0 1 0 6 0v-.75a.75.75 0 0 1 1.5 0v.75a4.5 4.5 0 1 1-9 0v-.75a.75.75 0 0 1 1.5 0v.75Z" clip-rule="evenodd"/></svg>';
	}

	/** @return void */
	protected static function boot() {
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ) );
	}

	/** @return void */
	public static function sync_theme() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::owns_chrome( $screen ) ) {
			return;
		}
		AdminKit_Fluent_Theme::print_spa_sync( array(
			'id'                  => 'adminkit-fluent-cart-theme-sync',
			'dark_classes'        => array( 'fluent_theme_dark' ),
			'observe_immediately' => true,
		) );
	}
}
