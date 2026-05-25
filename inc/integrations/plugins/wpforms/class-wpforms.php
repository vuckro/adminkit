<?php
/**
 * AdminKit_Integration_Wpforms — WPForms (Lite + Pro) admin skin.
 *
 * Tier B: WPForms hardcodes its colours in its own classes, so css/admin.css
 * overrides them by selector, remapping to AdminKit `--ak-*` tokens so the
 * WPForms admin screens follow light + dark. The colour→token mapping was
 * bootstrapped by `dev/adapter-scan.php` (--emit) then hand-tuned.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Wpforms extends AdminKit_Integration_Base {

	public static function slug() {
		return 'wpforms';
	}

	/**
	 * Both WPForms Lite and Pro define WPFORMS_VERSION at boot.
	 */
	public static function is_active() {
		return defined( 'WPFORMS_VERSION' );
	}

	/**
	 * WPForms admin screens: the top-level "WPForms" menu (overview) plus every
	 * `wpforms_page_wpforms-*` sub-page (entries, settings, addons, tools,
	 * payments, analytics, the form builder…). All carry 'wpforms' in the screen
	 * id, and `<body class>` carries `.wpforms-admin-page`.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'wpforms' );
	}

	/**
	 * Tier B version gate (majors only — see the base class). WPForms ships on
	 * the 1.x line (1.10 at time of writing); revisit if it jumps to a 2.x major
	 * that reshuffles these selectors.
	 *
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'WPFORMS_VERSION' ) ? WPFORMS_VERSION : null;
	}

	/**
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '1';
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		if ( ! static::host_within_tested_range() ) {
			return; // past the tested major — fall back to WPForms's native UI.
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-wpforms-admin',
			'src'       => 'inc/integrations/plugins/wpforms/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
