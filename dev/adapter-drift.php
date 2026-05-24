#!/usr/bin/env php
<?php
/**
 * AdminKit drift detector — catches when a host plugin (or WP core) changes the
 * CSS surface an adapter depends on, so the adapter can be reviewed BEFORE it
 * silently breaks on an update.
 *
 * Each adapter is skinned against a host's CSS: Tier A remaps the host's CSS
 * variables, Tier B overrides its selectors / hardcoded colors. When the host
 * ships a new version it can rename a variable, drop a class or rebrand a color —
 * and the adapter quietly stops matching. This tool freezes a *baseline* of the
 * host's CSS surface (its variables + dominant colors, scanned with the same
 * engine as adapter-scan.php), then re-scans on demand and reports the diff.
 *
 *   baseline.json lives next to the adapter:
 *     inc/integrations/{plugins|themes}/{slug}/baseline.json
 *   WP core's baseline lives at dev/baselines/wp-core.json
 *
 * Usage (run from anywhere inside the AdminKit repo):
 *   php dev/adapter-drift.php                      # diff every baseline vs the installed host
 *   php dev/adapter-drift.php --slug=acf           # one integration
 *   php dev/adapter-drift.php --wp-core            # WP core admin CSS
 *   php dev/adapter-drift.php --slug=acf --update  # (re)capture the baseline from the host
 *   php dev/adapter-drift.php --slug=acf --host=../advanced-custom-fields --update
 *   php dev/adapter-drift.php --md                 # markdown report (paste into an issue/PR)
 *
 * The host plugin must be installed (a sibling under wp-content/plugins/) to scan
 * it; integrations whose host is absent are skipped. Exit non-zero when drift
 * that can break an adapter is found (a host variable removed/changed, or the
 * brand color changed) — a CI/pre-update gate. New variables/colors are reported
 * as opportunities, not failures.
 *
 * @package AdminKit
 */

error_reporting( E_ALL & ~E_DEPRECATED );

require_once __DIR__ . '/css-scan.php';

define( 'AK_DRIFT_TOP', 80 ); // colors kept per baseline (by usage)

$argv = $_SERVER['argv'];
array_shift( $argv );

$opts = array( 'slug' => '', 'host' => '', 'update' => false, 'md' => false, 'wpcore' => false );
foreach ( $argv as $arg ) {
	if ( '--update' === $arg )  { $opts['update'] = true; continue; }
	if ( '--md' === $arg )      { $opts['md'] = true; continue; }
	if ( '--wp-core' === $arg ) { $opts['wpcore'] = true; continue; }
	if ( preg_match( '/^--slug=(.+)$/', $arg, $m ) ) { $opts['slug'] = $m[1]; continue; }
	if ( preg_match( '/^--host=(.+)$/', $arg, $m ) ) { $opts['host'] = $m[1]; continue; }
}

$root     = ak_find_root();
$nl       = "\n";
$drift    = false; // any breaking drift across all targets
$reported = 0;

$targets = ak_drift_targets( $root, $opts );
if ( ! $targets ) {
	fwrite( STDERR, "No baselines found. Capture one with:\n" );
	fwrite( STDERR, "  " . AK_DRIFT_CMD . " --slug=<slug> --host=../<host-dir> --update\n" );
	fwrite( STDERR, "  " . AK_DRIFT_CMD . " --wp-core --update\n" );
	exit( 2 );
}

$lines = array();
foreach ( $targets as $t ) {
	$host = ak_resolve_host( $root, $t, $opts );

	if ( null === $host['path'] || ! is_dir( $host['path'] ) ) {
		$lines[] = ak_h( $t['slug'] ) . "  skipped — host not installed" . ( $t['host_dir'] ? " ({$t['host_dir']})" : '' );
		continue;
	}

	$files = ak_collect_css_files( array( $host['path'] ) );
	if ( ! $files ) {
		$lines[] = ak_h( $t['slug'] ) . "  skipped — no CSS found under " . $host['path'];
		continue;
	}
	$snap = ak_snapshot( ak_scan_css( $files ), $host, count( $files ) );

	// ── Capture / refresh the baseline ──────────────────────────────────────
	if ( $opts['update'] ) {
		$baseline = array(
			'slug'         => $t['slug'],
			'host_dir'     => $host['dir'],
			'host_version' => $host['version'],
			'captured_at'  => gmdate( 'Y-m-d' ),
			'files'        => $snap['files'],
			'brand'        => $snap['brand'],
			'variables'    => $snap['variables'],
			'colors'       => $snap['colors'],
		);
		if ( false === file_put_contents( $t['baseline_path'], ak_json( $baseline ) . $nl ) ) {
			fwrite( STDERR, "x could not write {$t['baseline_path']}\n" );
			exit( 1 );
		}
		$rel = ltrim( str_replace( $root, '', $t['baseline_path'] ), '/' );
		$lines[] = ak_h( $t['slug'] ) . "  captured — {$snap['files']} file(s), "
			. count( $snap['variables'] ) . " vars, " . count( $snap['colors'] ) . " colors @ host "
			. ( $host['version'] ?: '?' ) . "  →  {$rel}";
		continue;
	}

	// ── Diff against the stored baseline ────────────────────────────────────
	if ( ! $t['baseline'] ) {
		$lines[] = ak_h( $t['slug'] ) . "  no baseline yet — run --slug={$t['slug']} --update to capture";
		continue;
	}

	$d = ak_diff( $t['baseline'], $snap );
	$reported++;
	if ( $d['breaking'] ) {
		$drift = true;
	}
	$lines[] = ak_render( $t, $host, $d, $opts['md'] );
}

// ── Output ──────────────────────────────────────────────────────────────────
echo $nl . ( $opts['md'] ? "# AdminKit drift report" : str_repeat( '=', 78 ) . $nl . "AdminKit drift report" ) . $nl;
echo $opts['md'] ? $nl : str_repeat( '=', 78 ) . $nl;
echo implode( $opts['md'] ? $nl . $nl : $nl, $lines ) . $nl;

if ( ! $opts['update'] && $reported ) {
	echo $nl . ( $opts['md'] ? '---' . $nl : str_repeat( '-', 78 ) . $nl );
	if ( $drift ) {
		echo "DRIFT: a host changed the CSS surface an adapter relies on (details above)." . $nl;
		echo "Review the adapter's css/admin.css + extension points (docs/EXTENDING.md), then" . $nl;
		echo "re-capture with --update once reconciled." . $nl . $nl;
		exit( 1 );
	}
	echo "OK: every baseline still matches its host (new colors/vars are opportunities, not breaks)." . $nl . $nl;
}
exit( 0 );


/* ============================ targets + host ============================ */

define( 'AK_DRIFT_CMD', 'php dev/adapter-drift.php' );

/**
 * Resolve the list of drift targets: each has slug, host_dir, baseline path and
 * the loaded baseline (or null). Honors --slug / --wp-core; otherwise every
 * integration that already has a baseline.json.
 *
 * @param string $root
 * @param array  $opts
 * @return array<int,array>
 */
function ak_drift_targets( $root, array $opts ) {
	$out = array();

	if ( $opts['wpcore'] ) {
		$path = $root . '/dev/baselines/wp-core.json';
		$out[] = ak_target( 'wp-core', $path, true );
		return $out;
	}

	if ( '' !== $opts['slug'] ) {
		// Find the adapter folder for an explicit slug (plugins/ or themes/).
		foreach ( glob( $root . '/inc/integrations/*/' . $opts['slug'], GLOB_ONLYDIR ) as $dir ) {
			$out[] = ak_target( $opts['slug'], $dir . '/baseline.json', false );
			return $out;
		}
		// Unknown folder but --update can still create one under plugins/.
		$out[] = ak_target( $opts['slug'], $root . '/inc/integrations/plugins/' . $opts['slug'] . '/baseline.json', false );
		return $out;
	}

	// All integrations that already carry a baseline.
	foreach ( glob( $root . '/inc/integrations/*/*/baseline.json' ) as $bp ) {
		$out[] = ak_target( basename( dirname( $bp ) ), $bp, false );
	}
	// Plus WP core, if its baseline exists.
	$wp = $root . '/dev/baselines/wp-core.json';
	if ( is_file( $wp ) ) {
		$out[] = ak_target( 'wp-core', $wp, true );
	}
	return $out;
}

/**
 * Build a single target descriptor, loading its baseline if present.
 *
 * @param string $slug
 * @param string $baseline_path
 * @param bool   $is_wp_core
 * @return array
 */
function ak_target( $slug, $baseline_path, $is_wp_core ) {
	$baseline = is_file( $baseline_path ) ? json_decode( (string) file_get_contents( $baseline_path ), true ) : null;
	return array(
		'slug'          => $slug,
		'baseline_path' => $baseline_path,
		'baseline'      => is_array( $baseline ) ? $baseline : null,
		'is_wp_core'    => $is_wp_core,
		'host_dir'      => is_array( $baseline ) && isset( $baseline['host_dir'] ) ? $baseline['host_dir'] : '',
	);
}

/**
 * Resolve a target's host path + version. Plugins live as siblings under
 * wp-content/plugins/; WP core is scanned from ABSPATH/wp-admin/css.
 *
 * @param string $root
 * @param array  $t
 * @param array  $opts
 * @return array{path:?string,dir:string,version:?string}
 */
function ak_resolve_host( $root, array $t, array $opts ) {
	$plugins_dir = dirname( $root );      // wp-content/plugins
	$abspath     = dirname( $root, 3 );   // WP root (…/public)

	if ( $t['is_wp_core'] ) {
		$path = $abspath . '/wp-admin/css';
		return array(
			'path'    => is_dir( $path ) ? $path : null,
			'dir'     => 'wp-admin/css',
			'version' => ak_wp_version( $abspath ),
		);
	}

	// host_dir: --host override → baseline's host_dir → guess (slug).
	$dir = $t['host_dir'];
	if ( '' !== $opts['host'] ) {
		$path = ak_abs( $opts['host'], $root );
		$dir  = basename( rtrim( $opts['host'], '/' ) );
	} elseif ( '' !== $dir ) {
		$path = $plugins_dir . '/' . $dir;
	} else {
		$dir  = $t['slug'];
		$path = $plugins_dir . '/' . $dir;
	}

	return array(
		'path'    => is_dir( $path ) ? $path : null,
		'dir'     => $dir,
		'version' => is_dir( $path ) ? ak_plugin_version( $path ) : null,
	);
}

/** Absolute path for a possibly-relative --host argument (relative to repo root). */
function ak_abs( $p, $root ) {
	if ( '' === $p ) {
		return $p;
	}
	if ( '/' === $p[0] ) {
		return $p;
	}
	return $root . '/' . $p;
}

/** Read a plugin's Version: header from its main file. */
function ak_plugin_version( $dir ) {
	foreach ( glob( $dir . '/*.php' ) as $f ) {
		$head = (string) file_get_contents( $f, false, null, 0, 8192 );
		if ( '' === $head || false === stripos( $head, 'Plugin Name:' ) ) {
			continue;
		}
		if ( preg_match( '/Version:\s*([0-9][0-9A-Za-z.\-]*)/i', $head, $m ) ) {
			return trim( $m[1] );
		}
	}
	return null;
}

/** Read $wp_version from wp-includes/version.php. */
function ak_wp_version( $abspath ) {
	$f = $abspath . '/wp-includes/version.php';
	if ( ! is_file( $f ) ) {
		return null;
	}
	if ( preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)/', (string) file_get_contents( $f ), $m ) ) {
		return $m[1];
	}
	return null;
}


/* ============================ snapshot + diff ============================ */

/**
 * Reduce a raw scan to the stable baseline shape: host variables (name→value)
 * and the top colors by usage (key → count / dominant category / suggested
 * token), plus the promoted brand color key.
 *
 * @param array $scan ak_scan_css() result
 * @param array $host resolved host (for version)
 * @param int   $fileCount
 * @return array
 */
function ak_snapshot( array $scan, array $host, $fileCount ) {
	$brand = ak_promote_brand( $scan['colors'] );

	$vars = array();
	foreach ( $scan['varDefs'] as $name => $d ) {
		$vars[ $name ] = $d['value'];
	}
	ksort( $vars );

	$colors = $scan['colors'];
	uasort( $colors, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
	$colors = array_slice( $colors, 0, AK_DRIFT_TOP, true );

	$slim = array();
	foreach ( $colors as $key => $c ) {
		$cats = $c['cats'];
		$slim[ $key ] = array(
			'count' => $c['count'],
			'cat'   => (string) array_key_first( $cats ),
			'token' => $c['token'],
		);
	}

	return array(
		'files'     => $fileCount,
		'brand'     => $brand,
		'variables' => $vars,
		'colors'    => $slim,
	);
}

/**
 * Diff a stored baseline against a fresh snapshot. Variables drive the breaking
 * verdict (a removed/changed host variable kills a Tier A remap); a changed
 * brand color also breaks. New vars/colors and removed colors are reported but
 * non-breaking (opportunities / churn).
 *
 * @param array $base baseline (has variables, colors, brand, host_version)
 * @param array $snap fresh snapshot
 * @return array
 */
function ak_diff( array $base, array $snap ) {
	$bVars = isset( $base['variables'] ) ? $base['variables'] : array();
	$sVars = $snap['variables'];
	$bCols = isset( $base['colors'] ) ? $base['colors'] : array();
	$sCols = $snap['colors'];

	$varsRemoved = array_diff_key( $bVars, $sVars );
	$varsAdded   = array_diff_key( $sVars, $bVars );
	$varsChanged = array();
	foreach ( array_intersect_key( $bVars, $sVars ) as $k => $v ) {
		if ( (string) $v !== (string) $sVars[ $k ] ) {
			$varsChanged[ $k ] = array( $v, $sVars[ $k ] );
		}
	}

	$colsRemoved = array_diff_key( $bCols, $sCols );
	$colsAdded   = array_diff_key( $sCols, $bCols );

	$brandChanged = isset( $base['brand'] ) && $base['brand'] !== $snap['brand'];

	$breaking = ( $varsRemoved || $varsChanged || $brandChanged );

	return array(
		'host_version' => array( isset( $base['host_version'] ) ? $base['host_version'] : null, isset( $snap['host_version'] ) ? $snap['host_version'] : null ),
		'varsRemoved'  => $varsRemoved,
		'varsAdded'    => $varsAdded,
		'varsChanged'  => $varsChanged,
		'colsRemoved'  => $colsRemoved,
		'colsAdded'    => $colsAdded,
		'brand'        => array( isset( $base['brand'] ) ? $base['brand'] : null, $snap['brand'] ),
		'brandChanged' => $brandChanged,
		'breaking'     => $breaking,
	);
}


/* ============================ rendering ============================ */

/** Section header for one target. */
function ak_h( $slug ) {
	return str_pad( $slug, 18 );
}

/** Render one target's diff (text or markdown). */
function ak_render( array $t, array $host, array $d, $md ) {
	$slug   = $t['slug'];
	$bv     = isset( $t['baseline']['host_version'] ) ? $t['baseline']['host_version'] : '?';
	$cv     = $host['version'] ?: '?';
	$verTag = ( $bv !== $cv ) ? " (was {$bv})" : '';
	$tag    = $d['breaking'] ? 'DRIFT' : ( ( $d['varsAdded'] || $d['colsAdded'] || $d['colsRemoved'] ) ? 'note' : 'ok' );

	$L   = array();
	$L[] = ( $md ? "## {$slug} — {$tag}" : "{$slug}  [{$tag}]  host {$cv}{$verTag}" );
	if ( $md ) {
		$L[] = "host `{$cv}`" . ( $bv !== $cv ? " (baseline `{$bv}`)" : '' );
	}

	$bullet = $md ? '- ' : '  • ';

	if ( $d['brandChanged'] ) {
		$L[] = $bullet . "brand/accent color changed: " . ak_ckey( $d['brand'][0] ) . " → " . ak_ckey( $d['brand'][1] ) . " — review the accent mapping";
	}
	foreach ( $d['varsRemoved'] as $name => $val ) {
		$L[] = $bullet . "variable REMOVED: `{$name}` (was {$val}) — any Tier A remap of it is now dead";
	}
	foreach ( $d['varsChanged'] as $name => $pair ) {
		$L[] = $bullet . "variable CHANGED: `{$name}` {$pair[0]} → {$pair[1]}";
	}
	foreach ( array_slice( $d['varsAdded'], 0, 12, true ) as $name => $val ) {
		$L[] = $bullet . "variable added: `{$name}` ({$val}) — consider remapping to a token";
	}
	if ( count( $d['varsAdded'] ) > 12 ) {
		$L[] = $bullet . '… and ' . ( count( $d['varsAdded'] ) - 12 ) . ' more new variables';
	}
	foreach ( array_slice( $d['colsAdded'], 0, 8, true ) as $key => $c ) {
		$L[] = $bullet . "color added: " . ak_ckey( $key ) . " ({$c['count']}× {$c['cat']} → {$c['token']})";
	}
	foreach ( array_slice( $d['colsRemoved'], 0, 8, true ) as $key => $c ) {
		$L[] = $bullet . "color gone: " . ak_ckey( $key ) . " (was {$c['count']}× → {$c['token']}) — any override for it may be stale";
	}

	if ( count( $L ) === ( $md ? 2 : 1 ) ) {
		$L[] = $bullet . "no change in the tracked CSS surface";
	}
	return implode( "\n", $L );
}

/** "rrggbb@a.aa" → "#rrggbb" (drop the opaque alpha suffix for readability). */
function ak_ckey( $key ) {
	if ( null === $key ) {
		return '(none)';
	}
	$hex = substr( $key, 0, 6 );
	$a   = substr( $key, 7 );
	return '#' . $hex . ( '1.00' === $a ? '' : ' a' . $a );
}

/** Pretty JSON with unescaped slashes/unicode, stable for git diffs. */
function ak_json( $data ) {
	return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}
