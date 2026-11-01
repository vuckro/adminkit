<?php
/** Shared persistence results for AdminKit write endpoints. @package AdminKit */
defined( 'ABSPATH' ) || exit;

class AdminKit_Storage {

	/** update_option() returns false for both unchanged values and failed writes. */
	public static function save_option( $key, $value, $autoload = null ) {
		if ( update_option( $key, $value, $autoload ) || self::normalize( get_option( $key ) ) === self::normalize( $value ) ) {
			return true;
		}
		return self::error( 'adminkit_storage_failed' );
	}

	/**
	 * Canonicalize associative-array key order recursively so identical option maps
	 * with reordered keys are recognized as unchanged no-ops while list order and
	 * strict scalar types remain preserved.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	private static function normalize( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::normalize( $v );
		}
		return $value;
	}

	/** Keep SQL details out of REST responses; callers retain a machine-readable code. */
	public static function error( $code, $status = 500 ) {
		return new WP_Error( $code, __( 'Could not save', 'adminkit' ), array( 'status' => $status ) );
	}
}
