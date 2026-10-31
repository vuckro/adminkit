<?php
/** Regression: core editor chrome survives fresh defaults and plugin opt-outs. */
define( 'ABSPATH', __DIR__ . '/' );
class AdminKit_Settings {
	public static $values = array();
	public static function get( $key ) {
		return self::$values[ $key ] ?? false;
	}
}
require dirname( __DIR__ ) . '/inc/settings/class-settings-gate.php';

foreach ( array( false, true ) as $opt_in ) {
	AdminKit_Settings::$values = array(
		'integration_gutenberg_enabled' => $opt_in,
		'integration_woocommerce_enabled' => $opt_in,
	);
	if ( true !== AdminKit_Settings_Gate::gate_integration( true, 'gutenberg' )
		|| false !== AdminKit_Settings_Gate::gate_integration( false, 'gutenberg' )
		|| $opt_in !== AdminKit_Settings_Gate::gate_integration( true, 'woocommerce' )
		|| false !== AdminKit_Settings_Gate::gate_integration( false, 'woocommerce' ) ) {
		throw new RuntimeException( 'Core editor / plugin integration gate regression.' );
	}
}
echo "OK: core editor chrome survives defaults; plugin opt-ins and explicit veto remain respected.\n";
