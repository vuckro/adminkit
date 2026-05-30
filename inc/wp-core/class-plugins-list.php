<?php
/**
 * Plugins list tweaks — two opt-out behaviors on the plugins screen (plugins.php):
 *
 *   1. Active plugins first — sort active plugins to the top of the list so what's
 *      actually running is always in view. WordPress's list table re-sorts its rows
 *      by Name on every load (WP_Plugins_List_Table::prepare_items() forces
 *      `$orderby = 'Name'` when none is given), so filtering `all_plugins` upstream
 *      is futile. We reorder the table's already-prepared `items` on
 *      `pre_current_active_plugins`, which fires AFTER that sort and right before the
 *      rows render — so our order is the one displayed.
 *   2. Open on "Active" — land on the "Active" status filter instead of "All" when
 *      you open the plugins list from outside the screen. Via a guarded redirect on
 *      `load-plugins.php`; in-page navigation (the "All" tab) is preserved.
 *
 * Both are gated to the plugins screen (hooked from `load-plugins.php`) and each
 * carries its own setting (default ON) + filter, so they toggle independently.
 *
 * Filters:
 *   adminkit/plugins_active_first/enabled           (bool)  master on/off
 *   adminkit/plugins_default_active_filter/enabled  (bool)  master on/off
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Plugins_List {

	/**
	 * Register both settings (default ON) + wire the plugins-screen entry point. The
	 * settings register unconditionally so the Settings page can discover them while off.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'plugins_active_first_enabled', array( 'default' => true ) );
		AdminKit_Settings::register( 'plugins_default_active_filter_enabled', array( 'default' => true ) );

		add_action( 'load-plugins.php', array( __CLASS__, 'on_plugins_screen' ) );
	}

	/**
	 * Runs when the plugins screen loads. The default-filter redirect goes first (it
	 * may exit the request); the active-first reorder is attached to
	 * `pre_current_active_plugins`, which fires after the list table has sorted its
	 * rows by Name and just before they render.
	 *
	 * @return void
	 */
	public static function on_plugins_screen() {
		if ( self::default_active_filter_enabled() ) {
			self::maybe_redirect_to_active();
		}
		if ( self::active_first_enabled() ) {
			add_action( 'pre_current_active_plugins', array( __CLASS__, 'sort_active_first' ) );
		}
	}

	/**
	 * Master switch — sort active plugins to the top.
	 *
	 * @return bool
	 */
	public static function active_first_enabled() {
		return (bool) apply_filters( 'adminkit/plugins_active_first/enabled', AdminKit_Settings::get( 'plugins_active_first_enabled' ) );
	}

	/**
	 * Master switch — default to the "Active" status filter.
	 *
	 * @return bool
	 */
	public static function default_active_filter_enabled() {
		return (bool) apply_filters( 'adminkit/plugins_default_active_filter/enabled', AdminKit_Settings::get( 'plugins_default_active_filter_enabled' ) );
	}

	/**
	 * Reorder the prepared list-table rows so active plugins come first, preserving
	 * each group's existing (alphabetical) order. Operates on the global
	 * `$wp_list_table->items` on `pre_current_active_plugins` — after
	 * WP_Plugins_List_Table::prepare_items() has sorted them by Name — so our order
	 * is what renders. `is_plugin_active()` is true for network-active plugins too,
	 * so those sort up with the active set on multisite. (On the single-status views
	 * — Active / Inactive — every row shares a status, so this is a no-op there.)
	 *
	 * @return void
	 */
	public static function sort_active_first() {
		global $wp_list_table;
		if ( ! $wp_list_table instanceof WP_Plugins_List_Table
			|| empty( $wp_list_table->items )
			|| count( $wp_list_table->items ) < 2 ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active   = array();
		$inactive = array();
		foreach ( $wp_list_table->items as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$active[ $file ] = $data;
			} else {
				$inactive[ $file ] = $data;
			}
		}
		// Disjoint keys: the union concatenates active-then-inactive.
		$wp_list_table->items = $active + $inactive;
	}

	/**
	 * Send the user to the "Active" filter when they open the plugins list from
	 * outside the screen. Acts only on the default landing view (no `plugin_status`,
	 * or the catch-all "all") over GET, and never during search / pagination /
	 * bulk-action / post-activation-notice flows. In-page navigation is respected:
	 * the "All" tab links back to plugins.php, so a referer on the plugins screen
	 * keeps you on "all" and the tab stays usable. When nothing is active we leave
	 * the default view (an empty "Active" tab would be worse).
	 *
	 * @return void
	 */
	public static function maybe_redirect_to_active() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		$status = isset( $_GET['plugin_status'] ) ? sanitize_key( wp_unslash( $_GET['plugin_status'] ) ) : '';
		if ( '' !== $status && 'all' !== $status ) {
			return;
		}
		foreach ( array( 's', 'paged', 'action', 'action2', 'activate', 'deactivate', 'activate-multi', 'deactivate-multi', 'deleted', 'error' ) as $flag ) {
			if ( isset( $_GET[ $flag ] ) ) {
				return;
			}
		}
		// Clicking the "All" tab/link comes from the plugins screen itself — respect it.
		$referer = wp_get_referer();
		if ( $referer && false !== strpos( $referer, 'plugins.php' ) ) {
			return;
		}
		if ( ! self::has_active_plugins() ) {
			return;
		}
		wp_safe_redirect( add_query_arg( 'plugin_status', 'active', self_admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * Whether any plugin is active — site-level, or network-wide on multisite.
	 *
	 * @return bool
	 */
	private static function has_active_plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return ! empty( $active );
	}
}
