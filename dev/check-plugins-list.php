<?php
/** Independent plugin landing/sorting options, without loading WordPress or writing storage. */
define('ABSPATH',__DIR__.'/');
class AdminKit_Settings {
 public static $values=array('active_items_first_enabled'=>true,'active_items_first_plugins_default'=>true,'active_items_first_sort'=>true);
 public static function get($key){return self::$values[$key]??false;}
}
class WP_Plugins_List_Table { public $items=array(); }
class Plugins_Test_Redirect extends RuntimeException {}
$actions=array();$referer='';$filters=array();$active=array('active.php','active2.php');$network_admin=false;
function apply_filters($hook,$value){global$filters;return $filters[$hook]??$value;}
function add_action($hook,$callback){global$actions;$actions[]=$hook;}
function wp_get_referer(){global$referer;return $referer;}
function is_multisite(){return false;}
function is_network_admin(){global$network_admin;return $network_admin;}
function get_option($key,$default=array()){global$active;return $key==='active_plugins'?$active:$default;}
function is_plugin_active($file){global$active;return in_array($file,$active,true);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower($v));}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return $v;}
function absint($v){return abs((int)$v);}
function self_admin_url($path){return 'http://test/wp-admin/'.$path;}
function add_query_arg($key,$value,$url=null){if(is_array($key)){return $value.'?'.http_build_query($key);}return $url.'?'.http_build_query(array($key=>$value));}
function wp_safe_redirect($url){throw new Plugins_Test_Redirect($url);}
require dirname(__DIR__).'/inc/wp-core/class-plugins-list.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$_SERVER['REQUEST_METHOD']='GET';$_GET=array();
try{AdminKit_Plugins_List::on_plugins_screen();throw new RuntimeException('Active landing missing');}catch(Plugins_Test_Redirect$e){check(strpos($e->getMessage(),'plugin_status=active')!==false,'Wrong default landing');}
// Explicit `plugin_status=all` must work even with Referrer-Policy: no-referrer ($referer === '').
$referer='';$_GET=array('plugin_status'=>'all');AdminKit_Plugins_List::on_plugins_screen();
// Network Admin must never redirect to site-active view.
$network_admin=true;$_GET=array();$actions=array();AdminKit_Plugins_List::on_plugins_screen();check(!$actions,'Network admin must skip site plugin list overrides');
$network_admin=false;
AdminKit_Settings::$values['active_items_first_plugins_default']=false;
foreach(array(array(),array('activate'=>'true','plugin_status'=>'inactive'),array('deactivate'=>'true','plugin_status'=>'active'))as$params){$_GET=$params;$actions=array();AdminKit_Plugins_List::on_plugins_screen();check($actions===array('pre_current_active_plugins'),'Landing off must retain sorting only');}
$_GET=array();$wp_list_table=new WP_Plugins_List_Table();$original=array('off.php'=>array(),'active.php'=>array(),'off2.php'=>array(),'active2.php'=>array());$wp_list_table->items=$original;
AdminKit_Plugins_List::sort_active_first();check(array_keys($wp_list_table->items)===array('active.php','active2.php','off.php','off2.php'),'Grouping/order within each group changed');
foreach(array(array('orderby'=>'Name'),array('order'=>'desc'),array('s'=>'query'),array('plugin_status'=>'inactive'))as$params){$_GET=$params;$wp_list_table->items=$original;AdminKit_Plugins_List::sort_active_first();check($wp_list_table->items===$original,'Explicit sort/search/status changed');}
$_GET=array();AdminKit_Settings::$values['active_items_first_sort']=false;$wp_list_table->items=$original;AdminKit_Plugins_List::sort_active_first();check($wp_list_table->items===$original,'Sort off still grouped plugins');
AdminKit_Settings::$values['active_items_first_plugins_default']=true;
try{AdminKit_Plugins_List::on_plugins_screen();throw new RuntimeException('Sort off also disabled Active landing');}catch(Plugins_Test_Redirect$expected){}
foreach(array('activate'=>'active','deactivate'=>'all')as$flag=>$target){$_GET=array($flag=>'true','plugin_status'=>$target==='active'?'inactive':'active');try{AdminKit_Plugins_List::on_plugins_screen();throw new RuntimeException('Post-action follow missing');}catch(Plugins_Test_Redirect$e){check(strpos($e->getMessage(),'plugin_status='.$target)!==false,'Wrong post-action target');}}
AdminKit_Settings::$values['active_items_first_enabled']=false;$_GET=array();$actions=array();AdminKit_Plugins_List::on_plugins_screen();check(!$actions,'Master off registered sorting');
AdminKit_Settings::$values['active_items_first_enabled']=true;$filters=array('adminkit/plugins_active_first/enabled'=>false);AdminKit_Plugins_List::on_plugins_screen();check(!$actions,'Historical plugin gate ignored');
echo "OK: independent plugin landing/sorting, post-action follow, explicit user views, stable grouping and master/filter gates.\n";
