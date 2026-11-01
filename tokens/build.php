<?php
/**
 * Build the WaasKit variables used by AdminKit; no runtime dependencies.
 * Sources: palettes/waaskit.json (upstream snapshot), adminkit.json (role map).
 * Usage: php tokens/build.php [--check|--print].
 *
 * @package AdminKit
 */
$root = dirname( __DIR__ );

function ak_read_tokens( $path ) {
	$data = json_decode( file_get_contents( $path ), true );
	if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
		throw new RuntimeException( 'Invalid token JSON: ' . basename( $path ) );
	}
	return $data;
}

/** Follow dependencies for both themes; fail the build on missing or circular roles. */
function ak_token_dependencies( $name, array $values, array &$used, array $stack = array() ) {
	if ( isset( $stack[ $name ] ) ) {
		throw new RuntimeException( 'Circular token: ' . $name );
	}
	if ( ! isset( $values[ $name ] ) ) {
		throw new RuntimeException( 'Missing token: ' . $name );
	}
	if ( isset( $used[ $name ] ) ) {
		return;
	}
	$stack[ $name ] = true;
	preg_match_all( '/var\(\s*(--(?:wk|ak)-[a-z0-9-]+)/', $values[ $name ], $matches );
	foreach ( $matches[1] as $dependency ) {
		ak_token_dependencies( $dependency, $values, $used, $stack );
	}
	$used[ $name ] = true;
}

function ak_token_block( $selector, array $values, $scheme = '' ) {
	$css = "\n$selector {\n";
	if ( $scheme ) {
		$css .= "\tcolor-scheme: $scheme;\n";
	}
	foreach ( $values as $name => $value ) {
		if ( ! preg_match( '/^--(?:wk|ak)-[a-z0-9-]+$/', $name ) || ! is_string( $value ) || preg_match( '/[{};]/', $value ) ) {
			throw new RuntimeException( 'Invalid CSS token: ' . $name );
		}
		$css .= "\t$name: $value;\n";
	}
	return $css . "}\n";
}

function ak_legacy_rgb( array $values ) {
	return array( '--ak-primary-rgb' => $values['primary'], '--ak-primary-hover-rgb' => $values['hover'] );
}

try {
	$data = ak_read_tokens( __DIR__ . '/palettes/waaskit.json' );
	$roles = ak_read_tokens( __DIR__ . '/adminkit.json' );
	foreach ( array( 'primitives', 'light', 'dark' ) as $group ) {
		if ( empty( $data[ $group ] ) || ! is_array( $data[ $group ] ) ) {
			throw new RuntimeException( 'Missing token group: ' . $group );
		}
	}

	// Keep the complete upstream snapshot, but ship only roles consumed by the UI (assets/ + inc/).
	$wanted = array_merge( array_keys( $roles ), array_keys( $data['presets']['monochrome']['light'] ) );
	foreach ( array( $root . '/assets', $root . '/inc' ) as $scan_dir ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scan_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( ! in_array( $file->getExtension(), array( 'css', 'js', 'php' ), true ) || 0 === strpos( $file->getFilename(), 'waaskit-' ) ) {
				continue;
			}
			preg_match_all( '/var\(\s*(--wk-[a-z0-9-]+)/', (string) file_get_contents( $file->getPathname() ), $matches );
			$wanted = array_merge( $wanted, $matches[1] );
		}
	}
	$wanted = array_unique( $wanted );
	$used = array();
	$mono = $data['presets']['monochrome'];
	foreach ( array( 'light', 'dark' ) as $theme ) {
		foreach ( array( false, true ) as $monochrome ) {
			$values = array_merge( $data['primitives'], $data['light'], 'dark' === $theme ? $data['dark'] : array(), $monochrome ? $mono[ $theme ] : array(), $roles );
			$theme_used = array();
			foreach ( $wanted as $name ) {
				ak_token_dependencies( $name, $values, $theme_used );
			}
			$used += $theme_used;
		}
	}
	foreach ( array( 'primitives', 'light', 'dark' ) as $group ) {
		$data[ $group ] = array_intersect_key( $data[ $group ], $used );
	}
	$header = "/* GENERATED WaasKit roles — edit tokens/palettes/waaskit.json or tokens/adminkit.json.\n * Regenerate: php tokens/build.php; verify: php tokens/build.php --check. */\n";
	$outputs = array();
	foreach ( array( 'document', 'toolbar' ) as $context ) {
		$base = 'toolbar' === $context ? '#wpadminbar' : ':root, .adminkit-app .ak-bp__frame';
		$dark = 'toolbar' === $context ? ':root[data-adminkit-theme="dark"] #wpadminbar' : ':root[data-adminkit-theme="dark"], .adminkit-app .ak-bp__frame[data-preview-theme="dark"]';
		$light = array_merge( $data['primitives'], $data['light'], $mono['light'], ak_legacy_rgb( $data['legacy_rgb']['monochrome']['light'] ) );
		$css = $header . ak_token_block( $base, $light, 'light' );
		$css .= ak_token_block( $dark, array_merge( $data['dark'], $mono['dark'], ak_legacy_rgb( $data['legacy_rgb']['monochrome']['dark'] ) ), 'dark' );
		$blue_light = array_intersect_key( $data['light'], $mono['light'] );
		$blue_dark = array_intersect_key( array_merge( $data['light'], $data['dark'] ), $mono['dark'] );
		$blue_base = 'toolbar' === $context ? ':root[data-adminkit-accent="waaskit"] #wpadminbar' : ':root[data-adminkit-accent="waaskit"], .adminkit-app .ak-bp__frame[data-preview-accent="waaskit"]';
		$blue_night = 'toolbar' === $context ? ':root[data-adminkit-accent="waaskit"][data-adminkit-theme="dark"] #wpadminbar' : ':root[data-adminkit-accent="waaskit"][data-adminkit-theme="dark"], .adminkit-app .ak-bp__frame[data-preview-accent="waaskit"][data-preview-theme="dark"]';
		$css .= ak_token_block( $blue_base, array_merge( $blue_light, ak_legacy_rgb( $data['legacy_rgb']['waaskit']['light'] ) ) );
		$css .= ak_token_block( $blue_night, array_merge( $blue_dark, ak_legacy_rgb( $data['legacy_rgb']['waaskit']['dark'] ) ) );
		$filename = 'toolbar' === $context ? 'waaskit-toolbar-tokens.css' : 'waaskit-tokens.css';
		$outputs[ $root . '/assets/css/' . $filename ] = $css;
	}
	$outputs[ $root . '/assets/css/tokens.css' ] = $header . ak_token_block( ':root, #wpadminbar', $roles ) . "\n" . file_get_contents( __DIR__ . '/appearance.css' ) . "\n" . file_get_contents( __DIR__ . '/wordpress.css' );
	if ( in_array( '--print', $argv, true ) ) {
		echo reset( $outputs );
		exit;
	}
	foreach ( $outputs as $path => $css ) {
		if ( in_array( '--check', $argv, true ) ) {
			if ( ! is_file( $path ) || file_get_contents( $path ) !== $css ) {
				throw new RuntimeException( 'Token drift: run php tokens/build.php' );
			}
		} else {
			file_put_contents( $path, $css );
		}
	}
	echo 'OK: WaasKit roles built from ' . count( array_filter( array_keys( $used ), static function ( $name ) { return 0 === strpos( $name, '--wk-' ); } ) ) . " upstream variables\n";
} catch ( RuntimeException $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
