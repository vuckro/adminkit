<?php
/**
 * Regression checks for AdminKit_Integration_Base version gating, paused metadata,
 * centralized maybe_init() lifecycle and wordmark badge/entity stripping.
 */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$registered_hooks = array();
$current_screen   = (object) array( 'id' => 'toplevel_page_test-host' );

function apply_filters( $hook, $value ) {
	return $value;
}
function add_action( $hook, $callback, $priority = 10 ) {
	global $registered_hooks;
	$registered_hooks[] = array( $hook, $callback, $priority );
}
function get_current_screen() {
	global $current_screen;
	return $current_screen;
}
function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

require dirname( __DIR__ ) . '/inc/integrations/abstract-integration.php';

class Test_Adapter_Versioned extends AdminKit_Integration_Base {
	public static $active   = true;
	public static $cur      = '2.2.95';
	public static $max      = '2.2.95';
	public static $assets   = 0;
	public static $booted   = 0;

	public static function slug() {
		return 'test-host';
	}
	public static function is_active() {
		return self::$active;
	}
	public static function owns_screen( $screen ) {
		return is_object( $screen ) && 'toplevel_page_test-host' === $screen->id;
	}
	protected static function host_version() {
		return self::$cur;
	}
	protected static function max_tested_host_version() {
		return self::$max;
	}
	public static function register_assets() {
		++self::$assets;
	}
	protected static function boot() {
		++self::$booted;
	}
}

function check_gate( bool $cond, string $msg ): void {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

// 1. Major-only bound ('3', '11') allows any minor/patch in that major and pauses on next major (including pre-release).
Test_Adapter_Versioned::$max = '3';
Test_Adapter_Versioned::$cur = '3.17.0.1';
check_gate( Test_Adapter_Versioned::host_within_tested_range(), 'Major-only gate rejected valid 3.17.0.1' );
check_gate( null === Test_Adapter_Versioned::paused_version_info(), 'In-range adapter reported paused_version_info' );

Test_Adapter_Versioned::$cur = '4.0.0-beta1';
check_gate( ! Test_Adapter_Versioned::host_within_tested_range(), 'Major-only gate allowed 4.0.0-beta1' );
check_gate( array( 'current' => '4.0.0-beta1', 'max' => '3.x' ) === Test_Adapter_Versioned::paused_version_info(), 'Major-only paused_version_info mismatch' );

// 2. Major.minor bound ('2.2.95') allows patch updates (2.2.99) and pauses on untested minor (2.3.0 / 2.9.0).
Test_Adapter_Versioned::$max = '2.2.95';
Test_Adapter_Versioned::$cur = '2.2.99';
check_gate( Test_Adapter_Versioned::host_within_tested_range(), 'Major.minor gate rejected patch update 2.2.99' );

Test_Adapter_Versioned::$cur = '2.9.0';
check_gate( ! Test_Adapter_Versioned::host_within_tested_range(), 'Major.minor gate allowed untested minor 2.9.0' );
check_gate( array( 'current' => '2.9.0', 'max' => '2.2.x' ) === Test_Adapter_Versioned::paused_version_info(), 'Major.minor paused_version_info mismatch' );

// 3. Centralized maybe_init() skips register_assets(), boot(), and wordmark hook when paused.
$registered_hooks            = array();
Test_Adapter_Versioned::$assets = 0;
Test_Adapter_Versioned::$booted = 0;
Test_Adapter_Versioned::maybe_init();
check_gate( 0 === Test_Adapter_Versioned::$assets && 0 === Test_Adapter_Versioned::$booted && empty( $registered_hooks ), 'Paused adapter executed register_assets/boot/hooks' );

// In-range adapter runs all three.
Test_Adapter_Versioned::$cur = '2.2.95';
Test_Adapter_Versioned::maybe_init();
check_gate( 1 === Test_Adapter_Versioned::$assets && 1 === Test_Adapter_Versioned::$booted && 1 === count( $registered_hooks ), 'Active in-range adapter failed maybe_init()' );

// 4. print_menu_wordmark() strips nested count bubbles and decodes HTML entities.
$GLOBALS['menu'] = array(
	array(
		'AT&amp;T &ldquo;Orders&rdquo; <span class="awaiting-mod count-3"><span class="processing-count">3</span></span>',
		'manage_options',
		'test-host',
	),
);
ob_start();
Test_Adapter_Versioned::print_menu_wordmark();
$wordmark_css = ob_get_clean();
check_gate(
	false !== strpos( $wordmark_css, '--ak-wordmark:"AT&T “Orders”"' ) && false === strpos( $wordmark_css, '3' ),
	'Wordmark leaked count bubble or raw HTML entities: ' . $wordmark_css
);

echo "OK: adapter version gates (major & major.minor), paused_version_info, maybe_init guard, and wordmark sanitization.\n";
