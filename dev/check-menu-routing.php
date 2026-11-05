<?php
/** Regression: native admin routes, moved plugin callbacks, WooCommerce aliases and submenu URL roundtrips. */
$fixture = sys_get_temp_dir() . '/adminkit-routing-' . bin2hex( random_bytes( 5 ) );
mkdir( $fixture . '/wp-admin', 0700, true );
file_put_contents( $fixture . '/wp-admin/edit.php', '<?php' );
register_shutdown_function( static function () use ( $fixture ) { unlink( $fixture . '/wp-admin/edit.php' ); rmdir( $fixture . '/wp-admin' ); rmdir( $fixture ); } );
define( 'ABSPATH', $fixture . '/' );
function current_user_can( $cap ) { return true; }
function sanitize_text_field( $s ) { return strip_tags( $s ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function rest_sanitize_boolean( $s ) { return (bool) $s; }
function wp_json_encode( $a, $flags = 0 ) { return json_encode( $a, $flags ); }
function get_plugin_page_hookname( $slug, $parent ) { return $parent . '_page_' . str_replace( '.php', '', $slug ); }
$hooks = array();
function add_action( $hook, $callback, $priority = 10 ) { global $hooks; $hooks[$hook][] = $callback; }
function has_action( $hook ) { global $hooks; return ! empty( $hooks[$hook] ); }
function do_action( $hook ) { global $hooks; foreach ( $hooks[$hook] ?? array() as $cb ) { $cb(); } }
class AdminKit_Menu_Store { public static $config; public static function get_config() { return self::$config; } }
require dirname( __DIR__ ) . '/inc/features/menu/class-menu-config.php';
require dirname( __DIR__ ) . '/inc/features/menu/class-menu-layout.php';
require dirname( __DIR__ ) . '/inc/features/menu/class-menu-renderer.php';
require dirname( __DIR__ ) . '/inc/integrations/abstract-integration.php';
require dirname( __DIR__ ) . '/inc/integrations/plugins/woocommerce/class-woocommerce.php';
function routing_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$menu = array( array( 'Woo', 'manage_woocommerce', 'woocommerce' ), array( 'Marketing', 'manage_woocommerce', 'marketing' ) );
$submenu = array(
 'woocommerce' => array( array( 'Orders', 'edit_shop_orders', 'wc-orders' ), array( 'Orders', 'edit_shop_orders', 'edit.php?post_type=shop_order' ), array( 'Coupons', 'manage_options', 'coupons-moved' ) ),
 'marketing' => array( array( 'Coupons', 'edit_shop_coupons', 'edit.php?post_type=shop_coupon' ), array( 'Plugin', 'manage_woocommerce', 'plugin.php' ) ),
);
AdminKit_Menu_Store::$config = array( 'top' => array(), 'sub' => array( 'woocommerce' => array( array( 'slug' => 'edit.php?post_type=shop_coupon', 'position' => 0 ), array( 'slug' => 'plugin.php', 'position' => 1, 'url' => 'admin.php?page=plugin.php&tab=general' ) ) ) );
AdminKit_Integration_Woocommerce::clean_menu_aliases();
routing_check( count( $submenu['woocommerce'] ) === 1, 'WooCommerce technical aliases leaked into snapshot' );
$GLOBALS['_parent_pages'] = array( 'edit.php?post_type=shop_coupon' => 'marketing', 'plugin.php' => 'marketing' );
$GLOBALS['_registered_pages'] = array( 'marketing_page_edit?post_type=shop_coupon' => true, 'marketing_page_plugin' => true );
$called = 0;
add_action( 'marketing_page_plugin', static function () use ( &$called ) { ++$called; } );
AdminKit_Menu_Renderer::apply();
routing_check( ! isset( $GLOBALS['_registered_pages']['woocommerce_page_edit?post_type=shop_coupon'] ), 'Native edit.php was turned into a plugin route' );
routing_check( isset( $GLOBALS['_registered_pages']['woocommerce_page_plugin'] ), 'Moved plugin callback lost its registered route' );
do_action( 'woocommerce_page_plugin' );
routing_check( 1 === $called, 'Moved plugin callback not dispatched exactly once' );
$rows = array_values( $submenu['woocommerce'] );
routing_check( 'edit.php?post_type=shop_coupon' === $rows[0][2] && 'edit_shop_coupons' === $rows[0][1], 'Native coupon slug or capability changed' );
ob_start(); AdminKit_Menu_Renderer::print_url_overrides(); $js = ob_get_clean();
routing_check( str_contains( $js, 'ak-sub-url-' ) && str_contains( $js, 'plugin.php' ), 'Submenu override not emitted' );
$clean = AdminKit_Menu_Config::sanitize_items( array( array( 'parent'=>'woocommerce', 'slug'=>'plugin.php', 'url'=>'admin.php?page=plugin.php&tab=general' ), array( 'parent'=>'woocommerce', 'slug'=>'unsafe', 'url'=>'javascript:alert(1)' ) ) );
routing_check( 'admin.php?page=plugin.php&tab=general' === $clean[0]['url'] && '' === $clean[1]['url'], 'Submenu URL validation failed' );
$submenu = array( 'woocommerce' => array( array( 'Orders', 'edit_shop_orders', 'edit.php?post_type=shop_order' ), array( 'Coupons', 'manage_options', 'coupons-moved' ) ) );
AdminKit_Integration_Woocommerce::clean_menu_aliases();
routing_check( 2 === count( $submenu['woocommerce'] ), 'Legacy-only WooCommerce entries removed without replacement' );
echo "OK: native routes, plugin dispatch, capabilities, submenu URLs and WooCommerce alias boundaries.\n";
