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
 * Files:
 *   editor.css         — header / sidebar / publish button / tabs / snackbar
 *   dark-mode-map.css  — placeholder for the @wordpress/components +
 *                        edit-post + edit-site + editor token mapping.
 *                        Contains no rules yet; filled when the Gutenberg
 *                        dark-mode work starts.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Gutenberg extends AdminKit_Integration_Base {

	const BASE = 'inc/integrations/gutenberg/css/';

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
}
