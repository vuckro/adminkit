<?php
/**
 * Plugin Name:       AdminKit
 * Plugin URI:        https://github.com/vuckro/adminkit
 * Description:       Give your WordPress admin a clean, modern look — optional dark mode, menu and toolbar editors, and polished plugin screens. Standalone, no setup.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Waaskit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       adminkit
 * Domain Path:       /languages
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

define( 'ADMINKIT_VERSION', '1.0.0' );
define( 'ADMINKIT_FILE', __FILE__ );
define( 'ADMINKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADMINKIT_URL', plugin_dir_url( __FILE__ ) );

// The orchestrator loads + boots every module from its own ordered registry
// (inc/class-plugin.php → AdminKit_Plugin::MODULES). Adding a module is a one-line
// edit there; this loader stays static.
require_once ADMINKIT_PATH . 'inc/class-plugin.php';
AdminKit_Plugin::init();
