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
	 * The six built-in WP Settings screens AdminKit restyles as a tabbed card
	 * UI. Screen ids are the page basenames minus `.php`. Single source of truth
	 * for both the `options.css` registration and the `options.js` tab-navigation
	 * enqueue below.
	 *
	 * @var string[]
	 */
	const OPTIONS_SCREENS = array(
		'options-general',
		'options-writing',
		'options-reading',
		'options-discussion',
		'options-media',
		'options-permalink',
	);

	/**
	 * The built-in Tools screens unified under one pill tab strip (tools.js) so
	 * they read as a single tabbed section. Shared by the tools.css registration
	 * and the tab-nav enqueue below.
	 *
	 * @var string[]
	 */
	const TOOLS_SCREENS = array(
		'tools',
		'import',
		'export',
		'site-health',
		'export-personal-data',
		'erase-personal-data',
	);

	/**
	 * Register every asset. Called once from the plugin orchestrator
	 * after `AdminKit_Assets::init()`.
	 *
	 * @return void
	 */
	public static function register() {
		$tokens = array( AdminKit_Assets::TOKENS_HANDLE );

		// JS counterpart of the per-screen stylesheets: the options-screen tab
		// navigation is a footer script, so it can't ride the (style-only) asset
		// registry. Hook it here — registered alongside the CSS, gated to the
		// same six screens — so all options-screen wiring stays in one place.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_options_js' ) );
		// Same idea for the Tools screens: one pill tab strip linking Available
		// Tools / Import / Export / Site Health / personal-data export + erase, so
		// they read as one tabbed section. Footer script, gated to TOOLS_SCREENS.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_tools_js' ) );

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
		// Core Settings screens — one shared stylesheet (cards + clarity) for all
		// six built-in options pages. The screen ids are the page basenames minus
		// `.php` (e.g. options-general.php → 'options-general'); each file's rules
		// also self-scope via the matching `.{page}-php` body class. The matching
		// tab navigation ships as a footer script, enqueued for the same six
		// screens (see enqueue_options_js, hooked just below).
		self::register_screen( 'options', self::OPTIONS_SCREENS );
		// Tools screens — the pill tab strip styling (the JS that builds it ships as
		// a footer script, see enqueue_tools_js below).
		self::register_screen( 'tools', self::TOOLS_SCREENS );
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
	 * Enqueue the options-screen tab-navigation script on the six built-in
	 * Settings pages. Mirrors the `options.css` gate (same `OPTIONS_SCREENS`
	 * list) but on the JS side: the script wraps each section (its heading +
	 * body) into a tab panel and builds a tab strip above them, showing one panel
	 * at a time (first tab active by default; remembered per-screen). Every field
	 * stays in the DOM so the single form still submits them all. i18n labels
	 * ride along as an inline bootstrap. No-op when AdminKit isn't styling the
	 * admin.
	 *
	 * @return void
	 */
	public static function enqueue_options_js() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		if ( ! AdminKit_Screen::is_one_of( self::OPTIONS_SCREENS ) ) {
			return;
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-options',
			'assets/js/wp-screens/options.js',
			array(),
			'window.AdminKitOptions=' . wp_json_encode( array(
				// Synthesized tab title for the leading table(s) that have no own
				// heading (general / reading / discussion).
				'general' => __( 'General', 'adminkit' ),
				// Accessible label for the tablist (aria-label).
				'nav'     => __( 'Settings sections', 'adminkit' ),
			) ) . ';'
		);
	}

	/**
	 * Enqueue the Tools-screen tab strip on the built-in Tools pages. The script
	 * inserts ONE pill tab strip (plain links) after the screen's <h1> so Available
	 * Tools / Import / Export / Site Health / personal-data export + erase read as a
	 * single tabbed section. Nothing is moved or fetched — each tab links to its
	 * native screen, so every page keeps its own markup, forms and handlers, and
	 * with JS off the pages are 100% native. i18n labels + the tab list (id, label,
	 * url) + the current screen ride along as an inline bootstrap.
	 *
	 * @return void
	 */
	public static function enqueue_tools_js() {
		if ( ! AdminKit_Settings::get( 'tools_unified_enabled' ) ) {
			return; // feature toggle off → leave the native, separate Tools pages.
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		if ( ! AdminKit_Screen::is_one_of( self::TOOLS_SCREENS ) ) {
			return;
		}
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$current = $screen ? $screen->id : '';
		$tabs    = array(
			array( 'id' => 'tools',                'label' => __( 'Available Tools', 'adminkit' ),      'url' => admin_url( 'tools.php' ) ),
			array( 'id' => 'import',               'label' => __( 'Import', 'adminkit' ),               'url' => admin_url( 'import.php' ) ),
			array( 'id' => 'export',               'label' => __( 'Export', 'adminkit' ),               'url' => admin_url( 'export.php' ) ),
			array( 'id' => 'site-health',          'label' => __( 'Site Health', 'adminkit' ),          'url' => admin_url( 'site-health.php' ) ),
			array( 'id' => 'export-personal-data', 'label' => __( 'Export Personal Data', 'adminkit' ), 'url' => admin_url( 'export-personal-data.php' ) ),
			array( 'id' => 'erase-personal-data',  'label' => __( 'Erase Personal Data', 'adminkit' ),  'url' => admin_url( 'erase-personal-data.php' ) ),
		);
		AdminKit_Assets::enqueue_script(
			'adminkit-tools',
			'assets/js/wp-screens/tools.js',
			array(),
			'window.AdminKitTools=' . wp_json_encode( array(
				'tabs'    => $tabs,
				'current' => $current,
				'nav'     => __( 'Tools', 'adminkit' ),
			) ) . ';'
		);
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
