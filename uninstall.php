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
	foreach ( array( 'adminkit_settings', 'adminkit_toolbar', 'adminkit_menu_seen', 'adminkit_storage_version', 'adminkit_stats_db_version', 'adminkit_stats_pruned', 'adminkit_avatars_default_synced' ) as $option ) {
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
		$transient = preg_replace( '/^_(?:site_)?transient_(?:timeout_)?/', '', $name );
		if ( 0 === strpos( $name, '_site_transient_' ) ) { delete_site_transient( $transient ); }
		else { delete_transient( $transient ); }
	}
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}adminkit_stats" );
}

if ( is_multisite() ) {
	$adminkit_offset = 0;
	do {
		$adminkit_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $adminkit_offset ) );
		foreach ( (array) $adminkit_sites as $adminkit_blog_id ) {
			switch_to_blog( $adminkit_blog_id );
			adminkit_uninstall_site();
			restore_current_blog();
		}
		$adminkit_offset += 100;
	} while ( is_array( $adminkit_sites ) && 100 === count( $adminkit_sites ) );
} else {
	adminkit_uninstall_site();
}

// The menu table is shared by the whole install and versioned by a network option.
// The table name comes from WordPress's trusted base prefix.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}adminkit_menu_items" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_site_option( 'adminkit_menu_db_version' );

// Retired user metadata is global on multisite; never remove WordPress session tokens.
foreach ( array( 'adminkit_avatar_seed', 'adminkit_has_gravatar', 'adminkit_last_login', 'adminkit_dash_layout', 'adminkit_dash_hidden', 'adminkit_stats_range' ) as $adminkit_meta_key ) {
	delete_metadata( 'user', 0, $adminkit_meta_key, '', true );
}

if ( is_multisite() ) {
	// Site transients live in network sitemeta, not the per-site options tables.
	$adminkit_network_keys = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
		$wpdb->esc_like( '_site_transient_adminkit_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_adminkit_' ) . '%'
	) );
	foreach ( get_networks( array( 'fields' => 'ids', 'number' => 0 ) ) as $adminkit_network_id ) {
		delete_network_option( $adminkit_network_id, 'adminkit_menu_db_version' );
		foreach ( (array) $adminkit_network_keys as $adminkit_network_key ) {
			delete_network_option( $adminkit_network_id, $adminkit_network_key );
			$adminkit_transient = preg_replace( '/^_site_transient_(?:timeout_)?/', '', $adminkit_network_key );
			wp_cache_delete( $adminkit_transient, 'site-transient' );
		}
	}
}
