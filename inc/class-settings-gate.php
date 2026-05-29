<?php
/**
 * Settings-driven gates for integrations and generic plugin theming.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Settings_Gate {

	/**
	 * Register the filters that pause AdminKit on opted-out plugin screens.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'adminkit/integration_enabled', array( __CLASS__, 'gate_integration' ), 10, 2 );
		add_filter( 'adminkit/should_load', array( __CLASS__, 'gate_generic_theming' ), 10, 2 );
		add_filter( 'adminkit/suppress_auto_theme', array( __CLASS__, 'suppress_auto_theme_for_adapters' ), 10, 2 );
	}

	/**
	 * Gate AdminKit's admin restyle depending on which plugin owns the screen.
	 *
	 * @param bool   $should_load
	 * @param string $context admin | login | frontend | editor.
	 * @return bool
	 */
	public static function gate_generic_theming( $should_load, $context ) {
		if ( 'admin' !== $context || ! $should_load ) {
			return $should_load;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return $should_load;
		}
		foreach ( AdminKit_Settings_Catalog::integration_specs() as $s ) {
			if ( ! method_exists( $s['class'], 'owns_screen' ) ) {
				continue;
			}
			if ( call_user_func( array( $s['class'], 'owns_screen' ), $screen ) ) {
				return (bool) apply_filters( 'adminkit/integration_enabled', true, $s['slug'] );
			}
		}

		$off = (array) AdminKit_Settings::get( 'generic_theming_off' );
		if ( ! $off ) {
			return true;
		}
		$file = self::plugin_file_for_screen( $screen );
		return ! ( $file && in_array( $file, $off, true ) );
	}

	/**
	 * Suppress auto-theme on screens owned by enabled native adapters.
	 *
	 * @param bool            $suppress
	 * @param WP_Screen|null $screen
	 * @return bool
	 */
	public static function suppress_auto_theme_for_adapters( $suppress, $screen ) {
		if ( $suppress || ! $screen ) {
			return $suppress;
		}
		foreach ( AdminKit_Settings_Catalog::integration_specs() as $s ) {
			if ( ! method_exists( $s['class'], 'owns_screen' ) ) {
				continue;
			}
			if ( ! apply_filters( 'adminkit/integration_enabled', true, $s['slug'] ) ) {
				continue;
			}
			if ( call_user_func( array( $s['class'], 'owns_screen' ), $screen ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An integration runs unless the user turned it off.
	 *
	 * @param bool   $enabled
	 * @param string $slug
	 * @return bool
	 */
	public static function gate_integration( $enabled, $slug ) {
		$v = AdminKit_Settings::get( 'integration_' . $slug . '_enabled' );
		return ( null === $v ) ? $enabled : (bool) $v;
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
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $_data ) {
			$dir  = dirname( $file );
			$base = basename( $file, '.php' );
			if ( '.' === $dir ) {
				$dir = $base;
			}
			if ( $slug === $dir || $slug === $base
				|| 0 === strpos( $slug, $dir . '-' ) || 0 === strpos( $slug, $dir . '_' ) ) {
				return ( $cache[ $id ] = $file );
			}
		}
		return ( $cache[ $id ] = null );
	}
}
