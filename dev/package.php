#!/usr/bin/env php
<?php
/**
 * AdminKit packaging — produces a clean, install-ready copy of the plugin.
 *
 * Reads .distignore at the repo root and exports the runtime-only tree
 * (no dev/, no tokens/ sources, no .git, no baseline.json …). The result is
 * what end users should drop into wp-content/plugins/, and what we ship as a
 * release zip. Anything NOT shipped (dev tooling, build sources, scratch)
 * stays in GitHub — only the runtime is packaged.
 *
 * The script defaults to packaging origin/docs/overhaul (the active integration
 * branch — see CLAUDE.md). Use --ref to package a different branch / tag, or
 * --working-tree to package the local uncommitted state.
 *
 * Usage (run from anywhere inside the AdminKit repo):
 *
 *   # Install in-place to a WP plugins dir (creates / refreshes adminkit/):
 *   php dev/package.php --target=/path/to/wp-content/plugins
 *
 *   # Produce a release zip (top-level dir inside zip is adminkit/):
 *   php dev/package.php --zip=adminkit-1.0.0.zip
 *
 *   # Package a specific ref:
 *   php dev/package.php --ref=main --zip=adminkit-1.0.0.zip
 *
 *   # Package the local working tree (incl. uncommitted changes):
 *   php dev/package.php --working-tree --target=/path/to/wp-content/plugins
 *
 *   # Dry run — list files that would ship:
 *   php dev/package.php --dry-run
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

$opts = getopt(
	'',
	array(
		'ref::',
		'working-tree',
		'target::',
		'zip::',
		'dry-run',
		'quiet',
		'help',
	)
);

if ( isset( $opts['help'] ) ) {
	fwrite( STDOUT, file_get_contents( __FILE__, false, null, 0, 1800 ) );
	exit( 0 );
}

$root = realpath( __DIR__ . '/..' );
if ( ! $root || ! is_dir( $root . '/.git' ) ) {
	fwrite( STDERR, "error: cannot find AdminKit repo root (no .git at $root).\n" );
	exit( 1 );
}

$ref          = $opts['ref']           ?? 'origin/docs/overhaul';
$use_working  = isset( $opts['working-tree'] );
$target       = $opts['target']        ?? null;
$zip          = $opts['zip']           ?? null;
$dry_run      = isset( $opts['dry-run'] );
$quiet        = isset( $opts['quiet'] );

if ( ! $target && ! $zip && ! $dry_run ) {
	fwrite( STDERR, "error: pass --target=DIR, --zip=FILE, or --dry-run. Use --help for full usage.\n" );
	exit( 2 );
}

$log = function ( string $msg ) use ( $quiet ): void {
	if ( ! $quiet ) {
		fwrite( STDOUT, $msg . "\n" );
	}
};

$distignore = $root . '/.distignore';
if ( ! is_file( $distignore ) ) {
	fwrite( STDERR, "error: .distignore not found at $distignore.\n" );
	exit( 1 );
}

$staging = sys_get_temp_dir() . '/adminkit-pkg-' . bin2hex( random_bytes( 4 ) );
mkdir( $staging, 0700, true );
$plugin_staging = $staging . '/adminkit';
mkdir( $plugin_staging, 0700, true );

register_shutdown_function(
	static function () use ( $staging ): void {
		ak_rmrf( $staging );
	}
);

// 1. Export the source tree to the staging dir.
if ( $use_working ) {
	$log( "→ source: working tree at $root" );
	ak_rsync_excluding(
		$root . '/',
		$plugin_staging . '/',
		$distignore,
		// Working-tree path also needs to skip transient stuff that .distignore omits
		// because it lives at the repo root, not inside the tracked tree.
		array( '.git', '.DS_Store' )
	);
} else {
	$log( "→ source: $ref (git archive)" );
	$tar = "$staging/source.tar";
	$cmd = sprintf( 'git -C %s archive --format=tar %s -o %s', escapeshellarg( $root ), escapeshellarg( $ref ), escapeshellarg( $tar ) );
	exec( $cmd, $out, $rc );
	if ( $rc !== 0 ) {
		fwrite( STDERR, "error: git archive failed (rc=$rc). Did you fetch origin? Try: git -C $root fetch origin\n" );
		exit( 1 );
	}
	$tar_cmd = sprintf( 'tar -xf %s -C %s', escapeshellarg( $tar ), escapeshellarg( $plugin_staging ) );
	exec( $tar_cmd, $out, $rc );
	unlink( $tar );
	if ( $rc !== 0 ) {
		fwrite( STDERR, "error: tar extract failed.\n" );
		exit( 1 );
	}
	// Now prune .distignore entries from the staged copy. git archive already
	// honoured .gitattributes (export-ignore) but not .distignore.
	ak_prune_distignore( $plugin_staging, $distignore );
}

// 2. Drop the .distignore / dev-only meta files themselves from the staged copy
// (they don't belong in a user install).
foreach ( array( '.distignore', '.gitignore' ) as $f ) {
	$p = "$plugin_staging/$f";
	if ( is_file( $p ) ) {
		unlink( $p );
	}
}

// 3. Sanity gate — these should NOT survive into the staged copy.
$forbidden = array( 'dev', 'tokens', '.git', '.claude', 'node_modules', 'vendor' );
$found     = array();
foreach ( $forbidden as $dir ) {
	if ( file_exists( "$plugin_staging/$dir" ) ) {
		$found[] = $dir;
	}
}
if ( $found ) {
	fwrite( STDERR, "error: forbidden paths survived packaging: " . implode( ', ', $found ) . "\n" );
	exit( 1 );
}

// 4. Plugin header sanity check — make sure the main file is still there.
$main_file = "$plugin_staging/adminkit.php";
if ( ! is_file( $main_file ) ) {
	fwrite( STDERR, "error: adminkit.php missing from package — staging looks broken.\n" );
	exit( 1 );
}

// 5. Report what we have.
$file_count = ak_count_files( $plugin_staging );
$size       = ak_dir_size( $plugin_staging );
$log( "→ packaged: $file_count files, " . ak_human_bytes( $size ) );

if ( $dry_run ) {
	$log( "→ dry-run: files that would ship —" );
	ak_list_files( $plugin_staging, $plugin_staging, $log );
	exit( 0 );
}

// 6. Deliver.
if ( $target ) {
	$target = rtrim( $target, '/' );
	if ( ! is_dir( $target ) ) {
		fwrite( STDERR, "error: --target dir does not exist: $target\n" );
		exit( 1 );
	}
	$dest = "$target/adminkit";
	$log( "→ installing to $dest" );
	ak_rmrf( $dest );
	ak_cp_r( $plugin_staging, $dest );
	$log( "✓ installed (refresh: deactivate + reactivate the plugin in WP)" );
}

if ( $zip ) {
	$zip_abs = ak_abs_path( $zip );
	if ( file_exists( $zip_abs ) ) {
		unlink( $zip_abs );
	}
	$log( "→ writing zip $zip_abs" );
	$cwd = getcwd();
	chdir( $staging );
	// `adminkit/` is the top-level entry inside the zip → unzips to wp-content/plugins/adminkit/.
	exec( sprintf( 'zip -rq %s adminkit', escapeshellarg( $zip_abs ) ), $out, $rc );
	chdir( $cwd );
	if ( $rc !== 0 ) {
		fwrite( STDERR, "error: zip failed (rc=$rc)\n" );
		exit( 1 );
	}
	$log( "✓ zip written (" . ak_human_bytes( filesize( $zip_abs ) ) . ")" );
}

exit( 0 );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Rsync $src → $dst, honouring .distignore patterns plus an extra exclude list.
 */
function ak_rsync_excluding( string $src, string $dst, string $distignore_file, array $extra_excludes = array() ): void {
	$cmd = array( 'rsync', '-a', '--delete' );
	foreach ( ak_read_distignore_patterns( $distignore_file ) as $pattern ) {
		$cmd[] = '--exclude=' . $pattern;
	}
	foreach ( $extra_excludes as $pattern ) {
		$cmd[] = '--exclude=' . $pattern;
	}
	$cmd[] = $src;
	$cmd[] = $dst;
	$cmd_str = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
	exec( $cmd_str, $out, $rc );
	if ( $rc !== 0 ) {
		fwrite( STDERR, "error: rsync failed (rc=$rc)\n" );
		exit( 1 );
	}
}

/**
 * Walk the staging dir and delete anything matching a .distignore pattern.
 * Used after `git archive`, which doesn't honour .distignore.
 */
function ak_prune_distignore( string $staging, string $distignore_file ): void {
	$patterns = ak_read_distignore_patterns( $distignore_file );
	$it       = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $staging, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $entry ) {
		$rel = substr( $entry->getPathname(), strlen( $staging ) + 1 );
		if ( ak_path_matches_any( $rel, $patterns ) ) {
			if ( $entry->isDir() ) {
				ak_rmrf( $entry->getPathname() );
			} else {
				@unlink( $entry->getPathname() );
			}
		}
	}
}

function ak_read_distignore_patterns( string $file ): array {
	$lines    = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	$patterns = array();
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( $line === '' || $line[0] === '#' ) {
			continue;
		}
		$patterns[] = $line;
	}
	return $patterns;
}

/**
 * Does $rel (a path relative to the staging root) match any .distignore pattern?
 * Supports the small subset we actually use: bare names (apply at any depth),
 * `**\/` prefix (any depth), trailing slashes (treat as dir).
 */
function ak_path_matches_any( string $rel, array $patterns ): bool {
	$basename = basename( $rel );
	foreach ( $patterns as $p ) {
		$p = rtrim( $p, '/' );
		// Glob-style "**/foo" → match basename or any segment.
		if ( strpos( $p, '**/' ) === 0 ) {
			$tail = substr( $p, 3 );
			if ( fnmatch( $tail, $basename ) || fnmatch( $tail, $rel ) ) {
				return true;
			}
			continue;
		}
		// Bare name "dev" or "*.log" → match at any depth.
		if ( strpos( $p, '/' ) === false ) {
			if ( fnmatch( $p, $basename ) ) {
				return true;
			}
			// Also match top-level dir/file by exact relative path.
			if ( fnmatch( $p, $rel ) ) {
				return true;
			}
			continue;
		}
		// Path-anchored "foo/bar" → exact match from root.
		if ( fnmatch( $p, $rel ) || strpos( $rel, $p . '/' ) === 0 || $rel === $p ) {
			return true;
		}
	}
	return false;
}

function ak_rmrf( string $path ): void {
	if ( ! file_exists( $path ) && ! is_link( $path ) ) {
		return;
	}
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path );
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $entry ) {
		if ( $entry->isDir() ) {
			@rmdir( $entry->getPathname() );
		} else {
			@unlink( $entry->getPathname() );
		}
	}
	@rmdir( $path );
}

function ak_cp_r( string $src, string $dst ): void {
	if ( ! is_dir( $dst ) ) {
		mkdir( $dst, 0755, true );
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $it as $entry ) {
		$rel = substr( $entry->getPathname(), strlen( $src ) + 1 );
		$out = "$dst/$rel";
		if ( $entry->isDir() ) {
			if ( ! is_dir( $out ) ) {
				mkdir( $out, 0755, true );
			}
		} else {
			copy( $entry->getPathname(), $out );
		}
	}
}

function ak_count_files( string $path ): int {
	$n  = 0;
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $entry ) {
		if ( $entry->isFile() ) {
			++$n;
		}
	}
	return $n;
}

function ak_dir_size( string $path ): int {
	$bytes = 0;
	$it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $entry ) {
		if ( $entry->isFile() ) {
			$bytes += $entry->getSize();
		}
	}
	return $bytes;
}

function ak_human_bytes( int $b ): string {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$i     = 0;
	$n     = (float) $b;
	while ( $n >= 1024 && $i < count( $units ) - 1 ) {
		$n /= 1024;
		++$i;
	}
	return sprintf( '%.1f %s', $n, $units[ $i ] );
}

function ak_list_files( string $path, string $root, callable $log ): void {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
	);
	$rows = array();
	foreach ( $it as $entry ) {
		if ( $entry->isFile() ) {
			$rows[] = substr( $entry->getPathname(), strlen( $root ) + 1 );
		}
	}
	sort( $rows );
	foreach ( $rows as $r ) {
		$log( "    $r" );
	}
}

function ak_abs_path( string $p ): string {
	if ( $p === '' || $p[0] === '/' ) {
		return $p;
	}
	return getcwd() . '/' . $p;
}
