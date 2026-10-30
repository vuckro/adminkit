<?php
/** Verify the shipped catalog, legacy role aliases and safe SVG markup. */
define( 'ABSPATH', '/' );
define( 'ADMINKIT_PATH', dirname( __DIR__ ) . '/' );
require ADMINKIT_PATH . 'inc/class-icons.php';
$roles = array( 'home', 'pencil', 'pencil-square', 'document-pencil', 'article', 'eye', 'photo', 'document', 'chat', 'brush', 'plugin', 'users', 'wrench', 'cog', 'swatch', 'globe', 'plus', 'update', 'cart', 'store', 'tag', 'chart', 'email', 'megaphone', 'shield', 'lock', 'database', 'cloud', 'bolt', 'money', 'calendar', 'forms', 'feedback', 'translation', 'search', 'book', 'id', 'location', 'ticket', 'palette', 'gallery', 'list', 'sliders', 'app' );
foreach ( $roles as $role ) {
	if ( ! AdminKit_Icons::has( $role ) ) { throw new RuntimeException( 'Lost saved role: ' . $role ); }
}
foreach ( AdminKit_Icons::names() as $name ) {
	$svg = AdminKit_Icons::display_svg( $name );
	if ( ! preg_match( '/^[a-z0-9-]+$/', $name ) || false === strpos( $svg, 'fill="none"' ) || false === strpos( $svg, 'stroke-width="1.75"' ) || preg_match( '/<(?:script|foreignObject)|\son[a-z]+=|(?:href|src)=/i', $svg ) ) {
		throw new RuntimeException( 'Invalid icon: ' . $name );
	}
}
if ( '' !== AdminKit_Icons::svg( 'unknown-test-icon' ) || AdminKit_Icons::has( array() ) ) { throw new RuntimeException( 'Unknown icons must return empty markup' ); }
echo 'OK: ' . count( AdminKit_Icons::names() ) . " names; all saved roles resolve; outline SVGs are safe\n";
