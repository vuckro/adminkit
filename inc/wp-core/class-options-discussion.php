<?php
/**
 * AdminKit — options-discussion.php: 2-tab split (Avatars + Comment settings).
 *
 * WP renders the page as ONE giant `.form-table` of comment-related rows
 * (Default post settings, Other comment settings, Pagination, Email-me,
 * Before-comment-appears, Comment Moderation, Disallowed Keys) followed by
 * a single `<h2>Avatars</h2>` and a second `.form-table` of avatar settings.
 * That's a lot to scan vertically — split into two tabs:
 *
 *   • Avatars (default — the focused, single-purpose tab)
 *   • Comment settings (the long multi-section tab)
 *
 * Routing is by INPUT NAMES (locale-proof), not heading text. Form
 * submission is unchanged — every field stays in `<form action="options.php">`
 * so submit posts every row on every tab.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Options_Discussion {

	/**
	 * Hook the enqueue. Called once from the orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the tab-split script on `options-discussion.php` only, with
	 * the tab titles riding along as an inline bootstrap. Honors the global
	 * `adminkit/should_load` veto.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-discussion' !== $screen->id ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		$strings = array(
			'avatars'  => __( 'Avatars', 'adminkit' ),
			'comments' => __( 'Comment settings', 'adminkit' ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-options-discussion',
			'assets/js/wp-screens/options-discussion.js',
			array(),
			'window.AdminKitOptionsDiscussion=' . wp_json_encode( $strings ) . ';'
		);
	}
}
