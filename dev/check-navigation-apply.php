<?php
/** Apply real navigation managers to native-shaped fixtures; no WordPress storage. */
define('ABSPATH',__DIR__.'/');define('ADMINKIT_PATH',dirname(__DIR__).'/');
$admin=true;$allowed=true;$options=array();$writes=0;
function get_option($key,$default=false){global$options;return$options[$key]??$default;}
function update_option($key,$value,$autoload=null){global$options,$writes;$options[$key]=$value;++$writes;return true;}
function current_user_can($cap){global$allowed;return$allowed;}
function apply_filters($hook,$value){return$value;}
function is_admin(){global$admin;return$admin;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function wp_unslash($value){return $value;}
function sanitize_text_field($s){return strip_tags($s);}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
class AdminKit_Settings {const OPTION_KEY='adminkit_settings';public static $values=array();public static function get($key){return self::$values[$key]??false;}public static function register($key,$args){}}
class AdminKit_Settings_Page {const SLUG='adminkit';public static function is_settings_hook($hook){return in_array($hook,array('toplevel_page_adminkit','adminkit_page_adminkit-features','adminkit_page_adminkit-design','adminkit_page_adminkit-navigation','adminkit_page_adminkit-configuration'),true);}}
class AdminKit_Menu_Store {public static $config=array();public static function get_config(){return self::$config;}}
require ADMINKIT_PATH.'inc/class-icons.php';
require ADMINKIT_PATH.'inc/features/menu/class-menu-manager.php';
require ADMINKIT_PATH.'inc/features/toolbar/class-toolbar-config.php';
require ADMINKIT_PATH.'inc/features/toolbar/class-toolbar-snapshot.php';
require ADMINKIT_PATH.'inc/features/toolbar/class-toolbar-renderer.php';
require ADMINKIT_PATH.'inc/features/toolbar/class-toolbar-manager.php';
function check($value,$message){if(!$value)throw new RuntimeException($message);}
function item($slug,$title){return array($title,'read',$slug,$title,'menu-top','','dashicons-menu');}
$menu=array(2=>item('index.php','Dashboard'),5=>item('edit.php','Posts'),10=>item('tools.php','Tools'),81=>item('adminkit','AdminKit'));
$submenu=array('edit.php'=>array(array('All','read','edit.php'),array('Categories','manage_categories','edit-tags.php?taxonomy=category')),'tools.php'=>array(array('Native','read','tools.php')));
AdminKit_Menu_Store::$config=array('top'=>array(array('slug'=>'edit.php','title'=>'Articles','icon'=>'home','hidden'=>false),array('slug'=>'index.php','hidden'=>true),array('slug'=>'adminkit','hidden'=>true),array('slug'=>'custom-separator','type'=>'separator'),array('slug'=>'https://example.test','type'=>'link','title'=>'Custom','icon'=>'home','new_tab'=>true)), 'sub'=>array('tools.php'=>array(array('slug'=>'edit-tags.php?taxonomy=category','title'=>'Moved category','position'=>0,'hidden'=>false))));
AdminKit_Menu_Manager::apply();
check($menu[5][0]==='Articles'&&$menu[5][6]==='none','Menu title/icon override failed');
check(strpos($menu[2][4],'ak-mh')!==false&&strpos($menu[81][4],'ak-mh')===false,'Hidden/protected menu behavior failed');
check($submenu['tools.php'][0][0]==='Moved category'&&$submenu['tools.php'][0][1]==='manage_categories','Move lost capability/title/order');
check(count($submenu['edit.php'])===1,'Moved child remained duplicated');
check(count(array_filter($menu,function($i){return $i[2]==='https://example.test';}))===1,'Custom link not injected');
$pagenow='edit-tags.php';$taxnow='category';$typenow='post';$plugin_page='';
check(AdminKit_Menu_Manager::remap_parent_file('edit.php')==='tools.php','Taxonomy parent highlight failed');
check(AdminKit_Menu_Manager::remap_submenu_file(null)==='edit-tags.php?taxonomy=category','Taxonomy submenu highlight failed');
$options['adminkit_menu_seen']=array('admin-only','edit.php');$allowed=false;$before=$writes;
$m=new ReflectionMethod('AdminKit_Menu_Manager','seen_order');$m->setAccessible(true);$m->invoke(null,array('edit.php'));
check($writes===$before&&$options['adminkit_menu_seen']===array('admin-only','edit.php'),'Restricted user purged administrator history');
$allowed=true;$m->invoke(null,array('edit.php','new-plugin'));check($options['adminkit_menu_seen']===array('edit.php','new-plugin'),'Admin history update failed');
class Fixture_Bar {
 public $nodes=array();
 public function __construct(){foreach(array('a','b','my-account','menu-toggle','child')as$id)$this->add_node(array('id'=>$id,'parent'=>$id==='child'?'a':false,'title'=>$id,'meta'=>array('class'=>'native')));}
 public function get_node($id){return isset($this->nodes[$id])?clone $this->nodes[$id]:null;}
 public function add_node($node){$this->nodes[$node['id']]=(object)$node;}
 public function remove_node($id){unset($this->nodes[$id]);}
}
$options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('order'=>array('b','a'),'hidden'=>array('my-account','menu-toggle'),'titles'=>array('a'=>'Renamed'),'icons'=>array('a'=>'home')), 'frontend'=>array('hidden'=>array('b')));
$bar=new Fixture_Bar();AdminKit_Toolbar_Manager::apply($bar);
check($bar->get_node('my-account')&&$bar->get_node('menu-toggle'),'Protected toolbar nodes were hidden');
check(strpos($bar->get_node('a')->title,'Renamed')!==false&&strpos($bar->get_node('a')->meta['class'],'native')!==false,'Toolbar title/icon lost native metadata');
check($bar->get_node('child')->parent==='a','Toolbar reorder changed child parent');
check(array_slice(array_keys($bar->nodes),-2)===array('b','a'),'Toolbar saved order failed');
$admin=false;$bar=new Fixture_Bar();AdminKit_Toolbar_Manager::apply($bar);check(!$bar->get_node('b')&&$bar->get_node('a')->title==='a','Toolbar context isolation failed');
// Replacement text must be literal in every native markup shape, including dollars/backslashes.
$admin=true;
foreach (array('Price $1', '${2} and $0', 'Path \\1 & <tag> "quote"') as $title) {
 foreach (array(
  '<form id="adminbarsearch"><label>Before</label><input type="submit" value="Before"></form>',
  '<span class="ak-site-name-text">Before</span>',
  '<span class="ab-icon"></span><span class="ab-label">Before</span>',
  '<span class="display-name">Before</span><img src="avatar.png">',
  '<span class="ab-icon"></span><span class="screen-reader-text">Before</span>',
  '<span class="ab-icon"></span>Before',
  'Before'
 ) as $markup) {
  $bar=new Fixture_Bar(); $bar->nodes['a']->title=$markup;
  $options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('titles'=>array('a'=>$title)));
  AdminKit_Toolbar_Manager::apply($bar);
  $actual=$bar->get_node('a')->title;
  check(strpos($actual,esc_html(sanitize_text_field($title)))!==false, 'Toolbar interpreted replacement text: '.$actual);
  check(substr_count($actual,'class="ab-label"')<=1,'Toolbar duplicated label markup');
  if(strpos($markup,'<form')!==false)check(strpos($actual,'value="'.esc_attr(sanitize_text_field($title)).'"')!==false,'Search submit title was corrupted');
 }
}
// The manager remains the stable hook facade; helper classes must not register hooks.
$hooks=array();
function add_action($hook,$callback,$priority=10){global$hooks;$hooks[]=array($hook,$callback,$priority);}
AdminKit_Settings::$values=array('toolbar_manager_enabled'=>true);
AdminKit_Toolbar_Manager::init();
foreach(array(array('admin_bar_menu','capture_frontend',99998),array('admin_bar_menu','apply',99999),array('admin_head','print_css',22),array('wp_head','print_css',22),array('rest_api_init','register_routes',10))as$expected){
 check(in_array(array($expected[0],array('AdminKit_Toolbar_Manager',$expected[1]),$expected[2]),$hooks,true),'Public hook callback/priority changed');
}
$before=count($hooks);AdminKit_Toolbar_Manager::enqueue('unrelated');check(count($hooks)===$before,'Snapshot capture leaked to another screen');
AdminKit_Toolbar_Manager::enqueue('toplevel_page_adminkit');check(end($hooks)===array('admin_bar_menu',array('AdminKit_Toolbar_Manager','capture'),99998),'Admin capture hook changed');
AdminKit_Toolbar_Manager::enqueue('adminkit_page_adminkit-navigation');check(end($hooks)===array('admin_bar_menu',array('AdminKit_Toolbar_Manager','capture'),99998),'Navigation submenu missed pristine toolbar capture');
check(AdminKit_Toolbar_Manager::OPTION==='adminkit_toolbar'&&AdminKit_Toolbar_Manager::SNAPSHOT_KEY==='adminkit_toolbar_nodes'&&AdminKit_Toolbar_Manager::FRONTEND_SNAPSHOT_KEY==='adminkit_toolbar_nodes_frontend','Public storage constants changed');

// Snapshot collection is pristine, context-aware and independent of the saved layout.
define('HOUR_IN_SECONDS',3600);$transients=array();$bar_showing=true;
function get_transient($key){global$transients;return$transients[$key]??false;}
function set_transient($key,$value,$ttl){global$transients;$transients[$key]=$value;}
function delete_transient($key){global$transients;unset($transients[$key]);}
function is_admin_bar_showing(){global$bar_showing;return$bar_showing;}
function wp_strip_all_tags($text){return strip_tags($text);}
function __($text,$domain='default'){return$text;}
function rest_ensure_response($value){return$value;}
class Snapshot_Bar extends Fixture_Bar {public function get_nodes(){return$this->nodes;}}
$bar=new Snapshot_Bar();$bar->add_node(array('id'=>'site-name','parent'=>false,'title'=>'Native site'));
$bar->add_node(array('id'=>'my-account','parent'=>'top-secondary','title'=>'Howdy, Personal Admin Name'));
$bar->add_node(array('id'=>'search','parent'=>'top-secondary','title'=>'<form id="adminbarsearch"><label>Search</label></form>'));
$options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('titles'=>array('site-name'=>'Saved site')));
AdminKit_Toolbar_Manager::capture($bar);
$cached=$transients[AdminKit_Toolbar_Manager::SNAPSHOT_KEY];
check($cached[array_search('site-name',array_column($cached,'id'),true)]['label']==='Native site','Snapshot applied a saved title');
check($cached[array_search('my-account',array_column($cached,'id'),true)]['label']==='My Account','Snapshot leaked personal display name into shared transient');
$before=$options;$response=AdminKit_Toolbar_Manager::rest_get();check($before===$options,'Snapshot read changed persisted layout');
check($response['nodes']===$response['nodesByContext']['admin']&&$response['config']['titles']['site-name']==='Saved site','REST envelope changed');
check(in_array('search',array_column($response['nodesByContext']['frontend'],'id'),true)&&!in_array('menu-toggle',array_column($response['nodesByContext']['frontend'],'id'),true),'Frontend synthesis lost context isolation');
$admin=false;$allowed=false;AdminKit_Toolbar_Manager::capture_frontend($bar);check(!isset($transients[AdminKit_Toolbar_Manager::FRONTEND_SNAPSHOT_KEY]),'Restricted user populated privileged snapshot');
$allowed=true;AdminKit_Toolbar_Manager::capture_frontend($bar);check(isset($transients[AdminKit_Toolbar_Manager::FRONTEND_SNAPSHOT_KEY]),'Authorized frontend capture failed');

// CSS queues move with the renderer, while the public head callback stays stable.
$renderer_overrides=new ReflectionProperty('AdminKit_Toolbar_Renderer','icon_overrides');$renderer_overrides->setAccessible(true);$renderer_overrides->setValue(null,array());
$renderer_ab=new ReflectionProperty('AdminKit_Toolbar_Renderer','ab_item_overrides');$renderer_ab->setAccessible(true);$renderer_ab->setValue(null,array());
$options[AdminKit_Toolbar_Manager::OPTION]=array('frontend'=>array('icons'=>array('site-name'=>'home','search'=>'home','updates'=>'bell','hidden-node'=>'home'),'hidden'=>array('hidden-node')));
ob_start();AdminKit_Toolbar_Manager::print_css();$head_css=ob_get_clean();
check(strpos($head_css,'id="adminkit-toolbar-manager"')!==false&&strpos($head_css,'#wp-admin-bar-site-name')!==false&&strpos($head_css,'#adminbarsearch::before')!==false&&strpos($head_css,'#wp-admin-bar-updates')!==false&&strpos($head_css,'hidden-node')===false,'Head print_css() failed before admin_bar_menu::apply()');
$options[AdminKit_Toolbar_Manager::OPTION]=array('frontend'=>array('icons'=>array('site-name'=>'home','search'=>'home')));
AdminKit_Toolbar_Manager::apply($bar);ob_start();AdminKit_Toolbar_Manager::print_css();$css=ob_get_clean();
check(strpos($css,'id="adminkit-toolbar-manager"')!==false&&strpos($css,'#wp-admin-bar-site-name')!==false&&strpos($css,'#adminbarsearch::before')!==false&&strpos($css,'max-width:782px')!==false,'Renderer lost node CSS, search form or mobile rules');
$bar_showing=false;ob_start();AdminKit_Toolbar_Manager::print_css();check(ob_get_clean()==='','Icon CSS leaked without a toolbar');
AdminKit_Toolbar_Manager::flush_snapshots();check(!$transients,'Snapshot invalidation missed a context');
AdminKit_Settings::$values=array();
echo "OK: real menu/toolbar apply, protected nodes, stable children, metadata, taxonomy highlights, history authorization and context isolation.\n";

// The new native AdminKit pages still own the pristine editor snapshot.
$screen_check=new ReflectionMethod('AdminKit_Menu_Manager','is_menu_editor_screen');$screen_check->setAccessible(true);
$admin=true;foreach(array('adminkit','adminkit-features','adminkit-design','adminkit-navigation','adminkit-configuration')as$page){$_GET['page']=$page;check($screen_check->invoke(null),'Missing editor snapshot on '.$page);}
$_GET['page']='other-plugin';check(!$screen_check->invoke(null),'Snapshot escaped AdminKit pages');unset($_GET['page']);
