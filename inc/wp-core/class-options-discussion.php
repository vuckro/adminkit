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

	const PENDING_CLASS = 'ak-options-pending';
	const READY_CLASS   = 'ak-options-ready';

	/**
	 * Hook the enqueue. Called once from the orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'print_prepaint' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the current request should receive the tabbed enhancement.
	 *
	 * Shared by the pre-paint bootstrap and footer script so they never drift.
	 *
	 * @return bool
	 */
	private static function should_enhance() {
		return AdminKit_Screen::is_one_of( array( 'options-discussion' ) )
			&& (bool) apply_filters( 'adminkit/should_load', true, 'admin' );
	}

	/**
	 * Print the anti-FOUC pre-paint bootstrap.
	 *
	 * The tab splitter runs in the footer, after the first possible paint. Add a
	 * short-lived marker to <html> from the head so CSS can hide the raw stacked
	 * form until options-discussion.js has tagged and activated its tabs.
	 *
	 * @return void
	 */
	public static function print_prepaint() {
		if ( ! self::should_enhance() ) {
			return;
		}
		$pending = self::PENDING_CLASS;
		$ready   = self::READY_CLASS;
		?>
<script id="adminkit-options-discussion-prepaint">
(function () {
	var d = document.documentElement;
	d.classList.add(<?php echo wp_json_encode( $pending ); ?>);
	function reveal() {
		d.classList.remove(<?php echo wp_json_encode( $pending ); ?>);
		d.classList.add(<?php echo wp_json_encode( $ready ); ?>);
	}
	window.addEventListener('load', function () {
		var after = function () {
			if (d.classList.contains(<?php echo wp_json_encode( $pending ); ?>)) { reveal(); }
		};
		if (window.requestAnimationFrame) { window.requestAnimationFrame(after); }
		else { window.setTimeout(after, 0); }
	});
	setTimeout(function () {
		if (d.classList.contains(<?php echo wp_json_encode( $pending ); ?>)) { reveal(); }
	}, 3000);
})();
</script>
		<?php
	}

	/**
	 * Enqueue the tab-split script on `options-discussion.php` only, with
	 * the tab titles riding along as an inline bootstrap.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_enhance() ) {
			return;
		}

		$strings = array(
			'avatars'       => __( 'Avatars', 'adminkit' ),
			'comments'      => __( 'Comment settings', 'adminkit' ),
			// Synthesized heading + lede inserted before the Comments form-table
			// (WP renders that table without any preceding heading, so the tab
			// lands on an unexplained wall of fields otherwise).
			'commentsTitle' => __( 'Comment settings', 'adminkit' ),
			'commentsDesc'  => __( 'How comments work on your site — who can post, what they see when they do, when they get moderated, and the email you receive about them.', 'adminkit' ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-options-discussion',
			'assets/js/wp-screens/options-discussion.js',
			array(),
			'window.AdminKitOptionsDiscussion=' . wp_json_encode( $strings ) . ';'
		);
	}
}
