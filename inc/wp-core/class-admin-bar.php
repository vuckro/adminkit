<?php
/**
 * Responsive admin bar.
 *
 * On wp-admin touch widths, secondary native toolbar nodes move into an accessible
 * disclosure. Existing nodes/listeners and submenus are preserved, and desktop
 * restores their exact positions. Without JavaScript, the CSS scroll fallback
 * keeps every original action reachable. Public-site bars retain scrolling.
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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 10000 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 10000 );
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
		if ( ! wp_style_is( 'adminkit-adminbar', 'enqueued' ) ) {
			return;
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-admin-bar',
			'assets/js/wp-core/admin-bar.js'
		);
		$icons = array();
		foreach ( array( 'wp-logo' => 'info', 'command-palette' => 'search', 'comments' => 'message-square', 'new-content' => 'plus', 'query-monitor' => 'clock', 'ak-view-site' => 'external-link', 'ak-theme-toggle' => 'sun' ) as $node => $icon ) {
			$icons[ 'wp-admin-bar-' . $node ] = AdminKit_Icons::display_svg( $icon );
		}
		wp_localize_script( 'adminkit-admin-bar', 'AdminKitMobileBar', array(
			'more' => __( 'More actions', 'adminkit' ),
			'admin' => is_admin(),
			'queryMonitor' => 'Query Monitor',
			'icons' => $icons,
			'fallbackIcon' => AdminKit_Icons::display_svg( 'chevron-right' ),
		) );
	}
}
