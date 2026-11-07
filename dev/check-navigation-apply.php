<?php
/** Apply real navigation managers to native-shaped fixtures; no WordPress storage. */
define('ABSPATH',__DIR__.'/');define('ADMINKIT_PATH',dirname(__DIR__).'/');
$admin=true;$allowed=true;$options=array();$writes=0;
function get_option($key,$default=false){global$options;return$options[$key]??$default;}
function update_option($key,$value,$autoload=null){global$options,$writes;$options[$key]=$value;++$writes;return true;}
function current_user_can($cap){global$allowed,$allowed_caps;return is_array($allowed_caps)?in_array($cap,$allowed_caps,true):$allowed;}
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
 public function get_nodes(){return $this->nodes;}
 public function get_node($id){return isset($this->nodes[$id])?clone $this->nodes[$id]:null;}
 public function add_node($node){$this->nodes[$node['id']]=(object)$node;}
 public function remove_node($id){unset($this->nodes[$id]);}
}
$options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('order'=>array('b','a'),'hidden'=>array('my-account','menu-toggle'),'titles'=>array('a'=>'Renamed'),'icons'=>array('a'=>'home')), 'frontend'=>array('hidden'=>array('b')));
$bar=new Fixture_Bar();AdminKit_Toolbar_Manager::apply($bar);
check($bar->get_node('my-account')&&$bar->get_node('menu-toggle'),'Protected toolbar nodes were hidden');
check(strpos($bar->get_node('a')->title,'Renamed')!==false&&strpos($bar->get_node('a')->meta['class'],'native')!==false,'Toolbar title/icon lost native metadata');
check($bar->get_node('child')->parent==='a','Toolbar reorder changed child parent');
check(array_keys(array_filter($bar->nodes,function($node){return empty($node->parent);}))===array('b','a','my-account','menu-toggle'),'New context actions must follow saved toolbar order');
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

// Count bubbles (including nested spans) survive custom renames while HTML in custom titles is escaped.
$badged='Plugins <span class="update-plugins count-3"><span class="plugin-count">3</span></span>';
$renamed=AdminKit_Menu_Manager::apply_menu_title($badged,'Extensions <script>');
check($renamed==='Extensions &lt;script&gt; <span class="update-plugins count-3"><span class="plugin-count">3</span></span>','Menu rename lost nested count bubble or failed to escape HTML');

// Duplicate child slugs across parents stay disambiguated, and inaccessible target parents fall back safely.
$allowed=false;
$menu=array(5=>array('Posts','edit_posts','edit.php','Posts','menu-top','','dashicons-admin-post'),60=>array('Appearance','switch_themes','themes.php','Appearance','menu-top','','dashicons-admin-appearance'),80=>array('Settings','manage_options','options-general.php','Settings','menu-top','','dashicons-admin-settings'));
$submenu=array('edit.php'=>array(array('All','edit_posts','edit.php'),array('Shared','edit_posts','customize.php'),array('Categories','manage_categories','edit-tags.php?taxonomy=category')),'themes.php'=>array(array('Customize','edit_theme_options','customize.php')));
AdminKit_Menu_Store::$config=array('top'=>array(),'sub'=>array('edit.php'=>array(array('slug'=>'customize.php','title'=>'Post Custom','position'=>0,'hidden'=>false)),'themes.php'=>array(array('slug'=>'customize.php','title'=>'Theme Custom','position'=>0,'hidden'=>false)),'options-general.php'=>array(array('slug'=>'edit-tags.php?taxonomy=category','title'=>'Cats','position'=>0,'hidden'=>false))));
AdminKit_Menu_Manager::apply();
check($submenu['edit.php'][0][0]==='Post Custom'&&$submenu['themes.php'][0][0]==='Theme Custom','Duplicate child slugs across parents collided');
check(empty($submenu['options-general.php'])&&count($submenu['edit.php'])===3,'Submenu moved into parent inaccessible to current role');
$allowed=true;

// Native top-level pages can become second-level pages, retaining capabilities and children.
$menu=array(10=>item('plugins.php','Plugins'),20=>array('Weglot','manage_options','weglot','Weglot','menu-top','','dashicons-translation'),81=>item('adminkit','AdminKit'));
$submenu=array('plugins.php'=>array(array('Installed','activate_plugins','plugins.php')),'weglot'=>array(array('Weglot','manage_options','weglot'),array('Languages','manage_options','weglot-languages')));
AdminKit_Menu_Store::$config=array('top'=>array(array('slug'=>'plugins.php')),'sub'=>array('plugins.php'=>array(array('slug'=>'plugins.php','position'=>0),array('slug'=>'weglot','title'=>'Translate','position'=>1),array('slug'=>'weglot-languages','position'=>2))));
AdminKit_Menu_Manager::apply();
check(!isset($menu[20])&&!isset($submenu['weglot']),'Demoted parent remained at first level');
check(count($submenu['plugins.php'])===3,'Demotion lost or duplicated a child');
check($submenu['plugins.php'][1][0]==='Translate'&&$submenu['plugins.php'][1][1]==='manage_options','Demotion changed title/capability');
$plugin_page='weglot';$pagenow='admin.php';
check(AdminKit_Menu_Manager::remap_parent_file('weglot')==='plugins.php','Demoted plugin parent highlight failed');
check(AdminKit_Menu_Manager::remap_submenu_file(null)==='weglot','Demoted plugin child highlight failed');
// Missing/inaccessible destinations and protected AdminKit remain reachable at first level.
$allowed=false;
$menu=array(20=>item('weglot','Weglot'),81=>item('adminkit','AdminKit'));
$submenu=array();
AdminKit_Menu_Store::$config=array('top'=>array(),'sub'=>array('plugins.php'=>array(array('slug'=>'weglot'),array('slug'=>'adminkit'))));
AdminKit_Menu_Manager::apply();
check(isset($menu[20],$menu[81]),'Invalid target removed an accessible menu');
$allowed=true;

// Multisite preservation must recognize a moved row instead of resurrecting its former level.
AdminKit_Menu_Store::$config=array('top'=>array(array('slug'=>'weglot','type'=>'item')),'sub'=>array());
$moved=array(array('slug'=>'weglot','parent'=>'plugins.php','position'=>0));
check(count(AdminKit_Menu_Config::preserve_invisible_multisite_items($moved))===1,'Multisite resurrected a demoted top-level menu');
AdminKit_Menu_Store::$config=array('top'=>array(),'sub'=>array('plugins.php'=>array(array('slug'=>'weglot'))));
$promoted=array(array('slug'=>'weglot','parent'=>'','position'=>0));
check(count(AdminKit_Menu_Config::preserve_invisible_multisite_items($promoted))===1,'Multisite resurrected a promoted submenu');

// Only top-level toolbar items are exposed; native dropdowns remain owned by WordPress.
$bar=new Fixture_Bar();
$bar->add_node(array('id'=>'plugin-root','parent'=>false,'title'=>'Plugin'));
$bar->add_node(array('id'=>'plugin-settings','parent'=>'plugin-root','title'=>'Settings'));
$bar->add_node(array('id'=>'user-info','parent'=>'my-account','title'=>'Private identity'));
AdminKit_Toolbar_Manager::capture($bar);
$captured=$transients[AdminKit_Toolbar_Manager::SNAPSHOT_KEY];
check(in_array('plugin-root',array_column($captured,'id'),true),'Top-level toolbar customization disappeared');
check(!in_array('plugin-settings',array_column($captured,'id'),true),'Unsupported dropdown editing remains exposed');
check(!in_array('user-info',array_column($captured,'id'),true),'Personal identity entered shared toolbar snapshots');
$bar_showing=true;$admin=false;
$options[AdminKit_Toolbar_Manager::OPTION]=array('frontend'=>array('icons'=>array('plugin-root'=>'translation'),'titles'=>array('plugin-root'=>'Configure')));
AdminKit_Toolbar_Manager::apply($bar);
check(strpos($bar->get_node('plugin-root')->title,'Configure')!==false,'Top-level renaming failed');
check($bar->get_node('plugin-settings')->title==='Settings'&&$bar->get_node('plugin-settings')->parent==='plugin-root','Native dropdown was modified');
ob_start();AdminKit_Toolbar_Manager::print_css();$css=ob_get_clean();
check(strpos($css,'#wp-admin-bar-plugin-root > .ab-item::before{content:none')!==false,'Native plugin decoration was not suppressed');
check(strpos($css,'> .ab-item > .ab-icon')!==false,'Custom parent icon could leak into dropdown children');

check(strpos($css,'#wp-admin-bar-plugin-root > .ab-item{background-image:none !important}')!==false,'Native background-image logo survived custom icon replacement');

check(strpos($css,'#wp-admin-bar-plugin-root > .ab-item{padding-inline:calc(8px * var(--ak-space-scale,1)) !important}')!==false,'Native logo spacing survived custom replacement');

// Duplicate registered routes and conflicting legacy rows use one shared policy.
$native_menu=array(10=>item('options-general.php','Settings'),20=>item('plugins.php','Plugins'));
$native_sub=array('options-general.php'=>array(array('Slim SEO','manage_options','slim-seo'),array('Slim SEO Pro','manage_options','slim-seo')),'plugins.php'=>array(array('Installed','activate_plugins','plugins.php')));
$legacy=array('top'=>array(),'sub'=>array('plugins.php'=>array(array('slug'=>'slim-seo','hidden'=>true,'position'=>2),array('slug'=>'slim-seo','hidden'=>false,'title'=>'SEO','position'=>3))));
$normalized=AdminKit_Menu_Config::normalize($legacy);
check(count($normalized['sub']['plugins.php'])===1&&!$normalized['sub']['plugins.php'][0]['hidden'],'Legacy policy is not last-row-wins');
$menu=$native_menu;$submenu=$native_sub;AdminKit_Menu_Store::$config=$legacy;
AdminKit_Menu_Manager::apply();
$seo=array_values(array_filter($submenu['plugins.php'],function($row){return$row[2]==='slim-seo';}));
check(count($seo)===1&&$seo[0][0]==='SEO'&&strpos($seo[0][4]??'','ak-mh')===false,'Editor policy and rendered SEO route diverged');
check(empty($submenu['options-general.php']),'Moved duplicate left a source entry');
$legacy['sub']['plugins.php'][1]['hidden']=true;$menu=$native_menu;$submenu=$native_sub;AdminKit_Menu_Store::$config=$legacy;AdminKit_Menu_Manager::apply();
$seo=array_values(array_filter($submenu['plugins.php'],function($row){return$row[2]==='slim-seo';}));
check(count($seo)===1&&strpos($seo[0][4],'ak-mh')!==false,'Hidden policy not applied to deduplicated route');
$snapshot=AdminKit_Menu_Snapshot::build_snapshot($native_menu,$native_sub);
check(count($snapshot[0]['children'])===1,'Editor snapshot repeats a plugin route');
// The planner must be pure and must not move ambiguous shared routes by map order.
$shared=array('a'=>array(array('Shared A','read','shared')),'b'=>array(array('Shared B','read','shared')),'c'=>array(array('Shared C','read','shared')));
$config=array('top'=>array(),'sub'=>array('a'=>array(array('slug'=>'shared','title'=>'A','position'=>0)),'b'=>array(array('slug'=>'shared','title'=>'B','position'=>0))));
$copy=$shared;$plan=AdminKit_Menu_Layout::plan(array(),$shared,$config,array('a'=>true,'b'=>true,'c'=>true),'current_user_can');
check($shared===$copy&&count($plan['children'])===3&&empty($plan['moves']),'Pure layout moved an ambiguous native route');
$reversed=$config;$reversed['sub']=array_reverse($config['sub'],true);
check($plan===AdminKit_Menu_Layout::plan(array(),$shared,$reversed,array('a'=>true,'b'=>true,'c'=>true),'current_user_can'),'Config parent iteration order changes route placement');
echo "OK: unique route identity, legacy conflicts, hidden state, pristine planning and ambiguous-parent fallback.\n";

$menu=array(1=>item('a','A'),2=>item('b','B'),3=>item('c','C'));$submenu=$shared;AdminKit_Menu_Store::$config=$config;AdminKit_Menu_Manager::apply();
check(count($submenu['a'])===1&&count($submenu['b'])===1&&count($submenu['c'])===1,'Renderer moved ambiguous shared routes after planning');

$menu=$native_menu;$submenu=$native_sub;AdminKit_Menu_Store::$config=array('top'=>array(),'sub'=>array());AdminKit_Menu_Manager::apply();
check(count($submenu['options-general.php'])===1,'Duplicate native route returned with an empty saved layout');

// Groups and dropdown children are never editable, even via crafted saved layouts.
$bar=new Fixture_Bar();$bar->add_node(array('id'=>'top-secondary','group'=>true,'parent'=>false,'title'=>'Group'));
$bar->add_node(array('id'=>'my-account','parent'=>'top-secondary','title'=>'Account'));
$bar->add_node(array('id'=>'logout','parent'=>'my-account','title'=>'Log out'));
$cfg=array('hidden'=>array('top-secondary'),'order'=>array('logout','top-secondary'),'titles'=>array('logout'=>'Changed child'),'icons'=>array('logout'=>'home'));
AdminKit_Toolbar_Renderer::apply($bar,$cfg,AdminKit_Toolbar_Config::protected_nodes(),false);
check($bar->get_node('top-secondary')&&$bar->get_node('my-account')&&$bar->get_node('logout')->title==='Log out'&&$bar->get_node('logout')->parent==='my-account','Toolbar hierarchy/protected account bypassed');
$admin=true;$options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('icons'=>array('logout'=>'home')));
ob_start();AdminKit_Toolbar_Renderer::print_css();$css=ob_get_clean();check(strpos($css,':is(.ab-top-menu,.ak-mobile-bar-list) > #wp-admin-bar-logout')!==false,'Head icon CSS lacks root-tier boundary');
// Cyclic demotions must retain the native self routes as well as their parents.
$native=array(1=>item('a','A'),2=>item('b','B'));
$children=array('a'=>array(array('A','read','a')),'b'=>array(array('B','read','b')));
$cfg=array('top'=>array(),'sub'=>array('a'=>array(array('slug'=>'b')),'b'=>array(array('slug'=>'a'))));
$plan=AdminKit_Menu_Layout::plan($native,$children,$cfg,array('a'=>true,'b'=>true),'current_user_can');
check(empty($plan['removed'])&&empty($plan['moves'])&&isset($plan['children']['a']['a'],$plan['children']['b']['b']),'Cyclic move altered native self links');
// A later inaccessible duplicate must not erase a valid native capability.
$plan=AdminKit_Menu_Layout::plan(array(),array('p'=>array(array('Read','read','same'),array('Admin','manage_options','same'))),array('top'=>array(),'sub'=>array()),array('p'=>true),static function($cap){return$cap==='read';});
check($plan['children']['p']['same']['item'][1]==='read','Duplicate resolution removed an accessible native route');
$allowed_caps=array('read');$menu=array(1=>item('source','Source'),2=>array('Private','manage_options','target','Private','menu-top','',''));
$submenu=array('source'=>array(array('Route','read','route')),'target'=>array(array('Private','manage_options','private')));
AdminKit_Menu_Store::$config=array('top'=>array(),'sub'=>array('target'=>array(array('slug'=>'route','position'=>0))));AdminKit_Menu_Manager::apply();
check($submenu['source'][0][2]==='route','Child moved into a parent without any authorized entry');$allowed_caps=null;
echo "OK: toolbar hierarchy, generated CSS scope, cyclic routes and role-aware duplicate fallback.\n";

// The block editor is an admin screen: hiding comments is independent of frontend policy.
$options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('hidden'=>array('comments')), 'frontend'=>array());
foreach(array(true,false) as $admin){
 $bar=new Fixture_Bar();$bar->add_node(array('id'=>'comments','parent'=>'','title'=>'Comments'));
 AdminKit_Toolbar_Manager::apply($bar);
 check((bool)$bar->get_node('comments')===!$admin,'Admin/editor comment visibility diverges from the admin context');
}
$admin=true;

// A selected anchor glyph replaces the host's inner touch glyph (QM).
$renderer_overrides->setValue(null,array('wp-admin-bar-query-monitor'=>'clock'));
$renderer_ab->setValue(null,array('wp-admin-bar-query-monitor'=>true));
$bar_showing=true;
ob_start();AdminKit_Toolbar_Renderer::print_css();$css=ob_get_clean();
check(substr_count($css,'#wp-admin-bar-query-monitor > .ab-item > .ab-icon{display:none !important}')===2,'Custom anchor icon duplicates native touch glyph');

// FunnelKit rewrites its title and URL late; the page hook stays stable.
AdminKit_Menu_Store::$config=array('top'=>array(array('slug'=>'woofunnels','title'=>'Tunnels de vente')));
$menu=array(20=>array('FunnelKit','manage_options','woofunnels','FunnelKit','','toplevel_page_woofunnels'));$submenu=array();
AdminKit_Menu_Renderer::apply();
$menu[20][0]='FunnelKit <span class="update-plugins"><span class="plugin-count">2</span></span>';
$menu[20][2]='bwf';
AdminKit_Menu_Renderer::restore_titles();
check(strpos($menu[20][0],'Tunnels de vente')===0,'Late host rename overrides custom menu title');
check(strpos($menu[20][0],'plugin-count')!==false,'Late badge lost');
check($menu[20][2]==='bwf'&&count($menu)===1,'Restoring titles changed menu routing/structure');
echo "OK: late host title rewrites preserve custom labels, badges and routing.\n";

// Integration defaults use #adminmenu #toplevel_page_bricks; custom icons must win.
AdminKit_Menu_Store::$config=array('top'=>array(array('slug'=>'bricks','icon'=>'blocks')));
$menu=array(20=>array('Bricks','manage_options','bricks','Bricks','','toplevel_page_bricks','none'));$submenu=array();
AdminKit_Menu_Renderer::apply();
ob_start();AdminKit_Menu_Renderer::print_css();$css=ob_get_clean();
check(strpos($css,'#adminmenu #toplevel_page_bricks.ak-mi-1 .wp-menu-image::before')!==false,'Custom menu icon does not outrank integration default');
echo "OK: configured menu icons outrank integration defaults using stable menu IDs.\n";

AdminKit_Menu_Store::$config=array('top'=>array(array('slug'=>'wc-admin&path=/analytics/overview','icon'=>'chart-spline')));
$menu=array(20=>array('Statistics','manage_options','wc-admin&path=/analytics/overview','Statistics','','toplevel_page_wc-admin&path=/analytics/overview','none'));$submenu=array();
AdminKit_Menu_Renderer::apply();ob_start();AdminKit_Menu_Renderer::print_css();$css=ob_get_clean();
check(strpos($css,'#adminmenu #toplevel_page_wc-admin-path--analytics-overview.ak-mi-1 .wp-menu-image::before')!==false,'WooCommerce menu ID does not match WordPress sanitization');
