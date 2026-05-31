<?php
/**
 * Plugin Name:       AdminKit
 * Plugin URI:        https://github.com/vuckro/adminkit
 * Description:       Give your WordPress admin a clean, modern look — one-click dark mode, a refreshed dashboard, a menu editor, cookieless stats and polished plugin screens. Standalone, no setup.
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
require_once ADMINKIT_PATH . 'inc/class-icons.php';
require_once ADMINKIT_PATH . 'inc/class-plugin.php';
require_once ADMINKIT_PATH . 'inc/class-assets.php';
require_once ADMINKIT_PATH . 'inc/class-theme-toggle.php';

// Settings — the registry, catalog, access gate + the SPA page host.
require_once ADMINKIT_PATH . 'inc/settings/class-settings.php';
require_once ADMINKIT_PATH . 'inc/settings/class-settings-catalog.php';
require_once ADMINKIT_PATH . 'inc/settings/class-settings-gate.php';
require_once ADMINKIT_PATH . 'inc/settings/class-settings-page.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-chrome.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-admin-bar.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-login.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-branding.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-menu-icons.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-profile-account.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-local-avatars.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-list-table-chrome.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-options-general.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-options-discussion.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-post-previews.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-user-quick-edit.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-username-changer.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-footer.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-help-button.php';
require_once ADMINKIT_PATH . 'inc/wp-core/class-plugins-list.php';

// AdminKit features — self-contained tools that ADD a surface (their own admin page,
// a dashboard, a data store), as opposed to wp-core/ which restyles existing
// WordPress screens, and integrations/ which adapts third-party plugins. One folder
// per feature under inc/features/.
require_once ADMINKIT_PATH . 'inc/features/dashboard/class-custom-dashboard.php';
require_once ADMINKIT_PATH . 'inc/features/online-users/class-online-users.php';
require_once ADMINKIT_PATH . 'inc/features/notifications/class-notification-center.php';
require_once ADMINKIT_PATH . 'inc/features/menu/class-menu-store.php';
require_once ADMINKIT_PATH . 'inc/features/menu/class-menu-manager.php';
require_once ADMINKIT_PATH . 'inc/features/stats/class-stats-store.php';
require_once ADMINKIT_PATH . 'inc/features/stats/class-stats-tracker.php';
require_once ADMINKIT_PATH . 'inc/features/stats/class-stats-dashboard.php';
require_once ADMINKIT_PATH . 'inc/features/stats/class-stats-page.php';

register_activation_hook( ADMINKIT_FILE, array( 'AdminKit_Menu_Store', 'ensure_schema' ) );
register_activation_hook( ADMINKIT_FILE, array( 'AdminKit_Stats_Store', 'ensure_schema' ) );

AdminKit_Plugin::init();
