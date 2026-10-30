<?php
/** Run: php dev/check-uninstall.php. Verifies uninstall.php touches only AdminKit data. */
define( 'WP_UNINSTALL_PLUGIN', 'adminkit/adminkit.php' );
$deleted = array(); $site_deleted = array(); $queries = array(); $switched = array();
$options = array( '_transient_adminkit_menu_cfg_1', '_transient_timeout_adminkit_menu_cfg_1', '_transient_adminkit_toolbar_nodes', '_transient_other_cache', 'blogname' );
function defined_multisite() { return $GLOBALS['multisite']; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_sites( $args ) { return array( 1, 2 ); }
function switch_to_blog( $id ) { $GLOBALS['switched'][] = $id; }
function restore_current_blog() {}
function delete_option( $name ) { $GLOBALS['deleted'][] = $name; }
function delete_site_option( $name ) { $GLOBALS['site_deleted'][] = $name; }
class Test_Uninstall_DB {
	public $options = 'wp_options'; public $base_prefix = 'wp_';
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) { return $args; }
	public function get_col( $args ) {
		$out = array();
		foreach ( $GLOBALS['options'] as $name ) {
			foreach ( $args as $like ) { if ( 0 === strpos( $name, str_replace( '\\', '', rtrim( $like, '%' ) ) ) ) { $out[] = $name; break; } }
		}
		return $out;
	}
	public function query( $sql ) { $GLOBALS['queries'][] = $sql; }
}
$wpdb = new Test_Uninstall_DB();
foreach ( array( false, true ) as $multisite ) {
	$deleted = $site_deleted = $queries = $switched = array();
	$GLOBALS['multisite'] = $multisite;
	$file = file_get_contents( __DIR__ . '/../uninstall.php' );
	// Re-declare the helper only once.
	if ( function_exists( 'adminkit_uninstall_site' ) ) { $file = preg_replace( '/function adminkit_uninstall_site\(\) \{.*?\n\}\n/s', '', $file, 1 ); }
	eval( '?>' . $file );
	$expected_sites = $multisite ? 2 : 1;
	foreach ( $deleted as $name ) {
		if ( 0 !== strpos( $name, 'adminkit_' ) && ! preg_match( '/^_transient_(timeout_)?adminkit_/', $name ) ) { throw new RuntimeException( "Unexpected deletion: $name" ); }
	}
	if ( count( array_keys( $deleted, 'adminkit_settings', true ) ) !== $expected_sites ) { throw new RuntimeException( 'Settings not removed on every site.' ); }
	if ( in_array( '_transient_other_cache', $deleted, true ) || in_array( 'blogname', $deleted, true ) ) { throw new RuntimeException( 'Unrelated data removed.' ); }
	if ( array( 'DROP TABLE IF EXISTS wp_adminkit_menu_items' ) !== $queries ) { throw new RuntimeException( 'Unexpected SQL.' ); }
	if ( array( 'adminkit_menu_db_version' ) !== $site_deleted ) { throw new RuntimeException( 'Network version option kept.' ); }
}
echo "OK: uninstall removes only AdminKit options, transients and the menu table (single site and multisite).\n";
