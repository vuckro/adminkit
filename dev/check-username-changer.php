<?php
/** Verify renames are bound to one validated native form submission. */
define('ABSPATH', __DIR__ . '/'); define('DAY_IN_SECONDS', 86400);
require dirname(__DIR__) . '/inc/wp-core/class-username-changer.php';
class Rename_Errors { public $errors = array(); public function add($code, $message) { $this->errors[] = $code; } }
$allowed = true; $cleaned = array(); $actions = array(); $cookies = array();
function current_user_can($cap, $id) { global $allowed; return $allowed; }
function wp_unslash($value) { return $value; }
function wp_verify_nonce($value, $action) { return $value === 'valid' && $action === 'update-user_7'; }
function get_userdata($id) { return (object) array('ID'=>$id, 'user_login'=>'old'); }
function sanitize_user($value, $strict) { return preg_replace('/[^a-zA-Z0-9_.@ -]/', '', $value); }
function username_exists($value) { return $value === 'taken' ? 9 : false; }
function __($text, $domain) { return $text; }
function clean_user_cache($user) { global $cleaned; $cleaned[] = $user; }
function get_current_user_id() { return 7; }
function wp_get_session_token() { return 'current-token'; }
function wp_parse_auth_cookie($cookie, $scheme) { return array('expiration'=>time() + 14 * DAY_IN_SECONDS); }
function wp_clear_auth_cookie() {}
function wp_set_auth_cookie(...$args) { global $cookies; $cookies[] = $args; }
function do_action(...$args) { global $actions; $actions[] = $args; }
class WP_Session_Tokens { public static $destroyed = array(); public static function get_instance($id) { return new self(); } public function destroy_others($token) { self::$destroyed[] = $token; } public function destroy_all() { self::$destroyed[] = 'all'; } }
class Rename_DB { public $users='wp_users', $writes=0, $result=1; public function update(...$args) { ++$this->writes; return $this->result; } }
$wpdb = new Rename_DB(); $old = get_userdata(7);
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$_POST = array('user_login'=>'new');
AdminKit_Username_Changer::apply(7, $old);
check($wpdb->writes === 0, 'Unvalidated profile update changed login');
foreach (array(array('user_login'=>'new'), array('user_login'=>'new','_wpnonce'=>'bad'), array('user_login'=>array('new'),'_wpnonce'=>'valid'), array('user_login'=>str_repeat('a',61),'_wpnonce'=>'valid'), array('user_login'=>'taken','_wpnonce'=>'valid')) as $post) {
 $_POST=$post; $errors=new Rename_Errors(); AdminKit_Username_Changer::validate($errors,true,(object)array('ID'=>7)); AdminKit_Username_Changer::apply(7,$old);
 check($wpdb->writes === 0, 'Invalid form changed login');
}
$_POST=array('user_login'=>'new','_wpnonce'=>'valid'); $errors=new Rename_Errors();
AdminKit_Username_Changer::validate($errors,true,(object)array('ID'=>7));
AdminKit_Username_Changer::apply(8,get_userdata(8));
check($wpdb->writes === 0, 'Validation leaked to another target');
$_POST['user_login']='unvalidated-replacement';
AdminKit_Username_Changer::apply(7,$old);
check($wpdb->writes === 1 && count($actions) === 1 && $actions[0][3] === 'new', 'Validated candidate not applied');
check($cleaned[0] === $old && $cleaned[1] === 7, 'Old login and new user caches not both cleared');
check($cookies[0] === array(7,true,'','current-token') && WP_Session_Tokens::$destroyed === array('current-token'), 'Current session not preserved');
AdminKit_Username_Changer::apply(7,$old);
check($wpdb->writes === 1, 'Rename repeated after pending candidate consumed');
echo "OK: native nonce, candidate/target binding, length/duplicate validation, old cache and current session.\n";
