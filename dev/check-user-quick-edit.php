<?php
/** Respect target capabilities and editable_roles when assigning a quick-edit role. */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/inc/wp-core/class-user-quick-edit.php';
$allowed=true; $update=null; $response=null;
class Quick_Edit_Response extends RuntimeException {}
function current_user_can($cap, $id=null) { global $allowed; return $allowed; }
function get_current_user_id() { return 7; }
function check_ajax_referer($action) {}
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return $value; }
function get_editable_roles() { return array('subscriber'=>array('name'=>'Subscriber')); }
function wp_update_user($value) { global $update; $update=$value; return $value['ID']; }
function is_wp_error($value) { return false; }
function get_userdata($id) { return (object)array('roles'=>array('subscriber'),'first_name'=>'A','last_name'=>'B','user_email'=>'test@example.invalid'); }
function wp_roles() { return new class { public function get_names() { return array('subscriber'=>'Subscriber','administrator'=>'Administrator'); } }; }
function translate_user_role($name) { return $name; }
function __($text, $domain) { return $text; }
function wp_send_json_error($value,$status) { global $response; $response=$status; throw new Quick_Edit_Response(); }
function wp_send_json_success($value) { global $response; $response=200; throw new Quick_Edit_Response(); }
function check($value,$message) { if (!$value) throw new RuntimeException($message); }
foreach (array('administrator'=>false,'subscriber'=>true) as $role=>$accepted) {
 $_POST=array('user_id'=>8,'role'=>$role); $update=null;
 try { AdminKit_User_Quick_Edit::handle_save(); } catch (Quick_Edit_Response $done) {}
 check($accepted === isset($update['role']), 'editable_roles filter bypassed for '.$role);
}
$_POST=array('user_id'=>7,'role'=>'subscriber');
try { AdminKit_User_Quick_Edit::handle_save(); } catch (Quick_Edit_Response $done) {}
check(!isset($update['role']), 'Quick edit allowed accidental self-demotion');
$allowed=false; $update=null;
try { AdminKit_User_Quick_Edit::handle_save(); } catch (Quick_Edit_Response $done) {}
check($response === 403 && $update === null, 'Unauthorized target reached update');
echo "OK: editable role allowlist, self-demotion guard and target capability denial.\n";

function apply_filters($hook, $value) { return $value; }
