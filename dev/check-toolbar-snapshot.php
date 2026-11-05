<?php
/** Snapshot fallback must restore exactly its hooks, including host exceptions. */
$fixture=sys_get_temp_dir().'/adminkit-snapshot-'.bin2hex(random_bytes(5));mkdir($fixture.'/wp-includes',0700,true);
file_put_contents($fixture.'/wp-includes/class-wp-admin-bar.php','<?php class WP_Admin_Bar {public function initialize(){} public function add_menus(){} public function get_nodes(){return array();}}');
register_shutdown_function(static function()use($fixture){unlink($fixture.'/wp-includes/class-wp-admin-bar.php');rmdir($fixture.'/wp-includes');rmdir($fixture);});
define('ABSPATH',$fixture.'/');define('WPINC','wp-includes');
$hooks=array();$fail=false;$allowed=true;$writes=0;
function key_for($callback){return implode('::',$callback);}
function add_action($hook,$callback,$priority=10){global$hooks;$hooks[$hook][key_for($callback)]=$priority;}
function remove_action($hook,$callback,$priority){global$hooks;if(($hooks[$hook][key_for($callback)]??false)===$priority)unset($hooks[$hook][key_for($callback)]);}
function has_action($hook,$callback){global$hooks;return$hooks[$hook][key_for($callback)]??false;}
function do_action($hook,$bar){global$fail;if($fail)throw new RuntimeException('Host failed');}
function apply_filters($hook,$value){return$value;}
function current_user_can($cap){global$allowed;return$allowed;}
function set_transient(...$args){global$writes;++$writes;}
class AdminKit_Settings {public static function get($key){return false;}}
require dirname(__DIR__).'/inc/features/toolbar/class-toolbar-config.php';
require dirname(__DIR__).'/inc/features/toolbar/class-toolbar-snapshot.php';
function check_snapshot($ok,$message){if(!$ok)throw new RuntimeException($message);}
add_action('admin_bar_menu',array('AdminKit_Toolbar_Manager','apply'),137);
$before=$hooks;$method=new ReflectionMethod('AdminKit_Toolbar_Snapshot','snapshot_nodes');$method->setAccessible(true);
check_snapshot($method->invoke(null)===array()&&$before===$hooks,'Snapshot changed hook policy on success');
$fail=true;try{$method->invoke(null);throw new RuntimeException('Expected host failure');}catch(RuntimeException $error){check_snapshot($error->getMessage()==='Host failed','Unexpected host error');}
check_snapshot($before===$hooks,'Snapshot did not restore hooks after host exception');
$allowed=false;AdminKit_Toolbar_Snapshot::capture(new WP_Admin_Bar());check_snapshot($writes===0,'Unauthorized request populated shared toolbar snapshot');
echo "OK: snapshot hook priorities restored on success/failure, absent hooks remain absent and capture requires capability.\n";
