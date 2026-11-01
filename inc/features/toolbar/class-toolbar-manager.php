<?php
/**
 * Toolbar facade: WordPress hooks, permissions and REST response contracts.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Toolbar_Manager {

	const OPTION = AdminKit_Toolbar_Config::OPTION;
	const REST_NS = 'adminkit/v1';
	const REST_ROUTE = '/toolbar';
	const SNAPSHOT_KEY = AdminKit_Toolbar_Snapshot::SNAPSHOT_KEY;
	const FRONTEND_SNAPSHOT_KEY = AdminKit_Toolbar_Snapshot::FRONTEND_SNAPSHOT_KEY;

	/**
	 * Register the setting; bind the editor route and application only when enabled.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'toolbar_manager_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );


		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// Capture the live front-end bar when an administrator views the site so the
		// Toolbar editor's "Front end" mode reflects any theme/plugin front-end items.
		add_action( 'admin_bar_menu', array( __CLASS__, 'capture_frontend' ), 99998 );

		// Apply on BOTH bars (wp-admin + front-end for logged-in users), very late so
		// every node — core + plugins — already exists.
		add_action( 'admin_bar_menu', array( __CLASS__, 'apply' ), 99999 );
		add_action( 'admin_head', array( __CLASS__, 'print_css' ), 22 );
		add_action( 'wp_head', array( __CLASS__, 'print_css' ), 22 );
		add_action( 'update_option_' . AdminKit_Settings::OPTION_KEY, array( __CLASS__, 'flush_snapshots' ) );
		add_action( 'update_option_site_icon', array( __CLASS__, 'flush_snapshots' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'flush_snapshots' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush_snapshots' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush_snapshots' ) );
		add_action( 'switch_theme', array( __CLASS__, 'flush_snapshots' ) );
	}

	/**
	 * Master switch for the editor and toolbar application.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/toolbar_manager/enabled', AdminKit_Settings::get( 'toolbar_manager_enabled' ) );
	}

	/**
	 * Capture the PRISTINE bar on the AdminKit settings screen (admin context → the full
	 * node set, incl. is_admin()-gated nodes the REST context can't see), just before
	 * apply() @ 99999. Written server-side now; read by the SPA's REST GET a moment
	 * later in the same page load.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		$is_settings = method_exists( 'AdminKit_Settings_Page', 'is_settings_hook' )
			? AdminKit_Settings_Page::is_settings_hook( $hook )
			: ( 'toplevel_page_' . AdminKit_Settings_Page::SLUG === $hook );
		if ( ! $is_settings ) {
			return;
		}
		add_action( 'admin_bar_menu', array( __CLASS__, 'capture' ), 99998 );
	}

	/**
	 * Boot payload merged into window.AdminKitData. The SPA uses `enabled` to decide
	 * whether to render, and `route` to fetch the live nodes + saved layout.
	 *
	 * @return array
	 */
	public static function boot_data() {
		return array(
			'enabled' => self::is_enabled() && current_user_can( AdminKit_Toolbar_Config::capability() ),
			'route'   => self::REST_NS . self::REST_ROUTE,
		);
	}

	/**
	 * GET  adminkit/v1/toolbar — the pristine top-level nodes + the saved layout.
	 * POST adminkit/v1/toolbar — persist { admin: {...}, frontend: {...} }.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => static function () {
						return current_user_can( AdminKit_Toolbar_Config::capability() );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => static function () {
						return current_user_can( AdminKit_Toolbar_Config::capability() );
					},
				),
			)
		);
	}

	/**
	 * Capture the live front-end admin-bar nodes when an administrator visits the public
	 * site, BEFORE apply() reorders/hides them.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function capture_frontend( $bar ) {
		if ( is_admin() || ! is_object( $bar ) || ! is_admin_bar_showing() || ! current_user_can( AdminKit_Toolbar_Config::capability() ) ) {
			return;
		}
		AdminKit_Toolbar_Snapshot::capture_frontend( $bar );
	}

	/** Keep the public snapshot invalidation callback stable. */
	public static function flush_snapshots() {
		AdminKit_Toolbar_Snapshot::flush_snapshots();
	}

	/** Capture before customizations are applied, using the existing hook callback. */
	public static function capture( $bar ) {
		AdminKit_Toolbar_Snapshot::capture( $bar );
	}

	/** Read validated layouts; legacy flat options remain admin-only. */
	public static function get_config( $context = null ) {
		return AdminKit_Toolbar_Config::get_config( $context );
	}

	/** Return the existing REST envelope with pristine nodes and saved layouts. */
	public static function rest_get() {
		$nodes = AdminKit_Toolbar_Snapshot::read();
		$configs = self::get_config();
		return rest_ensure_response( array(
			'nodes' => $nodes['admin'],
			'nodesByContext' => $nodes,
			'config' => $configs['admin'],
			'configByContext' => $configs,
		) );
	}

	/** Validate and persist before returning the unchanged REST response shape. */
	public static function rest_save( $request ) {
		$configs = AdminKit_Toolbar_Config::save( $request->get_params() );
		if ( is_wp_error( $configs ) ) { return $configs; }
		return rest_ensure_response( array(
			'saved' => true,
			'config' => $configs['admin'],
			'configByContext' => $configs,
		) );
	}

	/** Resolve policy in the facade, then apply the validated active-context layout. */
	public static function apply( $bar ) {
		if ( ! is_object( $bar ) ) { return; }
		$cfg = self::get_config( is_admin() ? 'admin' : 'frontend' );
		if ( empty( $cfg['order'] ) && empty( $cfg['hidden'] ) && empty( $cfg['titles'] ) && empty( $cfg['icons'] ) ) { return; }
		AdminKit_Toolbar_Renderer::apply( $bar, $cfg, AdminKit_Toolbar_Config::protected_nodes(), (bool) AdminKit_Settings::get( 'branding_enabled' ) );
	}

	/** Keep the existing head hook callback while CSS state lives with its renderer. */
	public static function print_css() {
		AdminKit_Toolbar_Renderer::print_css();
	}
}
