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
$options = array();
$allowed = false;
$before_writes = $writes;
AdminKit_Upgrades::run();
if ( $writes !== $before_writes ) { throw new RuntimeException( 'Unauthorized upgrade.' ); }
echo "OK: retired avatar data removed; other settings/avatars preserved; upgrade is idempotent and permission-gated.\n";
