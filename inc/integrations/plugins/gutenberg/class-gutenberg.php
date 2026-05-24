<?php
/**
 * Gutenberg integration — block editor restyle.
 *
 * Registers editor CSS in the `editor` context, which AdminKit_Assets
 * dispatches via `enqueue_block_editor_assets`. The CSS only enters
 * editor surfaces (post / page / site editor / widgets editor /
 * navigation editor). The admin context still loads its chrome on
 * top so the WP-admin wrapping around the editor is themed too.
 *
 * Also injects the light/dark toggle into the editor header — the admin-bar
 * toggle is hidden in the fullscreen editor — reusing the theme toggle's own
 * attribute + storage key (so the choice is shared) and its enable switch.
 *
 * Files:
 *   editor.css         — header / sidebar / publish button / tabs / snackbar
 *   js/theme-toggle.js — sun/moon toggle injected into the editor header
 *   dark-mode-map.css  — @wordpress/components + edit-post + editor +
 *                        block-editor token bridge. Patches the Gutenberg
 *                        surfaces that hardcode #fff / #1e1e1e / #ddd
 *                        (panels, document bar, popovers, modals,
 *                        inserter, list view, form controls, block
 *                        toolbar) so the whole chrome flips with the
 *                        data-adminkit-theme="dark" toggle. Canvas
 *                        (.editor-styles-wrapper) is left alone.
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
	 * Gutenberg ships with WP core since 5.0 — always available on
	 * supported sites.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return true;
	}

	/**
	 * Register editor.css + dark-mode-map.css in the `editor` context.
	 * AdminKit_Assets dispatches this context on
	 * `enqueue_block_editor_assets`, which fires for block / site /
	 * widgets / navigation editors.
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
	}

	/**
	 * Enqueue the toggle brick into the block editor, with the theme toggle's
	 * (filterable) attribute + storage key so it flips the same mode. Gated by
	 * the `theme_toggle_enabled` feature switch and the editor `should_load`.
	 *
	 * @return void
	 */
	public static function enqueue_theme_toggle() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'editor' ) ) {
			return;
		}
		if ( ! AdminKit_Settings::get( 'theme_toggle_enabled' ) ) {
			return;
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
}
