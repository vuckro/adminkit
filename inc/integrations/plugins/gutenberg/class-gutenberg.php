<?php
/**
 * Gutenberg integration — block editor restyle (light + dark).
 *
 * Registers editor CSS in the `editor` context, which AdminKit_Assets dispatches
 * via `enqueue_block_editor_assets` (block / site / widgets / navigation editors).
 * The CSS only enters editor surfaces; the admin context still loads its chrome on
 * top so the WP-admin wrapping around the editor is themed too. Always on — the
 * editor is part of AdminKit's standard restyle (no feature toggle).
 *
 * The editor follows the global light/dark toggle: every rule reads --ak-* tokens,
 * which flip with `data-adminkit-theme`. Because the fullscreen editor hides the
 * admin-bar toggle, this also injects a matching sun/moon button into the editor
 * header (reusing the theme toggle's own attribute + storage key, so the choice is
 * shared).
 *
 * Files:
 *   editor.css         — header / sidebar / publish button / tabs / toggle / WP logo
 *   dark-mode-map.css  — @wordpress/components + edit-post + editor + block-editor
 *                        token bridge: patches the Gutenberg surfaces that hardcode
 *                        #fff / #1e1e1e / #ddd / #f0f0f0 (panels, document bar,
 *                        popovers, modals, inserter, list view, form controls,
 *                        block toolbar) so the whole chrome flips with the toggle.
 *                        Always on; the canvas (.editor-styles-wrapper) is left alone here.
 *   canvas.css         — OPT-IN theming of the iframed canvas (content + native blocks),
 *                        gated by the "Gutenberg" feature (editor_content_theme, OFF by
 *                        default). Injected INTO the editor iframe by canvas-theme.js.
 *   js/theme-toggle.js — sun/moon toggle injected into the editor header
 *   js/canvas-theme.js — injects the token CSS + canvas.css into the editor iframe and
 *                        mirrors data-adminkit-theme onto the iframe <html> (so the dark
 *                        token block flips the canvas). Gated by the "Gutenberg" feature.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Gutenberg extends AdminKit_Integration_Base {

	const BASE = 'inc/integrations/plugins/gutenberg/css/';

	/**
	 * @return string
	 */
	public static function slug() {
		return 'gutenberg';
	}

	/**
	 * Gutenberg ships with WP core since 5.0 — always available on supported sites.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return true;
	}

	/**
	 * Register editor.css + dark-mode-map.css in the `editor` context
	 * (dispatched on `enqueue_block_editor_assets`).
	 *
	 * @return void
	 */
	public static function register_assets() {
		$tokens = array( AdminKit_Assets::TOKENS_HANDLE );

		foreach ( array( 'editor', 'dark-mode-map' ) as $name ) {
			AdminKit_Assets::register( array(
				'handle'  => 'adminkit-gutenberg-' . $name,
				'src'     => self::BASE . $name . '.css',
				'deps'    => $tokens,
				'context' => 'editor',
			) );
		}
	}

	/**
	 * Wire the editor-header light/dark toggle.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_theme_toggle' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_canvas_theme' ) );
	}

	/**
	 * Enqueue the toggle brick into the block editor, with the theme toggle's
	 * (filterable) attribute + storage key so it flips the same mode. Gated by the
	 * editor `should_load` and the "Dark mode" feature (`theme_toggle_enabled`) —
	 * with dark mode off there's nothing to toggle.
	 *
	 * @return void
	 */
	public static function enqueue_theme_toggle() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'editor' ) ) {
			return;
		}
		if ( ! AdminKit_Settings::get( 'theme_toggle_enabled' ) ) {
			return; // "Dark mode" feature is off — nothing to toggle.
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-gutenberg-theme-toggle',
			'inc/integrations/plugins/gutenberg/js/theme-toggle.js',
			array(),
			'window.AdminKitEditorToggle=' . wp_json_encode( array(
				'attr'  => AdminKit_Theme_Toggle::attribute(),
				'key'   => AdminKit_Theme_Toggle::storage_key(),
				'label' => __( 'Toggle light / dark mode', 'adminkit' ),
			) ) . ';'
		);
	}

	/**
	 * OPT-IN: theme the editor's iframed canvas (content + native blocks) in light
	 * + dark. Gated by the "Gutenberg" feature (editor_content_theme, OFF by
	 * default) so a client's page layout is never altered unless asked.
	 *
	 * The block-editor canvas is a separate <iframe> document that neither the
	 * editor-chrome CSS nor the page's data-adminkit-theme attribute reach. So we
	 * hand canvas-theme.js the (mtime-stamped) URLs of the token files +
	 * wp-components.css (the @wordpress/components theming — placeholders, buttons,
	 * inputs) + canvas.css; it injects them as <link>s into the iframe <head>, tags
	 * the iframe <body> with `adminkit` (so the `body.adminkit …` --wp-* accent
	 * remap + component rules match inside, instead of falling back to WP blue),
	 * and mirrors the theme attribute onto the iframe <html> so the same --ak-*
	 * dark block flips the canvas. Raw stylesheets go straight in — no
	 * block_editor_settings_all transform to fight (which can rewrite :root).
	 *
	 * @return void
	 */
	public static function enqueue_canvas_theme() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'editor' ) ) {
			return;
		}
		if ( ! AdminKit_Settings::get( 'editor_content_theme' ) ) {
			return; // "Gutenberg" canvas theming off — leave the canvas WP-default.
		}
		$styles = array_values( array_filter( array(
			self::asset_url( AdminKit_Assets::WAASKIT_SRC ),              // token primitives (baseline)
			self::asset_url( AdminKit_Assets::TOKENS_SRC ),               // --ak-* (light + dark) + --wp-* accent remap
			self::asset_url( 'assets/css/wp-screens/wp-components.css' ), // @wordpress/components UI (placeholders, buttons, inputs)
			self::asset_url( self::BASE . 'canvas.css' ),                 // native-block content mapping
		) ) );
		AdminKit_Assets::enqueue_script(
			'adminkit-gutenberg-canvas-theme',
			'inc/integrations/plugins/gutenberg/js/canvas-theme.js',
			array(),
			'window.AdminKitCanvas=' . wp_json_encode( array(
				'attr'   => AdminKit_Theme_Toggle::attribute(),
				'styles' => $styles,
			) ) . ';'
		);
	}

	/**
	 * Plugin-root-relative asset path → absolute URL with an mtime cache-bust (so
	 * canvas edits skip the iframe cache). Empty string when the file is missing.
	 *
	 * @param string $src Path relative to ADMINKIT_PATH.
	 * @return string
	 */
	private static function asset_url( $src ) {
		$path = ADMINKIT_PATH . $src;
		if ( ! file_exists( $path ) ) {
			return '';
		}
		return ADMINKIT_URL . $src . '?ver=' . filemtime( $path );
	}
}
