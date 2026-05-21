<?php
/**
 * Core login — registers the wp-login.php stylesheet.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Login {

	/**
	 * Register the login stylesheet. Called once from the plugin
	 * orchestrator after `AdminKit_Assets::init()`.
	 *
	 * @return void
	 */
	public static function register() {
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-login',
			'src'     => 'assets/css/login.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'login',
		) );
	}
}
