<?php
/**
 * Rank Math editor integration. Native SEO fields and actions remain host-owned.
 *
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Seo_By_Rank_Math extends AdminKit_Integration_Base {
 /** @return string */
 public static function slug() { return 'seo-by-rank-math'; }
 /** @return string */
 public static function label() { return 'Rank Math SEO'; }
 /** @return string[] */
 public static function host_files() { return array( 'seo-by-rank-math/rank-math.php' ); }
 /** @return bool */
 public static function is_active() { return defined( 'RANK_MATH_VERSION' ); }
 /** @return string|null */
 protected static function host_version() { return defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null; }
 /** @return string */
 protected static function max_tested_host_version() { return '1.0'; }
 /**
  * @param \WP_Screen|null $screen Current screen.
  * @return bool
  */
 public static function owns_screen( $screen ) { return $screen && in_array( $screen->base, array( 'post', 'term', 'edit-tags' ), true ); }
 /** @return void */
 public static function register_assets() {
  AdminKit_Assets::register( array(
   'handle' => 'adminkit-rankmath-editor',
   'src' => 'inc/integrations/plugins/seo-by-rank-math/css/editor.css',
   'deps' => array( AdminKit_Assets::TOKENS_HANDLE ),
   'context' => 'admin',
   'condition' => array( __CLASS__, 'owns_screen' ),
  ) );
 }
}
