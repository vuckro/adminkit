<?php
/** Apply real navigation managers to native-shaped fixtures; no WordPress storage. */
define('ABSPATH',__DIR__.'/');define('ADMINKIT_PATH',dirname(__DIR__).'/');
$admin=true;$allowed=true;$options=array();$writes=0;
function get_option($key,$default=false){global$options;return$options[$key]??$default;}
function update_option($key,$value,$autoload=null){global$options,$writes;$options[$key]=$value;++$writes;return true;}
function current_user_can($cap){global$allowed;return$allowed;}
function apply_filters($hook,$value){return$value;}
function is_admin(){global$admin;return$admin;}
function sanitize_text_field($s){return strip_tags($s);}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
class AdminKit_Settings {public static function get($key){return false;}}
class AdminKit_Settings_Page {const SLUG='adminkit';}
class AdminKit_Menu_Store {public static $config=array();public static function get_config(){return self::$config;}}
require ADMINKIT_PATH.'inc/class-icons.php';
require ADMINKIT_PATH.'inc/features/menu/class-menu-manager.php';
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
echo "OK: real menu/toolbar apply, protected nodes, stable children, metadata, taxonomy highlights, history authorization and context isolation.\n";
