<?php
/**
 * AdminKit_Integration_Elementor — Elementor (Free + Pro) admin skin.
 *
 * Tier A: Elementor drives its admin UI — both the classic pages (Settings,
 * Tools, Role Manager, System Info…) and the React "app" (the Home dashboard) —
 * almost entirely from its own CSS variables (--e-*, --app-*, --body-*, the
 * --gray-* ramp, status + brand). css/admin.css redeclares those variables as
 * AdminKit --ak-* tokens, scoped under body.adminkit (a nearer ancestor than
 * Elementor's :root, so it wins) and under .eps-theme-dark (Elementor's own dark
 * class). The --ak-* tokens already flip with AdminKit's light/dark, so Elementor
 * follows for free — no !important, no flash. The colour→token map was
 * bootstrapped by dev/adapter-scan.php then hand-tuned.
 *
 * The live builder/editor canvas is intentionally NOT themed (it previews the
 * front-end): owns_screen only matches Elementor's wp-admin screens (which carry
 * 'elementor' in the screen id) — the editor runs on a 'post' screen, excluded.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Elementor extends AdminKit_Integration_Base {

	public static function slug() {
		return 'elementor';
	}

	/**
	 * Elementor Free defines ELEMENTOR_VERSION at boot (Pro requires Free).
	 */
	public static function is_active() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Elementor admin screens: the top-level "Elementor" menu (the React Home
	 * app) plus every elementor_page_* sub-page (settings, tools, role manager,
	 * system info, the Theme Builder / Templates lists…). All carry 'elementor'
	 * in the screen id. The live editor runs on a 'post' screen, so it's excluded.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'elementor' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
	}

	/**
	 * Tier A version gate (majors only — see the base class). Pinned to the
	 * tested major; revisit if Elementor reshuffles its variable names.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '4';
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		if ( ! static::host_within_tested_range() ) {
			return; // past the tested major — fall back to Elementor's native UI.
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-elementor-admin',
			'src'       => 'inc/integrations/plugins/elementor/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
