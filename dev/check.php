<?php
/** Regression checks for the custom-accent contrast helper. Run: php dev/check.php. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/inc/class-assets.php';

function ak_test_luminance( $hex ) {
	$rgb = sscanf( $hex, '#%02x%02x%02x' );
	foreach ( $rgb as &$channel ) {
		$channel /= 255;
		$channel = $channel <= 0.04045 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
}

foreach ( array( '#777777' => '#000000', '#ffffff' => '#000000', '#1549ab' => '#ffffff', '#000000' => '#ffffff', '#fff' => '#000000' ) as $input => $expected ) {
	if ( $expected !== AdminKit_Assets::contrast_text_for( $input ) ) {
		throw new RuntimeException( 'Wrong custom-accent foreground for ' . $input );
	}
}
$count = 0;
for ( $r = 0; $r <= 255; $r += 17 ) {
	for ( $g = 0; $g <= 255; $g += 17 ) {
		for ( $b = 0; $b <= 255; $b += 17 ) {
			$fill = sprintf( '#%02x%02x%02x', $r, $g, $b );
			$a = ak_test_luminance( $fill );
			$z = ak_test_luminance( AdminKit_Assets::contrast_text_for( $fill ) );
			$ratio = ( max( $a, $z ) + 0.05 ) / ( min( $a, $z ) + 0.05 );
			if ( $ratio < 4.5 ) {
				throw new RuntimeException( 'Insufficient custom-accent contrast for ' . $fill );
			}
			$count++;
		}
	}
}
echo "OK: $count custom colors meet 4.5:1, including the mid-gray regression\n";
