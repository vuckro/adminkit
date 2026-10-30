<?php
/** Run: php dev/check-brand-logo.php. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/inc/wp-core/class-branding.php';
$valid = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" fill="currentColor"><rect width="10" height="10"/><path fill="var(--wk-logo-inverse, #fff)" d="M1 1L2 2"/></svg>';
if ( ! AdminKit_Core_Branding::safe_logo_svg( $valid ) ) { throw new RuntimeException( 'Valid monochrome SVG rejected' ); }
foreach ( array( '<script/>', '<image href="https://example.com/x"/>', '<path onload="x"/>', '<path fill="url(https://example.com/x)"/>', '<foreignObject/>', '<?xml-stylesheet href="https://example.com/x"?>' ) as $unsafe ) {
 if ( AdminKit_Core_Branding::safe_logo_svg( '<svg xmlns="http://www.w3.org/2000/svg">' . $unsafe . '</svg>' ) ) { throw new RuntimeException( 'Unsafe SVG accepted' ); }
}
echo "OK: vector SVG accepted; scripts, external references and embedded content rejected\n";
