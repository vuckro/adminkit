<?php
/**
 * Regression check: Quick Edit enforces target capabilities, editable_roles, promote_user,
 * preserves secondary roles on multi-role accounts, rejects malformed/duplicate/self-changed emails,
 * and omits HTML name= attributes from cloned inputs.
 *
 * Run: php dev/check-user-quick-edit.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/inc/wp-core/class-user-quick-edit.php';

$allowed      = true;
$can_promote  = true;
$update       = null;
$response     = null;
$target_roles = array( 'subscriber' );

class Quick_Edit_Response extends RuntimeException {}

function apply_filters( $hook, $value ) {
	return $value;
}

function current_user_can( $cap, $id = null ) {
	global $allowed, $can_promote;
	if ( 'promote_user' === $cap ) {
		return $allowed && $can_promote;
	}
	return $allowed;
}

function get_current_user_id() {
	return 7;
}

function check_ajax_referer( $action ) {}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_key( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return $value;
}

function sanitize_email( $value ) {
	return false !== strpos( $value, '@' ) && false !== strpos( $value, '.' ) ? $value : '';
}

function is_email( $value ) {
	return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL );
}

function email_exists( $value ) {
	return 'taken@example.invalid' === strtolower( $value ) ? 99 : false;
}

function get_editable_roles() {
	return array(
		'subscriber' => array( 'name' => 'Subscriber' ),
		'editor'     => array( 'name' => 'Editor' ),
	);
}

function wp_update_user( $value ) {
	global $update;
	$update = $value;
	return $value['ID'];
}

function is_wp_error( $value ) {
	return false;
}

function get_userdata( $id ) {
	global $target_roles;
	return (object) array(
		'ID'         => $id,
		'roles'      => $target_roles,
		'first_name' => 'A',
		'last_name'  => 'B',
		'user_email' => 'test@example.invalid',
	);
}

function wp_roles() {
	return new class {
		public function get_names() {
			return array(
				'subscriber'    => 'Subscriber',
				'editor'        => 'Editor',
				'shop_manager'  => 'Shop Manager',
				'administrator' => 'Administrator',
			);
		}
	};
}

function translate_user_role( $name ) {
	return $name;
}

function __( $text, $domain ) {
	return $text;
}

function wp_send_json_error( $value, $status ) {
	global $response;
	$response = $status;
	throw new Quick_Edit_Response();
}

function wp_send_json_success( $value ) {
	global $response, $payload;
	$response = 200;
	$payload  = $value;
	throw new Quick_Edit_Response();
}

function check( $value, $message ) {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
}

// 1. Editable role allowlist & self-demotion guard.
foreach ( array( 'administrator' => false, 'subscriber' => true ) as $role => $accepted ) {
	$_POST  = array( 'user_id' => 8, 'role' => $role );
	$update = null;
	try {
		AdminKit_User_Quick_Edit::handle_save();
	} catch ( Quick_Edit_Response $done ) {}
	check( $accepted ? ( 200 === $response && isset( $update['role'] ) ) : ( 403 === $response && null === $update ), 'editable_roles filter bypassed for ' . $role );
}

$_POST  = array( 'user_id' => 7, 'role' => 'subscriber' );
$update = null;
try {
	AdminKit_User_Quick_Edit::handle_save();
} catch ( Quick_Edit_Response $done ) {}
check( ! isset( $update['role'] ), 'Quick edit allowed accidental self-demotion' );

// 2. Target-level `promote_user` meta-capability denial.
$can_promote = false;
$_POST       = array( 'user_id' => 8, 'role' => 'subscriber' );
$update      = null;
try {
	AdminKit_User_Quick_Edit::handle_save();
} catch ( Quick_Edit_Response $done ) {}
check( 403 === $response && null === $update, 'promote_user denial ignored' );
$can_promote = true;

// 3. Multi-role user (even non-0-indexed!) keeps secondary roles intact.
$target_roles = array( 2 => 'editor', 5 => 'shop_manager' );
$_POST        = array( 'user_id' => 8, 'first_name' => 'Multi', 'role' => 'editor' );
$update       = null;
$payload      = null;
try {
	AdminKit_User_Quick_Edit::handle_save();
} catch ( Quick_Edit_Response $done ) {}
check( 200 === $response && ! isset( $update['role'] ) && 'Editor, Shop Manager' === $payload['role_display'], 'Multi-role user had secondary role wiped or mislabelled' );

$_POST  = array( 'user_id' => 8, 'role' => 'subscriber' );
$update = null;
try {
	AdminKit_User_Quick_Edit::handle_save();
} catch ( Quick_Edit_Response $done ) {}
check( 400 === $response && null === $update, 'Multi-role user allowed destructive role replacement via Quick Edit' );
$target_roles = array( 'subscriber' );

// 4. Malformed, duplicate and self-edited emails are rejected before wp_update_user().
foreach ( array(
	array( 'post' => array( 'user_id' => 8, 'user_email' => 'not-an-email' ), 'code' => 400 ),
	array( 'post' => array( 'user_id' => 8, 'user_email' => '' ), 'code' => 400 ),
	array( 'post' => array( 'user_id' => 8, 'user_email' => 'taken@example.invalid' ), 'code' => 409 ),
	array( 'post' => array( 'user_id' => 7, 'user_email' => 'newself@example.invalid' ), 'code' => 400 ),
) as $case ) {
	$_POST  = $case['post'];
	$update = null;
	try {
		AdminKit_User_Quick_Edit::handle_save();
	} catch ( Quick_Edit_Response $done ) {}
	check( $case['code'] === $response && null === $update, 'Unsafe email edit accepted: ' . json_encode( $case ) );
}

// 5. Template omits name= attributes so outer GET form on users.php never leaks PII.
function get_current_screen() {
	return new stdClass();
}
function get_column_headers( $screen ) {
	return array();
}
function esc_html_e( $text, $domain ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $item ) use ( $field ) {
			return $item[ $field ];
		},
		$list
	);
}

ob_start();
AdminKit_User_Quick_Edit::print_form_template();
$markup = ob_get_clean();
preg_match_all( '/<option value="([^"]+)"/', $markup, $roles );
check( $roles[1] === array_keys( get_editable_roles() ), 'Form proposed an unassignable role' );
check( ! preg_match( '/<(?:input|select)\b[^>]*\bname=/i', $markup ), 'Quick Edit template leaked name= attributes into outer GET form' );

// 6. Omitted role and unauthorized target checks.
$_POST  = array( 'user_id' => 8, 'first_name' => 'Keep' );
$update = null;
try {
	AdminKit_User_Quick_Edit::handle_save();
} catch ( Quick_Edit_Response $done ) {}
check( 200 === $response && array( 'ID' => 8, 'first_name' => 'Keep' ) === $update, 'An omitted role blocked unrelated profile edits' );

$allowed = false;
$update  = null;
try {
	AdminKit_User_Quick_Edit::handle_save();
} catch ( Quick_Edit_Response $done ) {}
check( 403 === $response && null === $update, 'Unauthorized target reached update' );

echo "OK: editable role allowlist, multi-role preservation, email validation, self-email guard and PII-safe template.\n";
