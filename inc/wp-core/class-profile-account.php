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
	 * Marker class added to <html> while the tab layout is being built, so the
	 * raw (untabbed) profile form is hidden until profile-account.js reveals it.
	 *
	 * @var string
	 */
	const PENDING_CLASS = 'ak-account-pending';

	/**
	 * Marker class swapped in once the build finishes (or a safety fallback
	 * fires) — flips the form back to visible.
	 *
	 * @var string
	 */
	const READY_CLASS = 'ak-account-ready';

	/**
	 * Wire the hooks. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		// Pre-paint, priority 1 like the theme bootstrap, so the marker is on
		// <html> before first paint and profile.css can hide the raw form before
		// it ever shows. JS sets it (see print_prepaint) — never server-side — so
		// a no-JS / failed-JS visitor never enters the hidden state.
		add_action( 'admin_head', array( __CLASS__, 'print_prepaint' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether AdminKit should enhance the current request: an account screen AND
	 * the plugin's own `should_load` gate. Shared by the pre-paint bootstrap and
	 * the script enqueue so they switch on/off together.
	 *
	 * @return bool
	 */
	private static function should_enhance() {
		return AdminKit_Screen::is_one_of( self::SCREENS )
			&& (bool) apply_filters( 'adminkit/should_load', true, 'admin' );
	}

	/**
	 * Print the anti-FOUC pre-paint bootstrap (inline, in <head>).
	 *
	 * profile-account.js runs in the footer — after first paint — so without this
	 * the raw WordPress profile form (every section stacked, the avatar field,
	 * the full-length tables) flashes for a moment before snapping into the tabbed
	 * layout. This synchronously tags <html> with PENDING_CLASS so profile.css
	 * can hide the form *before* it paints; the footer script swaps in READY_CLASS
	 * at the end of its build to reveal it.
	 *
	 * Inline-in-<head> is the established anti-FOUC exception (see
	 * class-theme-toggle.php). The marker is added by JS, so:
	 *   - JS disabled / blocked → the class is never set → the hide rule never
	 *     matches → the form shows normally (just unstyled-into-tabs).
	 *   - JS enabled but profile-account.js throws mid-build → a `load` event
	 *     (and a timed) safety reveals the form regardless, so it can NEVER stay
	 *     permanently hidden.
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
<script id="adminkit-account-prepaint">
(function () {
	var d = document.documentElement;
	d.classList.add(<?php echo wp_json_encode( $pending ); ?>);
	// Safety net: if the footer builder never reveals the form (script error,
	// blocked asset, …), force it visible so content is never trapped hidden.
	function reveal() {
		d.classList.remove(<?php echo wp_json_encode( $pending ); ?>);
		d.classList.add(<?php echo wp_json_encode( $ready ); ?>);
	}
	window.addEventListener('load', function () {
		// One frame after load: profile-account.js (also footer) has run by now in
		// the normal case and already revealed. This only catches the failure case.
		var after = function () {
			if (d.classList.contains(<?php echo wp_json_encode( $pending ); ?>)) { reveal(); }
		};
		if (window.requestAnimationFrame) { window.requestAnimationFrame(after); }
		else { window.setTimeout(after, 0); }
	});
	// Absolute backstop in case `load` never fires (e.g. a hung subresource).
	setTimeout(function () {
		if (d.classList.contains(<?php echo wp_json_encode( $pending ); ?>)) { reveal(); }
	}, 3000);
})();
</script>
		<?php
	}

	/**
	 * Enqueue the tab-builder script on the user profile / edit / new screens,
	 * with the localized strings it matches headings against.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_enhance() ) {
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
			// Anti-FOUC marker classes (set on <html> pre-paint by print_prepaint);
			// the builder swaps pending → ready to reveal the form. Shared from PHP
			// so both sides use the same identifiers.
			'pendingClass'    => self::PENDING_CLASS,
			'readyClass'      => self::READY_CLASS,
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-profile-account',
			'assets/js/wp-core/profile-account.js',
			array(),
			'window.AdminKitProfileAccount=' . wp_json_encode( $strings ) . ';'
		);
	}
}
