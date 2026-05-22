<?php
/**
 * FluentCRM integration.
 *
 * FluentCRM v3 renders its admin as a Vue 3 SPA on Element Plus, themed
 * through two layers of CSS custom properties: its own `--fc-*` tokens
 * (surfaces, text, borders, brand, semantic) and Element Plus `--el-*`
 * tokens, which FluentCRM already wires *to* the `--fc-*` layer (e.g.
 * `--el-color-primary: var(--fc-deep-bg)`, `--el-bg-color: var(--fc-primary-bg)`).
 * So we only remap the `--fc-*` layer to AdminKit tokens — Element Plus
 * and the server-rendered topbar (which consume the same vars) follow
 * automatically, and the whole app flips with AdminKit's dark mode.
 * Loaded only on the toplevel_page_fluentcrm-admin screen (the SPA owns
 * every sub-route via hash routing under that one page).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Crm extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-crm';
	}

	/**
	 * FluentCRM defines `FLUENTCRM_PLUGIN_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENTCRM_PLUGIN_VERSION' );
	}

	/**
	 * FluentCRM registers a top-level menu with slug `fluentcrm-admin`
	 * via `add_menu_page()`, giving the screen id
	 * `toplevel_page_fluentcrm-admin`. Every sub-page is a hash route
	 * under the same screen, so one id covers the entire SPA.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluentcrm-admin' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-crm-admin',
			'src'       => 'inc/integrations/fluent-crm/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
