<?php
/**
 * Plugins list — one opt-out behavior that keeps the plugins screen (plugins.php)
 * leading with what's actually running. Three mechanics, one promise:
 *
 *   1. Open on "Active" — land on the "Active" status filter instead of "All" when
 *      you arrive at the plugins list from outside the screen, so what's running is
 *      the first thing you see. Falls back to "All" when nothing is active (an empty
 *      "Active" tab would be worse). In-page navigation — clicking the "All" tab — is
 *      respected.
 *   2. Active plugins first — on the "All" view, sort active plugins to the top.
 *      WordPress's list table re-sorts its rows by Name on every load
 *      (WP_Plugins_List_Table::prepare_items() forces `$orderby = 'Name'` when none
 *      is given), so filtering `all_plugins` upstream is futile. We reorder the
 *      table's already-prepared `items` on `pre_current_active_plugins`, which fires
 *      AFTER that sort and right before the rows render — so our order is displayed.
 *   3. Follow the plugin you just toggled — activating or deactivating a plugin from
 *      a filtered view would make it vanish from that view (its status no longer
 *      matches). Instead we follow it: activating jumps to "Active" so you see it
 *      among what's now running; deactivating drops to "All", where the active set is
 *      still on top and the row you just switched off sits right below it — so you see
 *      the result of your action and can undo it, rather than watching it disappear.
 *
 * All three are gated to the plugins screen (hooked from `load-plugins.php`) behind a
 * single setting (default ON) so they travel together as one coherent feature.
 *
 * Filter:
 *   adminkit/plugins_active_first/enabled  (bool)  master on/off
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Plugins_List {

	/**
	 * Register the setting (default ON) + wire the plugins-screen entry point. The
	 * setting registers unconditionally so the Settings page can discover it while off.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'plugins_active_first_enabled', array( 'default' => true ) );

		add_action( 'load-plugins.php', array( __CLASS__, 'on_plugins_screen' ) );
	}

	/**
	 * Runs when the plugins screen loads. The two redirects may exit the request, so
	 * they go first; the post-action hop (to "Active" after an activate, "All" after a
	 * deactivate) is checked before the open-on-"Active" landing (they never both apply
	 * to one load — a post-action reload carries an `activate*`/`deactivate*` flag that
	 * the landing redirect skips — but the ordering keeps that explicit). The
	 * active-first reorder is attached to `pre_current_active_plugins`, which fires
	 * after the list table has sorted its rows by Name and just before they render.
	 *
	 * @return void
	 */
	public static function on_plugins_screen() {
		if ( ! self::enabled() ) {
			return;
		}
		self::maybe_redirect_after_action();
		self::maybe_redirect_to_active();
		add_action( 'pre_current_active_plugins', array( __CLASS__, 'sort_active_first' ) );
	}

	/**
	 * Master switch — lead the plugins screen with active plugins.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'adminkit/plugins_active_first/enabled', AdminKit_Settings::get( 'plugins_active_first_enabled' ) );
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
	 * After a plugin is activated or deactivated, jump to the status view where the
	 * changed plugin is actually visible, rather than leaving you on a filter it just
	 * dropped out of:
	 *
	 *   - activate*   → "Active": the plugin is now running, so land on the active set
	 *                   (it would have vanished from the "Inactive" / search view you
	 *                   toggled it from).
	 *   - deactivate* → "All":    the plugin is now inactive, and "All" keeps it in
	 *                   sight just below the still-active rows (it would have vanished
	 *                   from "Active"), so you can see the result and undo it.
	 *
	 * Fires on the post-action reload — WordPress redirects back with the matching
	 * `activate` / `activate-multi` / `deactivate` / `deactivate-multi` flag set — and
	 * only when you're not already on the target view (an empty status is the "all"
	 * view). The flag is carried through so WordPress still prints its confirmation
	 * notice, and the search term / page number ride along so context is preserved.
	 *
	 * @return void
	 */
	public static function maybe_redirect_after_action() {
		$targets = array(
			'activate'         => 'active',
			'activate-multi'   => 'active',
			'deactivate'       => 'all',
			'deactivate-multi' => 'all',
		);
		$flag = '';
		foreach ( array_keys( $targets ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$flag = $key;
				break;
			}
		}
		if ( '' === $flag ) {
			return;
		}
		$status  = isset( $_GET['plugin_status'] ) ? sanitize_key( wp_unslash( $_GET['plugin_status'] ) ) : '';
		$current = ( '' === $status ) ? 'all' : $status;
		if ( $current === $targets[ $flag ] ) {
			return;
		}
		$args = array(
			'plugin_status' => $targets[ $flag ],
			$flag           => 'true',
		);
		if ( isset( $_GET['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		if ( isset( $_GET['paged'] ) ) {
			$args['paged'] = absint( wp_unslash( $_GET['paged'] ) );
		}
		wp_safe_redirect( add_query_arg( $args, self_admin_url( 'plugins.php' ) ) );
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
