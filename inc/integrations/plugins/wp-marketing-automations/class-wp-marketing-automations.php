<?php
/** FunnelKit Automations — scoped Tier B skin, verified against host 3.x. */
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 2 ) . '/trait-funnelkit-integration.php';

class AdminKit_Integration_Wp_Marketing_Automations extends AdminKit_Integration_Base {
	use AdminKit_FunnelKit_Integration;

	public static function slug() {
		return 'wp-marketing-automations';
	}

	public static function label() {
		return 'FunnelKit Automations';
	}

	public static function host_files() {
		return array( 'wp-marketing-automations/wp-marketing-automations.php', 'wp-marketing-automations-pro/wp-marketing-automations-pro.php' );
	}

	public static function is_active() {
		return defined( 'BWFAN_VERSION' );
	}

	protected static function host_version() {
		return defined( 'BWFAN_VERSION' ) ? BWFAN_VERSION : null;
	}

	protected static function wordmark_menu_slug() {
		return 'autonami';
	}

	protected static function funnelkit_variant() {
		return 'automations';
	}

	protected static function funnelkit_menu_icon() {
		return 'workflow';
	}

	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		return (bool) preg_match( '/autonami|bwfan|bwfcrm/', $screen->id );
	}
}
