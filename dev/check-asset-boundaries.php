<?php
/** Exercise actual core registrations and dispatch across screens and contexts. */
define('ABSPATH',__DIR__.'/');
define('ADMINKIT_PATH',dirname(__DIR__).'/');
define('ADMINKIT_URL','https://example.invalid/adminkit/');
define('ADMINKIT_VERSION','1.0.0-beta.1');
$styles=array();$scripts=array();$native_styles=array();$native_scripts=array();$bar=false;$allow=true;$appearance=true;
$screen=(object)array('id'=>'dashboard','base'=>'dashboard');
function wp_parse_args($args,$defaults){return array_merge($defaults,$args);}
function apply_filters($hook,$value,...$args){global $allow,$appearance;return 'adminkit/should_load'===$hook?$allow:('adminkit/appearance_enabled'===$hook?$appearance:$value);}
function add_action(...$args){}
function do_action(...$args){}
function wp_enqueue_style($handle,...$args){global $styles;$styles[$handle]=$args;}
function wp_register_style($handle,...$args){}
function wp_style_is($handle,$state){global $native_styles,$styles;return isset($styles[$handle])||in_array($handle,$native_styles,true);}
function wp_script_is($handle,$state){global $native_scripts;return in_array($handle,$native_scripts,true);}
function is_admin_bar_showing(){global $bar;return $bar;}
class AdminKit_Settings {public static function get($key){return false;}}
class AdminKit_Screen {public static function get(){return $GLOBALS['screen'];}}
require ADMINKIT_PATH.'inc/class-assets.php';
require ADMINKIT_PATH.'inc/wp-core/class-chrome.php';
AdminKit_Core_Chrome::register();
function check_boundary($value,$message){if(!$value)throw new RuntimeException($message);}
foreach(array('dashboard','edit-post','plugins','settings_page_adminkit') as $id){
 $styles=array();$native_styles=array();$native_scripts=array();$screen=(object)array('id'=>$id,'base'=>$id);
 AdminKit_Assets::dispatch_admin();
 check_boundary(!isset($styles['adminkit-media'])&&!isset($styles['adminkit-shared-thickbox']),'Unused dialogs loaded on '.$id);
 check_boundary(!isset($styles['adminkit-profile']),'Profile CSS leaked onto '.$id);
}
foreach(array('upload','media')as$id){$styles=array();$screen=(object)array('id'=>$id,'base'=>$id);AdminKit_Assets::dispatch_admin();check_boundary(isset($styles['adminkit-media']),'Native library lost its skin');}
$screen=(object)array('id'=>'host_page_custom','base'=>'host_page_custom');
foreach(array('style','script')as$type){
 $styles=array();$native_styles='style'===$type?array('media-views','thickbox'):array();$native_scripts='script'===$type?array('media-views','thickbox'):array();
 AdminKit_Assets::dispatch_admin();
 check_boundary(isset($styles['adminkit-media'],$styles['adminkit-shared-thickbox']),'Host-enqueued dialogs lost their skin');
}
$styles=array();$native_styles=array();$native_scripts=array();AdminKit_Assets::dispatch_frontend();check_boundary(!$styles,'Anonymous frontend emitted styles');
$bar=true;AdminKit_Assets::dispatch_frontend();check_boundary(isset($styles['adminkit-adminbar'])&&!isset($styles['adminkit-chrome'])&&!isset($styles['adminkit-media']),'Frontend loaded admin-screen content');
$styles=array();$allow=false;AdminKit_Assets::dispatch_admin();check_boundary(!$styles,'Global veto bypassed by core styles');
echo "OK: core assets respect screen, native dialog presence, anonymous frontend and global veto boundaries.\n";
