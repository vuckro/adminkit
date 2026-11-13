<?php
/** Shared Fluent theme stays scoped and respects each integration's opt-out. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
$allowed = true;
$current_filter = 'fluent_crm_asset_listed_slugs';
$current_screen = (object) array( 'id' => 'settings_page_fluent-mail' );
function is_admin() { return true; }
function current_filter() { global $current_filter; return $current_filter; }
function get_current_screen() { global $current_screen; return $current_screen; }
function apply_filters( $hook, $value, ...$args ) {
	global $allowed;
	return 'adminkit/integration_enabled' === $hook ? $allowed : $value;
}
class AdminKit_Integration_Fluent_Smtp {
	public static $active = true;
	public static $compatible = true;
	public static function is_active() { return self::$active; }
	public static function host_within_tested_range() { return self::$compatible; }
	public static function slug() { return 'fluent-smtp'; }
	public static function owns_screen( $screen ) { return $screen && 'settings_page_fluent-mail' === $screen->id; }
}
class AdminKit_Integration_Fluent_Crm extends AdminKit_Integration_Fluent_Smtp {}
class AdminKit_Integration_Fluent_Boards {
	public static $active = true;
	public static $compatible = true;
	public static function is_active() { return self::$active; }
	public static function host_within_tested_range() { return self::$compatible; }
	public static function slug() { return 'fluent-boards'; }
	public static function owns_screen( $screen ) {
		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}
		return 'toplevel_page_fluent-boards' === $screen->id || '_page_fluent-boards' === substr( $screen->id, -19 );
	}
}
require dirname( __DIR__ ) . '/inc/class-fluent-theme.php';
$screen = (object) array( 'id' => 'settings_page_fluent-mail' );
function check_fluent( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
check_fluent( AdminKit_Fluent_Theme::owns_screen( $screen ), 'Standalone app must not depend on FluentHub' );
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( null ), 'Missing screen must not acquire the bridge' );
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( (object) array( 'id' => 'dashboard' ) ), 'Unrelated screen acquired the bridge' );
$patterns = AdminKit_Fluent_Theme::allow_assets( array( '\/fluent-crm\/' ) );
check_fluent( 2 === count( $patterns ) && 1 === preg_match( '/' . implode( '|', $patterns ) . '/', '/plugins/adminkit/assets/js/test.js' ), 'Native allowlist did not preserve our exact plugin directory' );
check_fluent( 0 === preg_match( '/' . implode( '|', $patterns ) . '/', '/plugins/not-adminkit/file.js' ), 'Allowlist matched a different plugin' );
$current_screen = null;
check_fluent( count( AdminKit_Fluent_Theme::allow_assets( array() ) ) === 1, 'Early native asset policy lost AdminKit before current_screen' );
$current_screen = $screen;
$allowed = false;
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( $screen ), 'Integration opt-out ignored' );
check_fluent( array() === AdminKit_Fluent_Theme::allow_assets( array() ), 'Disabled integration changed host asset policy' );
$allowed = true;
AdminKit_Integration_Fluent_Smtp::$compatible = false;
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( $screen ), 'Paused integration acquired the bridge' );
AdminKit_Integration_Fluent_Smtp::$compatible = true;
AdminKit_Integration_Fluent_Smtp::$active = false;
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( $screen ), 'Inactive host acquired the bridge' );
$current_filter = 'fluent_boards/asset_listed_slugs';
$current_screen = (object) array( 'id' => 'toplevel_page_fluent-boards' );
check_fluent( AdminKit_Fluent_Theme::owns_screen( $current_screen ), 'FluentBoards standalone screen failed bridge ownership' );
check_fluent( array( '\\/adminkit\\/' ) === AdminKit_Fluent_Theme::allow_assets( array() ), 'FluentBoards standalone screen failed allowlist' );
$current_screen = (object) array( 'id' => 'fluentcrm-admin_page_fluent-boards' );
check_fluent( AdminKit_Fluent_Theme::owns_screen( $current_screen ), 'FluentBoards CRM submenu failed bridge ownership' );
check_fluent( array( '\\/adminkit\\/' ) === AdminKit_Fluent_Theme::allow_assets( array() ), 'FluentBoards CRM submenu failed allowlist' );
$current_filter = 'fluent_crm_asset_listed_slugs';
$current_screen = null;
echo "OK: Fluent standalone ownership, unrelated screens, inactive hosts, compatibility and integration opt-out.\n";

// Both native admin hook paths may run; enqueue and inline data must remain unique.
class AdminKit_Assets {
 public static $calls = 0;
 public static function enqueue_script( ...$args ) { ++self::$calls; }
}
class AdminKit_Theme_Toggle {
 public static function attribute() { return 'data-adminkit-theme'; }
 public static function storage_key() { return 'adminkit-theme'; }
}
class AdminKit_Settings { public static function get( $key ) { return true; } }
function __( $text, $domain ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
AdminKit_Integration_Fluent_Smtp::$active = true;
$current_screen = $screen;
AdminKit_Fluent_Theme::enqueue();
AdminKit_Fluent_Theme::enqueue();
check_fluent( 1 === AdminKit_Assets::$calls, 'Multiple native hook paths duplicated the bootstrap' );
echo "OK: Fluent native hook paths enqueue one bootstrap.\n";
