<?php
/**
 * Fired when AdminKit is deleted from the Plugins screen. Removes everything the
 * plugin stored: its options, cached transients and the network-shared menu table.
 * Deactivation keeps all data — only deletion runs this file.
 *
 * @package AdminKit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the per-site options and cached transients of the current site.
 *
 * @return void
 */
function adminkit_uninstall_site() {
	global $wpdb;
	foreach ( array( 'adminkit_settings', 'adminkit_toolbar', 'adminkit_menu_seen', 'adminkit_storage_version' ) as $option ) {
		delete_option( $option );
	}
	// Cached menu config (adminkit_menu_cfg_<blog_id>) and toolbar snapshot, including expired timeouts.
	$names = $wpdb->get_col( $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_adminkit_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_adminkit_' ) . '%',
		$wpdb->esc_like( '_site_transient_adminkit_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_adminkit_' ) . '%'
	) );
	foreach ( (array) $names as $name ) {
		delete_option( $name );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $adminkit_blog_id ) {
		switch_to_blog( $adminkit_blog_id );
		adminkit_uninstall_site();
		restore_current_blog();
	}
} else {
	adminkit_uninstall_site();
}

// The menu table is shared by the whole install and versioned by a network option.
// The table name comes from WordPress's trusted base prefix.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}adminkit_menu_items" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_site_option( 'adminkit_menu_db_version' );
