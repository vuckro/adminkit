<?php
/**
 * Etch (`etch`) integration — builder bypass, toolbar icon & settings screen bridge.
 *
 * - Steps AdminKit completely aside on Etch's visual builder (`?etch=magic`) so
 *   AdminKit never injects toolbar or document tokens into Etch's IDE canvas.
 * - Remaps Etch's unscoped `--e-*` admin stylesheet on `toplevel_page_etch` so
 *   the settings/license page respects both Light and Dark AdminKit modes without
 *   bleeding dark `#wpwrap` or `font-weight: 300 !important` onto WP chrome.
 * - Supplies a cohesive AdminKit icon for `#wp-admin-bar-edit-with-etch`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Etch extends AdminKit_Integration_Base {

	const MARK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" fill="#000"><path fill-rule="evenodd" clip-rule="evenodd" d="M11.8 80.5H42.1121C51.7977 80.5 60.031 73.4613 61.4888 63.9347C61.5535 62.6244 61.6624 61.585 61.782 61C66.3271 38.7595 86.112 22 109.8 22H188.2C193.609 22 198 26.3687 198 31.75V51.25C198 56.6313 193.609 61 188.2 61H101.261C78.9151 61 60.8 79.0234 60.8 101.255C60.8 111.331 52.5899 119.5 42.4622 119.5H11.8C6.39114 119.5 2 115.131 2 109.75V90.25C2 84.8687 6.39114 80.5 11.8 80.5ZM109.8 80.5H188.2C193.609 80.5 198 84.8687 198 90.25V109.75C198 115.131 193.609 119.5 188.2 119.5H109.8C101.101 119.5 93.2786 123.267 87.8939 129.25C86.217 131.113 84.7763 133.191 83.6194 135.437C81.5614 139.431 80.4 143.957 80.4 148.75C80.4 164.893 67.2263 178 51 178H11.8C6.39114 178 2 173.631 2 168.25V148.75C2 143.369 6.39114 139 11.8 139H51C59.6995 139 67.5214 135.233 72.9061 129.25C73.0813 129.055 73.254 128.858 73.4241 128.659C77.7747 123.558 80.4 116.957 80.4 109.75C80.4 104.957 81.5614 100.431 83.6194 96.4367C88.4922 86.9787 98.3917 80.5 109.8 80.5ZM109.8 139H188.2C193.609 139 198 143.369 198 148.75V168.25C198 173.631 193.609 178 188.2 178H109.8C104.391 178 100 173.631 100 168.25V148.75C100 143.369 104.391 139 109.8 139Z"/></svg>';

	/** @return string */
	public static function slug() {
		return 'etch';
	}

	/** @return string */
	public static function label() {
		return 'Etch';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'etch/etch.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'ETCH_PLUGIN_FILE' );
	}

	/** @return bool */
	public static function is_builder() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Etch builder URL query flag.
		return isset( $_GET['etch'] ) && 'magic' === $_GET['etch'];
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_etch' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-etch-admin',
			'src'       => 'inc/integrations/plugins/etch/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/** @return void */
	protected static function boot() {
		add_filter( 'adminkit/should_load', array( __CLASS__, 'bypass_builder' ) );
		add_filter( 'adminkit/toolbar_icons', array( __CLASS__, 'toolbar_icons' ) );
		add_filter( 'adminkit/toolbar_icon_ab_item_nodes', array( __CLASS__, 'toolbar_ab_item_nodes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'dequeue_host_unscoped_css' ), 99 );
	}

	/**
	 * Do not emit AdminKit assets inside Etch's full-screen visual builder (`?etch=magic`).
	 *
	 * @param bool $should_load Current decision.
	 * @return bool
	 */
	public static function bypass_builder( $should_load ) {
		return self::is_builder() ? false : $should_load;
	}

	/**
	 * Etch's `etch-admin-styles` sheet sets unscoped `#wpwrap`, `h1, h2, p, label { font-weight: 300 !important }`,
	 * and dark-only `rgba(255,255,255,0.1)` input borders across the entire admin chrome on `page=etch`.
	 * Dequeuing it lets AdminKit's clean card + form-table primitives and `admin.css` style the screen natively.
	 *
	 * @return void
	 */
	public static function dequeue_host_unscoped_css() {
		if ( self::owns_screen( get_current_screen() ) ) {
			wp_dequeue_style( 'etch-admin-styles' );
		}
	}

	/**
	 * @param array<string,string> $map Toolbar icon map.
	 * @return array<string,string>
	 */
	public static function toolbar_icons( $map ) {
		$map['edit-with-etch'] = self::MARK_SVG;
		return $map;
	}

	/**
	 * @param string[] $ids Text-only admin-bar node IDs.
	 * @return string[]
	 */
	public static function toolbar_ab_item_nodes( $ids ) {
		$ids[] = 'edit-with-etch';
		return $ids;
	}
}
