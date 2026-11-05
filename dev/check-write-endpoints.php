<?php
/** Exercise actual write controllers offline, including permissions and malformed payloads. */
require __DIR__ . '/check-feature-storage.php';
define('ADMINKIT_PATH', dirname(__DIR__) . '/');
$routes = array(); $allowed = false;
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function add_filter(...$args) {}
function apply_filters($hook, $value) { return $value; }
function current_user_can($cap) { global $allowed; return $allowed; }
function register_rest_route($namespace, $path, $args) { global $routes; $routes[$path] = $args; }
function rest_ensure_response($value) { return $value; }
function sanitize_text_field($value) { return strip_tags($value); }
function rest_sanitize_boolean($value) { return !in_array($value,array(false,0,'0','false'),true); }
function sanitize_hex_color($value) { return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i',$value) ? $value : null; }
class Write_Request { private $params; public function __construct($params) { $this->params=$params; } public function get_param($key) { return $this->params[$key] ?? null; } public function get_params() { return $this->params; } }
require dirname(__DIR__) . '/inc/settings/class-settings.php';
require dirname(__DIR__) . '/inc/settings/class-settings-catalog.php';
require dirname(__DIR__) . '/inc/settings/class-settings-page.php';
require dirname(__DIR__) . '/inc/features/menu/class-menu-manager.php';
require dirname(__DIR__) . '/inc/features/toolbar/class-toolbar-config.php';
require dirname(__DIR__) . '/inc/features/toolbar/class-toolbar-snapshot.php';
require dirname(__DIR__) . '/inc/features/toolbar/class-toolbar-renderer.php';
require dirname(__DIR__) . '/inc/features/toolbar/class-toolbar-manager.php';
AdminKit_Settings::init();
AdminKit_Settings_Page::register_routes(); AdminKit_Menu_Manager::register_routes(); AdminKit_Toolbar_Manager::register_routes();
foreach ($routes as $route) {
 foreach (isset($route['permission_callback']) ? array($route) : $route as $endpoint) {
  $allowed=false; check(false === call_user_func($endpoint['permission_callback']), 'Unauthorized route allowed');
  $allowed=true; check(true === call_user_func($endpoint['permission_callback']), 'Authorized route denied');
 }
}
$options['adminkit_settings']=array('logo_url'=>'https://example.test/keep.svg','theme_toggle_enabled'=>true);
$fail_option=false;
foreach (array(array(), array('values'=>null), array('values'=>'bad'), array('values'=>array('ui_font'=>array('geist'))), array('values'=>array('theme_toggle_enabled'=>array(false)))) as $params) {
 $before=$options;
 check(is_wp_error(AdminKit_Settings_Page::rest_save(new Write_Request($params))), 'Malformed settings accepted');
 check($before === $options, 'Malformed settings changed storage');
}
$result=AdminKit_Settings_Page::rest_save(new Write_Request(array('values'=>array('theme_toggle_enabled'=>false))));
check(!is_wp_error($result) && false === $options['adminkit_settings']['theme_toggle_enabled'] && isset($options['adminkit_settings']['logo_url']), 'Partial settings write lost logo or false');
$fail_option=true;
check(is_wp_error(AdminKit_Settings_Page::rest_save(new Write_Request(array('values'=>array('theme_toggle_enabled'=>true))))), 'Failed settings write reported success');
foreach (array(array(), array('items'=>null), array('items'=>'bad'), array('items'=>array('bad')), array('items'=>array(array('slug'=>array('bad'))))) as $params) {
 $before=$wpdb->deletes;
 check(is_wp_error(AdminKit_Menu_Manager::rest_save(new Write_Request($params))), 'Malformed menu accepted');
 check($before === $wpdb->deletes, 'Malformed menu reached SQL delete');
}
$options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('order'=>array('site-name')),'frontend'=>array('order'=>array('edit')));
foreach (array(array(), array('admin'=>'bad'), array('admin'=>null), array('order'=>'bad'), array('admin'=>array('order'=>'bad')), array('admin'=>array('icons'=>array('edit'=>array('bad'))))) as $params) {
 $before=$options;
 check(is_wp_error(AdminKit_Toolbar_Manager::rest_save(new Write_Request($params))), 'Malformed toolbar accepted');
 check($before === $options, 'Malformed toolbar changed storage');
}
check(is_wp_error(AdminKit_Toolbar_Manager::rest_save(new Write_Request(array('admin'=>array())))), 'Failed toolbar write reported success');
$fail_option=false;
$result=AdminKit_Toolbar_Manager::rest_save(new Write_Request(array('admin'=>array())));
check(!is_wp_error($result) && array('edit') === $result['configByContext']['frontend']['order'], 'Toolbar context isolation failed');
$options['adminkit_settings']=array('theme_toggle_enabled'=>'false');
check(false === AdminKit_Settings::get('theme_toggle_enabled'), 'Legacy string false enabled feature');
$options['adminkit_settings']=new stdClass();
check(true === AdminKit_Settings::get('theme_toggle_enabled'), 'Malformed option should use registered default');
class AdminKit_Core_Menu_Icons { public static function mapped_classes() { return array(); } }
require ADMINKIT_PATH . 'inc/class-icons.php';
AdminKit_Settings::register('menu_manager_enabled', array('default'=>false));
$before=$wpdb->reads;
$editor=AdminKit_Menu_Manager::editor_data();
check($before === $wpdb->reads && $editor['config'] === array('top'=>array(),'sub'=>array()), 'Disabled menu queried unprovisioned storage');
echo "OK: route permissions, input rejection, partial updates, write failures and typed legacy reads.\n";

// Valid but malformed row fields must not trigger a destructive replacement.
foreach(array(
 array('slug'=>'index.php','parent'=>array('bad')),
 array('slug'=>'index.php','hidden'=>'false'),
 array('slug'=>'index.php','position'=>-1),
 array('slug'=>'index.php','title'=>str_repeat('x',191)),
 array('slug'=>'index.php','url'=>'javascript:alert(1)'),
 array('slug'=>'index.php','icon'=>'not-a-real-icon'),
 array('slug'=>'index.php','type'=>'separator','parent'=>'plugins.php')
)as$row){$before=$wpdb->deletes;check(is_wp_error(AdminKit_Menu_Manager::rest_save(new Write_Request(array('items'=>array($row))))),'Invalid menu row accepted');check($before===$wpdb->deletes,'Invalid row reached SQL replacement');}
$options[AdminKit_Toolbar_Manager::OPTION]=array('admin'=>array('order'=>array('site-name'),'icons'=>array('site-name'=>'home')),'frontend'=>array('order'=>array('edit')));
$result=AdminKit_Toolbar_Manager::rest_save(new Write_Request(array('admin'=>array('titles'=>array('site-name'=>'New label')))));
check(!is_wp_error($result)&&$result['configByContext']['admin']['order']===array('site-name')&&$result['configByContext']['admin']['icons']['site-name']==='home','Partial toolbar fields reset sibling fields');
foreach(array(array('admin'=>array('hidden'=>array('my.account'))),array('admin'=>array('order'=>array('x'=>'site-name'))),array('admin'=>array('unexpected'=>true)))as$input){$before=$options;check(is_wp_error(AdminKit_Toolbar_Manager::rest_save(new Write_Request($input))),'Unsupported toolbar input accepted');check($before===$options,'Invalid toolbar input wrote options');}
echo "OK: atomic row rejection, strict identifiers and partial context field preservation.\n";
