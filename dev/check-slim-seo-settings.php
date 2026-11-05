<?php
/** Prevent duplicate Slim SEO settings render/save dispatch without removing Pro features. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
$hooks = array();
function has_action( $hook, $callback ) {
	global $hooks;
	return in_array( $callback, $hooks[ $hook ] ?? array(), true ) ? 10 : false;
}
function remove_action( $hook, $callback ) {
	global $hooks;
	$hooks[ $hook ] = array_values( array_filter( $hooks[ $hook ] ?? array(), static fn( $item ) => $item !== $callback ) );
}
require dirname( __DIR__ ) . '/inc/integrations/abstract-integration.php';
require dirname( __DIR__ ) . '/inc/integrations/plugins/slim-seo/class-slim-seo.php';
function check_settings( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
$core = 'eLightUp\\SlimSEO\\Common\\Settings\\Page';
$legacy = 'SlimSEOPro\\Page';
$hook = 'settings_page_slim-seo';
foreach ( array( array( $core ), array( $legacy ), array( $core, $legacy ), array( \SlimSEO\Settings\Page::class ), array( $core, \SlimSEO\Settings\Page::class ), array( $core, \SlimSEO\Settings\Page::class, $legacy ) ) as $renderers ) {
	$hooks = array( $hook => array(), 'load-' . $hook => array(), 'slim_seo_save' => array( array( 'ProFeatures', 'save' ) ) );
	foreach ( $renderers as $class ) {
		$hooks[ $hook ][] = array( $class, 'render' );
		$hooks[ 'load-' . $hook ][] = array( $class, 'save' );
	}
	$submenu = array( 'options-general.php' => array( array( 'Core', 'manage_options', 'slim-seo' ), array( 'Pro', 'manage_options', 'slim-seo' ), array( 'Other', 'manage_options', 'other' ) ) );
	AdminKit_Integration_Slim_Seo::deduplicate_settings_page();
	$expected = count( $renderers ) > 1 ? $core : $renderers[0];
	check_settings( $hooks[ $hook ] === array( array( $expected, 'render' ) ), 'Wrong settings renderer removed' );
	check_settings( $hooks[ 'load-' . $hook ] === array( array( $expected, 'save' ) ), 'Wrong save dispatcher removed' );
	check_settings( count( $hooks['slim_seo_save'] ) === 1, 'Pro feature save hooks changed' );
	check_settings( count( $submenu['options-general.php'] ) === ( count( $renderers ) > 1 ? 2 : 3 ), 'Menu cleanup was not conditional' );
	check_settings( end( $submenu['options-general.php'] )[2] === 'other', 'Unrelated menu changed' );
}
echo "OK: one Slim SEO renderer/save dispatcher only when both exist; standalone and Pro feature hooks preserved.\n";
