<?php
/**
 * Notification Center — a toolbar bell that collects admin notices into a right-side
 * drawer, decluttering wp-admin while keeping success confirmations inline.
 *
 * Two layers work together:
 *   • SERVER (here): output-buffer the whole notice-hook span (admin_notices →
 *     all_admin_notices) and re-emit it wrapped in a layout-transparent marker
 *     (#adminkit-nc-origin). Re-emitting into the NORMAL output stream keeps the
 *     notices fully LIVE — their scripts run, the native dismiss still binds — while
 *     handing the client the COMPLETE notice set, incl. custom banners that match no
 *     CSS selector.
 *   • CLIENT (assets/js/wp-core/notification-center.js): categorizes each notice and
 *     MOVES the groupable ones into the drawer with `appendChild` (preserving the
 *     live node). It tags moved notices `.inline` so WP core's common.js notice
 *     relocation doesn't yank them back onto the page.
 *
 * Scope: every admin notice moves to the panel EXCEPT — success confirmations
 * (`.notice-success` / legacy `.updated`, incl. settings_errors() "Settings saved"),
 * which stay INLINE where the user just triggered the action; WP "render in place"
 * `.inline` notices; and notices buried inside page UI (metabox / form / list table
 * / the settings app), which are left untouched. Net: info / warnings / errors /
 * nags collected in the bell, success + contextual feedback kept in place.
 *
 * Reversible: when the feature toggle is OFF, init() returns early and nothing is
 * wired — no bell, no script, no style — so every notice renders inline (vanilla).
 *
 * Filters:
 *   adminkit/notifications/enabled   (bool)       master on/off
 *   adminkit/notifications/js_allow  (string[])   CSS selectors forced INTO the bell
 *   adminkit/notifications/js_deny   (string[])   CSS selectors forced to stay inline
 * Plus per-notice opt-in attributes for plugin authors:
 *   data-ak-nc-group  → always group this notice
 *   data-ak-nc-keep   → always keep this notice inline
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Notification_Center {

	const NODE_ID       = 'ak-notifications';
	const PENDING_CLASS = 'ak-nc-pending'; // hides inline notices until the JS sorts them.
	const ACTIVE_CLASS  = 'ak-nc-active';  // added by the JS only → gates the bell's visibility.
	const ORIGIN_ID     = 'adminkit-nc-origin'; // wraps the captured notice-hook output.

	/** @var bool Whether the admin-notice output buffer is currently open. */
	private static $buffering = false;

	/**
	 * Register the setting + wire hooks. The setting is registered unconditionally
	 * so the Settings page can show the toggle while the feature is off.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'notification_center_enabled', array( 'default' => true ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'admin_bar_menu', array( __CLASS__, 'register_node' ), 997 );
		add_action( 'admin_head', array( __CLASS__, 'print_prepaint' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// Capture EVERY notice-hook output (whatever its markup) by buffering from the
		// first notice hook to the last, then re-emitting it wrapped in a marker. The
		// re-emit goes into the normal output stream, so the notices stay fully live —
		// their scripts run, the native dismiss still binds — the wrapper just lets the
		// client collect them all, including custom banners that match no CSS selector.
		add_action( 'admin_notices', array( __CLASS__, 'capture_start' ), PHP_INT_MIN );
		add_action( 'network_admin_notices', array( __CLASS__, 'capture_start' ), PHP_INT_MIN );
		add_action( 'user_admin_notices', array( __CLASS__, 'capture_start' ), PHP_INT_MIN );
		add_action( 'all_admin_notices', array( __CLASS__, 'capture_end' ), PHP_INT_MAX );

		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-notification-center',
			'src'     => 'assets/css/wp-core/notification-center.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'admin',
		) );
	}

	/**
	 * Master switch — registered setting (default ON) through a filter.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/notifications/enabled', AdminKit_Settings::get( 'notification_center_enabled' ) );
	}

	/**
	 * Only enhance standard admin-notice screens. The block editor renders its
	 * notices through a separate React store (`.components-notice-list`), not the
	 * admin_notices DOM, so we step aside there; the Customizer has no notices.
	 *
	 * @return bool
	 */
	private static function should_enhance() {
		$screen = AdminKit_Screen::get();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return false;
		}
		return (bool) apply_filters( 'adminkit/should_load', true, 'admin' );
	}

	/**
	 * Register the admin-bar bell node — sits just left of the view-site (998) and
	 * theme-toggle (999) nodes in the `top-secondary` cluster. The count badge span
	 * is rendered empty + hidden; the JS fills and unhides it. The whole node stays
	 * hidden via CSS until the JS adds ACTIVE_CLASS, so a no-JS visitor never sees an
	 * inert bell. Bell glyph: Heroicons (MIT), solid to match the other toolbar icons.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function register_node( $bar ) {
		// Admin only — `admin_bar_menu` also fires on the front-end, but there are no
		// admin notices to collect there, so the bell would just be a dead icon.
		if ( ! is_admin() || ! self::is_enabled() ) {
			return;
		}
		$bell = '<svg class="ak-theme-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.25 9a6.75 6.75 0 0 1 13.5 0v.75c0 2.123.8 4.057 2.118 5.52a.75.75 0 0 1-.297 1.206c-1.544.57-3.16.99-4.831 1.243a3.75 3.75 0 1 1-7.48 0 24.585 24.585 0 0 1-4.831-1.244.75.75 0 0 1-.298-1.205A8.217 8.217 0 0 0 5.25 9.75V9Zm4.502 8.9a2.25 2.25 0 1 0 4.496 0 25.057 25.057 0 0 1-4.496 0Z" clip-rule="evenodd" /></svg>';

		$bar->add_node( array(
			'id'     => self::NODE_ID,
			'parent' => 'top-secondary',
			'title'  => '<span class="ak-nc-bell">' . $bell . '<span class="ak-nc-badge" hidden></span></span>',
			'href'   => '#',
			'meta'   => array(
				'class' => 'ak-nc-toggle',
				'title' => __( 'Notifications', 'adminkit' ),
			),
		) );
	}

	/**
	 * Print the anti-flash bootstrap — add a marker to <html> from the head so the
	 * inline notices stay hidden until the footer JS has moved the promos out of the
	 * page (no flash of nags before relocation). Triple safety-reveal (load → rAF,
	 * plus a 3s timeout) so notices are NEVER stranded if the footer JS fails to
	 * load. Mirrors AdminKit_Core_Options_General::print_prepaint().
	 *
	 * @return void
	 */
	public static function print_prepaint() {
		if ( ! self::should_enhance() ) {
			return;
		}
		$pending = self::PENDING_CLASS;
		?>
<script id="adminkit-notifications-prepaint">
(function () {
	var d = document.documentElement;
	d.classList.add(<?php echo wp_json_encode( $pending ); ?>);
	function reveal() { d.classList.remove(<?php echo wp_json_encode( $pending ); ?>); }
	window.addEventListener('load', function () {
		if (window.requestAnimationFrame) { requestAnimationFrame(reveal); } else { setTimeout(reveal, 0); }
	});
	setTimeout(reveal, 3000);
})();
</script>
		<?php
	}

	/**
	 * Enqueue the footer script with its i18n + config bootstrap. Skipped where
	 * there are no standard admin notices to sort (block editor, Customizer).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_enhance() ) {
			return;
		}

		$config = array(
			'title'        => __( 'Notifications', 'adminkit' ),
			'subtitle'     => __( 'Grouped notices', 'adminkit' ),
			'empty'        => __( 'You’re all caught up.', 'adminkit' ),
			'emptyDesc'    => __( 'Notices from plugins and WordPress collect here, out of your way.', 'adminkit' ),
			'close'        => __( 'Close', 'adminkit' ),
			'openLabel'    => __( 'Notifications', 'adminkit' ),
			'nodeId'       => self::NODE_ID,
			'pendingClass' => self::PENDING_CLASS,
			'activeClass'  => self::ACTIVE_CLASS,
			'allow'        => array_values( (array) apply_filters( 'adminkit/notifications/js_allow', array() ) ),
			'deny'         => array_values( (array) apply_filters( 'adminkit/notifications/js_deny', array() ) ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-notification-center',
			'assets/js/wp-core/notification-center.js',
			array(),
			'window.AdminKitNotificationCenter=' . wp_json_encode( $config ) . ';'
		);
	}

	/**
	 * Open an output buffer at the FIRST notice hook (admin / network / user) so
	 * everything every notice callback echoes is captured. Guarded to open once.
	 *
	 * @return void
	 */
	public static function capture_start() {
		if ( self::$buffering || ! self::should_enhance() ) {
			return;
		}
		ob_start();
		self::$buffering = true;
	}

	/**
	 * Close the buffer at the LAST notice hook (`all_admin_notices`) and re-emit the
	 * captured markup wrapped in a layout-transparent marker. Re-emitting into the
	 * normal output stream keeps the notices fully live (their scripts run, the native
	 * dismiss still binds); the wrapper just hands the client the complete notice set.
	 *
	 * @return void
	 */
	public static function capture_end() {
		if ( ! self::$buffering ) {
			return;
		}
		self::$buffering = false;
		$html = ob_get_clean();
		if ( '' === trim( (string) $html ) ) {
			return;
		}
		// $html is the verbatim output of the notice hooks — already escaped by whatever
		// emitted it; we only wrap it, adding no new data of our own.
		echo '<div id="' . esc_attr( self::ORIGIN_ID ) . '" class="ak-nc-origin">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
