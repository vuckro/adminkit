<?php
/** Slim SEO list styling must follow the host's column surface, never unrelated screens. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
$host_columns = false;
function wp_style_is( $handle, $state ) {
	global $host_columns;
	return $host_columns && 'slim-seo-admin-columns' === $handle && 'enqueued' === $state;
}
require dirname( __DIR__ ) . '/inc/integrations/abstract-integration.php';
require dirname( __DIR__ ) . '/inc/integrations/plugins/slim-seo/class-slim-seo.php';
function check_columns( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
foreach ( array( 'edit', 'edit-tags' ) as $base ) {
	$screen = (object) array( 'id' => 'custom-content', 'base' => $base );
	check_columns( ! AdminKit_Integration_Slim_Seo::owns_columns_screen( $screen ), 'Skin loaded without host columns' );
	$host_columns = true;
	check_columns( AdminKit_Integration_Slim_Seo::owns_columns_screen( $screen ), 'Host list columns lost their adapter' );
	$host_columns = false;
}
$host_columns = true;
foreach ( array( 'dashboard', 'post', 'term', 'plugins' ) as $base ) {
	check_columns( ! AdminKit_Integration_Slim_Seo::owns_columns_screen( (object) array( 'base' => $base ) ), 'Column styling leaked to ' . $base );
}
check_columns( ! AdminKit_Integration_Slim_Seo::owns_columns_screen( null ), 'Missing screen was accepted' );
echo "OK: Slim SEO columns follow host assets on post/taxonomy lists only.\n";
