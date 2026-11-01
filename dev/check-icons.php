<?php
/**
 * Verify the shipped icon catalog, legacy role aliases, OPcache sync and safe SVG markup.
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

define( 'ABSPATH', '/' );
define( 'ADMINKIT_PATH', dirname( __DIR__ ) . '/' );

require ADMINKIT_PATH . 'inc/class-icons.php';

$roles = array(
	'home', 'pencil', 'pencil-square', 'document-pencil', 'article', 'eye',
	'photo', 'document', 'chat', 'brush', 'plugin', 'users', 'wrench', 'cog',
	'swatch', 'globe', 'plus', 'update', 'cart', 'store', 'tag', 'chart',
	'email', 'megaphone', 'shield', 'lock', 'database', 'cloud', 'bolt',
	'money', 'calendar', 'forms', 'feedback', 'translation', 'search', 'book',
	'id', 'location', 'ticket', 'palette', 'gallery', 'list', 'sliders', 'app',
);

foreach ( $roles as $role ) {
	if ( ! AdminKit_Icons::has( $role ) ) {
		throw new RuntimeException( 'Lost saved role: ' . $role );
	}
}

foreach ( AdminKit_Icons::names() as $name ) {
	$svg = AdminKit_Icons::display_svg( $name );
	if (
		! preg_match( '/^[a-z0-9-]+$/', $name )
		|| false === strpos( $svg, 'fill="none"' )
		|| false === strpos( $svg, 'stroke-width="1.75"' )
		|| preg_match( '/<(?:script|foreignObject)|\son[a-z]+=|(?:href|src)=/i', $svg )
	) {
		throw new RuntimeException( 'Invalid icon: ' . $name );
	}
}

if ( '' !== AdminKit_Icons::svg( 'unknown-test-icon' ) || AdminKit_Icons::has( array() ) ) {
	throw new RuntimeException( 'Unknown icons must return empty markup' );
}

echo 'OK: ' . count( AdminKit_Icons::names() ) . " names; all saved roles resolve; outline SVGs are safe\n";

// Both editors share one strict contract, including legacy raw/encoded/base64 inputs.
$valid = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 2L3 4" fill="none" stroke="currentColor"/></svg>';
foreach ( array( $valid, 'data:image/svg+xml,' . rawurlencode( $valid ), 'data:image/svg+xml;base64,' . base64_encode( $valid ) ) as $input ) {
	$clean = AdminKit_Icons::sanitize_icon( $input );
	if ( 0 !== strpos( $clean, 'data:image/svg+xml,' ) || false === strpos( AdminKit_Icons::icon_mask( $input ), 'mask:' ) ) {
		throw new RuntimeException( 'Safe SVG rejected' );
	}
}

$unsafe_fragments = array(
	'<script/>',
	'<foreignObject/>',
	'<image href="https://x.test"/>',
	'<path onload="alert(1)"/>',
	'<path style="fill:red"/>',
	'<path fill="url(https://x.test)"/>',
	'<path fill="URL(HTTP://x.test)"/>',
	'<use href="#x"/>',
	'<animate/>',
);

foreach ( $unsafe_fragments as $markup ) {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg">' . $markup . '</svg>';
	foreach ( array( $svg, 'data:image/svg+xml,' . rawurlencode( $svg ), 'data:image/svg+xml;base64,' . base64_encode( $svg ) ) as $input ) {
		if ( '' !== AdminKit_Icons::sanitize_icon( $input ) || '' !== AdminKit_Icons::icon_mask( $input ) ) {
			throw new RuntimeException( 'Unsafe SVG accepted: ' . $markup );
		}
	}
}

$malformed_inputs = array(
	'data:image/svg+xml,</style><script>x</script>',
	'<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>',
	'data:image/svg+xml;base64,???',
	'<svg><path></svg>',
	str_repeat( 'x', 16385 ),
);

foreach ( $malformed_inputs as $input ) {
	if ( '' !== AdminKit_Icons::sanitize_icon( $input ) ) {
		throw new RuntimeException( 'Malformed/oversized icon accepted' );
	}
}

foreach ( AdminKit_Icons::names() as $name ) {
	if ( '' === AdminKit_Icons::validated_svg( AdminKit_Icons::svg( $name ) ) ) {
		throw new RuntimeException( 'Bundled SVG incompatible with shared validator: ' . $name );
	}
}

$raw_catalog    = json_decode( (string) file_get_contents( ADMINKIT_PATH . 'assets/icons/lucide.json' ), true );
$expected_paths = array();
foreach ( AdminKit_Icons::ALIASES as $role => $target ) {
	if ( isset( $raw_catalog[ $target ] ) ) {
		$expected_paths[ $role ] = $raw_catalog[ $target ];
	}
}
$expected_paths += (array) $raw_catalog;

if ( AdminKit_Lucide_Paths::MAP !== $expected_paths ) {
	throw new RuntimeException( 'inc/generated/lucide-paths.php is out of sync with assets/icons/lucide.json; run php dev/build-icons.php' );
}

echo "OK: shared SVG canonicalization, legacy encodings, strict rejection, OPcache sync and all bundled vectors.\n";
