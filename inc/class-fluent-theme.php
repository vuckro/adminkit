<?php
/**
 * Shared Fluent theme intent, independent of the optional FluentHub shell.
 *
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Fluent_Theme {
	/** @var bool Prevent duplicate inline bootstraps across native hook paths. */
	private static $enqueued = false;
	/** @return void */
	public static function init() {
		add_filter( 'fluent_crm_asset_listed_slugs', array( __CLASS__, 'allow_assets' ) );
		add_filter( 'fluent_booking/asset_listed_slugs', array( __CLASS__, 'allow_assets' ) );
		add_filter( 'fluent_boards/asset_listed_slugs', array( __CLASS__, 'allow_assets' ) );
		add_action( 'current_screen', array( __CLASS__, 'enqueue' ), 10001 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 10001 );
	}

	/**
	 * Only an active, enabled and version-compatible owner may load the bridge.
	 *
	 * @param WP_Screen|null $screen Current admin screen.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		foreach ( array( 'Fluent_Auth', 'Fluent_Toolkit', 'Fluent_Smtp', 'Fluent_Snippets', 'Fluentform', 'Fluent_Crm', 'Fluent_Booking', 'Fluent_Boards', 'Fluent_Community', 'Fluent_Cart', 'Fluent_Affiliate' ) as $name ) {
			$class = 'AdminKit_Integration_' . $name;
			if ( ! class_exists( $class ) || ! $class::is_active() || ! $class::host_within_tested_range() || ! apply_filters( 'adminkit/integration_enabled', true, $class::slug() ) ) {
				continue;
			}
			if ( $class::owns_screen( $screen ) || ( 'Fluent_Toolkit' === $name && $class::has_shell() ) || ( 'Fluent_Cart' === $name && $class::owns_chrome( $screen ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Native Fluent asset isolation otherwise removes AdminKit's queued scripts.
	 * Preserve the host allowlist, adding only our exact plugin directory.
	 *
	 * @param string[] $slugs Approved host patterns.
	 * @return string[]
	 */
	public static function allow_assets( $slugs ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$owners = array(
			'fluent_crm_asset_listed_slugs'     => 'AdminKit_Integration_Fluent_Crm',
			'fluent_booking/asset_listed_slugs' => 'AdminKit_Integration_Fluent_Booking',
			'fluent_boards/asset_listed_slugs'  => 'AdminKit_Integration_Fluent_Boards',
		);
		$class = $owners[ current_filter() ] ?? '';
		// Hosts can build their loader policy before WordPress sets WP_Screen.
		$owner_ready = $class && class_exists( $class ) && $class::is_active() && $class::host_within_tested_range() && apply_filters( 'adminkit/integration_enabled', true, $class::slug() );
		if ( is_admin() && $owner_ready && ( ! $screen || $class::owns_screen( $screen ) ) && apply_filters( 'adminkit/should_load', true, 'admin' ) && apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) {
			$slugs[] = '\/adminkit\/';
		}
		return array_values( array_unique( $slugs ) );
	}

	/** @return void */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::$enqueued || ! self::owns_screen( $screen ) || ! apply_filters( 'adminkit/should_load', true, 'admin' ) || ! apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) {
			return;
		}
		self::$enqueued = true;
		$data = array(
			'attr' => AdminKit_Theme_Toggle::attribute(),
			'key' => AdminKit_Theme_Toggle::storage_key(),
			'enabled' => (bool) AdminKit_Settings::get( 'theme_toggle_enabled' ),
			'label' => __( 'Toggle light / dark mode', 'adminkit' ),
		);
		AdminKit_Assets::enqueue_script( 'adminkit-fluent-toolkit-theme', 'inc/integrations/plugins/fluent-toolkit/js/theme.js', array(), 'window.AdminKitFluentHub=' . wp_json_encode( $data ) . ';' );
	}
}
