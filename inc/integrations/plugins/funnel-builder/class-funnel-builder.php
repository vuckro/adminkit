<?php
/** FunnelKit Builder — scoped Tier B skin, verified against host 3.x. */
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 2 ) . '/trait-funnelkit-integration.php';

class AdminKit_Integration_Funnel_Builder extends AdminKit_Integration_Base {
	use AdminKit_FunnelKit_Integration {
		register_assets as private register_app_assets;
	}

	public static function slug() {
		return 'funnel-builder';
	}

	public static function label() {
		return 'Funnel Builder';
	}

	public static function host_files() {
		return array( 'funnel-builder/funnel-builder.php', 'funnel-builder-pro/funnel-builder-pro.php' );
	}

	public static function is_active() {
		return defined( 'WFFN_VERSION' );
	}

	/** @return string Verified host minor series; newer minors fall back to native UI. */
	protected static function max_tested_host_version() {
		return '3.18';
	}

	protected static function host_version() {
		return defined( 'WFFN_VERSION' ) ? WFFN_VERSION : null;
	}

	protected static function wordmark_menu_slug() {
		return 'woofunnels';
	}

	protected static function funnelkit_variant() {
		return 'builder';
	}

	protected static function funnelkit_menu_icon() {
		return 'funnel';
	}

	/** Register the native dashboard widget separately from the FunnelKit app. */
	public static function register_assets() {
		self::register_app_assets();
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register(
			array(
				'handle'    => 'adminkit-funnelkit-dashboard',
				'src'       => 'inc/integrations/plugins/funnel-builder/css/dashboard.css',
				'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
				'context'   => 'admin',
				'condition' => array( __CLASS__, 'owns_dashboard' ),
			)
		);
	}

	/** @return bool Whether this is the native WordPress dashboard. */
	public static function owns_dashboard( $screen ) {
		return $screen && 'dashboard' === $screen->id;
	}

	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		return (bool) preg_match( '/(?:^|^edit-|_)(?:bwf|wffn|wfacp|wfty|wfop|wfocu|wfob)(?:$|[-_&])/', $screen->id )
			|| in_array( $screen->post_type, array( 'wffn_funnel', 'wfacp_checkout', 'wffn_landing', 'wffn_optin', 'wffn_ty', 'wffn_optin_ty', 'wfocu_offer', 'wfob_bump' ), true );
	}
}
