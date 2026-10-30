<?php
/** Run: php dev/check-automatic-branding.php [fixture JSON path]. */
define( 'ABSPATH', __DIR__ . '/' );
class AdminKit_Settings {
 public static $logo = 'http://localhost:10013/wp-content/uploads/2026/10/waaskit-logo.svg';
 public static function brand_logo( $mode ) { return self::$logo; }
 public static function dynamic_logo_class() { return ' ak-logo-dynamic'; }
 public static function get( $key ) { return 'favicon'; } // A legacy choice must not suppress the logo.
}
$GLOBALS['icon'] = 'http://localhost:10013/wp-content/uploads/2026/10/cropped-Favicon-WaasKit-150x150.png';
function is_admin_bar_showing() { return true; }
function is_admin() { return true; }
function apply_filters( $hook, $value ) { return $value; }
function get_bloginfo( $key, $context = '' ) { return 'WaasKit'; }
function get_site_icon_url( $size ) { return $GLOBALS['icon']; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url_raw( $value ) { return $value; }
class Test_Brand_Bar {
 public $node;
 public function __construct() { $this->node = (object) array( 'title' => 'WaasKit', 'href' => '/native-target', 'meta' => array( 'class' => 'native-class' ) ); }
 public function get_node( $id ) { return $this->node; }
 public function add_node( $node ) { $this->node = (object) $node; }
}
require dirname( __DIR__ ) . '/inc/wp-core/class-branding.php';
$bar = new Test_Brand_Bar();
AdminKit_Core_Branding::inject_brand_logo( $bar );
if ( ! str_contains( $bar->node->title, 'ak-brand-compact' ) || '/native-target' !== $bar->node->href || ! str_contains( $bar->node->meta['class'], 'native-class' ) ) { throw new RuntimeException( 'Identity must preserve native navigation and expose both marks' ); }
ob_start(); AdminKit_Core_Branding::print_admin(); $css = ob_get_clean();
if ( ! str_contains( $css, 'body.folded' ) || ! str_contains( $css, 'body.auto-fold' ) ) { throw new RuntimeException( 'Native menu states missing' ); }
if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( array( 'node' => $bar->node, 'css' => $css ) ) ); }
$GLOBALS['icon'] = '';
$bar = new Test_Brand_Bar(); AdminKit_Core_Branding::inject_brand_logo( $bar );
if ( ! str_contains( $bar->node->title, 'dashicons-wordpress' ) ) { throw new RuntimeException( 'Compact fallback missing' ); }
AdminKit_Settings::$logo = '';
$bar = new Test_Brand_Bar(); AdminKit_Core_Branding::inject_brand_logo( $bar );
if ( 'WaasKit' !== $bar->node->title ) { throw new RuntimeException( 'No logo should retain native title' ); }
echo "OK: automatic marks, native menu states, legacy choice ignored and missing-image fallbacks\n";
