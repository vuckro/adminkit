<?php
/** An explicit global veto leaves WP-Core outputs, assets and write entrypoints inert. */
define('ABSPATH',__DIR__.'/');define('ADMINKIT_PATH',dirname(__DIR__).'/');
$admin=true;$allow=false;$screen=(object)array('id'=>'profile','base'=>'profile','post_type'=>'post');
function is_admin(){global$admin;return$admin;}
function apply_filters($hook,$value){global$allow;return$hook==='adminkit/should_load'?$allow:$value;}
function get_current_screen(){global$screen;return$screen;}
function current_user_can(...$args){return true;}
function is_admin_bar_showing(){return true;}
function is_multisite(){return false;}
function add_action(...$args){}
function add_filter(...$args){}
function wp_parse_args($args,$defaults){return array_merge($defaults,$args);}
function wp_enqueue_style(...$args){throw new RuntimeException('Style escaped global veto');}
function wp_enqueue_script(...$args){throw new RuntimeException('Script escaped global veto');}
function wp_add_inline_style(...$args){throw new RuntimeException('Inline style escaped global veto');}
function wp_add_inline_script(...$args){throw new RuntimeException('Inline script escaped global veto');}
function get_current_user_id(){return 1;}
function __($s,$domain){return$s;}
class Gate_Denial extends RuntimeException{}
function wp_send_json_error($data,$status){if($status!==403)throw new RuntimeException('Wrong veto status');throw new Gate_Denial();}
class AdminKit_Settings {public static function get($key){return true;}public static function register(...$args){}}
require ADMINKIT_PATH.'inc/class-screen.php';
require ADMINKIT_PATH.'inc/class-assets.php';
foreach(glob(ADMINKIT_PATH.'inc/wp-core/class-*.php')as$file)require_once$file;
foreach(array('AdminKit_User_Quick_Edit','AdminKit_Username_Changer')as$class)$class::init();
$registry=new ReflectionProperty('AdminKit_Assets','registry');$registry->setAccessible(true);
$registered=array_column($registry->getValue(),'handle');
foreach(array('adminkit-user-quick-edit','adminkit-username-changer')as$handle){if(!in_array($handle,$registered,true))throw new RuntimeException('WP-Core style not registered: '.$handle);}
$callbacks=array(
 array('AdminKit_User_Quick_Edit','enqueue',array('users.php')),
 array('AdminKit_User_Quick_Edit','print_form_template',array()),
 array('AdminKit_Username_Changer','enqueue',array('profile.php')),
 array('AdminKit_Username_Changer','validate',array(new stdClass(),true,(object)array('ID'=>1))),
 array('AdminKit_Username_Changer','apply',array(1,new stdClass())),
 array('AdminKit_Core_Admin_Bar','enqueue',array()),
 array('AdminKit_Core_Reading_Layout','enqueue',array()),
 array('AdminKit_Core_Options_General','enqueue',array()),
 array('AdminKit_Core_Options_General','print_prepaint',array()),
 array('AdminKit_Core_Options_Discussion','enqueue',array()),
 array('AdminKit_Core_Options_Discussion','print_prepaint',array()),
 array('AdminKit_Profile_Account','enqueue',array()),
 array('AdminKit_Profile_Account','print_prepaint',array()),
 array('AdminKit_Core_List_Table_Chrome','enqueue',array()),
 array('AdminKit_Core_Branding','print_admin',array()),
 array('AdminKit_Core_Branding','print_frontend',array()),
 array('AdminKit_Core_Chrome','clean_chrome_css',array('admin')),
 array('AdminKit_Post_Previews','maybe_hook_screen',array((object)array('base'=>'edit','post_type'=>'post'))),
 array('AdminKit_Post_Previews','hook_inline_save',array()),
 array('AdminKit_Content_List','on_content_screen',array()),
 array('AdminKit_Plugins_List','on_plugins_screen',array())
);
foreach($callbacks as$callback){ob_start();call_user_func_array(array($callback[0],$callback[1]),$callback[2]);$out=ob_get_clean();if($out!=='')throw new RuntimeException('Markup escaped veto: '.implode('::',array_slice($callback,0,2)));}
if(AdminKit_Core_Login::filter_header_url('native')!=='native'||AdminKit_Core_Branding::logo_upload_mimes(array())!==array())throw new RuntimeException('Login/upload modification escaped veto');
try{AdminKit_User_Quick_Edit::handle_save();throw new RuntimeException('Vetoed AJAX did not deny');}catch(Gate_Denial $expected){}
foreach(array(true,false)as$admin)AdminKit_Assets::enqueue_script('test','missing.js');
foreach(glob(ADMINKIT_PATH.'inc/wp-core/class-*.php')as$file){if(preg_match('/\bwp_enqueue_(?:style|script)\s*\(/',file_get_contents($file)))throw new RuntimeException('WP-Core bypasses Assets: '.$file);}
echo "OK: WP-Core vetoed callbacks emit no markup/assets/writes; user tools register styles through Assets.\n";
