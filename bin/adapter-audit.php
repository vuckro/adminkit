#!/usr/bin/env php
<?php
/**
 * AdminKit integration CSS-debt audit.
 *
 * Walks every `inc/integrations/{slug}/css/*.css` file and reports the amount
 * of selector-override debt each adapter carries: the count of `!important`
 * declarations (the proxy for "fighting the host's own CSS" instead of
 * remapping its variables) plus raw hex literals.
 *
 * Tier A adapters (pure variable remap) should stay at 0 `!important`. Tier B
 * adapters override host selectors out of necessity — the host hardcodes its
 * colors (FluentBooking) or compiles Tailwind with `important: true`
 * (FlyingPress) — so their accepted ceiling is recorded in $BUDGET below.
 *
 * The script exits non-zero when any adapter climbs ABOVE its ceiling, i.e.
 * when debt GROWS: a clean adapter sprouting an override, or unbudgeted new
 * debt, fails loudly, while today's host-forced debt is left alone. It is a
 * ratchet, not a tribunal — raise a ceiling only when the new override is
 * genuinely unavoidable (and prefer remapping the host's variables instead;
 * see docs/INTEGRATIONS.md).
 *
 * Usage:  php bin/adapter-audit.php
 *
 * @package AdminKit
 */

// Accepted `!important` ceiling per adapter. Anything not listed must stay at 0
// (Tier A). Entries below carry debt FORCED by the host, not a code-quality
// fault — they are the baseline as of the last review.
$BUDGET = array(
	'fluent-booking'    => 101, // ApexCharts chart text/grid/tooltip + Element Plus (host inline styles)
	'flying-press'      => 41,
	'fluentcart'        => 27,
	'gutenberg'         => 8,
	'fluent-smtp'       => 6,
	'happyfiles'        => 6,
	'wp-migrate-db-pro' => 6,
	'fluentform'        => 2,
);

$root = dirname( __DIR__ ) . '/inc/integrations';
if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "integrations dir not found: $root\n" );
	exit( 2 );
}

$rows = array();
foreach ( glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
	$files = glob( $dir . '/css/*.css' );
	if ( ! $files ) {
		continue;
	}
	$lines = 0;
	$bang  = 0;
	$hex   = 0;
	foreach ( $files as $file ) {
		$css      = (string) file_get_contents( $file );
		$lines   += substr_count( $css, "\n" ) + 1;
		$stripped = preg_replace( '!/\*.*?\*/!s', '', $css ); // ignore comments
		$bang    += substr_count( strtolower( $stripped ), '!important' );
		$hex     += (int) preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $stripped );
	}
	$rows[ basename( $dir ) ] = array(
		'files' => count( $files ),
		'lines' => $lines,
		'bang'  => $bang,
		'hex'   => $hex,
	);
}

// Heaviest adapters first.
uasort( $rows, static function ( $a, $b ) {
	return $b['bang'] <=> $a['bang'];
} );

$fail = array();
printf( "\n%-20s %6s %7s %12s %5s %5s\n", 'adapter', 'files', 'lines', '!important', 'hex', 'tier' );
echo str_repeat( '-', 64 ) . "\n";
foreach ( $rows as $slug => $r ) {
	$ceiling = $BUDGET[ $slug ] ?? 0;
	$tier    = $r['bang'] > 0 ? 'B' : 'A';
	$over    = $r['bang'] > $ceiling;
	if ( $over ) {
		$fail[] = $slug;
	}
	printf(
		"%-20s %6d %7d %12d %5d %5s%s\n",
		$slug,
		$r['files'],
		$r['lines'],
		$r['bang'],
		$r['hex'],
		$tier,
		$over ? '  <== OVER ' . $ceiling : ''
	);
}
echo str_repeat( '-', 64 ) . "\n";

if ( $fail ) {
	printf( "\nFAIL: override debt grew in: %s\n", implode( ', ', $fail ) );
	echo "If the new override is genuinely host-forced, raise its ceiling in \$BUDGET.\n";
	echo "Otherwise, remap the host's CSS variables instead (docs/INTEGRATIONS.md).\n\n";
	exit( 1 );
}
echo "\nOK: every adapter is within its accepted !important budget.\n\n";
exit( 0 );
