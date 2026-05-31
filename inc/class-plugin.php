<?php
/**
 * Plugin orchestrator.
 *
 * Boots every core module, auto-discovers integrations from
 * `inc/integrations/`, and fires the `adminkit/loaded` action once
 * everything is wired. Modules are static singletons — nothing here
 * needs to be instantiated by callers.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Plugin {

	/**
	 * The module registry — the SINGLE place to edit when adding/removing a module.
	 *
	 * Keys are paths relative to `inc/`; values are the boot callable run in this
	 * exact order, or `null` for a file that only needs loading (helpers, the
	 * settings catalog/gate which Settings_Page boots itself, and the data stores).
	 * `init()` loads every file first, then boots the non-null ones in array order,
	 * so boot order is explicit and require order can't trip anything up.
	 *
	 * Adding a module: drop `inc/<folder>/class-<name>.php`, add one line here.
	 * (`wp-core/` restyles existing WP screens; `features/` adds new surfaces;
	 * `inc/integrations/` is separate and auto-discovered — no entry needed.)
	 *
	 * @var array<string, string|null>
	 */
	const MODULES = array(
		// Helpers — loaded for everyone, nothing to boot.
		'class-screen.php'                                     => null,
		'class-icons.php'                                      => null,

		// Core services + the settings system, in boot order.
		'class-assets.php'                                     => 'AdminKit_Assets::init',
		'wp-core/class-chrome.php'                             => 'AdminKit_Core_Chrome::register',
		'wp-core/class-login.php'                              => 'AdminKit_Core_Login::register',
		'wp-core/class-branding.php'                           => 'AdminKit_Core_Branding::init',
		'wp-core/class-menu-icons.php'                         => 'AdminKit_Core_Menu_Icons::init',
		'settings/class-settings.php'                          => 'AdminKit_Settings::init',
		'settings/class-settings-catalog.php'                 => null, // booted by Settings_Page
		'settings/class-settings-gate.php'                    => null, // booted by Settings_Page
		'settings/class-settings-page.php'                     => 'AdminKit_Settings_Page::init',
		'class-theme-toggle.php'                               => 'AdminKit_Theme_Toggle::init',

		// wp-core — restyle of existing WordPress screens.
		'wp-core/class-profile-account.php'                    => 'AdminKit_Profile_Account::init',
		'wp-core/class-local-avatars.php'                      => 'AdminKit_Local_Avatars::init',
		'wp-core/class-list-table-chrome.php'                  => 'AdminKit_Core_List_Table_Chrome::init',
		'wp-core/class-admin-bar.php'                          => 'AdminKit_Core_Admin_Bar::init',
		'wp-core/class-options-general.php'                    => 'AdminKit_Core_Options_General::init',
		'wp-core/class-options-discussion.php'                 => 'AdminKit_Core_Options_Discussion::init',
		'wp-core/class-post-previews.php'                      => 'AdminKit_Post_Previews::init',
		'wp-core/class-user-quick-edit.php'                    => 'AdminKit_User_Quick_Edit::init',
		'wp-core/class-username-changer.php'                   => 'AdminKit_Username_Changer::init',

		// features — AdminKit tools that add their own surface.
		'features/online-users/class-online-users.php'         => 'AdminKit_Online_Users::init',
		'features/dashboard/class-dashboard-cards.php'         => null, // presentation half, used by Custom_Dashboard
		'features/dashboard/class-custom-dashboard.php'        => 'AdminKit_Custom_Dashboard::init',
		'features/notifications/class-notification-center.php' => 'AdminKit_Notification_Center::init',
		'wp-core/class-footer.php'                             => 'AdminKit_Footer::init',
		'wp-core/class-help-button.php'                        => 'AdminKit_Help_Button::init',
		'wp-core/class-plugins-list.php'                       => 'AdminKit_Plugins_List::init',
		'features/menu/class-menu-store.php'                   => null, // schema only
		'features/menu/class-menu-manager.php'                 => 'AdminKit_Menu_Manager::init',
		'features/stats/class-stats-store.php'                 => null, // schema only
		'features/stats/class-stats-tracker.php'               => 'AdminKit_Stats_Tracker::init',
		'features/stats/class-stats-dashboard.php'             => 'AdminKit_Stats_Dashboard::init',
		'features/stats/class-stats-page.php'                  => 'AdminKit_Stats_Page::init',
	);

	/**
	 * Boot the plugin. Called once from the loader.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		// Pass 1: load every module file. Pass 2: boot the ones with an entry
		// point, in registry order. Splitting the passes means a module's boot
		// can safely reference any other module's class (all are loaded first).
		foreach ( self::MODULES as $path => $boot ) {
			require_once ADMINKIT_PATH . 'inc/' . $path;
		}
		foreach ( self::MODULES as $boot ) {
			if ( null !== $boot ) {
				call_user_func( $boot );
			}
		}

		self::boot_integrations();

		/**
		 * Fires once every AdminKit module has registered its hooks.
		 *
		 * Use this to register additional integrations or to mutate
		 * AdminKit's own filters from third-party plugins.
		 */
		do_action( 'adminkit/loaded' );
	}

	/**
	 * Load the plugin text domain so bundled translations in `/languages/` apply
	 * when the site language changes. Hooked on `init` — WordPress 6.7+ flags
	 * text domains loaded earlier (`_load_textdomain_just_in_time`).
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'adminkit', false, dirname( plugin_basename( ADMINKIT_FILE ) ) . '/languages' );
	}

	/**
	 * Require the integration base class, then every
	 * `inc/integrations/{plugins|themes}/{slug}/class-{slug}.php` file, and
	 * queue each integration's `maybe_init()` on `after_setup_theme`. The
	 * deferred hook ensures the host's own constants (e.g. BRICKS_VERSION) are
	 * defined by the time the integration checks for them — plugins
	 * load before themes.
	 *
	 * Convention:
	 *   Folder        inc/integrations/{plugins|themes}/{slug}/
	 *   File          inc/integrations/{plugins|themes}/{slug}/class-{slug}.php
	 *   Class         AdminKit_Integration_{Slug}  (Studly_Case_With_Underscores)
	 *                 extends AdminKit_Integration_Base
	 *
	 * The class name derives from the file's basename, so the {plugins,themes}
	 * grouping is purely organizational — it does not affect discovery.
	 * Adding a new integration = drop one folder under the right group. No edits here.
	 *
	 * @return void
	 */
	private static function boot_integrations() {
		require_once ADMINKIT_PATH . 'inc/integrations/abstract-integration.php';

		$files = glob( ADMINKIT_PATH . 'inc/integrations/*/*/class-*.php' );
		if ( empty( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			require_once $file;
			$slug  = substr( basename( $file, '.php' ), strlen( 'class-' ) );
			$class = 'AdminKit_Integration_' . str_replace( '-', '_', ucwords( $slug, '-' ) );
			if ( class_exists( $class ) && method_exists( $class, 'maybe_init' ) ) {
				add_action( 'after_setup_theme', array( $class, 'maybe_init' ) );
			}
		}
	}
}
