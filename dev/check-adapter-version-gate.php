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

// HappyFiles 1.9 must load the real adapter, while unknown minors stay paused.
require dirname( __DIR__ ) . '/inc/integrations/plugins/happyfiles/class-happyfiles.php';
class Test_Happyfiles_Adapter extends AdminKit_Integration_Happyfiles {
	public static $version = '1.9';
	protected static function host_version() {
		return self::$version;
	}
}
check_gate( Test_Happyfiles_Adapter::host_within_tested_range(), 'HappyFiles 1.9 skin unexpectedly paused' );
Test_Happyfiles_Adapter::$version = '1.9.99';
check_gate( Test_Happyfiles_Adapter::host_within_tested_range(), 'HappyFiles 1.9 patch skin unexpectedly paused' );
Test_Happyfiles_Adapter::$version = '1.10.0';
check_gate( ! Test_Happyfiles_Adapter::host_within_tested_range(), 'Untested HappyFiles minor was allowed' );
Test_Happyfiles_Adapter::$version = '2.0.0';
check_gate( ! Test_Happyfiles_Adapter::host_within_tested_range(), 'Untested HappyFiles major was allowed' );
echo "OK: HappyFiles 1.9 adapter version regression.\n";

// FluentSMTP toolbar evolves independently from its legacy settings skin.
class AdminKit_Assets {
	const TOKENS_HANDLE = 'tokens';
	public static $registered = array();
	public static function register( $asset ) {
		self::$registered[] = $asset;
	}
}
require dirname( __DIR__ ) . '/inc/integrations/plugins/fluent-smtp/class-fluent-smtp.php';
class Test_Fluent_Smtp_Adapter extends AdminKit_Integration_Fluent_Smtp {
	public static $version = '2.4.5';
	public static function is_active() { return true; }
	protected static function host_version() { return self::$version; }
}
foreach ( array( '2.2.95' => 3, '2.4.5' => 2, '2.5.0' => 0 ) as $version => $count ) {
	Test_Fluent_Smtp_Adapter::$version = $version;
	AdminKit_Assets::$registered = array();
	Test_Fluent_Smtp_Adapter::maybe_init();
	check_gate( count( AdminKit_Assets::$registered ) === $count, 'FluentSMTP asset boundary mismatch: ' . $version );
	foreach ( AdminKit_Assets::$registered as $asset ) {
		if ( false !== strpos( $asset['handle'], '-toolbar-' ) ) {
			check_gate( 'is_admin_bar_showing' === $asset['condition'], 'FluentSMTP toolbar must require a visible admin bar' );
		}
	}
}
echo "OK: FluentSMTP toolbar/settings version boundaries and visible-toolbar condition.\n";

// Embedded ACF fields must be skinned on native screens only when ACF queues them.
$acf_input_enqueued = false;
function wp_style_is( $handle, $state ) {
	global $acf_input_enqueued;
	return 'acf-input' === $handle && 'enqueued' === $state && $acf_input_enqueued;
}
require dirname( __DIR__ ) . '/inc/integrations/plugins/acf/class-acf.php';
$native_taxonomy = (object) array( 'id' => 'edit-collection_fle', 'post_type' => 'ressource' );
check_gate( ! AdminKit_Integration_Acf::owns_screen( $native_taxonomy ), 'Native taxonomy was treated as an ACF settings page' );
check_gate( ! AdminKit_Integration_Acf::owns_input_screen( $native_taxonomy ), 'ACF field styles loaded without native inputs' );
$acf_input_enqueued = true;
check_gate( AdminKit_Integration_Acf::owns_input_screen( $native_taxonomy ), 'Embedded native ACF inputs were not detected' );
echo "OK: ACF embedded-field stylesheet boundary.\n";

// A moved Slim SEO page keeps its adapter, without claiming unrelated screens.
function get_plugin_page_hookname( $slug, $parent ) { return str_replace( '.php', '', $parent ) . '_page_' . $slug; }
require dirname( __DIR__ ) . '/inc/integrations/plugins/slim-seo/class-slim-seo.php';
$GLOBALS['_parent_pages']['slim-seo'] = 'plugins.php';
$current_screen = (object) array( 'id' => 'plugins_page_slim-seo' );
check_gate( AdminKit_Integration_Slim_Seo::owns_screen( $current_screen ), 'Moved Slim SEO page lost its adapter' );
$classes = AdminKit_Integration_Slim_Seo::settings_body_class( 'adminkit plugins_page_slim-seo' );
check_gate( 1 === substr_count( AdminKit_Integration_Slim_Seo::settings_body_class( $classes ), 'settings_page_slim-seo' ), 'Moved native CSS scope missing or repeated' );
$current_screen = (object) array( 'id' => 'plugins_page_other' );
check_gate( ! AdminKit_Integration_Slim_Seo::owns_screen( $current_screen ) && 'adminkit' === AdminKit_Integration_Slim_Seo::settings_body_class( 'adminkit' ), 'Slim SEO adapter leaked to another moved page' );
echo "OK: moved Slim SEO screen and native CSS scope boundaries.\n";
