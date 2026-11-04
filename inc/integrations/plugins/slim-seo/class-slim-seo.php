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

	/** @return string Human-readable host name shown in Settings → Plugins. */
	public static function label() {
		return 'Slim SEO';
	}

	/** @return string[] Host plugin file(s) this adapter themes. */
	public static function host_files() {
		return array( 'slim-seo/slim-seo.php', 'slim-seo-pro/slim-seo-pro.php' );
	}

	/**
	 * Slim SEO defines `SLIM_SEO_VER` in its bootstrap (`slim-seo.php`).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'SLIM_SEO_VER' ) || defined( 'SLIM_SEO_PRO_VER' );
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
	 * Only load the list adapter alongside the host's real column stylesheet.
	 *
	 * @param \WP_Screen|null $screen Screen.
	 * @return bool
	 */
	public static function owns_columns_screen( $screen ) {
		return $screen && in_array( $screen->base, array( 'edit', 'edit-tags' ), true )
			&& wp_style_is( 'slim-seo-admin-columns', 'enqueued' );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-slim-seo-columns',
			'src'       => 'inc/integrations/plugins/slim-seo/css/columns.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE, 'slim-seo-admin-columns' ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_columns_screen' ),
		) );

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
	/** @return void */
	protected static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'deduplicate_settings_page' ), 10001 );
	}

	/**
	 * Older Pro can register its standalone page alongside the shared core page.
	 * Keep the canonical renderer/save dispatcher; retain all Pro tab/save hooks.
	 * This is a no-op when either renderer is absent (including Pro standalone).
	 *
	 * @return void
	 */
	public static function deduplicate_settings_page() {
		$core = 'eLightUp\\SlimSEO\\Common\\Settings\\Page';
		$legacy = 'SlimSEOPro\\Page';
		$hook = 'settings_page_slim-seo';
		if ( false === has_action( $hook, array( $core, 'render' ) ) || false === has_action( $hook, array( $legacy, 'render' ) ) ) { return; }
		remove_action( $hook, array( $legacy, 'render' ) );
		if ( false !== has_action( 'load-' . $hook, array( $core, 'save' ) ) ) {
			remove_action( 'load-' . $hook, array( $legacy, 'save' ) );
		}
		global $submenu;
		$seen = false;
		foreach ( $submenu['options-general.php'] ?? array() as $key => $item ) {
			if ( 'slim-seo' !== ( $item[2] ?? '' ) ) { continue; }
			if ( $seen ) { unset( $submenu['options-general.php'][ $key ] ); }
			$seen = true;
		}
	}

}
