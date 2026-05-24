<?php
/**
 * Slim SEO integration.
 *
 * Maps Slim SEO's settings page (Settings → Slim SEO) to AdminKit
 * tokens. Slim SEO ships its own `--ss-color-*` design tokens and
 * redeclares `--wp-admin-theme-color` inside `.wrap`, both of which
 * override AdminKit's global cascade on this single screen. The
 * stylesheet routes them back to AdminKit tokens and patches the
 * surfaces that hardcode white / dark text (header bar, tabs card,
 * feature toggle titles).
 *
 * Loaded only on `settings_page_slim-seo` via `owns_screen()`.
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
	 * Slim SEO defines `SLIM_SEO_VER` in its bootstrap (`slim-seo.php`).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'SLIM_SEO_VER' );
	}

	/**
	 * Slim SEO renders its settings under Settings → Slim SEO, which
	 * gives the screen ID `settings_page_slim-seo`. The body class
	 * matches, which is what our scoped selectors rely on.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'settings_page_slim-seo' === $screen->id;
	}

	/**
	 * Screens where Slim SEO loads CSS that sets --ss-color-* on
	 * :root: post-edit (meta-tags + redirection meta boxes), term-edit
	 * (meta-tags), and the Slim SEO settings page itself. Anywhere
	 * else, Slim SEO's :root vars aren't declared so our override is
	 * dead weight.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_global_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		if ( self::owns_screen( $screen ) ) {
			return true;
		}
		return in_array( $screen->base, array( 'post', 'edit-tags', 'term' ), true );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Variable remap routing Slim SEO's --ss-color-* vars to our
		// tokens. Loaded only where Slim SEO actually enqueues a
		// stylesheet that sets those vars on :root (see owns_global_screen).
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-slim-seo-global',
			'src'       => 'inc/integrations/plugins/slim-seo/css/global.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_global_screen' ),
		) );

		// Settings-page-only chrome (header bar, sidebar hide, postbox
		// surfaces, …) — conditional on the settings page.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-slim-seo-admin',
			'src'       => 'inc/integrations/plugins/slim-seo/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
