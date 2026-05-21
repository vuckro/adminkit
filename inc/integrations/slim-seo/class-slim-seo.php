<?php
/**
 * Slim SEO integration — stub.
 *
 * Detection signature when implementing:
 *   defined( 'SLIM_SEO_VER' )
 *
 * Planned scope:
 *   - Polish the per-post Slim SEO metabox (title / description / OG fields)
 *   - Map the plugin's settings page to AdminKit tokens
 *
 * CSS files will land under `inc/integrations/slim-seo/css/`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Slim_Seo extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'slim-seo';
	}

	/**
	 * @return bool
	 */
	public static function is_active() {
		return false;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// TODO: register CSS files under inc/integrations/slim-seo/css/.
	}

	/**
	 * @return void
	 */
	protected static function boot() {
		// TODO: hook Slim-SEO-specific filters once the polish lands.
	}
}
