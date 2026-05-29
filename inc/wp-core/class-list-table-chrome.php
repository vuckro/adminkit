<?php
/**
 * List-table chrome polish.
 *
 * The status-filter row (`.subsubsub`: All | Active | Inactive …) ships counts
 * wrapped in parentheses, e.g. "(12)". We strip those server-side before first
 * paint so chrome.css can style clean numeric badges. CSS hides WordPress's
 * literal pipe separators. The footer script only handles table scrolling,
 * Quick Edit sizing, and fallback count cleanup for plugin-owned strips.
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
		// edit-comments.php returns its status links through `comment_status_links`
		// (applied inside WP_Comments_List_Table::get_views()), which the generic
		// `views_{screen}` hook above doesn't catch — so the counts flashed there.
		// Strip on this filter too; strip_views_decorations is idempotent.
		add_filter( 'comment_status_links', array( __CLASS__, 'strip_views_decorations' ) );
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
	 * Strip the "( )" wrapper from every `<span class="count">` inside the
	 * filtered views array, leaving whatever is inside. WP wraps the count two
	 * different ways: plain digits on posts/pages/users —
	 * `<span class="count">(24)</span>` — and a NESTED count span on comments —
	 * `<span class="count">(<span class="approved-count">5</span>)</span>`. The
	 * regex peels only the parens (inner content is kept verbatim), so both
	 * shapes are cleaned and our CSS renders the result as a notification badge.
	 *
	 * Locale-safe: matches the markup pattern, not the language. Idempotent —
	 * re-running on already-stripped output is a no-op (no parens left to match).
	 *
	 * @param string[]|mixed $views Filtered views (associative or indexed).
	 * @return mixed
	 */
	public static function strip_views_decorations( $views ) {
		if ( ! is_array( $views ) ) {
			return $views;
		}
		$pattern = '/(<span\b[^>]*\bclass\s*=\s*"[^"]*\bcount\b[^"]*"[^>]*>)\s*\(\s*(.*?)\s*\)\s*(<\/span>)/is';
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
