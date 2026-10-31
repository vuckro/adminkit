<?php
/**
 * Toolbar layout validation, compatibility reads and persistence.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Toolbar_Config {

	const OPTION = 'adminkit_toolbar';

	/** Capability required to view + save the toolbar layout (filterable). */
	public static function capability() {
		return apply_filters( 'adminkit/toolbar/capability', 'manage_options' );
	}

	/**
	 * Node ids that must never be hidden/removed — keeps the user from locking
	 * themselves out of their account menu / the AdminKit-facing bits. Filterable.
	 *
	 * @return array<string,bool> id => true
	 */
	public static function protected_nodes() {
		$ids = (array) apply_filters( 'adminkit/toolbar/protected_nodes', array(
			'wp-admin-bar-my-account', // the account menu (sign-out lives under it)
			'wp-admin-bar-menu-toggle', // the responsive bar toggle
		) );
		return array_fill_keys( array_map( static function ( $id ) { return preg_replace( '/^wp-admin-bar-/', '', (string) $id ); }, $ids ), true );
	}

	/**
	 * Sanitise a single context slice (`admin` or `frontend`) from raw option or REST data.
	 *
	 * @param mixed              $raw
	 * @param array<string,bool> $protected
	 * @return array{order:string[],hidden:string[],titles:array<string,string>,icons:array<string,string>}
	 */
	private static function sanitize_context_slice( $raw, $protected = array() ) {
		if ( ! is_array( $raw ) ) {
			return array(
				'order'  => array(),
				'hidden' => array(),
				'titles' => array(),
				'icons'  => array(),
			);
		}
		$order = array();
		if ( isset( $raw['order'] ) && is_array( $raw['order'] ) ) {
			foreach ( $raw['order'] as $id ) {
				$id = self::sanitize_node_id( $id );
				if ( '' !== $id ) {
					$order[] = $id;
				}
			}
		}
		$hidden = array();
		if ( isset( $raw['hidden'] ) && is_array( $raw['hidden'] ) ) {
			foreach ( $raw['hidden'] as $id ) {
				$id = self::sanitize_node_id( $id );
				if ( '' !== $id && ! isset( $protected[ $id ] ) ) {
					$hidden[] = $id;
				}
			}
		}
		$titles = array();
		if ( isset( $raw['titles'] ) && is_array( $raw['titles'] ) ) {
			foreach ( $raw['titles'] as $id => $t ) {
				$id = self::sanitize_node_id( $id );
				$t  = is_string( $t ) ? sanitize_text_field( $t ) : '';
				if ( '' !== $id && '' !== $t ) {
					$titles[ $id ] = $t;
				}
			}
		}
		$icons = array();
		if ( isset( $raw['icons'] ) && is_array( $raw['icons'] ) ) {
			foreach ( $raw['icons'] as $id => $ic ) {
				$id = self::sanitize_node_id( $id );
				$ic = self::sanitize_icon( $ic );
				if ( '' !== $id && '' !== $ic ) {
					$icons[ $id ] = $ic;
				}
			}
		}
		return array(
			'order'  => array_values( array_unique( $order ) ),
			'hidden' => array_values( array_unique( $hidden ) ),
			'titles' => $titles,
			'icons'  => $icons,
		);
	}

	/**
	 * The saved layout: either for a specific context (`'admin'` | `'frontend'`) or the
	 * full per-context map when `$context` is omitted. Legacy flat `{ order, hidden,
	 * titles, icons }` options migrate transparently to the `admin` context on read.
	 *
	 * @param string|null $context Optional `'admin'` or `'frontend'`.
	 * @return array
	 */
	public static function get_config( $context = null ) {
		$raw       = get_option( self::OPTION, array() );
		$protected = self::protected_nodes();
		if ( is_array( $raw ) && ( isset( $raw['admin'] ) || isset( $raw['frontend'] ) ) ) {
			$all = array(
				'admin'    => self::sanitize_context_slice( isset( $raw['admin'] ) ? $raw['admin'] : array(), $protected ),
				'frontend' => self::sanitize_context_slice( isset( $raw['frontend'] ) ? $raw['frontend'] : array(), $protected ),
			);
		} else {
			$all = array(
				'admin'    => self::sanitize_context_slice( $raw, $protected ),
				'frontend' => self::sanitize_context_slice( array(), $protected ),
			);
		}
		if ( 'admin' === $context || 'frontend' === $context ) {
			return $all[ $context ];
		}
		return $all;
	}

	/**
	 * A node id is a slug-ish string (`wp-admin-bar-…`); keep only safe chars.
	 *
	 * @param mixed $id
	 * @return string
	 */
	private static function sanitize_node_id( $id ) {
		$id = is_string( $id ) ? trim( $id ) : '';
		return preg_replace( '/[^a-zA-Z0-9_\-]/', '', $id );
	}

	/**
	 * Sanitise a toolbar icon value: `wp-default`, a curated WordPress dashicon class,
	 * a known AdminKit icon name, or a custom SVG data-URI. Mirrors
	 * AdminKit_Menu_Manager::sanitize_icon().
	 *
	 * @param mixed $icon
	 * @return string
	 */
	private static function sanitize_icon( $icon ) {
		return AdminKit_Icons::sanitize_icon( $icon );
	}

	/**
	 * Validate + persist a layout from merged request parameters. Accepts either a per-context
	 * `{ admin: {...}, frontend: {...} }` payload or a legacy flat `{ order, hidden,
	 * titles, icons }` payload (which updates `admin` while keeping `frontend`).
	 * Protected ids can't be hidden.
	 *
	 * @param array $params
	 * @return array|WP_Error
	 */
	public static function save( array $params ) {
		$protected   = self::protected_nodes();
		$current     = self::get_config();
		$admin_in    = $params['admin'] ?? null;
		$frontend_in = $params['frontend'] ?? null;

		foreach ( array( 'admin', 'frontend', 'order', 'hidden', 'titles', 'icons' ) as $key ) {
			if ( array_key_exists( $key, $params ) && ! is_array( $params[ $key ] ) ) {
				return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
			}
		}
		if ( ! array_intersect_key( $params, array_flip( array( 'admin', 'frontend', 'order', 'hidden', 'titles', 'icons' ) ) ) ) {
			return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
		}

		foreach ( array( $admin_in, $frontend_in, $params ) as $slice ) {
			if ( ! is_array( $slice ) ) { continue; }
			foreach ( array( 'order', 'hidden', 'titles', 'icons' ) as $field ) {
				if ( ! array_key_exists( $field, $slice ) ) { continue; }
				if ( ! is_array( $slice[ $field ] ) ) {
					return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
				}
				foreach ( $slice[ $field ] as $value ) {
					if ( ! is_string( $value ) ) {
						return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
					}
				}
			}
		}

		if ( is_array( $admin_in ) || is_array( $frontend_in ) ) {
			$saved = array(
				'admin'    => is_array( $admin_in ) ? self::sanitize_context_slice( $admin_in, $protected ) : $current['admin'],
				'frontend' => is_array( $frontend_in ) ? self::sanitize_context_slice( $frontend_in, $protected ) : $current['frontend'],
			);
		} else {
			$legacy = array(
				'order'  => $params['order'] ?? null,
				'hidden' => $params['hidden'] ?? null,
				'titles' => $params['titles'] ?? null,
				'icons'  => $params['icons'] ?? null,
			);
			$saved  = array(
				'admin'    => self::sanitize_context_slice( $legacy, $protected ),
				'frontend' => $current['frontend'],
			);
		}

		$result = AdminKit_Storage::save_option( self::OPTION, $saved, false );
		if ( is_wp_error( $result ) ) { return $result; }

		return self::get_config();
	}
}
