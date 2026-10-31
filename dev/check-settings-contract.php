<?php
/** Early schemas, longest-prefix ownership, frozen migration and legacy chrome filters. */
define('ABSPATH', __DIR__ . '/'); define('ADMINKIT_PATH', dirname(__DIR__) . '/');
$options=array(); $filters=array(); $styles=array();
function get_option($key,$default=false) { global $options; return $options[$key] ?? $default; }
function wp_parse_args($args,$defaults) { return array_merge($defaults,$args); }
function add_filter(...$args) {}
function apply_filters($hook,$value) { global $filters; return array_key_exists($hook,$filters) ? $filters[$hook] : $value; }
function sanitize_hex_color($value) { return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i',$value) ? $value : null; }
function rest_sanitize_boolean($value) { return !in_array($value,array(false,0,'0','false'),true); }
function __($value,$domain) { throw new RuntimeException('Early registration translated a label.'); }
function get_plugins() { return array('foo/foo.php'=>array(),'foo-pro/foo-pro.php'=>array(),'single.php'=>array()); }
function wp_add_inline_style($handle,$css) { global $styles; $styles[]=$css; }
class AdminKit_Assets { const TOKENS_HANDLE='adminkit-tokens'; }
require ADMINKIT_PATH . 'inc/settings/class-settings.php';
require ADMINKIT_PATH . 'inc/settings/class-settings-catalog.php';
require ADMINKIT_PATH . 'inc/settings/class-settings-gate.php';
require ADMINKIT_PATH . 'inc/wp-core/class-chrome.php';
function check($value,$message) { if (!$value) throw new RuntimeException($message); }
AdminKit_Settings::init();
foreach(glob(ADMINKIT_PATH.'inc/integrations/*/*/class-*.php') as $file) {
 $slug=substr(basename($file,'.php'),6);
 if($slug==='gutenberg') continue;
 check(isset(AdminKit_Settings::schema()['integration_'.$slug.'_enabled']), 'Missing early integration schema: '.$slug);
}
$method=new ReflectionMethod('AdminKit_Settings_Gate','plugin_file_for_screen'); $method->setAccessible(true);
foreach(array('toplevel_page_foo-pro-settings'=>'foo-pro/foo-pro.php','tools_page_foo_tools'=>'foo/foo.php','toplevel_page_single-tools'=>'single.php','toplevel_page_foobar'=>null) as $id=>$expected) {
 check($method->invoke(null,(object)array('id'=>$id))===$expected,'Wrong prefix match: '.$id);
}
foreach(array(false,true) as $master) {
 foreach(array(false,true) as $footer) {
  foreach(array(false,true) as $help) {
   $styles=array(); $filters=array('adminkit/clean_chrome/enabled'=>$master,'adminkit/hide_footer/enabled'=>$footer,'adminkit/hide_help_button/enabled'=>$help);
   AdminKit_Core_Chrome::clean_chrome_css('admin'); $css=implode('',$styles);
   check((strpos($css,'#wpfooter')!==false)===$footer,'Footer compatibility filter ignored');
   check((strpos($css,'#contextual-help-link-wrap')!==false)===$help,'Help compatibility filter ignored');
  }
 }
}
$styles=array(); AdminKit_Core_Chrome::clean_chrome_css('login'); check(!$styles,'Chrome leaked outside admin');
$source=file_get_contents(ADMINKIT_PATH.'inc/class-upgrades.php');
// Extract the complete block by counting braces (the original block has no braces in strings).
$start=strpos($source, 'if ( $version < 6 ) {'); $depth=0; $end=null;
for($i=strpos($source,'{',$start);$i<strlen($source);$i++) { if($source[$i]==='{')$depth++; if($source[$i]==='}' && --$depth===0) { $end=$i; break; } }
check($end!==null,'Missing frozen upgrade 6');
check(hash('sha256',substr($source,$start,$end-$start+1))==='c6e5ddda622605f54037308dddaa1e5e50ca54f9283506f8ce9d6d57d2ea3b90','Upgrade 6 changed; add a new upgrade instead.');
echo "OK: early unlocalized integration schema, longest prefix, legacy chrome filters and immutable upgrade 6.\n";
