<?php
/**
 * build.php — generate the shipped WaasKit token baseline.
 *
 * Reads the committed WaasKit palette source
 *   tokens/palettes/{neutre,marque,notifications,semantique}.json
 * and writes
 *   assets/css/waaskit-tokens.css
 * a single `:root { … }` block of every WaasKit primitive + semantic token.
 *
 * The JSON is the source of truth; this CSS is a GENERATED artifact.
 * It emits each token's LIGHT-CONTEXT value (the JSON `light` field) — i.e. exactly
 * what Bricks supplies in wp-admin (always a light context). AdminKit owns the
 * dark flip via the --ak-* layer (assets/css/tokens.css), so the baseline carries
 * no dark block: it is a drop-in for what a provider would feed.
 *
 * Usage:
 *   php tokens/build.php            write assets/css/waaskit-tokens.css
 *   php tokens/build.php --check    exit 1 if the committed file is stale (drift gate)
 *   php tokens/build.php --print    print to stdout, write nothing
 *
 * Only the 4 colour palettes are consumed. AdminKit keeps geometry/type in px on
 * purpose (see assets/css/tokens.css "Conventions"), so no spacing/type palette.
 *
 * @package AdminKit
 */

/** Walk up from this script to the plugin root (adminkit.php + tokens/). */
function ak_root() {
	$d = __DIR__;
	for ( $i = 0; $i < 6; $i++ ) {
		if ( is_file( "$d/adminkit.php" ) && is_dir( "$d/tokens" ) ) {
			return $d;
		}
		$p = dirname( $d );
		if ( $p === $d ) {
			break;
		}
		$d = $p;
	}
	fwrite( STDERR, "error: cannot locate plugin root (adminkit.php + tokens/)\n" );
	exit( 2 );
}

$root    = ak_root();
$src_dir = "$root/tokens/palettes";
$out     = "$root/assets/css/waaskit-tokens.css";

// Order matters only for readability: primitives first, then the semantic layer.
$palettes = array( 'neutre', 'marque', 'notifications', 'semantique' );

$tokens = array();  // name => light value (insertion order preserved)
$dupes  = array();

foreach ( $palettes as $name ) {
	$path = "$src_dir/$name.json";
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "error: missing palette $path\n" );
		exit( 2 );
	}
	$data = json_decode( file_get_contents( $path ), true );
	if ( ! is_array( $data ) || empty( $data['colors'] ) ) {
		fwrite( STDERR, "error: bad palette JSON $path\n" );
		exit( 2 );
	}
	foreach ( $data['colors'] as $c ) {
		// Need a token name and a light-context value. Entries that only carry a
		// `dark` field are the provider's own dark mode — skipped (AdminKit owns dark).
		if ( ! isset( $c['raw'] ) || ! array_key_exists( 'light', $c ) ) {
			continue;
		}
		// Skip divider/section rows whose raw is a label ("↳ SURFACE"), not a var.
		if ( ! preg_match( '/^var\(\s*--([A-Za-z0-9-]+)\s*\)$/', trim( (string) $c['raw'] ), $m ) ) {
			continue;
		}
		$token = $m[1];
		$value = trim( (string) $c['light'] );
		if ( '' === $value ) {
			continue;
		}
		// Skip a no-op self-alias: the semantic layer re-exposes the notification
		// bases as `--success: var(--success)` etc. In one merged :root that is
		// circular, so drop it and keep the primitive's real colour (recorded above).
		if ( preg_match( '/^var\(\s*--' . preg_quote( $token, '/' ) . '\s*\)$/', $value ) ) {
			continue;
		}
		if ( isset( $tokens[ $token ] ) && $tokens[ $token ] !== $value ) {
			$dupes[ $token ] = array( $tokens[ $token ], $value );
		}
		$tokens[ $token ] = $value; // last light value wins
	}
}

if ( empty( $tokens ) ) {
	fwrite( STDERR, "error: no tokens parsed from $src_dir\n" );
	exit( 2 );
}

// Build the stylesheet. NOTE: the header is deterministic (no timestamp) so --check
// stays meaningful — the file only changes when the palette source changes.
$lines = array();
foreach ( $tokens as $token => $value ) {
	$lines[] = "\t--$token: $value;";
}
$css  = "/* WaasKit baseline tokens — GENERATED, do not edit by hand.\n";
$css .= "   Source:     tokens/palettes/{neutre,marque,notifications,semantique}.json\n";
$css .= "   Regenerate: php tokens/build.php\n";
$css .= "   Drift gate: php tokens/build.php --check\n";
$css .= "\n";
$css .= "   Each token is its LIGHT-CONTEXT value (what Bricks feeds wp-admin). The\n";
$css .= "   --ak-* layer (assets/css/tokens.css) owns the dark flip, so there is no dark\n";
$css .= "   block here. A live provider (Bricks) loads AFTER this sheet and overrides it.\n";
$css .= '   ' . count( $tokens ) . " tokens. */\n\n";
$css .= ":root {\n" . implode( "\n", $lines ) . "\n}\n";

$args = array_slice( $argv, 1 );

if ( in_array( '--print', $args, true ) ) {
	echo $css;
	exit( 0 );
}

if ( in_array( '--check', $args, true ) ) {
	$current = is_file( $out ) ? file_get_contents( $out ) : '';
	if ( $current === $css ) {
		echo 'OK: waaskit-tokens.css is in sync (' . count( $tokens ) . " tokens)\n";
		exit( 0 );
	}
	fwrite( STDERR, "DRIFT: waaskit-tokens.css differs from the palette source — run tokens/build.php\n" );
	exit( 1 );
}

file_put_contents( $out, $css );
echo 'wrote ' . str_replace( "$root/", '', $out ) . ' (' . count( $tokens ) . " tokens)\n";
foreach ( $dupes as $token => $vals ) {
	fwrite( STDERR, "  note: --$token had conflicting light values ({$vals[0]} vs {$vals[1]}) — kept last\n" );
}
