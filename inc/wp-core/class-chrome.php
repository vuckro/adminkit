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
	 * Screens that wear the T4 native-pages template
	 * (assets/css/wp-screens/native-pages.css + a tiny a11y JS). Adds a
	 * shared `adminkit-native-page` body class (see
	 * AdminKit_Assets::add_admin_body_class()) plus the edge-to-edge card
	 * styling. Extension: append a screen id here AND in the body-class
	 * list — that's the entire surface.
	 *
	 * @var string[]
	 */
	const NATIVE_PAGES_SCREENS = array(
		'options-general',
		'options-writing',
		'options-reading',
		'options-discussion',
		'options-media',
		'options-permalink',
		'profile',
		'user-edit',
		'user',
		'user-new',
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
		// Core Settings screens — one shared stylesheet (cards + clarity) for all
		// six built-in options pages. The screen ids are the page basenames minus
		// `.php` (e.g. options-general.php → 'options-general'); each file's rules
		// also self-scope via the matching `.{page}-php` body class. The matching
		// tab navigation ships as a footer script, enqueued for the same six
		// screens (see enqueue_options_js, hooked just below).
		self::register_screen( 'options', self::OPTIONS_SCREENS );
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

		// Native-pages T4 template — the FULL-WIDTH shared chrome for WP's
		// built-in Settings + Users edit screens. Registered LAST among the
		// per-screen CSS so its rules win on cascade ties (no `!important`
		// needed). Loads on NATIVE_PAGES_SCREENS = OPTIONS_SCREENS ∪ profile/
		// user-edit/user/user-new. Matches the body class
		// AdminKit_Assets::add_admin_body_class() puts on the same nine
		// screens (`adminkit-native-page`). The companion JS adds ARIA roles
		// to WP's `.nav-tab-wrapper` so the pill restyling has matching a11y
		// semantics; behaviour stays WP-native (server-side `?tab=` switch).
		self::register_screen( 'native-pages', self::NATIVE_PAGES_SCREENS );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_native_pages_js' ) );

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
	 * Enqueue the native-pages a11y script on every screen that wears the
	 * T4 template. The script only ANNOTATES WordPress's native
	 * `.nav-tab-wrapper` with `role="tablist"` / `role="tab"` /
	 * `aria-selected` so the pill restyling has matching ARIA semantics.
	 * Behaviour stays WP-native (server-side `?tab=` switch). Mirrors
	 * `enqueue_options_js()` but gated to NATIVE_PAGES_SCREENS.
	 *
	 * @return void
	 */
	public static function enqueue_native_pages_js() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		if ( ! AdminKit_Screen::is_one_of( self::NATIVE_PAGES_SCREENS ) ) {
			return;
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-native-pages',
			'assets/js/wp-screens/native-pages.js'
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
