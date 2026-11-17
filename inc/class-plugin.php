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
		'class-storage.php'                                    => null,
		'class-screen.php'                                     => null,
		'class-icons.php'                                      => null,
		'class-design-system.php'                              => null,

		// Core services + the settings system, in boot order.
		'class-assets.php'                                     => 'AdminKit_Assets::init',
		'wp-core/class-chrome.php'                             => 'AdminKit_Core_Chrome::register',
		'wp-core/class-login.php'                              => 'AdminKit_Core_Login::register',
		'wp-core/class-branding.php'                           => 'AdminKit_Core_Branding::init',
		'wp-core/class-menu-icons.php'                         => 'AdminKit_Core_Menu_Icons::init',
		'settings/class-settings.php'                          => 'AdminKit_Settings::init',
		'class-interface.php'                                 => 'AdminKit_Interface::init',
		'class-upgrades.php'                                  => 'AdminKit_Upgrades::init',
		'settings/class-settings-catalog.php'                 => null, // booted by Settings_Page
		'settings/class-settings-gate.php'                    => null, // booted by Settings_Page
		'settings/class-settings-i18n.php'                    => null, // UI string catalog for Settings_Page
		'settings/class-settings-page.php'                     => 'AdminKit_Settings_Page::init',
		'class-theme-toggle.php'                               => 'AdminKit_Theme_Toggle::init',
		'class-fluent-theme.php'                               => 'AdminKit_Fluent_Theme::init',

		// wp-core — restyle of existing WordPress screens.
		'wp-core/class-profile-account.php'                    => 'AdminKit_Profile_Account::init',
		'wp-core/class-list-table-chrome.php'                  => 'AdminKit_Core_List_Table_Chrome::init',
		'wp-core/class-admin-bar.php'                          => 'AdminKit_Core_Admin_Bar::init',
		'wp-core/class-options-general.php'                    => 'AdminKit_Core_Options_General::init',
		'wp-core/class-reading-layout.php'                     => 'AdminKit_Core_Reading_Layout::init',
		'wp-core/class-options-discussion.php'                 => 'AdminKit_Core_Options_Discussion::init',
		'wp-core/class-post-previews.php'                      => 'AdminKit_Post_Previews::init',
		'wp-core/class-user-quick-edit.php'                    => 'AdminKit_User_Quick_Edit::init',
		'wp-core/class-username-changer.php'                   => 'AdminKit_Username_Changer::init',

		// features — AdminKit tools that add their own surface.
		'features/notifications/class-notification-center.php' => 'AdminKit_Notification_Center::init',
		'wp-core/class-plugins-list.php'                       => 'AdminKit_Plugins_List::init',
		'wp-core/class-content-list.php'                       => 'AdminKit_Content_List::init',
		'features/menu/class-menu-store.php'                   => null, // schema only
		'features/menu/class-menu-config.php'                  => null,
		'features/menu/class-menu-snapshot.php'                => null,
		'features/menu/class-menu-layout.php'                   => null,
		'features/menu/class-menu-renderer.php'                => null,
		'features/menu/class-menu-manager.php'                 => 'AdminKit_Menu_Manager::init',
		'features/toolbar/class-toolbar-config.php'            => null,
		'features/toolbar/class-toolbar-snapshot.php'          => null,
		'features/toolbar/class-toolbar-renderer.php'          => null,
		'features/toolbar/class-toolbar-manager.php'           => 'AdminKit_Toolbar_Manager::init',
	);

	/** Data-only modules loaded by their owning service on first use. */
	const LAZY_MODULES = array(
		'generated/lucide-paths.php',
		'generated/integrations-manifest.php',
	);

	/** Admin-only modules skipped on frontend, REST, cron, and login requests. */
	const ADMIN_ONLY_MODULES = array(
		'class-fluent-theme.php'                               => true,
		'wp-core/class-profile-account.php'                    => true,
		'wp-core/class-list-table-chrome.php'                  => true,
		'wp-core/class-options-general.php'                    => true,
		'wp-core/class-reading-layout.php'                     => true,
		'wp-core/class-options-discussion.php'                 => true,
		'wp-core/class-post-previews.php'                      => true,
		'wp-core/class-user-quick-edit.php'                    => true,
		'wp-core/class-username-changer.php'                   => true,
		'features/notifications/class-notification-center.php' => true,
		'wp-core/class-plugins-list.php'                       => true,
		'wp-core/class-content-list.php'                       => true,
		'features/menu/class-menu-layout.php'                  => true,
		'features/menu/class-menu-renderer.php'                => true,
	);

	/** @var bool Prevent duplicate boots when `init()` is called both directly and via `plugins_loaded`. */
	private static $booted = false;

	/**
	 * Boot the plugin. Called once from the loader (on `plugins_loaded` priority 5,
	 * or immediately when included after `plugins_loaded`).
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		// Pass 1: load every active module file. Pass 2: boot the ones with an entry
		// point, in registry order. On frontend/REST/cron (`! is_admin()`), skip
		// admin-only modules so visitor traffic pays zero file-I/O cost for them.
		$skip_admin = function_exists( 'is_admin' ) && ! is_admin();
		foreach ( self::MODULES as $path => $boot ) {
			if ( $skip_admin && isset( self::ADMIN_ONLY_MODULES[ $path ] ) ) {
				continue;
			}
			require_once ADMINKIT_PATH . 'inc/' . $path;
		}
		foreach ( self::MODULES as $path => $boot ) {
			if ( null === $boot ) {
				continue;
			}
			if ( $skip_admin && isset( self::ADMIN_ONLY_MODULES[ $path ] ) ) {
				continue;
			}
			call_user_func( $boot );
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
	 * Load a single integration adapter class on demand from the compiled manifest.
	 *
	 * @param string $slug Integration slug.
	 * @return string|null Resolved adapter class name, or null if unknown/missing.
	 */
	public static function load_integration( $slug ) {
		if ( ! class_exists( 'AdminKit_Integrations_Manifest', false ) ) {
			require_once ADMINKIT_PATH . 'inc/generated/integrations-manifest.php';
		}
		if ( ! isset( AdminKit_Integrations_Manifest::ITEMS[ $slug ] ) ) {
			return null;
		}
		if ( ! class_exists( 'AdminKit_Integration_Base', false ) ) {
			require_once ADMINKIT_PATH . 'inc/integrations/abstract-integration.php';
		}
		$entry = AdminKit_Integrations_Manifest::ITEMS[ $slug ];
		$class = $entry['class'];
		if ( ! class_exists( $class, false ) ) {
			$file = ADMINKIT_PATH . 'inc/' . $entry['file'];
			if ( ! is_file( $file ) ) {
				return null;
			}
			require_once $file;
		}
		return class_exists( $class ) ? $class : null;
	}

	/**
	 * Defer adapter loading and initialization until `after_setup_theme`, when host
	 * constants and theme bootstraps are ready. Only adapters matching the request
	 * context and an active host are included at runtime.
	 *
	 * @return void
	 */
	private static function boot_integrations() {
		add_action( 'after_setup_theme', array( __CLASS__, 'run_integrations' ) );
	}

	/**
	 * Initialize every adapter whose host plugin/theme is active in the current context.
	 *
	 * @return void
	 */
	public static function run_integrations() {
		if ( ! class_exists( 'AdminKit_Integrations_Manifest', false ) ) {
			require_once ADMINKIT_PATH . 'inc/generated/integrations-manifest.php';
		}
		$is_admin = ! function_exists( 'is_admin' ) || is_admin();
		foreach ( AdminKit_Integrations_Manifest::ITEMS as $slug => $entry ) {
			if ( ! $is_admin && empty( $entry['frontend'] ) ) {
				continue;
			}
			if ( ! AdminKit_Integrations_Manifest::is_host_active( $slug ) ) {
				continue;
			}
			$class = self::load_integration( $slug );
			if ( $class && method_exists( $class, 'maybe_init' ) ) {
				call_user_func( array( $class, 'maybe_init' ) );
			}
		}
	}
}
