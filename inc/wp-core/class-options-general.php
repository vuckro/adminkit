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

	/**
	 * Hook the enqueue. Called once from the orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the split-into-blocks script on `options-general.php` only,
	 * with the section titles riding along as an inline bootstrap. Honors
	 * the global `adminkit/should_load` veto.
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
			'identity'     => __( 'Site identity', 'adminkit' ),
			'identityDesc' => __( 'How your site presents itself — name, tagline and addresses.', 'adminkit' ),
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
