<?php
/**
 * Gutenberg integration — block editor restyle (light only).
 *
 * Registers editor.css in the `editor` context, which AdminKit_Assets dispatches
 * via `enqueue_block_editor_assets` (block / site / widgets / navigation editors).
 * The CSS only enters editor surfaces; the admin context still loads its chrome
 * on top so the WP-admin wrapping around the editor is themed too.
 *
 * The editor has no dark theme — WordPress ships none, and half-theming its many
 * hardcoded surfaces looks broken — so editor.css keeps it LIGHT even when
 * AdminKit dark mode is on globally (it re-pins the flipped tokens to their light
 * values on the editor shell).
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
	 * Register editor.css in the `editor` context (dispatched on
	 * `enqueue_block_editor_assets`).
	 *
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-gutenberg-editor',
			'src'     => self::BASE . 'editor.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'editor',
		) );
	}
}
