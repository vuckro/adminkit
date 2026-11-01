<?php
/**
 * Registry and distribution integrity, independent of a running WordPress installation.
 *
 * Verifies that every PHP file under `inc/` (outside `inc/integrations/`) is
 * registered once in `AdminKit_Plugin::MODULES`, that every boot callable is
 * valid, and that both `HEAD` and `--working-tree` packaging dry-runs include
 * all runtime files while excluding every dotfile and development artifact.
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ADMINKIT_PATH', dirname( __DIR__ ) . '/' );

require ADMINKIT_PATH . 'inc/class-plugin.php';

$modules = AdminKit_Plugin::MODULES;
foreach ( $modules as $path => $boot ) {
	if ( false !== strpos( $path, '..' ) || ! is_file( ADMINKIT_PATH . 'inc/' . $path ) ) {
		throw new RuntimeException( 'Missing/unsafe module: ' . $path );
	}
	require_once ADMINKIT_PATH . 'inc/' . $path;
}

foreach ( $modules as $path => $boot ) {
	if ( null !== $boot && ! is_callable( $boot ) ) {
		throw new RuntimeException( 'Invalid module entrypoint: ' . $path );
	}
}

$inc_files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( ADMINKIT_PATH . 'inc', FilesystemIterator::SKIP_DOTS )
);
foreach ( $inc_files as $file ) {
	$relative = substr( $file->getPathname(), strlen( ADMINKIT_PATH . 'inc/' ) );
	if (
		'php' === $file->getExtension()
		&& 'class-plugin.php' !== $relative
		&& 0 !== strpos( $relative, 'integrations/' )
		&& ! array_key_exists( $relative, $modules )
	) {
		throw new RuntimeException( 'Unregistered module: ' . $relative );
	}
}

// A duplicate associative key in MODULES would disappear before iteration.
$source   = (string) file_get_contents( ADMINKIT_PATH . 'inc/class-plugin.php' );
$registry = substr( $source, (int) strpos( $source, 'const MODULES = array(' ) );
$registry = substr( $registry, 0, (int) strpos( $registry, "\n\t);" ) );
preg_match_all( '/[\'"]([^\'"]+\.php)[\'"]\s*=>/', $registry, $paths );
if ( count( $paths[1] ) !== count( $modules ) ) {
	throw new RuntimeException( 'Duplicate module registration' );
}

/**
 * Run `dev/package.php --dry-run` and return shipped relative file paths.
 *
 * @param array<int, string> $args Extra CLI flags.
 * @return array<int, string>
 */
function package_files( array $args ): array {
	$command = array_merge( array( PHP_BINARY, ADMINKIT_PATH . 'dev/package.php', '--dry-run' ), $args );
	$pipes   = array();
	$spec    = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process = proc_open( $command, $spec, $pipes, ADMINKIT_PATH );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Cannot start packager' );
	}
	fclose( $pipes[0] );
	$out = (string) stream_get_contents( $pipes[1] );
	$err = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) {
		throw new RuntimeException( 'Package dry-run failed: ' . $err . $out );
	}
	preg_match_all( '/^    (.+)$/m', $out, $matches );
	return $matches[1];
}

$head    = package_files( array() );
$working = package_files( array( '--working-tree' ) );
if ( ! $head || ! $working ) {
	throw new RuntimeException( 'Empty package' );
}

foreach ( array( 'controls', 'menu-model', 'menu', 'toolbar' ) as $part ) {
	if ( ! in_array( 'assets/js/settings/navigation-' . $part . '.js', $working, true ) ) {
		throw new RuntimeException( 'Navigation factory excluded from working package: ' . $part );
	}
}

$required_runtime_assets = array(
	'assets/js/settings/design-colors.js',
	'assets/js/wp-core/profile-account-dom.js',
	'assets/css/vendor/element-plus.css',
);
foreach ( $required_runtime_assets as $part ) {
	if ( ! in_array( $part, $working, true ) ) {
		throw new RuntimeException( 'Runtime asset excluded from working package: ' . $part );
	}
}

foreach ( $modules as $path => $boot ) {
	if ( ! in_array( 'inc/' . $path, $working, true ) ) {
		throw new RuntimeException( 'Runtime module excluded from working package: ' . $path );
	}
}

$required_files = array(
	'adminkit.php',
	'uninstall.php',
	'languages/adminkit.pot',
	'languages/adminkit-fr_FR.po',
	'languages/adminkit-fr_FR.mo',
	'languages/adminkit-fr_FR.l10n.php',
);

foreach ( array( $head, $working ) as $package ) {
	foreach ( $required_files as $required ) {
		if ( ! in_array( $required, $package, true ) ) {
			throw new RuntimeException( 'Package missing ' . $required );
		}
	}
	foreach ( $package as $file ) {
		if ( preg_match( '~^(?:dev|tokens|docs|node_modules|vendor)/|(?:^|/)\.[^/]+|(?:^|/)(?:AGENTS|CLAUDE|README)\.md$|(?:^|/)baseline\.json$|(?:^|/)(?:phpstan\.neon|composer\.(?:json|lock))~', $file ) ) {
			throw new RuntimeException( 'Development or hidden dotfile shipped: ' . $file );
		}
	}
}

echo 'OK: ' . count( $modules ) . ' callable/present modules, no orphan/duplicate files; HEAD and working-tree package dry-runs clean (' . count( $working ) . " runtime files).\n";
