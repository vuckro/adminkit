<?php
/**
 * List-table chrome polish.
 *
 * The status-filter row (`.subsubsub`: All | Active | Inactive …) ships two
 * bits of markup that fight a modern presentation: literal " |" separators as
 * text nodes between the links, and counts wrapped in parentheses, e.g. "(12)".
 * CSS can hide the pipes but can't strip the parentheses, so a small footer
 * script removes both — leaving clean links + numeric counts that
 * wp-core/chrome.css styles into inline pills with round notification badges. It
 * also wraps each list table in a horizontal-scroll container and sizes Quick
 * Edit to the visible width.
 *
 * On users.php we also drop the native "Send password reset" row action: the
 * full password-reset affordance still lives on the user-edit screen
 * (Account Management → Send Reset Link), so removing the hover-row link only
 * trims one extra click while uncluttering the table.
 *
 * Behaviour lives in `assets/js/wp-core/list-table-chrome.js`, loaded as a
 * footer script on admin pages; it is a no-op wherever those elements are absent.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_List_Table_Chrome {

	/**
	 * Wire the hook. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'trim_row_actions' ), 99 );
		// Strip the parenthesised counts ("(24)" → "24") server-side, BEFORE
		// first paint, so the user never sees them flash to the cleaned value
		// when list-table-chrome.js runs in the footer. The JS still strips
		// them as a defensive fallback for plugins that bypass `views_*`.
		add_action( 'current_screen', array( __CLASS__, 'register_views_filter' ) );
	}

	/**
	 * Hook the `views_{screen}` filter for whatever list-table screen we're on,
	 * so it fires when WP_List_Table::views() runs. One filter, every screen.
	 *
	 * @return void
	 */
	public static function register_views_filter() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		add_filter( "views_{$screen->id}", array( __CLASS__, 'strip_views_decorations' ) );
	}

	/**
	 * Strip "(N)" from every `<span class="count">` inside the filtered views
	 * array. WP renders each `.subsubsub` link with a `.count` span carrying
	 * the parenthesised total (e.g. `<span class="count">(24)</span>`); the
	 * regex peels the parens out leaving the bare number, which our CSS pill
	 * styling then renders as a notification badge.
	 *
	 * Locale-safe: matches the markup pattern, not the language. Idempotent —
	 * re-running on already-stripped output is a no-op.
	 *
	 * @param string[]|mixed $views Filtered views (associative or indexed).
	 * @return mixed
	 */
	public static function strip_views_decorations( $views ) {
		if ( ! is_array( $views ) ) {
			return $views;
		}
		$pattern = '/(<span\b[^>]*\bclass\s*=\s*"[^"]*\bcount\b[^"]*"[^>]*>)\s*\(\s*([\d,.\xc2\xa0\s]+?)\s*\)\s*(<\/span>)/i';
		foreach ( $views as $key => $link ) {
			if ( is_string( $link ) ) {
				$views[ $key ] = preg_replace( $pattern, '$1$2$3', $link );
			}
		}
		return $views;
	}

	/**
	 * Drop native row actions we deemed clutter on users.php. Currently:
	 *   - `resetpassword` ("Send password reset") — still available in full on
	 *     the user-edit screen, just removed from the hover row.
	 */
	public static function trim_row_actions( $actions ) {
		unset( $actions['resetpassword'] );
		return $actions;
	}

	/**
	 * Enqueue the footer script that polishes list tables. No-op when AdminKit
	 * isn't styling the admin.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-list-table-chrome',
			'assets/js/wp-core/list-table-chrome.js'
		);
	}
}
