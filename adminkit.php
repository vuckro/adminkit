<?php
/**
 * Plugin Name:       AdminKit
 * Plugin URI:        https://github.com/vuckro/adminkit
 * Description:       Clean, modern restyle of wp-admin built on CSS tokens. Standalone — optional adapters layer in token providers (Bricks today, more later).
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

require_once ADMINKIT_PATH . 'inc/class-screen.php';
require_once ADMINKIT_PATH . 'inc/class-settings.php';
require_once ADMINKIT_PATH . 'inc/class-settings-page.php';
require_once ADMINKIT_PATH . 'inc/class-plugin.php';
require_once ADMINKIT_PATH . 'inc/class-assets.php';
require_once ADMINKIT_PATH . 'inc/class-theme-toggle.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-chrome.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-login.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-branding.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-menu-icons.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-profile-account.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-local-avatars.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-list-table-chrome.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-options-general.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-options-discussion.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-post-previews.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-auto-theme.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-user-quick-edit.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-username-changer.php';

AdminKit_Plugin::init();
