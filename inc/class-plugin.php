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
	 * Boot the plugin. Called once from the loader.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		AdminKit_Assets::init();
		AdminKit_Core_Chrome::register();
		AdminKit_Core_Login::register();
		AdminKit_Settings::init();
		AdminKit_Settings_Page::init();
		AdminKit_Theme_Toggle::init();
		AdminKit_Profile_Account::init();
		AdminKit_Account_Bar::init();
		AdminKit_Core_List_Table_Chrome::init();
		AdminKit_Post_Previews::init();

		self::boot_integrations();

		AdminKit_Dashboard::init();

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
