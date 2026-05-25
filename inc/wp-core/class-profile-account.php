<?php
/**
 * Account screen — horizontal tabs, one section at a time.
 *
 * WordPress renders profile.php / user-edit.php / user-new.php as one long
 * form: a run of `<h2>` headings (Personal Options, Name, Contact Info,
 * About, Account Management, Application Passwords, plus anything plugins
 * inject — WooCommerce billing/shipping, ACF, …), each followed by a
 * `.form-table`. Core exposes no per-section wrapper hook, so the regrouping
 * is done client-side.
 *
 * A script rebuilds the screen as a tab strip + one visible panel. Two curated
 * tabs (Informations — the day-to-day identity, e-mail, role and new-password
 * fields; Réglages — everything else) are filled by MOVING (never cloning) the
 * matching native `<tr>`s into it; every `<input>` keeps its `name`, so the form
 * posts exactly as WP expects — hidden panels still submit. Any section a plugin
 * adds that we don't map is swept into its own tab, with an icon picked from the
 * fields it contains (billing / shipping / ACF / generic), so third-party data
 * stays reachable. Layout lives in assets/css/wp-screens/profile.css: fields
 * render label-above in a two-column grid, with a few pairs (first/last name,
 * nickname/display name) sitting side by side.
 *
 * Matching is by WP's own localized heading strings + the rows' stable
 * `.user-*-wrap` classes (passed from PHP) so it survives translation; plugin
 * icons key off the non-localized field `name`s. Flat + motionless: tab switches
 * are an instant show/hide, nothing animates. Behaviour lives in
 * assets/js/wp-core/profile-account.js, loaded as a footer script only on the
 * account screens; the localized strings ride along as an inline bootstrap.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Profile_Account {

	/**
	 * Screen ids that carry the profile form.
	 *
	 * @var string[]
	 */
	const SCREENS = array( 'profile', 'user-edit', 'user-new' );

	/**
	 * Wire the hooks. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the tab-builder script on the user profile / edit / new screens,
	 * with the localized strings it matches headings against.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! AdminKit_Screen::is_one_of( self::SCREENS ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		// `sections` use WP core's default text domain so the JS matches each
		// heading by its *rendered* text — locale-proof. `cards` (and the labels
		// below) are AdminKit's own UI copy: English source strings in the
		// `adminkit` domain. Keep the source English — translations live in
		// languages/ (fr_FR maps "Information" → "Informations", etc.). A French
		// source string would leak to every untranslated locale.
		$strings = array(
			'sections' => array(
				'name'          => __( 'Name' ),
				'contact'       => __( 'Contact Info' ),
				'personal'      => __( 'Personal Options' ),
				'account'       => __( 'Account Management' ),
				'app_passwords' => __( 'Application Passwords' ),
				'about'         => array( __( 'About Yourself' ), __( 'About the user' ) ),
				'capabilities'  => __( 'Additional Capabilities' ),
			),
			'cards'    => array(
				'info'     => array( 'label' => __( 'Information', 'adminkit' ), 'desc' => __( 'Identity, contact and password.', 'adminkit' ) ),
				'settings' => array( 'label' => __( 'Settings', 'adminkit' ), 'desc' => __( 'Roles, language and preferences.', 'adminkit' ) ),
			),
			'addresses'       => __( 'Addresses', 'adminkit' ),
			'more'            => __( 'Other settings', 'adminkit' ),
			'nav'             => __( 'Account sections', 'adminkit' ),
			'username_locked' => __( 'This username cannot be changed.', 'adminkit' ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-profile-account',
			'assets/js/wp-core/profile-account.js',
			array(),
			'window.AdminKitProfileAccount=' . wp_json_encode( $strings ) . ';'
		);
	}
}
