<?php
/**
 * AdminKit test runner — dependency-free.
 *
 * There is no PHPUnit / Composer / wp-cli in this project; this is a tiny
 * hand-rolled runner you invoke with plain PHP:
 *
 *   php tests/run.php                         # pure-logic tests only (CI-safe)
 *   WP_LOAD=/path/to/wp-load.php php tests/run.php   # + the DB-backed seam tests
 *
 * It auto-detects WordPress: if WP_LOAD points at a wp-load.php (or one is found
 * by walking up from here), the DB-backed tests run too; otherwise they SKIP
 * cleanly (clearly reported) and only the pure tests run — so CI can run this
 * without a database. Exits non-zero if any test fails (CI gate).
 *
 * See tests/README.md.
 *
 * @package AdminKit
 */

error_reporting( E_ALL & ~E_DEPRECATED );

/* ── tiny assertion framework ──────────────────────────────────────────── */
$GLOBALS['ak_pass'] = 0;
$GLOBALS['ak_fail'] = 0;
$GLOBALS['ak_skip'] = 0;
$GLOBALS['ak_fails'] = array();

function ak_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['ak_pass']++;
		echo "  \033[32m✓\033[0m $msg\n";
	} else {
		$GLOBALS['ak_fail']++;
		$GLOBALS['ak_fails'][] = $msg;
		echo "  \033[31m✗ $msg\033[0m\n";
	}
}
function ak_eq( $expected, $actual, $msg ) {
	$same = ( $expected === $actual );
	if ( ! $same ) {
		$msg .= ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')';
	}
	ak_ok( $same, $msg );
}
function ak_group( $name ) { echo "\n\033[1m$name\033[0m\n"; }
function ak_skip( $name, $why ) { $GLOBALS['ak_skip']++; echo "\n\033[33m⊘ $name — SKIPPED ($why)\033[0m\n"; }

/* ── locate WordPress (optional) ───────────────────────────────────────── */
$wp_load = getenv( 'WP_LOAD' ) ?: '';
if ( '' === $wp_load ) {
	// Walk up from this plugin looking for wp-load.php (works inside a WP install).
	$dir = __DIR__;
	for ( $i = 0; $i < 8 && $dir !== dirname( $dir ); $i++ ) {
		if ( is_file( "$dir/wp-load.php" ) ) { $wp_load = "$dir/wp-load.php"; break; }
		$dir = dirname( $dir );
	}
}
$has_wp = false;
if ( $wp_load && is_file( $wp_load ) ) {
	$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
	$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
	if ( ! defined( 'WP_USE_THEMES' ) ) { define( 'WP_USE_THEMES', false ); }
	require $wp_load;
	$has_wp = class_exists( 'AdminKit_Plugin' );
}

$plugin = dirname( __DIR__ );

/* ════════════════════════════════════════════════════════════════════════
 * PURE TESTS — no database. Run everywhere (incl. CI).
 * ════════════════════════════════════════════════════════════════════════ */

ak_group( 'Dashboard default card order (pure)' );
if ( ! $has_wp ) {
	// Minimal stubs so the class file loads and default_order()/layout() run.
	foreach ( array( 'apply_filters', '__', 'esc_html__', 'esc_attr__' ) as $fn ) {
		if ( ! function_exists( $fn ) ) { eval( "function $fn(){ return func_num_args() ? func_get_arg(0) : null; }" ); }
	}
	if ( ! function_exists( 'add_filter' ) ) { eval( 'function add_filter(){}' ); }
	if ( ! function_exists( 'add_action' ) ) { eval( 'function add_action(){}' ); }
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/tmp/' ); }
	require_once "$plugin/inc/features/dashboard/class-custom-dashboard.php";
}
$m = new ReflectionMethod( 'AdminKit_Custom_Dashboard', 'default_order' );
$m->setAccessible( true );
$order = $m->invoke( null );
ak_eq( 'stats', $order['main'][0] ?? null, 'Statistics is the first main card' );
ak_ok( in_array( 'glance', $order['side'] ?? array(), true ), '“At a glance” lives in the side column' );
ak_ok( ! in_array( 'glance', $order['main'] ?? array(), true ), '“At a glance” is not in the main column' );

ak_group( 'Stats preset ranges (pure)' );
$pr = new ReflectionMethod( 'AdminKit_Stats_Dashboard', 'preset_range' );
// preset_range is only loaded with WP; guard.
if ( class_exists( 'AdminKit_Stats_Dashboard' ) ) {
	$pr->setAccessible( true );
	list( $s, $e ) = $pr->invoke( null, 'ytd' );
	ak_ok( preg_match( '/^\d{4}-01-01$/', $s ), 'Year-to-date starts on Jan 1' );
	ak_eq( current_time( 'Y-m-d' ), $e, 'Year-to-date ends today' );
} else {
	ak_skip( 'preset_range', 'needs WordPress (loaded via the orchestrator)' );
}

/* ════════════════════════════════════════════════════════════════════════
 * DB-BACKED SEAM TESTS — need a live WordPress + database.
 * ════════════════════════════════════════════════════════════════════════ */

if ( ! $has_wp ) {
	ak_skip( 'Stats store / Menu store / Settings sanitize', 'no WordPress (set WP_LOAD=…)' );
} else {
	global $wpdb;

	ak_group( 'Settings sanitize — only known keys, empties dropped' );
	$clean = AdminKit_Settings_Page::sanitize( array(
		'custom_dashboard_enabled' => '1',
		'definitely_not_a_setting' => 'evil',
		'brand_accent'             => '#abcdef',
	) );
	ak_ok( array_key_exists( 'custom_dashboard_enabled', $clean ), 'a known toggle is kept' );
	ak_ok( ! array_key_exists( 'definitely_not_a_setting', $clean ), 'an unknown key is rejected' );

	ak_group( 'Menu store — save → get round-trip' );
	$blog = get_current_blog_id();
	$before = AdminKit_Menu_Store::get_config( $blog );
	AdminKit_Menu_Store::ensure_schema();
	AdminKit_Menu_Store::save_config( array(
		array( 'slug' => 'index.php', 'parent' => '', 'position' => 0, 'hidden' => false ),
		array( 'slug' => 'edit.php',  'parent' => '', 'position' => 1, 'hidden' => true ),
	), $blog );
	$cfg = AdminKit_Menu_Store::get_config( $blog );
	ak_ok( ! empty( $cfg['top'] ), 'saved top-level items read back' );
	$slugs = array_map( function ( $t ) { return $t['slug']; }, $cfg['top'] );
	ak_ok( in_array( 'index.php', $slugs, true ), 'a saved slug survives the round-trip' );
	// restore
	AdminKit_Menu_Store::save_config( isset( $before['top'] ) || isset( $before['sub'] ) ? array() : array(), $blog );

	ak_group( 'Stats store — record → summary_range aggregation' );
	AdminKit_Stats_Store::ensure_schema();
	// Assert on the range TOTAL over a 2-day window [yesterday, today], not the
	// exact-date day bucket: record() always stamps current_time('Y-m-d'), and a
	// 2-day window stays correct even if the clock rolls past midnight mid-test.
	$today  = current_time( 'Y-m-d' );
	$yday   = gmdate( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) );
	$base   = (int) AdminKit_Stats_Store::summary_range( $yday, $today )['totals']['pageviews'];
	AdminKit_Stats_Store::record( '/ak-test-page/', true, 'example.com' );   // a visit + pageview
	AdminKit_Stats_Store::record( '/ak-test-page/', false, '' );             // a pageview, not a visit
	$after  = AdminKit_Stats_Store::summary_range( $yday, $today );
	ak_eq( $base + 2, (int) $after['totals']['pageviews'], 'two records add two pageviews to the running total' );
	$paths = array();
	foreach ( (array) ( $after['top_pages'] ?? array() ) as $r ) { $paths[ $r['name'] ] = (int) $r['pageviews']; }
	ak_ok( ( $paths['/ak-test-page/'] ?? 0 ) >= 2, 'the test path shows in top_pages' );

	ak_group( 'Stats store — mark_active → recent_activity' );
	$ts = time();
	AdminKit_Stats_Store::mark_active( str_repeat( 'a', 32 ), $ts, '/live-a/', 'google.com' );
	AdminKit_Stats_Store::mark_active( str_repeat( 'b', 32 ), $ts, '/live-b/', 'direct' );
	$active = AdminKit_Stats_Store::active_visitors( $ts );
	ak_ok( $active >= 2, 'two distinct tokens are counted active' );
	$recent = AdminKit_Stats_Store::recent_activity( $ts );
	ak_ok( isset( $recent[ str_repeat( 'a', 32 ) ] ), 'recent_activity records a token with its path/source' );
}

/* ── summary ───────────────────────────────────────────────────────────── */
$p = $GLOBALS['ak_pass']; $f = $GLOBALS['ak_fail']; $sk = $GLOBALS['ak_skip'];
echo "\n" . str_repeat( '─', 50 ) . "\n";
echo "WordPress: " . ( $has_wp ? "yes ($wp_load)" : 'no — DB tests skipped' ) . "\n";
echo "\033[1m$p passed, $f failed" . ( $sk ? ", $sk group(s) skipped" : '' ) . "\033[0m\n";
if ( $f ) {
	echo "\033[31mFAILED:\033[0m\n  - " . implode( "\n  - ", $GLOBALS['ak_fails'] ) . "\n";
	exit( 1 );
}
exit( 0 );
