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

		// --- wp-screens/ (always-loaded broad-usage files; legacy section 'pages') ---
		// wp-components / wpds / font-library are used across many screens
		// (Gutenberg, site editor, customizer, options-connectors, font-library).
		// media.css styles the Media modal (Featured image, Site Icon, …), which
		// opens on almost any screen, plus upload.php chrome (self-scoped via the
		// .upload-php body class). Loading these broadly is cheaper — and, for the
		// modal, more correct — than enumerating every screen.
		foreach ( array( 'wp-components', 'wpds', 'font-library', 'media' ) as $broad ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-' . $broad,
				'src'     => self::ASSETS_BASE . 'wp-screens/' . $broad . '.css',
				'deps'    => $tokens,
				'context' => 'admin',
				'section' => 'pages',
			) );
		}

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
		// Custom-dashboard CSS — registered unconditionally, but its condition ALSO
		// checks the feature toggle AT ENQUEUE TIME (admin_enqueue_scripts), not now.
		// This matters: Chrome::register() runs before Custom_Dashboard::init()
		// registers the setting default, so an `is_enabled()` test here would read
		// false on a fresh install (option not yet saved → default not yet known) and
		// the dashboard would render unstyled until the toggle was saved. Deferring the
		// check to the closure fixes that, and still leaves the native dashboard
		// untouched when the feature is off.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-dashboard',
			'src'       => self::ASSETS_BASE . 'wp-screens/dashboard.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'section'   => 'pages',
			'condition' => static function ( $screen ) {
				return $screen && 'dashboard' === $screen->id
					&& class_exists( 'AdminKit_Custom_Dashboard' )
					&& AdminKit_Custom_Dashboard::is_enabled();
			},
		) );
		// Hover-preview panel CSS (#ak-preview-pop) for the recent-activity
		// thumbnails — the same stylesheet the list-table previews use. Loaded here
		// (the post-previews feature only loads it on list screens) so the dashboard
		// hover panel is styled; the script is enqueued by the dashboard module.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-dashboard-preview',
			'src'       => self::ASSETS_BASE . 'wp-components/post-previews.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'section'   => 'pages',
			'condition' => static function ( $screen ) {
				return $screen && 'dashboard' === $screen->id
					&& class_exists( 'AdminKit_Custom_Dashboard' )
					&& AdminKit_Custom_Dashboard::is_enabled();
			},
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
