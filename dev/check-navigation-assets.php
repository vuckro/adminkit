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
function check($ok,$message) { if(!$ok)throw new RuntimeException($message); }
foreach(array('main','menu','toolbar')as$screen){
 $scripts=array();$inline=array();AdminKit_Settings_Page::enqueue_app(array('screen'=>$screen));
 $by_file=array();foreach($scripts as$handle=>$script){$path=substr($script['src'],strlen(ADMINKIT_URL));check(is_file(ADMINKIT_PATH.$path),'Missing runtime asset: '.$path);check($script['footer']&&$script['version'],'Missing footer/cache version');$by_file[$path]=$handle;}
 $controls=$by_file['assets/js/settings/navigation-controls.js'];
 $helpers=array($controls,$by_file['assets/js/settings/navigation-menu.js'],$by_file['assets/js/settings/navigation-toolbar.js']);
 $navigation=$by_file['assets/js/settings/navigation.js'];$host=$by_file['assets/js/settings.js'];
 foreach($helpers as$helper)check(in_array($helper,$scripts[$navigation]['deps'],true),'Navigation host can run before a factory');
 foreach(array_slice($helpers,1)as$helper)check(in_array($controls,$scripts[$helper]['deps'],true),'Editor is missing shared-control dependency');
 $seen=array();$visit=function($handle)use(&$visit,&$seen,$scripts){if($handle==='wp-api-fetch')return;check(isset($scripts[$handle]),'Unregistered dependency: '.$handle);if(isset($seen[$handle])){check($seen[$handle]===2,'Cyclic script dependency');return;}$seen[$handle]=1;foreach($scripts[$handle]['deps']as$dep)$visit($dep);$seen[$handle]=2;};$visit($host);
 check(isset($seen[$navigation]),'Loader does not depend on navigation host');
 check($inline[$host][1]==='before'&&strpos($inline[$host][0],'window.AdminKitData=')===0,'Boot data is not attached before loader');
}
$scripts=array();AdminKit_Settings_Page::enqueue_app(array('screen'=>'unknown'));check(!$scripts,'Assets loaded outside supported screens');
echo "OK: main/menu/toolbar asset graphs resolve factories before host, with cache versions and inline boot data.\n";
