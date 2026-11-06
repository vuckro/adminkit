<?php
/** Rank Math adapter follows host pages/assets and its integration lifecycle. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
define( 'RANK_MATH_VERSION', '1.0.281' );
$enqueued = array();
class AdminKit_Assets {
	const TOKENS_HANDLE = 'adminkit-tokens';
	public static $entries = array();
	public static function register( $entry ) { self::$entries[ $entry['handle'] ] = $entry; }
}
function wp_style_is( $handle, $state ) {
	global $enqueued;
	return 'enqueued' === $state && in_array( $handle, $enqueued, true );
}
function get_plugin_page_hookname( $page, $parent ) { return 'plugins_page_' . $page; }
function get_current_screen() { return $GLOBALS['screen']; }
require dirname( __DIR__ ) . '/inc/integrations/abstract-integration.php';
require dirname( __DIR__ ) . '/inc/integrations/plugins/rank-math/class-rank-math.php';
function check_rank_math( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
check_rank_math( AdminKit_Integration_Rank_Math::is_active(), 'Host detection failed' );
check_rank_math( AdminKit_Integration_Rank_Math::host_within_tested_range(), 'Tested version rejected' );
foreach ( array( 'toplevel_page_rank-math', 'toplevel_page_rank-math-network', 'rank-math_page_rank-math-options-general', 'rank-math_page_rank-math-status', 'rank-math_page_rank-math-role-manager' ) as $id ) {
	$GLOBALS['screen'] = (object) array( 'id' => $id );
	check_rank_math( AdminKit_Integration_Rank_Math::owns_asset_screen( $GLOBALS['screen'] ), 'Host page missed' );
	check_rank_math( str_contains( AdminKit_Integration_Rank_Math::body_class( 'adminkit' ), 'ak-rank-math-page' ), 'Stable scope missing' );
}
$GLOBALS['_parent_pages']['rank-math-options-general'] = 'plugins.php';
check_rank_math( AdminKit_Integration_Rank_Math::owns_screen( (object) array( 'id' => 'plugins_page_rank-math-options-general' ) ), 'Moved page missed' );
$GLOBALS['screen'] = (object) array( 'id' => 'dashboard' );
check_rank_math( 'adminkit' === AdminKit_Integration_Rank_Math::body_class( 'adminkit' ), 'Page scope leaked' );
check_rank_math( ! AdminKit_Integration_Rank_Math::owns_asset_screen( $GLOBALS['screen'] ), 'Unrelated screen accepted' );
check_rank_math( ! AdminKit_Integration_Rank_Math::owns_asset_screen( null ), 'Null screen accepted' );
foreach ( array( 'rank-math-common', 'rank-math-editor', 'rank-math-post-bulk-edit', 'rank-math-dashboard-widget' ) as $handle ) {
	$enqueued = array( $handle );
	check_rank_math( AdminKit_Integration_Rank_Math::owns_asset_screen( $GLOBALS['screen'] ), 'Host asset missed: ' . $handle );
}
AdminKit_Integration_Rank_Math::register_assets();
check_rank_math( 2 === count( AdminKit_Assets::$entries ), 'Expected shared and page-only assets' );
$pages = AdminKit_Assets::$entries['adminkit-rank-math-pages'];
check_rank_math( ! call_user_func( $pages['condition'], $GLOBALS['screen'] ), 'Page bundle leaked beside host widget/editor assets' );
check_rank_math( call_user_func( $pages['condition'], (object) array( 'id' => 'rank-math_page_rank-math-status' ) ), 'Secondary page bundle missing' );
check_rank_math( array( 'adminkit-rank-math' ) === $pages['deps'], 'Secondary bundle must follow shared styles' );
echo "OK: Rank Math host pages, moved pages, editor/list/widget assets and isolation.\n";
