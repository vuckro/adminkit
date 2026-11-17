<?php
/**
 * Regression coverage for concurrent process exit-status collection.
 *
 * @package AdminKit
 */

$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/test-all.php' ) . ' --self-test-pool';
passthru( $command, $status );
exit( $status );
