<?php
/** FunnelKit Builder — scoped Tier B skin, verified against host 3.x. */
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 2 ) . '/trait-funnelkit-integration.php';

class AdminKit_Integration_Funnel_Builder extends AdminKit_Integration_Base {
	use AdminKit_FunnelKit_Integration;

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

	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		return (bool) preg_match( '/(?:^|_)(?:bwf|wffn|wfacp|wfty|wfop|wfocu)(?:$|[-_&])/', $screen->id )
			|| in_array( $screen->post_type, array( 'wffn_funnel', 'wfacp_checkout', 'wffn_landing', 'wffn_optin', 'wffn_ty', 'wffn_optin_ty' ), true );
	}
}
