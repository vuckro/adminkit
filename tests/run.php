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
 * by walking up from here) AND its database is reachable, the DB-backed tests run
 * too. Otherwise they SKIP cleanly (clearly reported) and only the pure tests run
 * — so CI can run this without a database. An explicit WP_LOAD with an unreachable
 * database fails fast with a clear error. Exits non-zero if any test fails (CI gate).
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

/**
 * Minimal WordPress function/constant shims for the pure test path.
 *
 * Keep these deliberately small: they only cover the pure methods exercised
 * below. Anything that needs real WordPress belongs in the DB-backed block.
 *
 * @return void
 */
function ak_boot_pure_wp_stubs() {
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/tmp/' ); }
	if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook_name, $value = null ) {
			return $value;
		}
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text, $domain = 'default' ) {
			return $text;
		}
	}
	if ( ! function_exists( 'esc_attr__' ) ) {
		function esc_attr__( $text, $domain = 'default' ) {
			return $text;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter() {
			return true;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action() {
			return true;
		}
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = 0 ) {
			if ( 'timestamp' === $type || 'U' === $type ) {
				return time();
			}
			if ( 'mysql' === $type ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
			return gmdate( (string) $type );
		}
	}
}

/**
 * Find wp-config.php next to, or one level above, wp-load.php.
 *
 * @param string $wp_load Absolute path to wp-load.php.
 * @return string
 */
function ak_wp_config_for_load( $wp_load ) {
	$root = dirname( (string) realpath( $wp_load ) );
	foreach ( array( $root . '/wp-config.php', dirname( $root ) . '/wp-config.php' ) as $candidate ) {
		if ( is_file( $candidate ) ) {
			return $candidate;
		}
	}
	return '';
}

/**
 * Read simple define( 'DB_*', '...' ) values from wp-config.php.
 *
 * @param string $wp_config Absolute path to wp-config.php.
 * @return array<string,string>
 */
function ak_wp_db_config( $wp_config ) {
	$contents = file_get_contents( $wp_config ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $contents ) {
		return array();
	}
	$out = array();
	foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $key ) {
		if ( preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $key, '/' ) . '[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s', $contents, $m ) ) {
			$out[ $key ] = stripcslashes( $m[2] );
		}
	}
	return $out;
}

/**
 * Preflight the WordPress database before requiring wp-load.php. Without this,
 * a LocalWP checkout with MySQL stopped exits through WordPress' HTML DB error
 * before the pure tests can run.
 *
 * Returns ok=true when the config cannot be inspected; in that case the runner
 * falls back to WordPress' normal bootstrap behaviour.
 *
 * @param string $wp_load Absolute path to wp-load.php.
 * @return array{ok:bool,reason:string}
 */
function ak_wp_db_preflight( $wp_load ) {
	if ( ! function_exists( 'mysqli_init' ) ) {
		return array( 'ok' => true, 'reason' => '' );
	}
	$wp_config = ak_wp_config_for_load( $wp_load );
	if ( '' === $wp_config ) {
		return array( 'ok' => true, 'reason' => '' );
	}
	$db = ak_wp_db_config( $wp_config );
	foreach ( array( 'DB_NAME', 'DB_USER', 'DB_HOST' ) as $required ) {
		if ( ! array_key_exists( $required, $db ) ) {
			return array( 'ok' => true, 'reason' => '' );
		}
	}

	$host   = '' !== $db['DB_HOST'] ? $db['DB_HOST'] : 'localhost';
	$port   = null;
	$socket = null;
	if ( preg_match( '/^([^:]+):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	} elseif ( preg_match( '/^([^:]+):(.+)$/', $host, $m ) ) {
		$host   = $m[1];
		$socket = $m[2];
	}

	$mysqli = mysqli_init();
	if ( ! $mysqli ) {
		return array( 'ok' => true, 'reason' => '' );
	}
	$driver     = new mysqli_driver();
	$old_report = $driver->report_mode;
	mysqli_report( MYSQLI_REPORT_OFF );
	$ok         = @$mysqli->real_connect(
		$host,
		$db['DB_USER'],
		isset( $db['DB_PASSWORD'] ) ? $db['DB_PASSWORD'] : '',
		$db['DB_NAME'],
		$port,
		$socket
	);
	$error      = $ok ? '' : mysqli_connect_error();
	if ( $ok ) {
		$mysqli->close();
	}
	mysqli_report( $old_report );

	return array(
		'ok'     => (bool) $ok,
		'reason' => $ok ? '' : sprintf( 'database unavailable for %s@%s (%s)', $db['DB_USER'], $db['DB_HOST'], $error ),
	);
}

/* ── locate WordPress (optional) ───────────────────────────────────────── */
$wp_load_env = getenv( 'WP_LOAD' );
$wp_load     = $wp_load_env ?: '';
$wp_reason   = 'no WordPress (set WP_LOAD=...)';
$auto_wp     = false;
if ( '' === $wp_load ) {
	// Walk up from this plugin looking for wp-load.php (works inside a WP install).
	$dir = __DIR__;
	for ( $i = 0; $i < 8 && $dir !== dirname( $dir ); $i++ ) {
		if ( is_file( "$dir/wp-load.php" ) ) { $wp_load = "$dir/wp-load.php"; $auto_wp = true; break; }
		$dir = dirname( $dir );
	}
}
$has_wp = false;
if ( $wp_load && is_file( $wp_load ) ) {
	$preflight = ak_wp_db_preflight( $wp_load );
	if ( ! $preflight['ok'] ) {
		$wp_reason = ( $auto_wp ? 'auto-detected WordPress skipped: ' : 'WordPress skipped: ' ) . $preflight['reason'];
		if ( ! $auto_wp && $wp_load_env ) {
			fwrite( STDERR, "error: WP_LOAD cannot bootstrap WordPress: {$preflight['reason']}\n" );
			exit( 1 );
		}
	} else {
		$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
		$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
		if ( ! defined( 'WP_USE_THEMES' ) ) { define( 'WP_USE_THEMES', false ); }
		require $wp_load;
		$has_wp = class_exists( 'AdminKit_Plugin' );
		if ( ! $has_wp ) {
			$wp_reason = 'WordPress loaded, but AdminKit is not active';
		}
	}
} elseif ( $wp_load_env ) {
	$wp_reason = 'WP_LOAD does not point to a readable wp-load.php';
}

$plugin = dirname( __DIR__ );

/* ════════════════════════════════════════════════════════════════════════
 * PURE TESTS — no database. Run everywhere (incl. CI).
 * ════════════════════════════════════════════════════════════════════════ */

ak_group( 'Dashboard default card order (pure)' );
if ( ! $has_wp ) {
	ak_boot_pure_wp_stubs();
	require_once "$plugin/inc/features/dashboard/class-custom-dashboard.php";
	require_once "$plugin/inc/features/stats/class-stats-dashboard.php";
}
$m = new ReflectionMethod( 'AdminKit_Custom_Dashboard', 'default_order' );
$m->setAccessible( true );
$order = $m->invoke( null );
ak_eq( 'stats', $order['main'][0] ?? null, 'Statistics is the first main card' );
ak_ok( in_array( 'glance', $order['side'] ?? array(), true ), '“At a glance” lives in the side column' );
ak_ok( ! in_array( 'glance', $order['main'] ?? array(), true ), '“At a glance” is not in the main column' );

ak_group( 'Stats preset ranges (pure)' );
if ( class_exists( 'AdminKit_Stats_Dashboard' ) ) {
	$pr = new ReflectionMethod( 'AdminKit_Stats_Dashboard', 'preset_range' );
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
	ak_skip( 'Stats store / Menu store / Settings sanitize', $wp_reason );
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

	ak_group( 'Stats store — record → aggregation' );
	AdminKit_Stats_Store::ensure_schema();
	$today = current_time( 'Y-m-d' );
	// Assert through query_summary_range() — the UNCACHED aggregator. summary_range()
	// wraps it in a 5-minute transient, so a write-then-read in the same window would
	// see stale data (correct in production, useless for asserting the increment).
	// We test the SQL aggregation deterministically here; the cached public wrapper's
	// contract (shape) is checked separately below.
	$q = new ReflectionMethod( 'AdminKit_Stats_Store', 'query_summary_range' );
	$q->setAccessible( true );
	$base = (int) ( $q->invoke( null, $today, $today, 50 )['days'][ $today ]['pageviews'] ?? 0 );
	AdminKit_Stats_Store::record( '/ak-test-page/', true, 'example.com' );   // a visit + pageview
	AdminKit_Stats_Store::record( '/ak-test-page/', false, '' );             // a pageview, not a visit
	$after = $q->invoke( null, $today, $today, 50 );
	ak_eq( $base + 2, (int) ( $after['days'][ $today ]['pageviews'] ?? 0 ), 'two records add two pageviews for today' );
	$paths = array();
	foreach ( (array) ( $after['top_pages'] ?? array() ) as $r ) { $paths[ $r['name'] ] = (int) $r['pageviews']; }
	ak_ok( ( $paths['/ak-test-page/'] ?? 0 ) >= 2, 'the test path shows in top_pages' );

	ak_group( 'Stats store — public summary_range() contract' );
	$sum = AdminKit_Stats_Store::summary_range( $today, $today );
	foreach ( array( 'start', 'end', 'days', 'top_pages', 'top_sources' ) as $k ) {
		ak_ok( array_key_exists( $k, $sum ), "summary_range() returns a '$k' key" );
	}

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
