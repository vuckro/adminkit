<?php
/**
 * Unified offline quality gate for AdminKit.
 *
 * Runs PHP/JS syntax checks, token build drift, regression unit suites,
 * CSS contracts, adapter budgets, i18n/module/packaging checks, static
 * analysis (when installed under dev/) and jsdom suites in parallel.
 *
 * Usage:
 *   php dev/test-all.php          # Full gate (CI + pre-commit)
 *   php dev/test-all.php --fast   # Skip optional heavy static-analysis pass
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

$root     = dirname( __DIR__ );
chdir( $root );

$started  = microtime( true );
$fast     = in_array( '--fast', $argv, true );
$workers  = max( 2, min( 8, (int) ( getenv( 'AK_TEST_WORKERS' ) ?: 8 ) ) );
$failures = array();
$checks   = 0;

/**
 * Execute a batch of commands concurrently using a non-blocking proc_open pool
 * so verbose child output can never deadlock stdout/stderr pipes.
 *
 * @param array<int, array<int, string>> $commands List of argv command arrays.
 * @param int                            $concurrency Max concurrent child processes.
 * @return array<int, array{0:int, 1:string}> Exit code + combined output keyed by command index.
 */
function ak_test_run_pool( array $commands, int $concurrency ): array {
	$results = array();
	$queue   = array_keys( $commands );
	$running = array();

	while ( ! empty( $queue ) || ! empty( $running ) ) {
		while ( count( $running ) < $concurrency && ! empty( $queue ) ) {
			$idx     = array_shift( $queue );
			$command = $commands[ $idx ];
			$pipes   = array();
			$spec    = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open( $command, $spec, $pipes );
			if ( ! is_resource( $process ) ) {
				$results[ $idx ] = array( 1, 'Cannot start ' . $command[0] );
				continue;
			}
			fclose( $pipes[0] );
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
			$running[ $idx ] = array(
				'process' => $process,
				'stdout'  => $pipes[1],
				'stderr'  => $pipes[2],
				'output'  => '',
			);
		}

		if ( empty( $running ) ) {
			break;
		}

		$read = array();
		foreach ( $running as $job ) {
			if ( ! feof( $job['stdout'] ) ) {
				$read[] = $job['stdout'];
			}
			if ( ! feof( $job['stderr'] ) ) {
				$read[] = $job['stderr'];
			}
		}

		if ( ! empty( $read ) ) {
			$write  = null;
			$except = null;
			if ( stream_select( $read, $write, $except, 0, 50000 ) > 0 ) {
				foreach ( $running as $idx => &$job ) {
					if ( in_array( $job['stdout'], $read, true ) ) {
						$job['output'] .= (string) stream_get_contents( $job['stdout'] );
					}
					if ( in_array( $job['stderr'], $read, true ) ) {
						$job['output'] .= (string) stream_get_contents( $job['stderr'] );
					}
				}
				unset( $job );
			}
		}

		foreach ( $running as $idx => $job ) {
			$status = proc_get_status( $job['process'] );
			$eof    = feof( $job['stdout'] ) && feof( $job['stderr'] );
			if ( ! $status['running'] && $eof ) {
				$tail  = (string) stream_get_contents( $job['stdout'] );
				$tail .= (string) stream_get_contents( $job['stderr'] );
				fclose( $job['stdout'] );
				fclose( $job['stderr'] );
				$close_code      = proc_close( $job['process'] );
				$exit_code       = $status['exitcode'] >= 0 ? $status['exitcode'] : $close_code;
				$results[ $idx ] = array( $exit_code, $job['output'] . $tail );
				unset( $running[ $idx ] );
			}
		}
	}

	ksort( $results );
	return $results;
}

/**
 * Run a named test group in parallel and record any failures.
 *
 * @param string                         $label    Human-readable group label.
 * @param array<int, array<int, string>> $commands Commands to run.
 * @param int                            $pool     Max concurrency for this group.
 * @return void
 */
function ak_test_group( string $label, array $commands, int $pool = 8 ): void {
	global $failures, $checks;
	if ( empty( $commands ) ) {
		return;
	}

	$checks += count( $commands );
	$results = ak_test_run_pool( array_values( $commands ), $pool );
	$bad     = false;

	foreach ( $results as $idx => $pair ) {
		list( $code, $out ) = $pair;
		if ( 0 !== $code ) {
			$bad        = true;
			$cmd_string = implode( ' ', $commands[ $idx ] );
			$failures[] = $label . ' / ' . $cmd_string;
			fwrite( STDERR, 'FAIL: ' . $cmd_string . "\n" . $out . "\n" );
		}
	}

	echo ( $bad ? 'FAIL' : 'PASS' ) . ': ' . $label . ' (' . count( $commands ) . ")\n";
}

// Discover first-party PHP and JS sources (skip VCS and vendored toolchains only).
$php_files = array();
$js_files  = array();
$skip_rel  = array( '.git', 'node_modules', 'vendor', 'dev/vendor', 'dev/smoke/node_modules' );

$filter = new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
	static function ( SplFileInfo $entry ) use ( $root, $skip_rel ): bool {
		if ( ! $entry->isDir() ) {
			return true;
		}
		$rel = substr( $entry->getPathname(), strlen( $root ) + 1 );
		return ! in_array( $rel, $skip_rel, true ) && 'node_modules' !== $entry->getFilename();
	}
);

foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
	$ext = $file->getExtension();
	if ( 'php' === $ext ) {
		$php_files[] = $file->getPathname();
	} elseif ( 'js' === $ext ) {
		$js_files[] = $file->getPathname();
	}
}
sort( $php_files );
sort( $js_files );

ak_test_group(
	'PHP syntax',
	array_map(
		static function ( string $file ): array {
			return array( PHP_BINARY, '-l', $file );
		},
		$php_files
	),
	$workers
);

ak_test_group(
	'JavaScript syntax',
	array_map(
		static function ( string $file ): array {
			return array( 'node', '--check', $file );
		},
		$js_files
	),
	$workers
);

ak_test_group(
	'Generated tokens',
	array( array( PHP_BINARY, 'tokens/build.php', '--check' ) ),
	1
);

$regression_tests   = glob( 'dev/check*.php' ) ?: array();
sort( $regression_tests );
$regression_tests[] = 'dev/editor-gate-check.php';

ak_test_group(
	'Regression checks (including i18n/modules/packaging)',
	array_map(
		static function ( string $file ): array {
			return array( PHP_BINARY, $file );
		},
		$regression_tests
	),
	$workers
);

ak_test_group(
	'CSS contracts and adapter budgets',
	array(
		array( PHP_BINARY, 'dev/css-contract.php' ),
		array( PHP_BINARY, 'dev/adapter-audit.php' ),
	),
	2
);

$jsdom_tests = glob( 'dev/smoke/*.test.js' ) ?: array();
sort( $jsdom_tests );

ak_test_group(
	'Real jsdom modules',
	array_map(
		static function ( string $file ): array {
			return array( 'node', $file );
		},
		$jsdom_tests
	),
	$workers
);

if ( ! $fast ) {
	$static_cmds = array();
	if ( is_file( $root . '/dev/vendor/bin/phpcs' ) && is_file( $root . '/.phpcs.xml.dist' ) ) {
		$static_cmds[] = array( PHP_BINARY, 'dev/vendor/bin/phpcs', '--standard=.phpcs.xml.dist' );
	}
	if ( is_file( $root . '/dev/vendor/bin/phpstan' ) && is_file( $root . '/phpstan.neon.dist' ) && PHP_VERSION_ID >= 70400 ) {
		$static_cmds[] = array( PHP_BINARY, 'dev/vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon.dist', '--memory-limit=512M', '--no-progress' );
	}
	if ( is_file( $root . '/dev/smoke/node_modules/.bin/eslint' ) && is_file( $root . '/dev/smoke/eslint.config.js' ) ) {
		$static_cmds[] = array( $root . '/dev/smoke/node_modules/.bin/eslint', '--config', 'dev/smoke/eslint.config.js', 'assets/js', 'inc/integrations' );
	}
	if ( is_file( $root . '/dev/smoke/node_modules/.bin/stylelint' ) && is_file( $root . '/dev/smoke/.stylelintrc.json' ) ) {
		$static_cmds[] = array( $root . '/dev/smoke/node_modules/.bin/stylelint', '--config', 'dev/smoke/.stylelintrc.json', 'assets/css/**/*.css', 'inc/integrations/**/*.css' );
	}
	if ( ! empty( $static_cmds ) ) {
		ak_test_group( 'Static analysis (WPCS / PHPStan / ESLint / Stylelint)', $static_cmds, 4 );
	}
}

$elapsed = round( microtime( true ) - $started, 1 );
if ( ! empty( $failures ) ) {
	fwrite( STDERR, count( $failures ) . " failed checks out of $checks ($elapsed s).\n" );
	exit( 1 );
}

echo "OK: all $checks checks passed ($elapsed s). External host drift/browser checks remain separate.\n";
