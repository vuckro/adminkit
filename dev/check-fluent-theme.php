<?php
/** Shared Fluent theme stays scoped and respects each integration's opt-out. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
$allowed = true;
$current_screen = (object) array( 'id' => 'settings_page_fluent-mail' );
function is_admin() { return true; }
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
$allowed = false;
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( $screen ), 'Integration opt-out ignored' );
check_fluent( array() === AdminKit_Fluent_Theme::allow_assets( array() ), 'Disabled integration changed host asset policy' );
$allowed = true;
AdminKit_Integration_Fluent_Smtp::$compatible = false;
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( $screen ), 'Paused integration acquired the bridge' );
AdminKit_Integration_Fluent_Smtp::$compatible = true;
AdminKit_Integration_Fluent_Smtp::$active = false;
check_fluent( ! AdminKit_Fluent_Theme::owns_screen( $screen ), 'Inactive host acquired the bridge' );
echo "OK: Fluent standalone ownership, unrelated screens, inactive hosts, compatibility and integration opt-out.\n";
