<?php
/** Shared persistence results for AdminKit write endpoints. @package AdminKit */
defined( 'ABSPATH' ) || exit;

class AdminKit_Storage {

	/** update_option() returns false for both unchanged values and failed writes. */
	public static function save_option( $key, $value, $autoload = null ) {
		if ( update_option( $key, $value, $autoload ) || get_option( $key ) === $value ) {
			return true;
		}
		return self::error( 'adminkit_storage_failed' );
	}

	/** Keep SQL details out of REST responses; callers retain a machine-readable code. */
	public static function error( $code, $status = 500 ) {
		return new WP_Error( $code, __( 'Could not save', 'adminkit' ), array( 'status' => $status ) );
	}
}
