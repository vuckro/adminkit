<?php
/**
 * Core chrome — registers every CSS file AdminKit ships for the
 * admin and frontend (admin-bar) contexts.
 *
 * Layout:
 *   - core/*           always-loaded chrome (sidebar, postboxes, notices, ...)
 *   - components/*     always-loaded input/button/table primitives
 *   - screens/*        per-screen polish, conditionally loaded by `$screen->id`
 *   - screens/_shared  small components used across several screens (wp-filter,
 *                      thickbox, notification-dialog, cards) — always-loaded
 *   - third-party/*    overrides for non-core admin plugins (Choices.js,
 *                      Admin Menu Editor) — always-loaded; selectors are
 *                      no-ops when the host is absent
 *
 * Integration CSS (Gutenberg, WooCommerce, FluentCart, ...) lives in
 * each integration's own folder and is registered by the integration class.
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

		// --- core/ (always loaded in admin) ---
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-chrome',
			'src'     => self::ASSETS_BASE . 'core/chrome.css',
			'deps'    => $tokens,
			'context' => 'admin',
		) );
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-links',
			'src'     => self::ASSETS_BASE . 'core/links.css',
			'deps'    => $tokens,
			'context' => 'admin',
		) );
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-adminbar',
			'src'     => self::ASSETS_BASE . 'core/adminbar.css',
			'deps'    => $tokens,
			'context' => 'admin',
		) );

		// --- components/ (always loaded in admin; legacy section 'forms') ---
		// `section => 'forms'` keeps the 1.0 `adminkit/enqueue_forms`
		// filter working — Fluent integrations bail all four via that
		// single switch. Per-file granularity uses `adminkit/enqueue_{handle}`.
		foreach ( array( 'inputs', 'buttons', 'tables', 'form-table' ) as $component ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-' . $component,
				'src'     => self::ASSETS_BASE . 'components/' . $component . '.css',
				'deps'    => $tokens,
				'context' => 'admin',
				'section' => 'forms',
			) );
		}

		// --- screens/_shared/ (always loaded — small, used by several screens) ---
		foreach ( array( 'wp-filter', 'thickbox', 'notification-dialog', 'cards' ) as $shared ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-shared-' . $shared,
				'src'     => self::ASSETS_BASE . 'screens/_shared/' . $shared . '.css',
				'deps'    => $tokens,
				'context' => 'admin',
			) );
		}

		// --- screens/ (always-loaded broad-usage files; legacy section 'pages') ---
		// wp-components / wpds / font-library are used across many screens
		// (Gutenberg, site editor, customizer, options-connectors, font-library).
		// Loading them broadly is cheaper than enumerating every screen.
		foreach ( array( 'wp-components', 'wpds', 'font-library' ) as $broad ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-' . $broad,
				'src'     => self::ASSETS_BASE . 'screens/' . $broad . '.css',
				'deps'    => $tokens,
				'context' => 'admin',
				'section' => 'pages',
			) );
		}

		// --- screens/ (per-screen conditional; legacy section 'pages') ---
		self::register_screen( 'themes',          array( 'themes', 'theme-install' ) );
		self::register_screen( 'theme-install',   array( 'themes', 'theme-install' ) );
		self::register_screen( 'media',           array( 'upload', 'media', 'attachment' ) );
		self::register_screen( 'profile',         array( 'profile', 'user-edit', 'user-new' ) );
		self::register_screen( 'nav-menus',       array( 'nav-menus' ) );
		self::register_screen( 'plugins',         array( 'plugins', 'plugin-install' ) );
		self::register_screen( 'plugin-editor',   array( 'plugin-editor' ) );
		self::register_screen( 'code-mirror',     array( 'plugin-editor', 'theme-editor' ) );
		self::register_screen( 'update-core',     array( 'update-core' ) );
		self::register_screen( 'import',          array( 'import' ) );
		self::register_screen( 'site-health',     array( 'site-health', 'site-health-info', 'options-privacy', 'privacy-policy-guide' ) );

		// --- Frontend admin bar (when bar is showing on the frontend) ---
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-adminbar',
			'src'     => self::ASSETS_BASE . 'core/adminbar.css',
			'deps'    => $tokens,
			'context' => 'frontend',
			'section' => 'adminbar',
		) );
	}

	/**
	 * Register a `screens/{name}.css` file that loads only on the
	 * given screen ids.
	 *
	 * @param string   $name       Filename without extension (e.g. `themes`).
	 * @param string[] $screen_ids Screen ids to match against.
	 * @return void
	 */
	private static function register_screen( $name, array $screen_ids ) {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-' . $name,
			'src'       => self::ASSETS_BASE . 'screens/' . $name . '.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'section'   => 'pages',
			'condition' => static function ( $screen ) use ( $screen_ids ) {
				return $screen && in_array( $screen->id, $screen_ids, true );
			},
		) );
	}
}
