<?php
/**
 * PHPStan bootstrap constants & environment stubs for AdminKit.
 *
 * @package AdminKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'ADMINKIT_VERSION' ) ) {
	define( 'ADMINKIT_VERSION', '1.0.0' );
}
if ( ! defined( 'ADMINKIT_FILE' ) ) {
	define( 'ADMINKIT_FILE', dirname( __DIR__ ) . '/adminkit.php' );
}
if ( ! defined( 'ADMINKIT_PATH' ) ) {
	define( 'ADMINKIT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ADMINKIT_URL' ) ) {
	define( 'ADMINKIT_URL', 'https://example.test/wp-content/plugins/adminkit/' );
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'adminkit/adminkit.php' );
}
if ( ! class_exists( 'WP_SQLite_DB' ) ) {
	// Optional SQLite drop-in class in WordPress Playground.
	class WP_SQLite_DB {}
}
