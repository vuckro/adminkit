<?php
/**
 * WPCode (insert-headers-and-footers) integration — Tier A adapter.
 *
 * WPCode renders its admin from a `--wpcode-*` CSS variable layer on :root, so
 * css/admin.css remaps that layer to AdminKit tokens (the whole UI follows,
 * dark mode included). Scaffolded by the adminkit-adapter-scan skill (--emit), then tuned.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Wpcode extends AdminKit_Integration_Base {

	public static function slug() {
		return 'wpcode';
	}

	/**
	 * WPCode defines WPCODE_VERSION in its bootstrap (ihaf.php).
	 */
	public static function is_active() {
		return defined( 'WPCODE_VERSION' );
	}

	/**
	 * Every WPCode admin page: the top-level menu (slug `wpcode`) and its
	 * sub-pages share the `wpcode` slug fragment in their screen id
	 * (toplevel_page_wpcode, wpcode_page_*), and all carry the
	 * `.wpcode-admin-page` body class the CSS scopes to.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'wpcode' );
	}

	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-wpcode-admin',
			'src'       => 'inc/integrations/wpcode/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
