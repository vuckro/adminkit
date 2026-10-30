<?php
/** Run: php dev/check-upgrades.php. Checks cleanup without touching WordPress. */
define( 'ABSPATH', __DIR__ );
class AdminKit_Settings { const OPTION_KEY = 'adminkit_settings'; }
require dirname( __DIR__ ) . '/inc/class-upgrades.php';
$options = array();
$metadata = array();
$writes = 0;
$allowed = true;
function current_user_can( $cap ) { global $allowed; return $allowed; }
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { global $options, $writes; $options[ $key ] = $value; ++$writes; }
function rest_sanitize_boolean( $value ) { return ! in_array( $value, array( false, 0, '0', 'false' ), true ); }
function delete_option( $key ) { global $options, $writes; unset( $options[ $key ] ); ++$writes; }
function delete_metadata( $type, $id, $key, $value, $all ) {
 global $metadata, $writes;
 if ( 'user' !== $type || 0 !== $id || ! $all ) { throw new RuntimeException( 'Invalid cleanup scope.' ); }
 foreach ( $metadata as &$user ) { unset( $user[ $key ] ); }
 ++$writes;
}
foreach ( array( 'adminkit_portraits' => 'mystery', 'identicon' => 'identicon' ) as $before => $after ) {
 $options = array( 'avatar_default' => $before, 'adminkit_settings' => array( 'custom_avatars_enabled' => true, 'theme_toggle_enabled' => true ), 'adminkit_avatars_default_synced' => 1 );
 $metadata = array( 1 => array( 'adminkit_avatar_seed' => 'seed', 'first_name' => 'Keep' ), 2 => array( 'adminkit_has_gravatar' => '1' ) );
 AdminKit_Upgrades::run();
 if ( $after !== $options['avatar_default'] || isset( $options['adminkit_settings']['custom_avatars_enabled'] ) || isset( $options['adminkit_avatars_default_synced'] ) || true !== $options['adminkit_settings']['theme_toggle_enabled'] || array( 1 => array( 'first_name' => 'Keep' ), 2 => array() ) !== $metadata ) {
  throw new RuntimeException( 'Cleanup changed unrelated data or left retired data.' );
 }
 $before_writes = $writes;
 AdminKit_Upgrades::run();
 if ( $writes !== $before_writes ) { throw new RuntimeException( 'Upgrade is not idempotent.' ); }
}
$options = array( AdminKit_Upgrades::OPTION => 1, 'adminkit_settings' => array( 'bricks_builder_enabled' => true, 'plugins_active_first_enabled' => false, 'theme_toggle_enabled' => true ), 'bricks_global_settings' => array( 'builderMode' => 'dark' ) );
$metadata = array( 1 => array( 'first_name' => 'Keep' ) );
AdminKit_Upgrades::run();
if ( array( 'theme_toggle_enabled' => true, 'active_items_first_enabled' => false ) !== $options['adminkit_settings'] || array( 'builderMode' => 'dark' ) !== $options['bricks_global_settings'] || array( 1 => array( 'first_name' => 'Keep' ) ) !== $metadata || 3 !== $options[AdminKit_Upgrades::OPTION] ) {
 throw new RuntimeException( 'Version 2 did not clean only the retired flags.' );
}
$before_writes = $writes;
AdminKit_Upgrades::run();
if ( $writes !== $before_writes ) { throw new RuntimeException( 'Version 2 is not idempotent.' ); }
foreach ( array( true, false ) as $choice ) {
 $options = array( AdminKit_Upgrades::OPTION => 2, 'adminkit_settings' => array( 'plugins_active_first_enabled' => ! $choice, 'active_items_first_enabled' => $choice ) );
 AdminKit_Upgrades::run();
 if ( array( 'active_items_first_enabled' => $choice ) !== $options['adminkit_settings'] ) { throw new RuntimeException( 'An explicit shared priority choice was overwritten.' ); }
}
$options = array();
$allowed = false;
$before_writes = $writes;
AdminKit_Upgrades::run();
if ( $writes !== $before_writes ) { throw new RuntimeException( 'Unauthorized upgrade.' ); }
echo "OK: upgrades remove retired data/flags, preserve native settings and are idempotent and permission-gated.\n";
