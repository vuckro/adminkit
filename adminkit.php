<?php
/**
 * Plugin Name:       AdminKit
 * Plugin URI:        https://github.com/vuckro/adminkit
 * Description:       Clean, modern restyle of wp-admin built on a token-based design system. Standalone — optional adapters layer in design-system providers (Bricks today, more later).
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Waaskit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       adminkit
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

define( 'ADMINKIT_VERSION', '1.1.0' );
define( 'ADMINKIT_FILE', __FILE__ );
define( 'ADMINKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADMINKIT_URL', plugin_dir_url( __FILE__ ) );

require_once ADMINKIT_PATH . 'inc/class-screen.php';
require_once ADMINKIT_PATH . 'inc/helpers.php';
require_once ADMINKIT_PATH . 'inc/class-settings.php';
require_once ADMINKIT_PATH . 'inc/class-dashboard.php';
require_once ADMINKIT_PATH . 'inc/class-plugin.php';
require_once ADMINKIT_PATH . 'inc/class-assets.php';
require_once ADMINKIT_PATH . 'inc/class-theme-toggle.php';
require_once ADMINKIT_PATH . 'inc/core/class-chrome.php';
require_once ADMINKIT_PATH . 'inc/core/class-login.php';

AdminKit_Plugin::init();
