<?php
/**
 * Rank Math administration adapter. SEO data and save handlers stay host-owned.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Rank_Math extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() { return 'rank-math'; }

	/** @return string */
	public static function label() { return 'Rank Math SEO'; }

	/** @return string[] */
	public static function host_files() {
		return array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' );
	}

	/** @return bool */
	public static function is_active() { return defined( 'RANK_MATH_VERSION' ); }

	/** @return string|null */
	protected static function host_version() {
		return defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null;
	}

	/** @return string */
	protected static function max_tested_host_version() { return '1.0.281'; }

	/**
	 * Use registered page hooks, including pages moved by a menu manager.
	 * Avoid substring matches against unrelated plugin screens.
	 *
	 * @param \WP_Screen|null $screen Current screen.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen ) { return false; }
		if ( in_array( $screen->id, array( 'toplevel_page_rank-math', 'toplevel_page_rank-math-network' ), true ) || preg_match( '/^rank-math_page_rank-math(?:-|$)/', $screen->id ) ) { return true; }
		foreach ( $GLOBALS['_parent_pages'] ?? array() as $page => $parent ) {
			if ( preg_match( '/^rank-math(?:-|$)/', $page ) && function_exists( 'get_plugin_page_hookname' )
				&& get_plugin_page_hookname( $page, $parent ) === $screen->id ) { return true; }
		}
		return false;
	}

	/**
	 * Host asset presence also covers enabled CPTs, terms, classic/block editors,
	 * the dashboard widget and portal modals without guessing supported types.
	 *
	 * @param \WP_Screen|null $screen Current screen.
	 * @return bool
	 */
	public static function owns_asset_screen( $screen ) {
		if ( ! $screen ) { return false; }
		if ( self::owns_screen( $screen ) ) { return true; }
		foreach ( array( 'rank-math-common', 'rank-math-editor', 'rank-math-post-bulk-edit', 'rank-math-dashboard-widget' ) as $handle ) {
			if ( wp_style_is( $handle, 'enqueued' ) ) { return true; }
		}
		return false;
	}

	/** @return void */
	protected static function boot() {
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ), 100 );
	}

	/**
	 * Stable scope independent of the host's page classes and menu location.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return self::owns_screen( get_current_screen() ) ? $classes . ' ak-rank-math-page' : $classes;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-rank-math',
			'src'       => 'inc/integrations/plugins/rank-math/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_asset_screen' ),
		) );
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-rank-math-pages',
			'src'       => 'inc/integrations/plugins/rank-math/css/pages.css',
			'deps'      => array( 'adminkit-rank-math' ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
