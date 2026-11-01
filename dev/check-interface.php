<?php
/** Personal appearance inheritance, isolation and write permissions. */
define( 'ABSPATH', __DIR__ . '/' );
class AdminKit_Settings {
	public static $values = array();
	public static function get( $key ) { return self::$values[$key] ?? null; }
}
$user = 1; $site = 1; $meta = array(); $allowed = true; $nonce = true; $broad = true; $bar = true;
function get_current_user_id() { global $user; return $user; }
function get_current_blog_id() { global $site; return $site; }
function get_user_meta($id,$key,$single) { global $meta; return $meta[$id][$key] ?? ''; }
function update_user_meta($id,$key,$value) { global $meta; $meta[$id][$key]=$value; }
function delete_user_meta($id,$key) { global $meta; unset($meta[$id][$key]); }
function current_user_can(...$args) { global $allowed; return $allowed; }
function wp_verify_nonce(...$args) { global $nonce; return $nonce; }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return strip_tags($v); }
function sanitize_key($v) { return preg_replace('/[^a-z_]/','',$v); }
function apply_filters($hook,$value,...$args) { global $broad; return $hook==='adminkit/should_load' ? $broad : $value; }
function is_admin_bar_showing() { global $bar; return $bar; }
function check($value,$message) { if(!$value) throw new RuntimeException($message); }
require dirname(__DIR__) . '/inc/class-interface.php';
check(AdminKit_Interface::mode()==='adminkit','Existing installations must inherit AdminKit');
AdminKit_Settings::$values['interface_default']='wordpress';
check(AdminKit_Interface::mode()==='wordpress','Site default not inherited');
$_POST=array('adminkit_interface_nonce'=>'valid','adminkit_interface'=>'adminkit');
AdminKit_Interface::save_profile(1);
check(AdminKit_Interface::mode()==='adminkit','Explicit personal choice ignored');
$user=2;check(AdminKit_Interface::mode()==='wordpress','Preference leaked to another account');
$user=1;$site=2;check(AdminKit_Interface::mode()==='wordpress','Preference leaked to another site');
$site=1;check(AdminKit_Interface::mode()==='adminkit','Site switch lost explicit preference');
$before=$meta;$_POST['adminkit_interface']='wordpress';$nonce=false;AdminKit_Interface::save_profile(1);check($meta===$before,'Invalid nonce changed preference');
$nonce=true;$allowed=false;AdminKit_Interface::save_profile(1);check($meta===$before,'Missing capability changed preference');
$allowed=true;AdminKit_Interface::save_profile(2);check($meta===$before,'Another account preference was changed');
$_POST['adminkit_interface']=array('wordpress');AdminKit_Interface::save_profile(1);check($meta===$before,'Array input changed preference');
$_POST['adminkit_interface']='unknown';AdminKit_Interface::save_profile(1);check($meta===$before,'Unknown input changed preference');
$_POST['adminkit_interface']='inherit';AdminKit_Interface::save_profile(1);check(AdminKit_Interface::mode()==='wordpress','Inheritance did not restore current default');
check(!AdminKit_Interface::gate(true,'admin'),'Classic mode still styles admin');
AdminKit_Settings::$values['module_login_enabled']=true;check(AdminKit_Interface::gate(true,'login'),'Login incorrectly follows account appearance');
AdminKit_Settings::$values['module_login_enabled']=false;check(!AdminKit_Interface::gate(true,'login'),'Disabled login is styled');
AdminKit_Settings::$values['interface_default']='adminkit';
check(AdminKit_Interface::gate(true,'editor'),'Default AdminKit editor unavailable');
$bar=false;check(!AdminKit_Interface::gate(true,'frontend'),'Anonymous frontend is styled');
$broad=false;check(!AdminKit_Interface::gate(true,'admin'),'Broad third-party veto ignored');
$broad=true;check(!AdminKit_Interface::gate(false,'admin'),'Appearance veto overridden');
check(AdminKit_Interface::sanitize_default(array('wordpress'))==='adminkit','Malformed default is not safe');
echo "OK: personal interface defaults, account/site isolation, secure saves and contextual gates.\n";
