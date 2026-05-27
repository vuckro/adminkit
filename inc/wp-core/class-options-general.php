<?php
/**
 * AdminKit — Settings → General sectioning.
 *
 * WP renders options-general.php as one long .form-table that mixes site
 * identity, account/registration and date/time settings. This module enqueues
 * a small footer script that splits those rows into three card-style sections
 * by moving the matching <tr>s into per-section sub-tables (every <input>
 * keeps its name=, so the form still posts to options.php unchanged).
 *
 * Scoped to the `options-general` screen — nothing loads anywhere else.
 *
 * Layout for the card chrome lives in assets/css/wp-screens/options.css; the
 * behavior lives in assets/js/wp-screens/options-general.js.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Options_General {

	/**
	 * Wire the hook. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	/** Marker class added to <html> pre-paint so the un-rebuilt form is hidden
	 *  by options.css until `options-general.js` finishes splitting it into
	 *  tabs and clears the class. Matches the pattern profile-account.php
	 *  uses for the user profile screen. */
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
	 * Tag `<html>` with `ak-options-pending` before the page paints, so the raw
	 * (un-rebuilt) form stays hidden until `options-general.js` finishes the
	 * tab split + clears the class. Includes the same `load` + 3s safety
	 * net `class-profile-account.php` uses so a thrown script can never trap
	 * the form invisible.
	 *
	 * @return void
	 */
	public static function print_prepaint() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-general' !== $screen->id ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		$pending = wp_json_encode( self::PENDING_CLASS );
		?>
<script id="adminkit-options-general-prepaint">
(function () {
	var d = document.documentElement;
	d.classList.add(<?php echo $pending; ?>);
	function reveal() { d.classList.remove(<?php echo $pending; ?>); }
	// Safety net 1 — if the footer script never reveals (thrown error, blocked
	// asset), force-reveal on `load` so content is never trapped invisible.
	window.addEventListener('load', function () {
		var after = function () {
			if (d.classList.contains(<?php echo $pending; ?>)) { reveal(); }
		};
		if (window.requestAnimationFrame) { window.requestAnimationFrame(after); }
		else { window.setTimeout(after, 0); }
	});
	// Safety net 2 — if `load` itself never fires (hung subresource).
	setTimeout(function () {
		if (d.classList.contains(<?php echo $pending; ?>)) { reveal(); }
	}, 3000);
})();
</script>
		<?php
	}

	/**
	 * Enqueue the section-grouping script on options-general.php only, with
	 * localized section titles riding along as an inline bootstrap. Honors the
	 * global `adminkit/should_load` veto.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-general' !== $screen->id ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		$strings = array(
			'identity' => __( 'Site identity', 'adminkit' ),
			'siteIcon' => __( 'Site Icon', 'adminkit' ),
			'account'  => __( 'Account & registration', 'adminkit' ),
			'locale'   => __( 'Language, date & time', 'adminkit' ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-options-general',
			'assets/js/wp-screens/options-general.js',
			array(),
			'window.AdminKitOptionsGeneral=' . wp_json_encode( $strings ) . ';'
		);
	}
}
