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
class Test_Upgrade_Database {
 public $prefix='wp_'; public $options='wp_options'; public $last_error=''; public $fail_drop=false; public $drops=0;
 public function query($sql) {
  if($sql!=='DROP TABLE IF EXISTS wp_adminkit_stats')throw new RuntimeException('Unexpected destructive query.');
  if($this->fail_drop)return false;
  ++$this->drops;return 0;
 }
 public function esc_like($value){return addcslashes($value,'_%\\');}
 public function prepare($sql,$args){return $sql;}
 public function get_col($sql){global $options;return array_values(array_filter(array_keys($options),static function($key){return (bool)preg_match('/^_transient_(?:timeout_)?adminkit_(?:dash_|stats_)/',$key);}));}
}
$wpdb=new Test_Upgrade_Database();
function delete_transient($name){global $options,$writes;unset($options['_transient_'.$name],$options['_transient_timeout_'.$name]);++$writes;}
function wp_cache_supports($feature){return false;}
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
if ( array( 'theme_toggle_enabled' => true, 'active_items_first_enabled' => false ) !== $options['adminkit_settings'] || array( 'builderMode' => 'dark' ) !== $options['bricks_global_settings'] || array( 1 => array( 'first_name' => 'Keep' ) ) !== $metadata || 6 !== $options[AdminKit_Upgrades::OPTION] ) {
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
$options=array(AdminKit_Upgrades::OPTION=>3,'adminkit_settings'=>array('custom_dashboard_enabled'=>true,'stats_enabled'=>true,'menu_manager_enabled'=>true),'adminkit_stats_db_version'=>'1.1','adminkit_stats_pruned'=>'today','_transient_adminkit_dash_health_v5'=>'cache','_transient_timeout_adminkit_dash_health_v5'=>1,'_transient_adminkit_stats_sum_test'=>'cache','_transient_timeout_adminkit_stats_sum_test'=>1,'_transient_other_cache'=>'keep');
$metadata=array(1=>array('adminkit_dash_layout'=>array(),'adminkit_dash_hidden'=>array(),'adminkit_stats_range'=>'30d','metaboxhidden_dashboard'=>array('native-widget'),'first_name'=>'Keep'));
$wpdb->fail_drop=true;AdminKit_Upgrades::run();
if(3!==$options[AdminKit_Upgrades::OPTION]||!isset($options['adminkit_settings']['stats_enabled']))throw new RuntimeException('Failed drop was not retryable.');
$wpdb->fail_drop=false;AdminKit_Upgrades::run();
if(array('menu_manager_enabled'=>true)!==$options['adminkit_settings']||(isset($options['adminkit_stats_db_version'])||isset($options['adminkit_stats_pruned']))||isset($options['_transient_adminkit_dash_health_v5'])||isset($options['_transient_timeout_adminkit_stats_sum_test'])||'keep'!==$options['_transient_other_cache']||array(1=>array('metaboxhidden_dashboard'=>array('native-widget'),'first_name'=>'Keep'))!==$metadata)throw new RuntimeException('Module cleanup left retired data or changed unrelated data.');
$before_drops=$wpdb->drops;AdminKit_Upgrades::run();
if($before_drops!==$wpdb->drops)throw new RuntimeException('Completed cleanup repeated the table drop.');
$options = array( AdminKit_Upgrades::OPTION => 4, 'adminkit_settings' => array( 'online_users_enabled' => true, 'theme_toggle_enabled' => true ) );
$metadata = array( 1 => array( 'adminkit_last_login' => 123, 'session_tokens' => array( 'native' => 'keep' ), 'first_name' => 'Keep' ) );
AdminKit_Upgrades::run();
if ( array( 'theme_toggle_enabled' => true ) !== $options['adminkit_settings'] || array( 1 => array( 'session_tokens' => array( 'native' => 'keep' ), 'first_name' => 'Keep' ) ) !== $metadata || 6 !== $options[AdminKit_Upgrades::OPTION] ) {
 throw new RuntimeException( 'Online users cleanup changed native sessions or left retired data.' );
}
$before_writes = $writes;
AdminKit_Upgrades::run();
if ( $writes !== $before_writes ) { throw new RuntimeException( 'Online users cleanup repeated.' ); }
$options = array( AdminKit_Upgrades::OPTION => 5, 'adminkit_settings' => array( 'logo_light' => 'https://x.test/l.svg', 'logo_dark' => 'https://x.test/d.svg', 'logo_source' => 'url', 'wp_logo' => 'logo', 'login_logo' => 'favicon', 'ui_radius' => 'soft', 'ui_density' => 'compact', 'generic_theming_on' => array(), 'ui_font' => 'system', 'theme_toggle_enabled' => true ) );
AdminKit_Upgrades::run();
if ( array( 'ui_font' => 'system', 'theme_toggle_enabled' => true, 'logo_url' => 'https://x.test/l.svg' ) != $options['adminkit_settings'] || 6 !== $options[AdminKit_Upgrades::OPTION] ) {
 throw new RuntimeException( 'Retired logo/appearance keys were not migrated cleanly.' );
}
$before_writes = $writes;
AdminKit_Upgrades::run();
if ( $writes !== $before_writes ) { throw new RuntimeException( 'Version 6 cleanup repeated.' ); }
$options = array();
$allowed = false;
$before_writes = $writes;
AdminKit_Upgrades::run();
if ( $writes !== $before_writes ) { throw new RuntimeException( 'Unauthorized upgrade.' ); }
echo "OK: upgrades remove retired data/flags, preserve native settings and are idempotent and permission-gated.\n";
