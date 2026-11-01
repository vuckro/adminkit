<?php
/** Main and legacy navigation screens must load their factories before the host. */
define('ABSPATH',__DIR__.'/');define('ADMINKIT_PATH',dirname(__DIR__).'/');define('ADMINKIT_URL','https://example.invalid/adminkit/');
class AdminKit_Assets { const TOKENS_HANDLE='adminkit-tokens'; }
require ADMINKIT_PATH.'inc/settings/class-settings-page.php';
$scripts=array();$inline=array();
function wp_enqueue_style(...$args) {}
function wp_enqueue_script($handle,$src,$deps,$version,$footer) { global$scripts;$scripts[$handle]=array('src'=>$src,'deps'=>$deps,'version'=>$version,'footer'=>$footer); }
function wp_add_inline_script($handle,$data,$position) { global$inline;$inline[$handle]=array($data,$position); }
function wp_json_encode($value) { return json_encode($value); }
function apply_filters($hook,$value,...$args) { global $customization; return $hook==='adminkit/customization_available' ? ($customization ?? true) : $value; }
function check($ok,$message) { if(!$ok)throw new RuntimeException($message); }
foreach(array('main','menu','toolbar')as$screen){
 $scripts=array();$inline=array();AdminKit_Settings_Page::enqueue_app(array('screen'=>$screen));
 $by_file=array();foreach($scripts as$handle=>$script){$path=substr($script['src'],strlen(ADMINKIT_URL));check(is_file(ADMINKIT_PATH.$path),'Missing runtime asset: '.$path);check($script['footer']&&$script['version'],'Missing footer/cache version');$by_file[$path]=$handle;}
 $controls=$by_file['assets/js/settings/navigation-controls.js'];
 $menu_model=$by_file['assets/js/settings/navigation-menu-model.js'];
 $helpers=array($controls,$by_file['assets/js/settings/navigation-menu.js'],$by_file['assets/js/settings/navigation-toolbar.js']);
 $navigation=$by_file['assets/js/settings/navigation.js'];$host=$by_file['assets/js/settings.js'];
 foreach($helpers as$helper)check(in_array($helper,$scripts[$navigation]['deps'],true),'Navigation host can run before a factory');
 foreach(array_slice($helpers,1)as$helper)check(in_array($controls,$scripts[$helper]['deps'],true),'Editor is missing shared-control dependency');
 check(in_array($menu_model,$scripts[$by_file['assets/js/settings/navigation-menu.js']]['deps'],true),'Menu editor is missing menu-model dependency');
 $seen=array();$visit=function($handle)use(&$visit,&$seen,$scripts){if($handle==='wp-api-fetch')return;check(isset($scripts[$handle]),'Unregistered dependency: '.$handle);if(isset($seen[$handle])){check($seen[$handle]===2,'Cyclic script dependency');return;}$seen[$handle]=1;foreach($scripts[$handle]['deps']as$dep)$visit($dep);$seen[$handle]=2;};$visit($host);
 check(isset($seen[$navigation]),'Loader does not depend on navigation host');
 check($inline[$host][1]==='before'&&strpos($inline[$host][0],'window.AdminKitData=')===0,'Boot data is not attached before loader');
}
$scripts=array();AdminKit_Settings_Page::enqueue_app(array('screen'=>'unknown'));check(!$scripts,'Assets loaded outside supported screens');
echo "OK: main/menu/toolbar asset graphs resolve factories before host, with cache versions and inline boot data.\n";

// Native WordPress submenu entries are content pages, with the existing capability.
$native_pages=array();
class AdminKit_Menu_Manager { public static $enabled=true; public static function is_enabled(){return self::$enabled;} }
class AdminKit_Toolbar_Manager { public static $enabled=true; public static function is_enabled(){return self::$enabled;} }
function __($text,$domain){return $text;}
function add_menu_page($title,$label,$capability,$slug,$callback,$icon,$position){return 'toplevel_page_'.$slug;}
function add_submenu_page($parent,$title,$label,$capability,$slug,$callback){global $native_pages;if($parent===''){return 'admin_page_'.$slug;}$native_pages[$slug]=array($parent,$capability,$callback);return $parent.'_page_'.$slug;}
AdminKit_Settings_Page::add_submenu();
check(count($native_pages)===5,'Expected five native AdminKit content pages');
foreach($native_pages as$page){check($page[0]==='adminkit'&&$page[1]==='manage_options'&&$page[2]===array('AdminKit_Settings_Page','render_page'),'Native page lost parent, capability or shared host');}
$hooks=new ReflectionProperty('AdminKit_Settings_Page','page_tabs');$hooks->setAccessible(true);$registered=$hooks->getValue();
check($registered['adminkit_page_adminkit-navigation']==='navigation'&&$registered['adminkit_page_adminkit-configuration']==='configuration','Native hooks do not route to their own page');
foreach(array(array(false,false),array(true,false),array(false,true),array(true,true))as$enabled){
 AdminKit_Menu_Manager::$enabled=$enabled[0];AdminKit_Toolbar_Manager::$enabled=$enabled[1];
 $native_pages=array();$hooks->setValue(null,array());AdminKit_Settings_Page::add_submenu();
 $expected=$enabled[0]||$enabled[1];
 check(isset($native_pages['adminkit-navigation'])===$expected,'Navigation page visibility ignores enabled editors');
 check(count($native_pages)===($expected?5:4),'Navigation gate affects unrelated settings pages');
 $registered=$hooks->getValue();
 check(isset($registered['adminkit_page_adminkit-navigation'])===$expected,'Disabled navigation retains a registered page hook');
}

// Native 5-page mode slices script enqueues per active page while keeping full graph when pages === null.
$customization=false;$native_pages=array();AdminKit_Settings_Page::add_submenu();
check(!isset($native_pages['adminkit-design'])&&isset($native_pages['adminkit-features']),'Unavailable customization must hide only its own menu entry');
$customization=true;
$pages_map=array('overview'=>'#','features'=>'#','brand'=>'#','navigation'=>'#','configuration'=>'#');
$scripts=array();AdminKit_Settings_Page::enqueue_app(array('screen'=>'main','pages'=>$pages_map,'initialTab'=>'overview'));
check(!isset($scripts['adminkit-settings-navigation-controls'])&&!isset($scripts['adminkit-settings-main-design'])&&!isset($scripts['adminkit-settings-main-features'])&&isset($scripts['adminkit-settings-main'])&&isset($scripts['adminkit-settings']),'Overview page enqueued unused tab factories');
$scripts=array();AdminKit_Settings_Page::enqueue_app(array('screen'=>'main','pages'=>$pages_map,'initialTab'=>'brand'));
check(isset($scripts['adminkit-settings-main-design'])&&isset($scripts['adminkit-settings-main-plugins'])&&!isset($scripts['adminkit-settings-navigation-controls']),'Design page did not slice factories cleanly');
$scripts=array();AdminKit_Settings_Page::enqueue_app(array('screen'=>'main','pages'=>$pages_map,'initialTab'=>'navigation'));
check(isset($scripts['adminkit-settings-navigation-controls'])&&isset($scripts['adminkit-settings-main-navigation'])&&!isset($scripts['adminkit-settings-main-design']),'Navigation page did not load navigation factories cleanly');
echo "OK: native 5-page mode slices per-tab script factories cleanly.\n";
