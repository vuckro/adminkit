<?php
/**
 * Menu manager — hook facade and REST controller for the sidebar Menu Manager.
 *
 * Mirrors `AdminKit_Toolbar_Manager`:
 *   - `AdminKit_Menu_Config`   owns validation, URL sanitisation & Multisite preservation.
 *   - `AdminKit_Menu_Snapshot` captures the pristine `$menu`/`$submenu` tree & editor data.
 *   - `AdminKit_Menu_Renderer` applies saved layouts, remaps moved submenus & emits CSS/JS.
 *   - `AdminKit_Menu_Store`    persists rows in `{prefix}adminkit_menu`.
 *
 * Hiding here is menu-level only (declutter), not access control — direct URLs stay
 * reachable. Per-role access is the deferred v2 layer (the store reserves a column).
 *
 * Filter:
 *   `adminkit/menu_manager/enabled` (bool) — master on/off for application.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-menu-config.php';
require_once __DIR__ . '/class-menu-snapshot.php';
require_once __DIR__ . '/class-menu-renderer.php';

class AdminKit_Menu_Manager {

	/** @var string[] Curated WordPress dashicon classes offered in the Menu picker. */
	const WP_DASHICONS = AdminKit_Icons::WP_DASHICONS;

	/**
	 * Register the setting; bind editor routes, snapshots and schema only when enabled.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'menu_manager_enabled', array( 'default' => true ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		if ( is_admin() ) {
			add_action( 'init', array( __CLASS__, 'maybe_ensure_schema' ) );
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'hide_legacy_link_manager' ), 9990 );
		add_action( 'admin_menu', array( __CLASS__, 'capture_snapshot' ), 9998 );
		add_action( 'admin_menu', array( __CLASS__, 'apply' ), 9999 );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( __CLASS__, 'order_top_level' ) );
		add_filter( 'parent_file', array( __CLASS__, 'remap_parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'remap_submenu_file' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_css' ), 22 );
		add_action( 'admin_footer', array( __CLASS__, 'print_url_overrides' ) );
		add_action( 'wp_uninitialize_site', array( 'AdminKit_Menu_Store', 'delete_site_data' ) );
	}

	/**
	 * Provision the menu storage schema only for privileged administrators.
	 *
	 * @return void
	 */
	public static function maybe_ensure_schema() {
		if ( current_user_can( 'manage_options' ) ) {
			AdminKit_Menu_Store::ensure_schema();
		}
	}

	/**
	 * Master switch — gates application, snapshots and the write route.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/menu_manager/enabled', AdminKit_Settings::get( 'menu_manager_enabled' ) );
	}

	/** @return void */
	public static function hide_legacy_link_manager() {
		AdminKit_Menu_Renderer::hide_legacy_link_manager();
	}

	/** @return void */
	public static function capture_snapshot() {
		AdminKit_Menu_Snapshot::capture();
	}

	/** @return bool */
	private static function is_menu_editor_screen() {
		return AdminKit_Menu_Snapshot::is_menu_editor_screen();
	}

	/** @return array */
	public static function editor_data() {
		return AdminKit_Menu_Snapshot::editor_data( self::is_enabled() );
	}

	/** @return void */
	public static function apply() {
		AdminKit_Menu_Renderer::apply();
	}

	/**
	 * @param string $original_html Native `$menu[0]` / `$submenu[0]` markup.
	 * @param string $custom_title  Saved user label.
	 * @return string
	 */
	public static function apply_menu_title( $original_html, $custom_title ) {
		return AdminKit_Menu_Renderer::apply_menu_title( $original_html, $custom_title );
	}

	/**
	 * @param array $menu_order Native WordPress menu order.
	 * @return array
	 */
	public static function order_top_level( $menu_order ) {
		return AdminKit_Menu_Renderer::order_top_level( $menu_order );
	}

	/**
	 * @param array $current Top-level slugs present right now.
	 * @return array
	 */
	private static function seen_order( $current ) {
		return AdminKit_Menu_Renderer::seen_order( $current );
	}

	/**
	 * @param string $parent_file Native parent file.
	 * @return string
	 */
	public static function remap_parent_file( $parent_file ) {
		return AdminKit_Menu_Renderer::remap_parent_file( $parent_file );
	}

	/**
	 * @param string|null $submenu_file Native submenu file.
	 * @return string|null
	 */
	public static function remap_submenu_file( $submenu_file ) {
		return AdminKit_Menu_Renderer::remap_submenu_file( $submenu_file );
	}

	/** @return void */
	public static function print_css() {
		AdminKit_Menu_Renderer::print_css();
	}

	/** @return void */
	public static function print_url_overrides() {
		AdminKit_Menu_Renderer::print_url_overrides();
	}

	/**
	 * @param mixed $url Raw candidate URL or relative admin path.
	 * @return string
	 */
	public static function sanitize_menu_url( $url ) {
		return AdminKit_Menu_Config::sanitize_menu_url( $url );
	}

	/**
	 * Register `POST /adminkit/v1/menu`.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'adminkit/v1',
			'/menu',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_save' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Persist a submitted menu layout.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save( $request ) {
		$items_in = $request->get_param( 'items' );
		if ( ! is_array( $items_in ) || array_values( $items_in ) !== $items_in ) {
			return AdminKit_Storage::error( 'adminkit_invalid_menu', 400 );
		}
		foreach ( $items_in as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['slug'] ) || ! is_string( $item['slug'] ) || '' === trim( $item['slug'] ) ) {
				return AdminKit_Storage::error( 'adminkit_invalid_menu', 400 );
			}
		}
		$items = AdminKit_Menu_Config::sanitize_items( $items_in );
		if ( ! empty( $items ) && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_super_admin' ) && ! is_super_admin() ) {
			$items = AdminKit_Menu_Config::preserve_invisible_multisite_items( $items );
		}
		$result = AdminKit_Menu_Store::save_config( $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
