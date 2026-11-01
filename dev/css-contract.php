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
	if ( preg_match( '/\{[^}]*background(?:-color)?\s*:\s*var\(--ak-(?:primary|secondary|accent|error)\b[^}]*(?<![-\w])color\s*:\s*#(?:fff|ffffff)\b/is', $css ) ) {
		$failures[] = $file->getFilename() . ': hardcoded #fff on dynamic accent/error fill (use --ak-on-accent or --ak-on-danger-solid)';
	}
	if ( preg_match( '/--wpds-color-fg-interactive-(?:brand|error)-strong(?:-active)?\s*:\s*#(?:fff|ffffff)\b/i', $css ) ) {
		$failures[] = $file->getFilename() . ': hardcoded #fff on WPDS strong brand/error foreground';
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
foreach ( array( '.ak-foundations-panel', '.ak-rows' ) as $surface ) {
 if ( ! preg_match( '/' . preg_quote( $surface, '/' ) . '\s*\{[^}]*box-shadow:\s*var\(--ak-card-shadow\)/', $main ) ) {
  $failures[] = $surface . ': outer surface lost shared elevation';
 }
}
if ( false === strpos( $appearance, 'body.login' ) || false === strpos( $appearance, '--ak-card-shadow: var(--ak-design-elevation, var(--ak-default-card-shadow))' ) ) {
	$failures[] = 'Shared surface roles escaped the token source';
}
$ep_path = $root . '/assets/css/vendor/element-plus.css';
if ( ! is_file( $ep_path ) || ! preg_match( '/--el-color-primary\s*:\s*var\(\s*--ak-primary\s*\)/', (string) file_get_contents( $ep_path ) ) ) {
	$failures[] = 'Shared Element Plus bridge stylesheet missing or invalid';
}
foreach ( array( 'fluent-affiliate', 'fluent-booking', 'fluent-cart', 'fluent-community', 'fluent-crm', 'fluent-snippets' ) as $fluent_slug ) {
	$php_file = $root . '/inc/integrations/plugins/' . $fluent_slug . '/class-' . $fluent_slug . '.php';
	if ( ! is_file( $php_file ) || false === strpos( (string) file_get_contents( $php_file ), 'ELEMENT_PLUS_HANDLE' ) ) {
		$failures[] = $fluent_slug . ': missing AdminKit_Assets::ELEMENT_PLUS_HANDLE dependency';
	}
}
foreach ( glob( $root . '/inc/integrations/*/*/css/*.css' ) as $adapter_css ) {
	$stripped = preg_replace( '~/\*.*?\*/~s', '', (string) file_get_contents( $adapter_css ) );
	if ( preg_match_all( '/\{([^}]*)\}/s', $stripped, $blocks ) ) {
		foreach ( $blocks[1] as $decl_block ) {
			if ( preg_match( '/#[0-9a-fA-F]{3,8}\b/', $decl_block, $hex_match ) ) {
				$failures[] = basename( dirname( $adapter_css, 2 ) ) . '/' . basename( $adapter_css ) . ': raw hex in declaration (' . $hex_match[0] . ')';
				break;
			}
		}
	}
}
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: accent ink, scoped custom appearance and table ownership contracts\n";
