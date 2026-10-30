<?php
/** CSS ownership and semantic-role regressions. Run: php dev/css-contract.php. */
$root = dirname( __DIR__ );
$failures = array();
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/assets/css', FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( 'css' !== $file->getExtension() ) {
		continue;
	}
	$css = preg_replace( '~/\*.*?\*/~s', '', file_get_contents( $file->getPathname() ) );
	if ( preg_match( '/(?<![-\w])color\s*:\s*var\(--ak-primary(?:-hover)?\)/', $css ) ) {
		$failures[] = $file->getFilename() . ': accent fill used as text ink';
	}
}
$chrome = file_get_contents( $root . '/assets/css/wp-core/chrome.css' );
if ( preg_match( '/body\.adminkit\s+table\.widefat/', $chrome ) ) {
	$failures[] = 'Table reset escaped its component owner';
}
$tables = file_get_contents( $root . '/assets/css/wp-components/tables.css' );
if ( preg_match( '/body\.adminkit\s+\.widefat\s*\{/', $tables ) ) {
	$failures[] = 'Generic widefat rules also target native list tables';
}
$settings = file_get_contents( $root . '/assets/css/settings.css' );
if ( ! preg_match( '/\.ak-btn--primary:hover\s*\{[^}]*color:\s*var\(--ak-on-accent-hover\)/', $settings ) ) {
	$failures[] = 'Custom hover fill and foreground are no longer paired';
}
$appearance = file_get_contents( $root . '/tokens/appearance.css' );
if ( false === strpos( $appearance, '[data-adminkit-accent="custom"]' ) ) {
	$failures[] = 'Custom appearance is not scoped to its explicit preset';
}
$main = file_get_contents( $root . '/assets/css/settings/main.css' );
foreach ( array( '.ak-overview-list', '.ak-foundations-panel', '.ak-rows' ) as $surface ) {
 if ( ! preg_match( '/' . preg_quote( $surface, '/' ) . '\s*\{[^}]*box-shadow:\s*var\(--ak-card-shadow\)/', $main ) ) {
  $failures[] = $surface . ': outer surface lost shared elevation';
 }
}
if ( false === strpos( $appearance, 'body.login' ) || false === strpos( $appearance, '--ak-card-shadow: var(--ak-design-elevation, var(--ak-default-card-shadow))' ) ) {
 $failures[] = 'Shared surface roles escaped the token source';
}
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: accent ink, scoped custom appearance and table ownership contracts\n";
