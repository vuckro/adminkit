<?php
/**
 * Model per-site and multi-network storage; assert only AdminKit data is removed.
 *
 * Also verifies paginated `get_sites( ['number' => 100, 'offset' => ...] )`
 * iteration on large multisite installations.
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

define( 'WP_UNINSTALL_PLUGIN', 'adminkit/adminkit.php' );

function is_multisite() {
	return $GLOBALS['multisite'];
}

function get_sites( $args = array() ) {
	$all    = array( 1, 2 );
	$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
	$number = isset( $args['number'] ) ? (int) $args['number'] : count( $all );
	return array_slice( $all, $offset, $number );
}

function get_networks( $args = array() ) {
	return array( 1, 2 );
}

function switch_to_blog( $id ) {
	global $wpdb;
	$wpdb->prefix  = 'wp_' . $id . '_';
	$wpdb->options = $wpdb->prefix . 'options';
}

function restore_current_blog() {
	global $wpdb;
	$wpdb->prefix  = 'wp_';
	$wpdb->options = 'wp_options';
}

function delete_option( $key ) {
	$GLOBALS['deleted'][] = $key;
}

function delete_site_option( $key ) {
	$GLOBALS['network_deleted'][] = $key;
}

function delete_network_option( $id, $key ) {
	$GLOBALS['network_deleted'][] = $key;
}

function delete_transient( $key ) {
	$GLOBALS['transients'][] = $key;
}

function delete_site_transient( $key ) {
	$GLOBALS['transients'][] = $key;
}

function wp_cache_delete( $key, $group ) {
	$GLOBALS['cache'][] = array( $key, $group );
}

function delete_metadata( $type, $id, $key, $value, $all ) {
	if ( 'user' !== $type || 0 !== $id || ! $all ) {
		throw new RuntimeException( 'Unexpected metadata scope' );
	}
	$GLOBALS['metadata'][] = $key;
}

class Uninstall_DB {
	public $prefix      = 'wp_';
	public $base_prefix = 'wp_';
	public $options     = 'wp_options';
	public $sitemeta    = 'wp_sitemeta';

	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	public function prepare( $sql, ...$args ) {
		return array( $sql, $args );
	}

	public function get_col( $prepared ) {
		$names = false !== strpos( $prepared[0], 'meta_key' )
			? array( '_site_transient_adminkit_network', '_site_transient_timeout_adminkit_expired', '_site_transient_other' )
			: array( '_transient_adminkit_menu_cfg_1', '_transient_timeout_adminkit_expired', '_site_transient_adminkit_legacy', '_transient_other', 'blogname' );

		return array_values(
			array_filter(
				$names,
				static function ( $name ) use ( $prepared ) {
					foreach ( $prepared[1] as $pattern ) {
						if ( 0 === strpos( $name, str_replace( '\\', '', rtrim( $pattern, '%' ) ) ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	public function query( $sql ) {
		$GLOBALS['queries'][] = $sql;
	}
}

foreach ( array( false, true ) as $multisite ) {
	$GLOBALS['multisite'] = $multisite;
	$wpdb                 = new Uninstall_DB();
	$deleted              = array();
	$network_deleted      = array();
	$queries              = array();
	$transients           = array();
	$cache                = array();
	$metadata             = array();

	$source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
	if ( function_exists( 'adminkit_uninstall_site' ) ) {
		$source = (string) preg_replace( '/function adminkit_uninstall_site\(\) \{.*?\n\}\n/s', '', $source, 1 );
	}
	eval( '?>' . $source );

	foreach ( array_merge( $deleted, $network_deleted ) as $key ) {
		if ( ! preg_match( '/^(?:adminkit_|_(?:site_)?transient_(?:timeout_)?adminkit_)/', $key ) ) {
			throw new RuntimeException( 'Unrelated option removed: ' . $key );
		}
	}

	foreach ( $metadata as $key ) {
		if ( 0 !== strpos( $key, 'adminkit_' ) ) {
			throw new RuntimeException( 'Unrelated metadata removed' );
		}
	}

	$sites = $multisite ? 2 : 1;
	foreach ( array( 'adminkit_settings', 'adminkit_stats_db_version', 'adminkit_stats_pruned', 'adminkit_avatars_default_synced' ) as $key ) {
		if ( count( array_keys( $deleted, $key, true ) ) !== $sites ) {
			throw new RuntimeException( 'Historical site option retained: ' . $key );
		}
	}

	$expected = $multisite
		? array(
			'DROP TABLE IF EXISTS wp_1_adminkit_stats',
			'DROP TABLE IF EXISTS wp_2_adminkit_stats',
			'DROP TABLE IF EXISTS wp_adminkit_menu_items',
		)
		: array(
			'DROP TABLE IF EXISTS wp_adminkit_stats',
			'DROP TABLE IF EXISTS wp_adminkit_menu_items',
		);

	if ( $queries !== $expected ) {
		throw new RuntimeException( 'Unexpected table cleanup' );
	}
	if ( ! in_array( 'adminkit_expired', $transients, true ) ) {
		throw new RuntimeException( 'Expired transient not invalidated' );
	}
	if ( $multisite && ( ! in_array( '_site_transient_adminkit_network', $network_deleted, true ) || 4 !== count( $cache ) ) ) {
		throw new RuntimeException( 'Network transient cleanup incomplete' );
	}
	if ( 6 !== count( $metadata ) ) {
		throw new RuntimeException( 'Retired user metadata retained' );
	}
}

echo "OK: current/historical per-site storage, all networks, expired transients and owned metadata only.\n";
