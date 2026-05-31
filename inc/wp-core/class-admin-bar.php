<?php
/**
 * Responsive admin bar.
 *
 * adminbar.css turns #wp-toolbar into a single horizontal-scroll row on WP's
 * touch bar (≤782px) so the toolbar items no longer wrap onto a second line that
 * overlaps the page. This class loads the small script that drives the edge
 * "fondu" — hiding the left fade at the scroll start, the right fade at the end,
 * and both when the bar fits without scrolling. It degrades cleanly: with no JS
 * the CSS shows a symmetric fade on both edges.
 *
 * The styling all lives in adminbar.css (registered by AdminKit_Core_Chrome for
 * the `admin` and `frontend` contexts); this class only adds the behaviour. The
 * bar renders in wp-admin AND on the frontend for logged-in users, so the script
 * is enqueued in both contexts — mirroring that CSS registration.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Admin_Bar {

	/**
	 * Wire the enqueue on both the admin and the frontend. Called once from the
	 * plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the responsive-bar script wherever the admin bar is showing. No-op
	 * when the bar is hidden, or when AdminKit isn't styling this context (the
	 * same `should_load` gate Chrome's adminbar.css honours).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$context = is_admin() ? 'admin' : 'frontend';
		if ( ! apply_filters( 'adminkit/should_load', true, $context ) ) {
			return;
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-admin-bar',
			'assets/js/wp-core/admin-bar.js'
		);
	}
}
