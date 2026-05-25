#!/usr/bin/env php
<?php
/**
 * AdminKit auto-theme calibration — keep the runtime detector learning from the
 * adapters we ship.
 *
 * The runtime engine (assets/js/wp-core/auto-theme.js) classifies colours with
 * the SAME logic as ak_classify() in dev/css-scan.php — the logic behind every
 * hand-tuned adapter. This tool turns the real plugin colours each adapter has
 * captured (their baseline.json + the WP-core baseline) into a CORPUS and re-runs
 * the classifier over it. So every integration we add or update feeds the
 * calibration: you see how the classifier maps real-world colours, which ones sit
 * on a band EDGE (most likely to be wrong → review), and where the classifier now
 * DISAGREES with what a baseline captured (it evolved → confirm it's an improvement,
 * then re-capture the baseline).
 *
 * The loop, every time an adapter is added/updated:
 *   1. php dev/adapter-drift.php --slug=<slug> --host=<path> --update   # capture
 *   2. php dev/auto-theme-calibrate.php                                 # review
 *   3. mis-mapped? Tune ak_classify() in dev/css-scan.php AND mirror the band in
 *      assets/js/wp-core/auto-theme.js (classify()), then re-run until happy.
 *
 * Usage:
 *   php dev/auto-theme-calibrate.php            # full report
 *   php dev/auto-theme-calibrate.php --drift    # only colours that changed class
 *   php dev/auto-theme-calibrate.php --check    # exit 1 if any baseline drifted
 *
 * @package AdminKit
 */

error_reporting( E_ALL & ~E_DEPRECATED );
require_once __DIR__ . '/css-scan.php';

$argv = $_SERVER['argv'];
$only_drift = in_array( '--drift', $argv, true );
$check      = in_array( '--check', $argv, true );

$root = dirname( __DIR__ );
$nl   = "\n";

// ── Build the corpus from every baseline (the adapters + WP core) ────────────
$baselines = glob( $root . '/inc/integrations/*/*/baseline.json' );
$wp_core   = $root . '/dev/baselines/wp-core.json';
if ( is_file( $wp_core ) ) {
	$baselines[] = $wp_core;
}
if ( ! $baselines ) {
	fwrite( STDERR, "No baselines found. Capture one: php dev/adapter-drift.php --slug=<slug> --host=<path> --update\n" );
	exit( 1 );
}

$corpus = array(); // each: slug, key, rgba, cat, count, captured, current
foreach ( $baselines as $path ) {
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) || empty( $data['colors'] ) ) {
		continue;
	}
	$slug = isset( $data['slug'] ) ? $data['slug'] : basename( dirname( $path ) );
	foreach ( $data['colors'] as $key => $info ) {
		$rgba = ak_parse_key( $key );
		if ( ! $rgba ) {
			continue;
		}
		$cat      = isset( $info['cat'] ) ? $info['cat'] : 'bg';
		$captured = isset( $info['token'] ) ? $info['token'] : '?';
		list( $current ) = ak_classify( $rgba, $cat );
		$corpus[] = array(
			'slug'     => $slug,
			'key'      => $key,
			'rgba'     => $rgba,
			'cat'      => $cat,
			'count'    => isset( $info['count'] ) ? (int) $info['count'] : 0,
			'captured' => $captured,
			'current'  => $current,
		);
	}
}

// ── Report ───────────────────────────────────────────────────────────────────
$drift = array();
$edge  = array();
$dist  = array();
foreach ( $corpus as $c ) {
	$dist[ $c['current'] ] = ( isset( $dist[ $c['current'] ] ) ? $dist[ $c['current'] ] : 0 ) + 1;
	if ( $c['captured'] !== '?' && $c['captured'] !== $c['current'] ) {
		$drift[] = $c;
	}
	$why = ak_edge_reason( $c['rgba'] );
	if ( $why ) {
		$c['why'] = $why;
		$edge[]   = $c;
	}
}

echo $nl . str_repeat( '=', 78 ) . $nl;
echo 'AdminKit auto-theme calibration — ' . count( $corpus ) . ' real colours from '
	. count( $baselines ) . ' baseline(s)' . $nl;
echo str_repeat( '=', 78 ) . $nl . $nl;

// 1) Drift: the classifier now maps a captured colour to a different token.
echo '── Drift (classifier changed its mind vs the captured baseline) ──' . $nl;
if ( ! $drift ) {
	echo '  none — every captured colour still classifies the same.' . $nl;
} else {
	foreach ( $drift as $c ) {
		printf( "  %-10s %-14s %-7s %s  →  %s%s", $c['slug'], ak_hex( $c ), $c['cat'], $c['captured'], $c['current'], $nl );
	}
	echo $nl . '  → confirm these are improvements, then re-capture: php dev/adapter-drift.php --slug=<slug> --update' . $nl;
}
echo $nl;

if ( $only_drift ) {
	exit( $drift ? 1 : 0 );
}

// 2) Band edges: colours close to a threshold — the most likely to be mis-mapped.
echo '── Band-edge colours (review — small band tweaks would move these) ──' . $nl;
if ( ! $edge ) {
	echo '  none near a boundary.' . $nl;
} else {
	usort( $edge, function ( $a, $b ) { return $b['count'] - $a['count']; } );
	foreach ( array_slice( $edge, 0, 30 ) as $c ) {
		printf( "  %-10s %-14s %-7s → %-18s (%s)%s", $c['slug'], ak_hex( $c ), $c['cat'], $c['current'], $c['why'], $nl );
	}
	if ( count( $edge ) > 30 ) {
		echo '  … and ' . ( count( $edge ) - 30 ) . ' more.' . $nl;
	}
}
echo $nl;

// 3) Distribution: how the whole corpus maps, by token.
echo '── Token distribution across the corpus ──' . $nl;
arsort( $dist );
foreach ( $dist as $token => $n ) {
	printf( "  %5d  %s%s", $n, $token, $nl );
}
echo $nl;
echo 'Mirror reminder: assets/js/wp-core/auto-theme.js classify() must match the'
	. ' bands in dev/css-scan.php ak_classify(). Tune both together.' . $nl . $nl;

if ( $check && $drift ) {
	fwrite( STDERR, count( $drift ) . " colour(s) drifted from their baseline — re-capture or confirm.\n" );
	exit( 1 );
}
exit( 0 );

// ── helpers ──────────────────────────────────────────────────────────────────

/**
 * Parse a baseline colour key ("ffffff@1.00") into [r, g, b, a].
 *
 * @param string $key
 * @return array|null
 */
function ak_parse_key( $key ) {
	if ( ! preg_match( '/^([0-9a-f]{6})@([0-9.]+)$/i', $key, $m ) ) {
		return null;
	}
	return array(
		hexdec( substr( $m[1], 0, 2 ) ),
		hexdec( substr( $m[1], 2, 2 ) ),
		hexdec( substr( $m[1], 4, 2 ) ),
		(float) $m[2],
	);
}

/**
 * A short hex label for a corpus row.
 *
 * @param array $c
 * @return string
 */
function ak_hex( $c ) {
	$hex = sprintf( '#%02x%02x%02x', $c['rgba'][0], $c['rgba'][1], $c['rgba'][2] );
	if ( $c['rgba'][3] < 0.95 ) {
		$hex .= sprintf( ' a%.0f', $c['rgba'][3] * 100 );
	}
	return $hex;
}

/**
 * Flag a colour that sits within a small margin of a classifier band edge — the
 * cases a band tweak would move, and so the ones worth eyeballing. Mirrors the
 * thresholds in ak_classify().
 *
 * @param array $rgba
 * @return string '' when not near an edge, else a short reason.
 */
function ak_edge_reason( $rgba ) {
	list( $r, $g, $b, $a ) = $rgba;
	list( , , $l ) = ak_rgb_to_hsl( $r, $g, $b );
	$L      = $l * 100;
	$chroma = max( $r, $g, $b ) - min( $r, $g, $b );

	foreach ( array( 12, 24 ) as $edge ) {
		if ( abs( $chroma - $edge ) <= 3 ) {
			return sprintf( 'chroma %d ≈ %d (grey↔hued)', (int) round( $chroma ), $edge );
		}
	}
	foreach ( array( 28, 46, 60, 78, 80, 88, 90, 95 ) as $edge ) {
		if ( abs( $L - $edge ) <= 3 ) {
			return sprintf( 'L %d ≈ %d', (int) round( $L ), $edge );
		}
	}
	return '';
}
