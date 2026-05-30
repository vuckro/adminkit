<?php
/**
 * Hide admin footer — removes the WordPress admin footer bar (the "Thank you for
 * creating with WordPress" text and the version number, e.g. "Version 7.0") on
 * every admin screen, for a cleaner, app-like chrome.
 *
 * WordPress hard-prints `<div id="wpfooter">` in wp-admin/admin-footer.php, so it
 * can't be unhooked in PHP and emptying `admin_footer_text` / `update_footer` only
 * leaves an empty bar. We hide the whole bar with one scoped CSS rule appended to
 * AdminKit's tokens stylesheet via the designated `adminkit/tokens_enqueued` hook —
 * no extra request, no body class, nothing to undo. Reversible: when the toggle is
 * off, init() returns early and the native footer shows untouched.
 *
 * Filter:
 *   adminkit/hide_footer/enabled  (bool)  master on/off
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Footer {

	/**
	 * Register the setting (default ON) + wire the hide when enabled. The setting
	 * is registered unconditionally so the Settings page can discover it while off.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'hide_footer_enabled', array( 'default' => true ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'adminkit/tokens_enqueued', array( __CLASS__, 'hide_css' ) );
	}

	/**
	 * Master switch — registered setting (default ON) through a filter.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/hide_footer/enabled', AdminKit_Settings::get( 'hide_footer_enabled' ) );
	}

	/**
	 * Hide the footer bar via a scoped one-line rule appended to the tokens
	 * stylesheet. Admin context only — the footer is an admin-screen element.
	 *
	 * @param string $context admin | login | frontend | editor
	 * @return void
	 */
	public static function hide_css( $context ) {
		if ( 'admin' !== $context ) {
			return;
		}
		wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, 'body.adminkit #wpfooter{display:none}' );
	}
}
