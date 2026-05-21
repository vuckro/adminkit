<?php
/**
 * Bricks integration — optional adapter.
 *
 * AdminKit doesn't depend on Bricks. This file is the bridge that
 * makes both systems play nicely when the Bricks theme is active:
 *
 *  - **Token inheritance.** Bricks writes its global design variables
 *    to /uploads/bricks/css/style-manager.min.css whenever the user
 *    saves a token in the builder. We enqueue that file and pin it as
 *    a dep of `adminkit-tokens`, so a green changed to red in the
 *    Bricks builder flows through to every wp-admin button.
 *
 *  - **Builder bypass.** When the user is inside the Bricks Builder
 *    (?bricks=run, builder iframe, etc.) we skip every restyle so the
 *    builder UI stays pristine.
 *
 * Theme-toggle sync with Bricks is intentionally **not** done here.
 * Bricks's frontend JS owns `data-brx-theme` + `brx_mode` and a
 * MutationObserver bridge against it caused a feedback loop that
 * broke frontend interactivity. AdminKit's toggle uses its own
 * `data-adminkit-theme` + `adminkit-theme` so the two systems coexist
 * without interfering. Users who want sync can build their own bridge
 * via the `adminkit/theme_attribute` / `adminkit/theme_storage_key`
 * filters.
 *
 * Removing this folder removes Bricks support entirely; nothing else
 * in the plugin references Bricks.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Bricks extends AdminKit_Integration_Base {

	const TOKENS_HANDLE = 'adminkit-bricks-tokens';
	const TOKENS_REL    = '/bricks/css/style-manager.min.css';

	/**
	 * @return string
	 */
	public static function slug() {
		return 'bricks';
	}

	/**
	 * Whether the Bricks theme is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'BRICKS_VERSION' );
	}

	/**
	 * Whether the current request is rendering the Bricks Builder UI.
	 *
	 * @return bool
	 */
	public static function is_builder() {
		if ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] ) {
			return true;
		}
		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			return true;
		}
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			return true;
		}
		return false;
	}

	/**
	 * Wire the two AdminKit filters that integrate Bricks. No assets
	 * of our own; we piggyback on Bricks's generated stylesheet.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/should_load', array( __CLASS__, 'bypass_builder' ), 10, 2 );
		add_filter( 'adminkit/extra_tokens_handle', array( __CLASS__, 'provide_tokens' ), 10, 2 );
	}

	/**
	 * Bail out of every admin context that's rendering the Bricks
	 * builder. Login / frontend / non-builder admin pages are untouched.
	 *
	 * @param bool   $should_load
	 * @param string $context
	 * @return bool
	 */
	public static function bypass_builder( $should_load, $context ) {
		if ( 'admin' === $context && self::is_builder() ) {
			return false;
		}
		return $should_load;
	}

	/**
	 * Enqueue Bricks' generated tokens stylesheet and return its handle
	 * so AdminKit pins it as a dep of its own tokens.
	 *
	 * @param string|null $handle
	 * @param string      $context
	 * @return string|null
	 */
	public static function provide_tokens( $handle, $context ) {
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . self::TOKENS_REL;
		if ( ! file_exists( $path ) ) {
			return $handle;
		}
		wp_enqueue_style(
			self::TOKENS_HANDLE,
			$upload['baseurl'] . self::TOKENS_REL,
			array(),
			(string) filemtime( $path )
		);
		return self::TOKENS_HANDLE;
	}
}
