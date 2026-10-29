<?php
/**
 * Generate the WaasKit baseline from the committed native export snapshot.
 * Usage: php tokens/build.php [--check|--print].
 * No network access or dependency required. Theme selection belongs to AdminKit.
 *
 * @package AdminKit
 */
$root = dirname( __DIR__ );
$source = $root . '/tokens/palettes/waaskit.json';
$out = $root . '/assets/css/waaskit-tokens.css';
$data = json_decode( file_get_contents( $source ), true );
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "error: invalid WaasKit source JSON\n" );
	exit( 2 );
}
$css = "/* WaasKit — GENERATED, do not edit by hand.\n"
	. " * Source: tokens/palettes/waaskit.json (native WaasKit export).\n"
	. " * Regenerate: php tokens/build.php; drift gate: php tokens/build.php --check.\n"
	. " * Scoped to AdminKit's document theme; no frontend component CSS imported.\n */\n";
foreach ( array( 'primitives', 'light', 'dark' ) as $group ) {
	if ( empty( $data[ $group ] ) || ! is_array( $data[ $group ] ) ) {
		fwrite( STDERR, "error: missing token group $group\n" );
		exit( 2 );
	}
	$selector = 'dark' === $group ? ':root[data-adminkit-theme="dark"], .adminkit-app .ak-bp__frame[data-preview-theme="dark"]' : ':root, .adminkit-app .ak-bp__frame';
	$css .= "\n$selector {\n";
	if ( 'primitives' !== $group ) {
		$css .= "\tcolor-scheme: " . ( 'dark' === $group ? 'dark' : 'light' ) . ";\n";
	}
	foreach ( $data[ $group ] as $name => $value ) {
		if ( ! preg_match( '/^--wk-[a-z0-9-]+$/', $name ) || ! is_string( $value ) || preg_match( '/[{};]/', $value ) ) {
			fwrite( STDERR, "error: invalid token $name\n" );
			exit( 2 );
		}
		$css .= "\t$name: $value;\n";
	}
	$css .= "}\n";
}
// The frontend toolbar has its own scope; never overwrite the public theme.
$toolbar_css = preg_replace(
	array( '/:root\[data-adminkit-theme="dark"\], \.adminkit-app \.ak-bp__frame\[data-preview-theme="dark"\]/', '/:root, \.adminkit-app \.ak-bp__frame/' ),
	array( ':root[data-adminkit-theme="dark"] #wpadminbar', '#wpadminbar' ),
	$css
);
$bridge = file_get_contents( $root . '/assets/css/tokens.css' );
if ( ! preg_match( '/:root \{([^}]+)\}/', $bridge, $match ) ) {
	fwrite( STDERR, "error: missing AdminKit role bridge\n" );
	exit( 2 );
}
$toolbar_css .= "\n#wpadminbar {" . $match[1] . "}\n";
$outputs = array( $out => $css, $root . '/assets/css/waaskit-toolbar-tokens.css' => $toolbar_css );
if ( in_array( '--print', $argv, true ) ) {
	echo $css;
	exit;
}
foreach ( $outputs as $path => $content ) {
	if ( in_array( '--check', $argv, true ) ) {
		if ( ! is_file( $path ) || file_get_contents( $path ) !== $content ) {
			fwrite( STDERR, "DRIFT: run php tokens/build.php\n" );
			exit( 1 );
		}
	} else {
		file_put_contents( $path, $content );
		echo 'wrote ' . basename( $path ) . "\n";
	}
}
if ( in_array( '--check', $argv, true ) ) {
	echo "OK: WaasKit document and toolbar tokens are in sync\n";
}
