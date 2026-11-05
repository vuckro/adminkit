<?php
/**
 * Plugin Name: AdminKit disposable menu test host
 * Description: Native plugin-page registrations for isolated browser tests only.
 * License: GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;
add_action( 'admin_menu', static function () {
	$render = static function () { echo '<div class="wrap" id="ak-fixture-page">Native plugin page</div>'; };
	add_menu_page( 'Fixture suite', 'Fixture suite', 'manage_options', 'ak-fixture-root', $render );
	add_submenu_page( 'ak-fixture-root', 'Settings', 'Settings', 'manage_options', 'ak-fixture-shared', $render );
	// Some free/Pro pairs register the same physical route twice.
	add_submenu_page( 'ak-fixture-root', 'Settings', 'Settings Pro', 'manage_options', 'ak-fixture-shared', $render );
} );
