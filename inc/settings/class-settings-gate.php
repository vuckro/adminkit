<?php
/**
 * Settings-driven gates for integrations and generic plugin theming.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Settings_Gate {

	const PLUGIN_MAP_TRANSIENT = 'adminkit_plugin_prefixes';

	/**
	 * Register the filters that pause AdminKit on opted-out plugin screens.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'adminkit/integration_enabled', array( __CLASS__, 'gate_integration' ), 10, 2 );
		add_filter( 'adminkit/should_load', array( __CLASS__, 'gate_generic_theming' ), 10, 2 );
		if ( function_exists( 'add_action' ) ) {
			add_action( 'activated_plugin', array( __CLASS__, 'flush_plugin_map' ) );
			add_action( 'deactivated_plugin', array( __CLASS__, 'flush_plugin_map' ) );
			add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_plugin_map' ) );
		}
	}

	/**
	 * Flush the cached plugin-prefix lookup table when plugins change.
	 *
	 * @return void
	 */
	public static function flush_plugin_map() {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::PLUGIN_MAP_TRANSIENT );
		}
	}

	/**
	 * Gate AdminKit's admin restyle depending on which plugin owns the screen.
	 *
	 * @param bool   $should_load
	 * @param string $context admin | login | frontend | editor.
	 * @return bool
	 */
	public static function gate_generic_theming( $should_load, $context ) {
		static $memo = array();
		if ( 'admin' !== $context || ! $should_load ) {
			return $should_load;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return $should_load;
		}
		$screen_id = isset( $screen->id ) ? (string) $screen->id : '';
		if ( '' !== $screen_id && array_key_exists( $screen_id, $memo ) ) {
			return $memo[ $screen_id ];
		}
		foreach ( AdminKit_Settings_Catalog::integration_specs() as $s ) {
			if ( ! method_exists( $s['class'], 'owns_screen' ) || ! call_user_func( array( $s['class'], 'is_active' ) ) ) {
				continue;
			}
			if ( call_user_func( array( $s['class'], 'owns_screen' ), $screen ) ) {
				$allowed = (bool) apply_filters( 'adminkit/integration_enabled', true, $s['slug'] );
				if ( '' !== $screen_id ) {
					$memo[ $screen_id ] = $allowed;
				}
				return $allowed;
			}
		}

		$file    = self::plugin_file_for_screen( $screen );
		$allowed = ! $file || plugin_basename( ADMINKIT_FILE ) === $file
			|| ! in_array( $file, (array) AdminKit_Settings::get( 'generic_theming_off' ), true );
		if ( '' !== $screen_id ) {
			$memo[ $screen_id ] = $allowed;
		}
		return $allowed;
	}

	/**
	 * Detected integrations default on; explicit saved opt-outs remain effective.
	 *
	 * @param bool   $enabled
	 * @param string $slug
	 * @return bool
	 */
	public static function gate_integration( $enabled, $slug ) {
		// Gutenberg ships with WordPress. Its chrome must accompany the admin
		// skin even when optional plugin integrations are all disabled.
		if ( 'gutenberg' === $slug ) {
			return $enabled;
		}
		$v = AdminKit_Settings::get( 'integration_' . $slug . '_enabled' );
		return $enabled && ( null === $v || (bool) $v );
	}

	/**
	 * Best-effort screen -> plugin file mapping for generic plugin opt-outs.
	 *
	 * @param WP_Screen|null $screen
	 * @return string|null
	 */
	private static function plugin_file_for_screen( $screen ) {
		static $cache = array();
		if ( ! $screen ) {
			return null;
		}
		$id = (string) $screen->id;
		if ( array_key_exists( $id, $cache ) ) {
			return $cache[ $id ];
		}

		$slug = '';
		if ( 0 === strpos( $id, 'toplevel_page_' ) ) {
			$slug = substr( $id, strlen( 'toplevel_page_' ) );
		} elseif ( false !== strpos( $id, '_page_' ) ) {
			$slug = substr( $id, strpos( $id, '_page_' ) + strlen( '_page_' ) );
		}
		if ( '' === $slug ) {
			return ( $cache[ $id ] = null );
		}
		$prefixes = self::plugin_prefixes();
		$match    = null;
		$length   = -1;
		foreach ( $prefixes as $prefix => $file ) {
			if ( ( $slug === $prefix || 0 === strpos( $slug, $prefix . '-' ) || 0 === strpos( $slug, $prefix . '_' ) )
				&& strlen( $prefix ) > $length ) {
				$match  = $file;
				$length = strlen( $prefix );
			}
		}
		return ( $cache[ $id ] = $match );
	}

	/**
	 * Cached `prefix => plugin_file` table so `get_plugins()` does not scan disk on
	 * every `*_page_*` screen.
	 *
	 * @return array<string, string>
	 */
	private static function plugin_prefixes() {
		static $prefixes = null;
		if ( null !== $prefixes ) {
			return $prefixes;
		}
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( self::PLUGIN_MAP_TRANSIENT );
			if ( is_array( $cached ) ) {
				$prefixes = $cached;
				return $prefixes;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$prefixes = array();
		foreach ( get_plugins() as $file => $_data ) {
			$dir  = dirname( $file );
			$base = basename( $file, '.php' );
			if ( '.' === $dir ) {
				$dir = $base;
			}
			foreach ( array_unique( array( $dir, $base ) ) as $prefix ) {
				if ( '' !== $prefix && ! isset( $prefixes[ $prefix ] ) ) {
					$prefixes[ $prefix ] = $file;
				}
			}
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::PLUGIN_MAP_TRANSIENT, $prefixes, DAY_IN_SECONDS );
		}
		return $prefixes;
	}
}
