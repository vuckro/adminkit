<?php
/** Native appearance keeps tool assets without global styles or theme bootstrap. */
define('ABSPATH', __DIR__.'/');
define('ADMINKIT_PATH', dirname(__DIR__).'/');
define('ADMINKIT_URL', 'https://example.invalid/adminkit/');
define('ADMINKIT_VERSION','1.0.0');
$appearance=false;$allow=true;$styles=array();$scripts=array();
function apply_filters($hook,$value,...$args){global$appearance,$allow;return $hook==='adminkit/appearance_enabled'?$appearance:($hook==='adminkit/should_load'?$allow:$value);}
function wp_parse_args($args,$defaults){return array_merge($defaults,$args);}
function is_admin(){return true;}
function current_action(){return 'admin_head';}
function wp_enqueue_style($handle,$src=null,$deps=array(),...$args){global$styles;$styles[$handle]=$deps;}
function wp_enqueue_script($handle,...$args){global$scripts;$scripts[]=$handle;}
function do_action(...$args){}
function check($value,$message){if(!$value)throw new RuntimeException($message);}
class AdminKit_Screen {public static function get(){return null;}}
require ADMINKIT_PATH.'inc/class-assets.php';
require ADMINKIT_PATH.'inc/class-theme-toggle.php';
AdminKit_Assets::register(array('handle'=>'skin','src'=>'assets/css/wp-core/chrome.css'));
AdminKit_Assets::register(array('handle'=>'tool','src'=>'assets/css/wp-core/user-quick-edit.css','deps'=>array(AdminKit_Assets::TOKENS_HANDLE),'appearance'=>false));
AdminKit_Assets::dispatch_admin();
check(isset($styles['tool'], $styles[AdminKit_Assets::COMPONENTS_HANDLE]),'Native mode lost tool CSS or scoped variables');
check($styles['tool']===array(AdminKit_Assets::COMPONENTS_HANDLE),'Native tool CSS still depends on global skin');
check(!isset($styles['skin'], $styles[AdminKit_Assets::TOKENS_HANDLE]),'Native mode loaded global appearance');
check(AdminKit_Assets::add_admin_body_class('wp-admin')==='wp-admin','Native mode carries modern body class');
AdminKit_Assets::enqueue_script('tool-js','assets/js/wp-core/user-quick-edit.js');
check($scripts===array('tool-js'),'Native mode lost feature JavaScript');
ob_start();AdminKit_Theme_Toggle::print_script();$out=ob_get_clean();check($out==='','Native mode emitted global theme bootstrap');
$styles=array();$scripts=array();$allow=false;AdminKit_Assets::dispatch_admin();AdminKit_Assets::enqueue_script('blocked','missing.js');
check(!$styles&&!$scripts,'Broad external veto failed to block tools');
echo "OK: classic appearance preserves scoped tool CSS/JS without global skin, theme bootstrap or veto bypass.\n";
