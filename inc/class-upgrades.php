<?php
/**
 * Versioned storage upgrades. Runs once per site, on an administrator request.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Upgrades {

	const VERSION = 9;
	const OPTION  = 'adminkit_storage_version';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'run' ) );
	}

	public static function run() {
		if ( ! current_user_can( 'manage_options' ) || (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		$version = (int) get_option( self::OPTION, 0 );
		// Preserve legacy intent before cleanup, including repair of completed older upgrades.
		if ( $version < 8 && ! self::migrate_clean_chrome() ) { return; }
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

		if ( $version < 4 && ! self::remove_dashboard_and_stats() ) {
			return; // Retry a failed table removal on the next administrator request.
		}

		if ( $version < 5 ) {
			$settings = get_option( AdminKit_Settings::OPTION_KEY, array() );
			if ( is_array( $settings ) && array_key_exists( 'online_users_enabled', $settings ) ) {
				unset( $settings['online_users_enabled'] );
				update_option( AdminKit_Settings::OPTION_KEY, $settings );
			}
			// WordPress session_tokens belong to core and must remain untouched.
			delete_metadata( 'user', 0, 'adminkit_last_login', '', true );
		}

		if ( $version < 6 ) {
			// One brand logo (`logo_url`); drop retired logo/appearance/preset keys.
			$settings = get_option( AdminKit_Settings::OPTION_KEY, array() );
			$retired  = array( 'logo_light', 'logo_dark', 'logo_source', 'wp_logo', 'login_logo', 'ui_radius', 'ui_density', 'generic_theming_on', 'hide_footer_enabled', 'hide_help_button_enabled', 'editor_content_theme' );
			if ( is_array( $settings ) && array_intersect( $retired, array_keys( $settings ) ) ) {
				if ( empty( $settings['logo_url'] ) ) {
					foreach ( array( 'logo_light', 'logo_dark' ) as $legacy ) {
						if ( ! empty( $settings[ $legacy ] ) && is_string( $settings[ $legacy ] ) ) {
							$settings['logo_url'] = $settings[ $legacy ];
							break;
						}
					}
				}
				$settings = array_diff_key( $settings, array_flip( $retired ) );
				update_option( AdminKit_Settings::OPTION_KEY, $settings );
			}
		}

		// Upgrade 8 also repairs settings left behind by a failed historical write.
		if ( ! self::repair_legacy_settings() ) { return; }

		// Upgrade 9: ensure small, hot options are autoloaded to avoid extra per-request SELECTs.
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array(
				AdminKit_Settings::OPTION_KEY => true,
				'adminkit_toolbar'            => true,
			) );
		}

		update_option( self::OPTION, self::VERSION, true );
	}

	/** Verify historical settings writes before advancing the storage version. */
	private static function repair_legacy_settings() {
		$before = get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( ! is_array( $before ) ) { return true; }
		$settings = $before;
		if ( array_key_exists( 'plugins_active_first_enabled', $settings ) && ! array_key_exists( 'active_items_first_enabled', $settings ) ) {
			$settings['active_items_first_enabled'] = rest_sanitize_boolean( $settings['plugins_active_first_enabled'] );
		}
		if ( empty( $settings['logo_url'] ) ) {
			foreach ( array( 'logo_light', 'logo_dark' ) as $legacy ) {
				if ( ! empty( $settings[ $legacy ] ) && is_string( $settings[ $legacy ] ) ) {
					$settings['logo_url'] = $settings[ $legacy ];
					break;
				}
			}
		}
		$retired = array( 'custom_avatars_enabled', 'bricks_builder_enabled', 'plugins_active_first_enabled', 'custom_dashboard_enabled', 'stats_enabled', 'online_users_enabled', 'logo_light', 'logo_dark', 'logo_source', 'wp_logo', 'login_logo', 'ui_radius', 'ui_density', 'generic_theming_on', 'hide_footer_enabled', 'hide_help_button_enabled', 'editor_content_theme' );
		$settings = array_diff_key( $settings, array_flip( $retired ) );
		return $settings === $before || update_option( AdminKit_Settings::OPTION_KEY, $settings )
			|| $settings === get_option( AdminKit_Settings::OPTION_KEY, array() );
	}

	/** Upgrade 7. Persist intent before older cleanup, so a failed write is retryable. */
	private static function migrate_clean_chrome() {
		$before = get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( ! is_array( $before ) ) { return true; }
		$settings = $before;
		$legacy = array_intersect_key( $before, array_flip( array( 'hide_footer_enabled', 'hide_help_button_enabled' ) ) );
		if ( ! array_key_exists( 'clean_chrome_enabled', $settings ) && $legacy ) {
			// A legacy opt-out wins: do not hide a surface the user kept visible.
			$settings['clean_chrome_enabled'] = ! in_array( false, array_map( 'rest_sanitize_boolean', $legacy ), true );
		}
		$settings = array_diff_key( $settings, array_flip( array( 'hide_footer_enabled', 'hide_help_button_enabled', 'editor_content_theme' ) ) );
		return $settings === $before || update_option( AdminKit_Settings::OPTION_KEY, $settings )
			|| $settings === get_option( AdminKit_Settings::OPTION_KEY, array() );
	}

	/** Remove only the retired modules' site data; leave native dashboard preferences. */
	private static function remove_dashboard_and_stats() {
		global $wpdb;
		// The table name comes from WordPress's trusted per-site prefix.
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}adminkit_stats" ) ) {
			return false;
		}
		$settings = get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( is_array( $settings ) && ( array_key_exists( 'custom_dashboard_enabled', $settings ) || array_key_exists( 'stats_enabled', $settings ) ) ) {
			unset( $settings['custom_dashboard_enabled'], $settings['stats_enabled'] );
			update_option( AdminKit_Settings::OPTION_KEY, $settings );
		}
		delete_option( 'adminkit_stats_db_version' );
		delete_option( 'adminkit_stats_pruned' );
		foreach ( array( 'adminkit_dash_layout', 'adminkit_dash_hidden', 'adminkit_stats_range' ) as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}

		// Include expired timeout-only entries. Use the API to invalidate object caches.
		$patterns = array();
		foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
			foreach ( array( 'adminkit_dash_', 'adminkit_stats_' ) as $namespace ) {
				$patterns[] = $wpdb->esc_like( $prefix . $namespace ) . '%';
			}
		}
		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$patterns
		) );
		if ( $wpdb->last_error ) {
			return false;
		}
		foreach ( array_unique( array_map( static function ( $name ) {
			return preg_replace( '/^_transient_(?:timeout_)?/', '', $name );
		}, $names ) ) as $name ) {
			delete_transient( $name );
		}
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'adminkit_stats' );
		}
		return true;
	}
}
