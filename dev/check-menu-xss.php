<?php
/**
 * Regression check: custom menu links, URL overrides, CSS selector keys and schema provisioning
 * reject XSS payloads, unsafe URI schemes and unprivileged callers both at write and render time.
 *
 * Run: php dev/check-menu-xss.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ADMINKIT_PATH', dirname( __DIR__ ) . '/' );

$can_manage   = false;
$schema_calls = 0;
$slug_filters = array();

function current_user_can( $cap ) {
	global $can_manage;
	return 'manage_options' === $cap ? $can_manage : false;
}

function apply_filters( $hook, $value ) {
	global $slug_filters;
	return array_key_exists( $hook, $slug_filters ) ? $slug_filters[ $hook ] : $value;
}

function wp_strip_all_tags( $s ) {
	return strip_tags( (string) $s );
}

function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}

function rest_sanitize_boolean( $v ) {
	return (bool) $v;
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

class AdminKit_Settings {
	public static function get( $k ) {
		return true;
	}
	public static function register( $k, $a ) {}
}

class AdminKit_Settings_Page {
	const SLUG = 'adminkit';
}

class AdminKit_Menu_Store {
	public static $config = array();
	public static $saved  = null;

	public static function ensure_schema() {
		global $schema_calls;
		++$schema_calls;
		return true;
	}

	public static function get_config() {
		return self::$config;
	}

	public static function save_config( array $items ) {
		self::$saved = $items;
		return true;
	}
}

class Mock_Menu_Request {
	private $params;
	public function __construct( array $params ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
}

function rest_ensure_response( $v ) {
	return $v;
}

function is_wp_error( $v ) {
	return false;
}

require ADMINKIT_PATH . 'inc/class-storage.php';
require ADMINKIT_PATH . 'inc/class-icons.php';
require ADMINKIT_PATH . 'inc/wp-core/class-menu-icons.php';
require ADMINKIT_PATH . 'inc/features/menu/class-menu-manager.php';

function assert_check( $cond, $msg ) {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

// 1. Unprivileged users never trigger dbDelta via maybe_ensure_schema().
$can_manage = false;
AdminKit_Menu_Manager::maybe_ensure_schema();
assert_check( 0 === $schema_calls, 'Unprivileged user triggered ensure_schema()' );
$can_manage = true;
AdminKit_Menu_Manager::maybe_ensure_schema();
assert_check( 1 === $schema_calls, 'Administrator failed to trigger ensure_schema()' );

// 2. Write-time URL sanitisation rejects single-quote attribute breakouts & unsafe schemes.
$unsafe_urls = array(
	"https://x.test/'onfocus='alert(1)'autofocus='",
	'https://x.test/"onclick="alert(1)',
	'https://x.test/<script>alert(1)</script>',
	'javascript:alert(1)',
	'JAVASCRIPT:alert(1)',
	'data:text/html,<script>alert(1)</script>',
	'vbscript:msgbox(1)',
	'//evil.example/phish',
	"admin.php?page=foo'onmouseover='alert(1)",
	"https://x.test/path\nonload=alert(1)",
);

foreach ( $unsafe_urls as $bad ) {
	assert_check( '' === AdminKit_Menu_Manager::sanitize_menu_url( $bad ), 'Accepted unsafe menu URL: ' . $bad );
}

$safe_urls = array(
	'https://example.test/docs?tab=1#top',
	'http://localhost:10013/wp-admin/edit.php?post_type=page',
	'edit.php?post_type=page&post_status=publish',
	'/wp-admin/admin.php?page=adminkit',
	'mailto:support@example.test',
	'tel:+33102030405',
);

foreach ( $safe_urls as $good ) {
	assert_check( $good === AdminKit_Menu_Manager::sanitize_menu_url( $good ), 'Rejected valid menu URL: ' . $good );
}

// 3. REST write strips malicious link rows and malicious URL overrides.
AdminKit_Menu_Manager::rest_save(
	new Mock_Menu_Request(
		array(
			'items' => array(
				array(
					'type'  => 'link',
					'slug'  => "https://x.test/'onfocus='alert(1)'",
					'title' => 'XSS Link',
				),
				array(
					'type'  => 'item',
					'slug'  => 'index.php',
					'url'   => 'javascript:alert(1)',
					'title' => 'Dashboard',
				),
				array(
					'type'  => 'link',
					'slug'  => 'https://safe.example/help',
					'title' => 'Help',
				),
			),
		)
	)
);
assert_check( 2 === count( AdminKit_Menu_Store::$saved ), 'Malicious custom link was persisted' );
assert_check( '' === AdminKit_Menu_Store::$saved[0]['url'], 'Malicious URL override was persisted' );
assert_check( 'https://safe.example/help' === AdminKit_Menu_Store::$saved[1]['slug'], 'Safe custom link was dropped' );

// 4. Render-time defence in depth: compromised DB rows cannot inject quotes into $menu or footer JS.
$menu    = array(
	2 => array( 'Dashboard', 'read', 'index.php', 'Dashboard', 'menu-top', '', 'dashicons-BADC{color:red}' ),
);
$submenu = array();
AdminKit_Menu_Store::$config = array(
	'top' => array(
		array(
			'slug'   => 'index.php',
			'icon'   => 'wp-default',
			'url'    => "https://x.test/'onfocus='alert(1)'",
			'hidden' => false,
		),
		array(
			'type'  => 'link',
			'slug'  => "https://x.test/'onfocus='alert(1)'",
			'title' => 'Injected',
		),
	),
	'sub' => array(),
);

AdminKit_Menu_Manager::apply();
foreach ( $menu as $entry ) {
	assert_check( false === strpos( (string) $entry[2], "'" ), 'Single quote reached $menu href slot' );
}
ob_start();
AdminKit_Menu_Manager::print_css();
$css_out = ob_get_clean();
assert_check( false === strpos( $css_out, '{color:red}' ), 'Malformed dashicon class injected CSS' );

ob_start();
AdminKit_Menu_Manager::print_url_overrides();
$js_out = ob_get_clean();
assert_check( '' === $js_out, 'Unsafe URL override emitted footer script' );

// 5. Slug icon filter rejects non-identifier keys before interpolating CSS selectors.
$slug_filters['adminkit/menu_slug_icons'] = array(
	'bad{background:red}' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h1v1H0z"/></svg>',
	'good-plugin_1'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h1v1H0z"/></svg>',
);
$slug_css_method = new ReflectionMethod( 'AdminKit_Core_Menu_Icons', 'slug_css' );
$slug_css_method->setAccessible( true );
$slug_css = $slug_css_method->invoke( null );
assert_check( false === strpos( $slug_css, 'bad{background:red}' ) && false !== strpos( $slug_css, '#toplevel_page_good-plugin_1' ), 'Menu slug CSS selector validation failed' );

echo "OK: custom menu links, URL overrides, CSS selector keys and schema provisioning hardened.\n";
