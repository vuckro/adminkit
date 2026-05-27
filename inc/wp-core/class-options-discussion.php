<?php
/**
 * AdminKit — Settings → Discussion two-tab regrouping.
 *
 * WP renders seven `<h2>` settings sections on options-discussion.php (Default
 * post settings, Other comment settings, Email me whenever, Before a comment
 * appears, Comment Moderation, Disallowed Comment Keys, Avatars). This module
 * enqueues a small footer script that routes every section into one of two
 * coherent tabs — Comment settings + Avatars — so the page reads as two
 * focused panels instead of one long stack.
 *
 * Scoped to the `options-discussion` screen only.
 *
 * Behaviour: assets/js/wp-screens/options-discussion.js
 * Card chrome:  assets/css/wp-screens/options.css
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Options_Discussion {

	/** Same anti-FOUC marker as `AdminKit_Core_Options_General::PENDING_CLASS`
	 *  — both pages share one `<html>` class so a single rule in options.css
	 *  hides whichever screen we're on while the JS rebuilds. */
	const PENDING_CLASS = 'ak-options-pending';

	/**
	 * Wire the hooks. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		// Anti-FOUC bootstrap — runs at priority 1 so the class is on <html>
		// before any styled content paints.
		add_action( 'admin_head', array( __CLASS__, 'print_prepaint' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Tag `<html>` with `ak-options-pending` before the page paints, so the
	 * raw (un-rebuilt) form stays hidden until `options-discussion.js`
	 * finishes the two-tab routing + clears the class. Includes the same
	 * safety nets the General page uses.
	 *
	 * @return void
	 */
	public static function print_prepaint() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-discussion' !== $screen->id ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		$pending = wp_json_encode( self::PENDING_CLASS );
		?>
<script id="adminkit-options-discussion-prepaint">
(function () {
	var d = document.documentElement;
	d.classList.add(<?php echo $pending; ?>);
	function reveal() { d.classList.remove(<?php echo $pending; ?>); }
	window.addEventListener('load', function () {
		var after = function () {
			if (d.classList.contains(<?php echo $pending; ?>)) { reveal(); }
		};
		if (window.requestAnimationFrame) { window.requestAnimationFrame(after); }
		else { window.setTimeout(after, 0); }
	});
	setTimeout(function () {
		if (d.classList.contains(<?php echo $pending; ?>)) { reveal(); }
	}, 3000);
})();
</script>
		<?php
	}

	/**
	 * Enqueue the section-grouping script on options-discussion.php only,
	 * with localized tab titles inline. Honors the global `adminkit/should_load`
	 * veto so child themes can disable the regrouping cleanly.
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
			'comments' => __( 'Comment settings', 'adminkit' ),
			'avatars'  => __( 'Avatars', 'adminkit' ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-options-discussion',
			'assets/js/wp-screens/options-discussion.js',
			array(),
			'window.AdminKitOptionsDiscussion=' . wp_json_encode( $strings ) . ';'
		);
	}
}
