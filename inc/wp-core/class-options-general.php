<?php
/**
 * AdminKit — options-general.php: split into themed blocks.
 *
 * WP renders every general setting (Site Title, Tagline, Admin Email,
 * Membership, Language, Date/time, …) into ONE long `.form-table`. That's
 * fine to scan top-to-bottom but the reader has no visual cue that
 * "Membership" is conceptually separate from "Site Title". This module
 * enqueues a small footer script that moves the `<tr>`s into three
 * themed sub-cards (Site identity / Account & registration / Locale)
 * for readability. Form submission is unchanged — every input keeps its
 * `name=` so options.php receives the same POST.
 *
 * The behaviour lives in `assets/js/wp-screens/options-general.js`; the
 * card chrome reuses the existing `.form-table` polish from
 * `assets/css/wp-screens/options.css`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Options_General {

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
	 * Whether the current request should receive the tabbed/carded enhancement.
	 *
	 * Shared by the pre-paint bootstrap and footer script so they never drift.
	 *
	 * @return bool
	 */
	private static function should_enhance() {
		return AdminKit_Screen::is_one_of( array( 'options-general' ) )
			&& (bool) apply_filters( 'adminkit/should_load', true, 'admin' );
	}

	/**
	 * Print the anti-FOUC pre-paint bootstrap.
	 *
	 * The builder runs in the footer, after the first possible paint. Add a
	 * short-lived marker to <html> from the head so CSS can hide the raw one-table
	 * layout until options-general.js has grouped it into cards.
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
<script id="adminkit-options-general-prepaint">
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
	 * Enqueue the split-into-blocks script on `options-general.php` only,
	 * with the section titles riding along as an inline bootstrap.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_enhance() ) {
			return;
		}

		$strings = array(
			// Top-level tabs — General groups everything that's not locale.
			'tabGeneral'   => __( 'General', 'adminkit' ),
			// Block titles + one-line lede shown above each card.
			'identity'     => __( 'Site identity', 'adminkit' ),
			'identityDesc' => __( 'How your site presents itself — name, tagline and addresses.', 'adminkit' ),
			'siteIcon'     => __( 'Site Icon', 'adminkit' ),
			'siteIconDesc' => __( 'Shown in browser tabs, bookmark lists and the app icon when visitors save your site to a phone home screen.', 'adminkit' ),
			'account'      => __( 'Account & registration', 'adminkit' ),
			'accountDesc'  => __( 'Admin email, who can sign up, and the default role assigned to new users.', 'adminkit' ),
			'locale'       => __( 'Language, date & time', 'adminkit' ),
			'localeDesc'   => __( 'Site language plus the timezone and formats used wherever dates appear.', 'adminkit' ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-options-general',
			'assets/js/wp-screens/options-general.js',
			array(),
			'window.AdminKitOptionsGeneral=' . wp_json_encode( $strings ) . ';'
		);
	}
}
