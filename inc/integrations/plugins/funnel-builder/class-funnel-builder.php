<?php
/** FunnelKit Builder — scoped Tier B skin, verified against host 3.x. */
defined( 'ABSPATH' ) || exit;
class AdminKit_Integration_Funnel_Builder extends AdminKit_Integration_Base {
 public static function slug() { return 'funnel-builder'; }
 public static function label() { return 'Funnel Builder'; }
 public static function host_files() { return array( 'funnel-builder/funnel-builder.php', 'funnel-builder-pro/funnel-builder-pro.php' ); }
 public static function is_active() { return defined( 'WFFN_VERSION' ); }
 protected static function host_version() { return defined( 'WFFN_VERSION' ) ? WFFN_VERSION : null; }
 protected static function max_tested_host_version() { return '3'; }
 protected static function wordmark_menu_slug() { return 'woofunnels'; }
 public static function owns_screen( $screen ) {
  if ( ! $screen ) { return false; }
  return (bool) preg_match( '/(?:^|_)(?:bwf|wffn|wfacp|wfty|wfop|wfocu)(?:$|[-_&])/', $screen->id ) || in_array( $screen->post_type, array( 'wffn_funnel', 'wfacp_checkout', 'wffn_landing', 'wffn_optin', 'wffn_ty', 'wffn_optin_ty' ), true );
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
   if ( isset( $item[2], $item[4] ) && 'woofunnels' === $item[2] && false !== strpos( $item[4], 'ak-mi-' ) ) {
    return $icons; // Preserve the user's Menu Manager icon override.
   }
  }
  $icons['woofunnels'] = AdminKit_Icons::svg( 'funnel' );
  return $icons;
 }
 public static function enqueue_behaviors() {
  if ( self::owns_screen( get_current_screen() ) && apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
   AdminKit_Assets::enqueue_script( 'adminkit-funnelkit-keyboard', 'inc/integrations/plugins/funnel-builder/js/shared.js' );
  }
 }
 public static function body_class( $classes ) { return self::owns_screen( get_current_screen() ) ? $classes . ' adminkit-funnelkit adminkit-funnelkit-builder' : $classes; }
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
   'handle' => 'adminkit-funnelkit-builder-shared',
   'src' => 'inc/integrations/plugins/funnel-builder/css/shared.css',
   'deps' => array( AdminKit_Assets::TOKENS_HANDLE ),
   'context' => 'admin',
   'condition' => array( __CLASS__, 'owns_screen' ),
  ) );
  AdminKit_Assets::register( array(
   'handle' => 'adminkit-funnelkit-builder',
   'src' => 'inc/integrations/plugins/funnel-builder/css/admin.css',
   'deps' => array( 'adminkit-funnelkit-builder-shared' ),
   'context' => 'admin',
   'condition' => array( __CLASS__, 'owns_screen' ),
  ) );
  AdminKit_Assets::register( array(
   'handle' => 'adminkit-funnelkit-builder-controls',
   'src' => 'inc/integrations/plugins/funnel-builder/css/controls.css',
   'deps' => array( 'adminkit-funnelkit-builder' ),
   'context' => 'admin',
   'condition' => array( __CLASS__, 'owns_screen' ),
  ) );
 }
}
