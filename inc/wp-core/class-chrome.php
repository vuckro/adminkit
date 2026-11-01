<?php
/**
 * Core chrome — registers every CSS file AdminKit ships for the
 * admin and frontend (admin-bar) contexts.
 *
 * Layout:
 *   - wp-core/*           always-loaded chrome (sidebar, postboxes, notices, ...)
 *   - wp-components/*     always-loaded input/button/table primitives
 *   - wp-screens/*        per-screen polish, conditionally loaded by `$screen->id`
 *   - wp-screens/_shared  small components used across several screens (wp-filter,
 *                         thickbox, notification-dialog, cards) — always-loaded
 *
 * Integration CSS (Gutenberg, WooCommerce, the Fluent suite, ...) lives in
 * each integration's own folder under `inc/integrations/{plugins|themes}/{slug}/css/`
 * and is registered by that integration's class, not here.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Chrome {

	const ASSETS_BASE = 'assets/css/';

	/**
	 * Register every asset. Called once from the plugin orchestrator
	 * after `AdminKit_Assets::init()`.
	 *
	 * @return void
	 */
	public static function register() {
		$tokens = array( AdminKit_Assets::TOKENS_HANDLE );

		// --- wp-core/ (always loaded in admin) ---
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-chrome',
			'src'     => self::ASSETS_BASE . 'wp-core/chrome.css',
			'deps'    => $tokens,
			'context' => 'admin',
		) );
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-links',
			'src'     => self::ASSETS_BASE . 'wp-core/links.css',
			'deps'    => $tokens,
			'context' => 'admin',
		) );
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-adminbar',
			'src'     => self::ASSETS_BASE . 'wp-core/adminbar.css',
			'deps'    => $tokens,
			'context' => 'admin',
		) );

		// --- wp-components/ (always loaded in admin; legacy section 'forms') ---
		// `section => 'forms'` keeps the 1.0 `adminkit/enqueue_forms`
		// filter working — Fluent integrations bail all four via that
		// single switch. Per-file granularity uses `adminkit/enqueue_{handle}`.
		foreach ( array( 'inputs', 'buttons', 'tables', 'form-table' ) as $component ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-' . $component,
				'src'     => self::ASSETS_BASE . 'wp-components/' . $component . '.css',
				'deps'    => $tokens,
				'context' => 'admin',
				'section' => 'forms',
			) );
		}

		// Generic feedback surfaces (solid-fill `.notice-alt` + the AJAX
		// update/install/activate states) — extends the notices already themed in
		// wp-core/chrome.css so an UNSUPPORTED plugin's feedback reads right in
		// dark too. Kept OUT of the 'forms' section so it isn't bailed by the
		// Fluent `adminkit/enqueue_forms` switch; toggle via `adminkit/enqueue_adminkit-feedback`.
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-feedback',
			'src'     => self::ASSETS_BASE . 'wp-components/feedback.css',
			'deps'    => $tokens,
			'context' => 'admin',
		) );

		// --- wp-screens/_shared/ (always loaded — small, used by several screens) ---
		foreach ( array( 'wp-filter', 'thickbox', 'notification-dialog', 'cards' ) as $shared ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-shared-' . $shared,
				'src'     => self::ASSETS_BASE . 'wp-screens/_shared/' . $shared . '.css',
				'deps'    => $tokens,
				'context' => 'admin',
			) );
		}

		// --- wp-screens/ (broad-usage & WPDS/Font Library conditional files; section 'pages') ---
		// media.css styles the Media modal (Featured image, Site Icon, …), which can
		// open on almost any screen, plus upload.php chrome (self-scoped via .upload-php).
		foreach ( array( 'wp-components', 'media' ) as $broad ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-' . $broad,
				'src'     => self::ASSETS_BASE . 'wp-screens/' . $broad . '.css',
				'deps'    => $tokens,
				'context' => 'admin',
				'section' => 'pages',
			) );
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-wpds',
			'src'       => self::ASSETS_BASE . 'wp-screens/wpds.css',
			'deps'      => $tokens,
			'context'   => 'admin',
			'section'   => 'pages',
			'condition' => static function ( $screen ) {
				if ( function_exists( 'wp_style_is' ) && ( wp_style_is( 'wp-components', 'enqueued' ) || wp_style_is( 'wp-edit-site', 'enqueued' ) ) ) {
					return true;
				}
				if ( ! $screen ) {
					return false;
				}
				if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
					return true;
				}
				return in_array( $screen->id, array( 'site-editor', 'widgets', 'options-connectors', 'appearance_page_font-library', 'edit-wp_block', 'edit-wp_template', 'edit-wp_template_part', 'edit-wp_navigation' ), true );
			},
		) );
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-font-library',
			'src'       => self::ASSETS_BASE . 'wp-screens/font-library.css',
			'deps'      => $tokens,
			'context'   => 'admin',
			'section'   => 'pages',
			'condition' => static function ( $screen ) {
				if ( function_exists( 'wp_style_is' ) && wp_style_is( 'wp-edit-site', 'enqueued' ) ) {
					return true;
				}
				return $screen && ( 'site-editor' === $screen->id || 'appearance_page_font-library' === $screen->id || 0 === strpos( (string) $screen->id, 'appearance_page_font' ) );
			},
		) );

		// --- Customizer (wp-admin/customize.php) ---
		// Its own context: customize.php skips admin_enqueue_scripts, so this rides
		// the `customize` dispatch (customize_controls_enqueue_scripts) wired in
		// AdminKit_Assets. No screen condition — it always loads in that context.
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-customize',
			'src'     => self::ASSETS_BASE . 'wp-screens/customize.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'customize',
			'section' => 'pages',
		) );

		// --- wp-screens/ (per-screen conditional; legacy section 'pages') ---
		// post-edit uses $screen->base instead of ->id so it fires for every
		// post type (pages, CPTs like bricks_template, woo products, …),
		// not just the built-in 'post' type.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-post-edit',
			'src'       => self::ASSETS_BASE . 'wp-screens/post-edit.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'section'   => 'pages',
			'condition' => static function ( $screen ) {
				return $screen && 'post' === $screen->base;
			},
		) );
		// Core Settings screens — one shared stylesheet (per-section card visual)
		// for all six built-in options pages. The screen ids are the page basenames
		// minus `.php` (e.g. options-general.php → 'options-general'); each rule
		// in `options.css` is also self-scoped via a `.{page}-php` body class so
		// it never leaks onto plugin settings pages that reuse `.form-table`.
		self::register_screen( 'options', array(
			'options-general',
			'options-writing',
			'options-reading',
			'options-discussion',
			'options-media',
			'options-permalink',
		) );
		AdminKit_Assets::register( array(
			'handle' => 'adminkit-taxonomy-layout',
			'src' => self::ASSETS_BASE . 'wp-screens/taxonomy.css',
			'deps' => $tokens,
			'context' => 'admin',
			'condition' => static function ( $screen ) { return $screen && 'edit-tags' === $screen->base; },
		) );
		self::register_screen( 'themes',          array( 'themes', 'theme-install' ) );
		self::register_screen( 'theme-install',   array( 'themes', 'theme-install' ) );
		// user-new.php reports screen id 'user' (WP strips '-new'); the CSS
		// still scopes via the .user-new-php body class. Keep 'user-new' too.
		self::register_screen( 'profile',         array( 'profile', 'user-edit', 'user', 'user-new' ) );
		self::register_screen( 'nav-menus',       array( 'nav-menus' ) );
		self::register_screen( 'widgets',         array( 'widgets' ) );
		self::register_screen( 'plugins',         array( 'plugins', 'plugin-install' ) );
		self::register_screen( 'plugin-editor',   array( 'plugin-editor', 'theme-editor' ) );
		self::register_screen( 'code-mirror',     array( 'plugin-editor', 'theme-editor' ) );
		self::register_screen( 'update-core',     array( 'update-core' ) );
		self::register_screen( 'import',          array( 'import' ) );
		self::register_screen( 'site-health',     array( 'site-health', 'site-health-info', 'options-privacy', 'privacy-policy-guide' ) );
		self::register_screen( 'about',           array( 'about', 'credits', 'freedoms', 'privacy', 'contribute' ) );

		// --- Frontend admin bar (when bar is showing on the frontend) ---
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-adminbar',
			'src'     => self::ASSETS_BASE . 'wp-core/adminbar.css',
			'deps'    => $tokens,
			'context' => 'frontend',
			'section' => 'adminbar',
		) );

		add_action( 'adminkit/tokens_enqueued', array( __CLASS__, 'clean_chrome_css' ) );
	}

	/**
	 * Hide the WordPress footer bar (#wpfooter) and contextual Help tab
	 * (#contextual-help-link-wrap) according to the enabled module's sub-options.
	 *
	 * @param string $context Asset context.
	 * @return void
	 */
	public static function clean_chrome_css( $context ) {
		if ( 'admin' !== $context || ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		$enabled = (bool) apply_filters( 'adminkit/clean_chrome/enabled', (bool) AdminKit_Settings::get( 'clean_chrome_enabled' ) );
		$selectors = array();
		if ( apply_filters( 'adminkit/hide_footer/enabled', $enabled && (bool) AdminKit_Settings::get( 'clean_chrome_hide_footer' ) ) ) {
			$selectors[] = 'body.adminkit #wpfooter';
		}
		if ( apply_filters( 'adminkit/hide_help_button/enabled', $enabled && (bool) AdminKit_Settings::get( 'clean_chrome_hide_help' ) ) ) {
			$selectors[] = 'body.adminkit #contextual-help-link-wrap';
		}
		if ( $selectors ) {
			wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, implode( ',', $selectors ) . '{display:none}' );
		}
	}

	/**
	 * Register a `wp-screens/{name}.css` file that loads only on the
	 * given screen ids.
	 *
	 * @param string   $name       Filename without extension (e.g. `themes`).
	 * @param string[] $screen_ids Screen ids to match against.
	 * @return void
	 */
	private static function register_screen( $name, array $screen_ids ) {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-' . $name,
			'src'       => self::ASSETS_BASE . 'wp-screens/' . $name . '.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'section'   => 'pages',
			'condition' => static function ( $screen ) use ( $screen_ids ) {
				return $screen && in_array( $screen->id, $screen_ids, true );
			},
		) );
	}
}
