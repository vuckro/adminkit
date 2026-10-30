<?php
/**
 * Versioned storage upgrades. Runs once per site, on an administrator request.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Upgrades {

	const VERSION = 3;
	const OPTION  = 'adminkit_storage_version';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'run' ) );
	}

	public static function run() {
		if ( ! current_user_can( 'manage_options' ) || (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		$version = (int) get_option( self::OPTION, 0 );
		if ( $version < 1 ) {
			// Retire generated portraits without changing another avatar provider's choice.
			if ( 'adminkit_portraits' === get_option( 'avatar_default' ) ) {
				update_option( 'avatar_default', 'mystery' );
			}
			$settings = get_option( AdminKit_Settings::OPTION_KEY, array() );
			if ( is_array( $settings ) && array_key_exists( 'custom_avatars_enabled', $settings ) ) {
				unset( $settings['custom_avatars_enabled'] );
				update_option( AdminKit_Settings::OPTION_KEY, $settings );
			}
			delete_option( 'adminkit_avatars_default_synced' );
			delete_metadata( 'user', 0, 'adminkit_has_gravatar', '', true );
			delete_metadata( 'user', 0, 'adminkit_avatar_seed', '', true );
		}

		if ( $version < 2 ) {
			$settings = get_option( AdminKit_Settings::OPTION_KEY, array() );
			if ( is_array( $settings ) && array_key_exists( 'bricks_builder_enabled', $settings ) ) {
				unset( $settings['bricks_builder_enabled'] );
				update_option( AdminKit_Settings::OPTION_KEY, $settings );
			}
		}

		if ( $version < 3 ) {
			$settings = get_option( AdminKit_Settings::OPTION_KEY, array() );
			if ( is_array( $settings ) && array_key_exists( 'plugins_active_first_enabled', $settings ) ) {
				if ( ! array_key_exists( 'active_items_first_enabled', $settings ) ) {
					$settings['active_items_first_enabled'] = rest_sanitize_boolean( $settings['plugins_active_first_enabled'] );
				}
				unset( $settings['plugins_active_first_enabled'] );
				update_option( AdminKit_Settings::OPTION_KEY, $settings );
			}
		}

		// Admin-only migration state does not need to be autoloaded on the front end.
		update_option( self::OPTION, self::VERSION, false );
	}
}
