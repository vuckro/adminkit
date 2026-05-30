<?php
/**
 * Hide the Help button — removes the contextual "Help" toggle at the top-right of
 * admin screens (the tab next to "Screen Options", `#contextual-help-link-wrap`)
 * for cleaner, app-like chrome.
 *
 * WordPress prints the Help toggle in wp-admin/includes/class-wp-screen.php whenever
 * a screen registers help tabs. Rather than stripping help tabs per-screen in PHP,
 * we hide just the toggle with one scoped CSS rule appended to AdminKit's tokens
 * stylesheet via the designated `adminkit/tokens_enqueued` hook — no extra request,
 * no body class, and "Screen Options" is left untouched. Reversible: when the toggle
 * is off, init() returns early and the native Help button shows.
 *
 * Filter:
 *   adminkit/hide_help_button/enabled  (bool)  master on/off
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Help_Button {

	/**
	 * Register the setting (default ON) + wire the hide when enabled. The setting is
	 * registered unconditionally so the Settings page can discover it while off.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'hide_help_button_enabled', array( 'default' => true ) );

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
		return (bool) apply_filters( 'adminkit/hide_help_button/enabled', AdminKit_Settings::get( 'hide_help_button_enabled' ) );
	}

	/**
	 * Hide the Help toggle via a scoped one-line rule appended to the tokens
	 * stylesheet. Admin context only — the Help button is an admin-screen element.
	 *
	 * @param string $context admin | login | frontend | editor
	 * @return void
	 */
	public static function hide_css( $context ) {
		if ( 'admin' !== $context ) {
			return;
		}
		wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, 'body.adminkit #contextual-help-link-wrap{display:none}' );
	}
}
