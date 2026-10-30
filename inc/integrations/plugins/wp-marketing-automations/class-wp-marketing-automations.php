<?php
/** FunnelKit Automations — scoped Tier B skin, verified against host 3.x. */
defined( 'ABSPATH' ) || exit;
class AdminKit_Integration_Wp_Marketing_Automations extends AdminKit_Integration_Base {
 public static function slug() { return 'wp-marketing-automations'; }
 public static function is_active() { return defined( 'BWFAN_VERSION' ); }
 protected static function host_version() { return defined( 'BWFAN_VERSION' ) ? BWFAN_VERSION : null; }
 protected static function max_tested_host_version() { return '3'; }
 protected static function wordmark_menu_slug() { return 'autonami'; }
 public static function owns_screen( $screen ) {
  if ( ! $screen ) { return false; }
  return (bool) preg_match( '/autonami|bwfan|bwfcrm/', $screen->id );
 }
 protected static function boot() {
  if ( self::host_within_tested_range() ) {
   add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
   add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_behaviors' ) );
   add_filter( 'adminkit/menu_slug_icons', array( __CLASS__, 'menu_icons' ) );
  }
 }
 public static function menu_icons( $icons ) {
  foreach ( (array) ( $GLOBALS['menu'] ?? array() ) as $item ) {
   if ( isset( $item[2], $item[4] ) && 'autonami' === $item[2] && false !== strpos( $item[4], 'ak-mi-' ) ) {
    return $icons; // Preserve the user's Menu Manager icon override.
   }
  }
  $icons['autonami'] = AdminKit_Icons::svg( 'workflow' );
  return $icons;
 }
 public static function enqueue_behaviors() {
  if ( self::owns_screen( get_current_screen() ) && apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
   AdminKit_Assets::enqueue_script( 'adminkit-funnelkit-keyboard', 'inc/integrations/plugins/funnel-builder/js/shared.js' );
  }
 }
 public static function body_class( $classes ) { return self::owns_screen( get_current_screen() ) ? $classes . ' adminkit-funnelkit adminkit-funnelkit-automations' : $classes; }
 public static function register_assets() {
  if ( ! self::host_within_tested_range() ) {
   return;
  }
  AdminKit_Assets::register( array(
   'handle' => 'adminkit-funnelkit-menu',
   'src' => 'inc/integrations/plugins/funnel-builder/css/menu.css',
   'deps' => array( AdminKit_Assets::TOKENS_HANDLE ),
   'context' => 'admin',
  ) );
  AdminKit_Assets::register( array(
   'handle' => 'adminkit-funnelkit-automations-shared',
   'src' => 'inc/integrations/plugins/funnel-builder/css/shared.css',
   'deps' => array( AdminKit_Assets::TOKENS_HANDLE ),
   'context' => 'admin',
   'condition' => array( __CLASS__, 'owns_screen' ),
  ) );
  AdminKit_Assets::register( array(
   'handle' => 'adminkit-funnelkit-automations',
   'src' => 'inc/integrations/plugins/wp-marketing-automations/css/admin.css',
   'deps' => array( 'adminkit-funnelkit-automations-shared' ),
   'context' => 'admin',
   'condition' => array( __CLASS__, 'owns_screen' ),
  ) );
  AdminKit_Assets::register( array(
   'handle' => 'adminkit-funnelkit-automations-controls',
   'src' => 'inc/integrations/plugins/funnel-builder/css/controls.css',
   'deps' => array( 'adminkit-funnelkit-automations' ),
   'context' => 'admin',
   'condition' => array( __CLASS__, 'owns_screen' ),
  ) );
 }
}
