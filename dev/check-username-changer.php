<?php
/**
 * Regression check: renames require edit_users + edit_user, respect illegal_user_logins
 * and email collisions, allow case-only self-renames, and mint a fresh cookie when a
 * simultaneous password update invalidated the previous session token.
 *
 * Run: php dev/check-username-changer.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

require dirname( __DIR__ ) . '/inc/wp-core/class-username-changer.php';

class Rename_Errors {
	public $errors = array();
	public function add( $code, $message ) {
		$this->errors[] = $code;
	}
}

$allowed       = true;
$can_edit_all  = true;
$token_valid   = true;
$illegal_list  = array( 'superadmin', 'root' );
$cleaned       = array();
$actions       = array();
$cookies       = array();

function current_user_can( $cap, $id = null ) {
	global $allowed, $can_edit_all;
	if ( 'edit_users' === $cap ) {
		return $allowed && $can_edit_all;
	}
	return $allowed;
}

function apply_filters( $hook, $value ) {
	global $illegal_list;
	if ( 'illegal_user_logins' === $hook ) {
		return $illegal_list;
	}
	return $value;
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_verify_nonce( $value, $action ) {
	return 'valid' === $value && 'update-user_7' === $action;
}

function get_userdata( $id ) {
	return (object) array( 'ID' => $id, 'user_login' => 'old' );
}

function sanitize_user( $value, $strict ) {
	return preg_replace( '/[^a-zA-Z0-9_.@ -]/', '', $value );
}

function username_exists( $value ) {
	if ( 0 === strcasecmp( $value, 'old' ) ) {
		return 7; // Case-insensitive MySQL match on the user's own login.
	}
	return 'taken' === $value ? 9 : false;
}

function email_exists( $value ) {
	return 'other@example.test' === strtolower( $value ) ? 9 : false;
}

function __( $text, $domain ) {
	return $text;
}

function clean_user_cache( $user ) {
	global $cleaned;
	$cleaned[] = $user;
}

function get_current_user_id() {
	return 7;
}

function wp_get_session_token() {
	return 'current-token';
}

function wp_parse_auth_cookie( $cookie, $scheme ) {
	return array( 'expiration' => time() + 14 * DAY_IN_SECONDS );
}

function wp_clear_auth_cookie() {}

function wp_set_auth_cookie( ...$args ) {
	global $cookies;
	$cookies[] = $args;
}

function do_action( ...$args ) {
	global $actions;
	$actions[] = $args;
}

class WP_Session_Tokens {
	public static $destroyed = array();
	public static function get_instance( $id ) {
		return new self();
	}
	public function verify( $token ) {
		global $token_valid;
		return $token_valid;
	}
	public function destroy_others( $token ) {
		self::$destroyed[] = $token;
	}
	public function destroy_all() {
		self::$destroyed[] = 'all';
	}
}

class Rename_DB {
	public $users  = 'wp_users';
	public $writes = 0;
	public $result = 1;
	public function update( ...$args ) {
		++$this->writes;
		return $this->result;
	}
}

$wpdb = new Rename_DB();
$old  = get_userdata( 7 );

function check( $value, $message ) {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
}

// 1. Unvalidated apply() must never write.
$_POST = array( 'user_login' => 'new' );
AdminKit_Username_Changer::apply( 7, $old );
check( 0 === $wpdb->writes, 'Unvalidated profile update changed login' );

// 2. Subscriber self-edit (`edit_user` = true, `edit_users` = false) MUST be blocked!
$can_edit_all = false;
$_POST        = array( 'user_login' => 'admin', '_wpnonce' => 'valid' );
$errors       = new Rename_Errors();
AdminKit_Username_Changer::validate( $errors, true, (object) array( 'ID' => 7 ) );
AdminKit_Username_Changer::apply( 7, $old );
check( 0 === $wpdb->writes && empty( $errors->errors ), 'Subscriber without edit_users could rename own login' );
$can_edit_all = true;

// 3. Invalid nonces, lengths, duplicates, illegal logins and email collisions are rejected.
foreach ( array(
	array( 'user_login' => 'new' ),
	array( 'user_login' => 'new', '_wpnonce' => 'bad' ),
	array( 'user_login' => array( 'new' ), '_wpnonce' => 'valid' ),
	array( 'user_login' => str_repeat( 'a', 61 ), '_wpnonce' => 'valid' ),
	array( 'user_login' => 'taken', '_wpnonce' => 'valid' ),
	array( 'user_login' => 'SuperAdmin', '_wpnonce' => 'valid' ),
	array( 'user_login' => 'other@example.test', '_wpnonce' => 'valid' ),
) as $post ) {
	$_POST  = $post;
	$errors = new Rename_Errors();
	AdminKit_Username_Changer::validate( $errors, true, (object) array( 'ID' => 7 ) );
	AdminKit_Username_Changer::apply( 7, $old );
	check( 0 === $wpdb->writes, 'Invalid/illegal/colliding form changed login' );
}

// 4. Validated candidate is bound to target ID, preserves valid session, and clears caches.
$_POST  = array( 'user_login' => 'new', '_wpnonce' => 'valid' );
$errors = new Rename_Errors();
AdminKit_Username_Changer::validate( $errors, true, (object) array( 'ID' => 7 ) );
AdminKit_Username_Changer::apply( 8, get_userdata( 8 ) );
check( 0 === $wpdb->writes, 'Validation leaked to another target' );

$_POST['user_login'] = 'unvalidated-replacement';
AdminKit_Username_Changer::apply( 7, $old );
check( 1 === $wpdb->writes && 1 === count( $actions ) && 'new' === $actions[0][3], 'Validated candidate not applied' );
check( $cleaned[0] === $old && 7 === $cleaned[1], 'Old login and new user caches not both cleared' );
check( $cookies[0] === array( 7, true, '', 'current-token' ) && WP_Session_Tokens::$destroyed === array( 'current-token' ), 'Current session not preserved' );

AdminKit_Username_Changer::apply( 7, $old );
check( 1 === $wpdb->writes, 'Rename repeated after pending candidate consumed' );

// 5. Case-only rename (`old` -> `Old`) succeeds AND simultaneous password change mints a fresh session token.
$token_valid = false;
$_POST       = array( 'user_login' => 'Old', '_wpnonce' => 'valid' );
$errors      = new Rename_Errors();
AdminKit_Username_Changer::validate( $errors, true, (object) array( 'ID' => 7 ) );
check( empty( $errors->errors ), 'Case-only rename rejected as duplicate' );
AdminKit_Username_Changer::apply( 7, $old );
check( 2 === $wpdb->writes && $cookies[1] === array( 7, true, '', '' ), 'Invalidated password-change token was reused instead of minting a fresh session' );

echo "OK: edit_users privilege check, illegal_user_logins, email collision, case-only rename and fresh session after password change.\n";
