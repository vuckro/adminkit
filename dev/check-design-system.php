<?php
/** Regression coverage for shared runtime foundations. Run: php dev/check-design-system.php. */
define( 'ABSPATH', __DIR__ . '/' );
$profile = array();
function get_option( $key, $default = null ) { global $profile; return 'adminkit_settings' === $key ? $profile : $default; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_hex_color( $value ) { return preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $value ) ? $value : null; }
require dirname( __DIR__ ) . '/inc/settings/class-settings.php';
require dirname( __DIR__ ) . '/inc/class-assets.php';
require dirname( __DIR__ ) . '/inc/class-design-system.php';
function ak_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$base = AdminKit_Design_System::css();
ak_assert( false !== strpos( $base, '--ak-type-scale:1;' ), 'Default type scale' );
ak_assert( false === strpos( $base, '--ak-design-elevation:' ), 'Default preserves theme contact elevation' );
foreach ( array( 'flat' => 'none', 'subtle' => 'var(--wk-elevation-1)', 'raised' => 'var(--wk-elevation-2)' ) as $choice => $expected ) {
 $profile = array( 'design_system' => array( 'elevation' => $choice, 'border_width' => 2 ) );
 $css = AdminKit_Design_System::css();
 ak_assert( false !== strpos( $css, '--ak-design-elevation:' . $expected . ';' ), 'Saved elevation reaches card/table role' );
 ak_assert( false !== strpos( $css, '--ak-border-width:2px;' ), 'Saved outline width reaches runtime' );
 ak_assert( false === strpos( $css, '--ak-shadow-subtle:' ), 'Surface elevation must not alter control shadows' );
}
// A borderless profile must survive persistence and compilation, including string 0.
foreach ( array( 0, 1, 2, '0', '1', '2' ) as $width ) {
 $profile = array( 'design_system' => array( 'border_width' => $width, 'radius_scale' => 0, 'components' => array( 'buttons' => array( 'highlight' => 'off' ) ) ) );
 $clean = AdminKit_Settings::sanitize_design_system( $profile['design_system'] );
 ak_assert( (int) $width === $clean['border_width'], 'Outline width survives sanitization, including zero' );
 $css = AdminKit_Design_System::css();
 ak_assert( false !== strpos( $css, '--ak-border-width:' . (int) $width . 'px;' ), 'Outline width reaches the shared runtime' );
 ak_assert( false === strpos( $css, '--ak-focus-ring:' ), 'Decorative border choice must not remove keyboard focus' );
}
foreach ( array( -1, 3, 4, 5, 1.5, true, array(), '0px' ) as $invalid_width ) {
 $clean = AdminKit_Settings::sanitize_design_system( array( 'border_width' => $invalid_width ) );
 ak_assert( 1 === $clean['border_width'], 'Unsupported outline widths fall back to one pixel' );
}
// Component choices survive sanitization, old profiles and malformed input.
foreach ( array( 'off', 'hover', 'always' ) as $choice ) {
 $profile = array( 'design_system' => array( 'components' => array( 'buttons' => array( 'highlight' => $choice ) ) ) );
 $clean = AdminKit_Settings::sanitize_design_system( $profile['design_system'] );
 ak_assert( $choice === $clean['components']['buttons']['highlight'], 'Component mode survives save sanitization' );
 $css = AdminKit_Design_System::css();
 $effect = 'var(--ak-btn-highlight-effect)';
 $empty = 'inset 0 0 0 transparent';
 ak_assert( false !== strpos( $css, '--ak-btn-primary-highlight:' . ( 'always' === $choice ? $effect : $empty ) . ';' ), 'Rest state matches selected highlight mode' );
 ak_assert( false !== strpos( $css, '--ak-btn-primary-highlight-hover:' . ( 'off' === $choice ? $empty : $effect ) . ';' ), 'Hover state matches selected highlight mode' );
}
foreach ( array( array(), array( 'components' => 'invalid' ), array( 'components' => array( 'buttons' => array( 'highlight' => array( 'off' ), 'unknown' => 'x' ) ) ) ) as $input ) {
 $clean = AdminKit_Settings::sanitize_design_system( $input );
 ak_assert( array( 'buttons' => array( 'highlight' => 'always' ) ) === $clean['components'], 'Legacy and malformed component values retain compatible defaults' );
}
$profile = array( 'ui_font' => 'system', 'design_system' => array( 'type_scale' => 1.5, 'radius_scale' => 0, 'elevation' => 'raised', 'colors' => array( 'light' => array( 'bg' => '#eef6ef', 'surface' => '#ffffff', 'text' => '#142b20', 'accent' => '#276044', 'border' => '#819d8b' ), 'dark' => array( 'bg' => '#11251b', 'surface' => '#183425', 'text' => '#f0fff4', 'accent' => '#9bdfb6', 'success-text' => '#aaddbb' ) ) ) );
$custom = AdminKit_Design_System::css();
ak_assert( false !== strpos( $custom, '--ak-radius-scale:0;' ), 'Zero radius must survive' );
ak_assert( false !== strpos( $custom, '--ak-type-scale:1.5;' ), 'Continuous scales must reach runtime' );
ak_assert( false !== strpos( $custom, '--ak-bg:#eef6ef;' ) && false !== strpos( $custom, '--ak-bg:#11251b;' ), 'Both palettes must ship' );
ak_assert( false !== strpos( $custom, '--ak-primary-rgb:39,96,68;' ), 'Legacy RGB consumers follow palette' );
ak_assert( false !== strpos( $custom, '--ak-on-accent:#ffffff;' ), 'Automatic contrasting foreground' );
ak_assert( false !== strpos( $custom, '--ak-primary-hover:' ) && false !== strpos( $custom, '--wk-accent-hover:' ), 'Derived interactive aliases' );
ak_assert( false !== strpos( $custom, '--ak-success-subtle:' ), 'Status tint follows status ink' );
$toolbar = AdminKit_Design_System::css( true );
foreach ( explode( '}', $toolbar ) as $rule ) {
 if ( ! $rule ) { continue; }
 $selector = strstr( $rule, '{', true );
 foreach ( explode( ',', $selector ) as $arm ) { ak_assert( false !== strpos( $arm, '#wpadminbar' ), 'Frontend CSS escaped toolbar' ); }
}
ak_assert( false !== strpos( $custom, '[data-adminkit-theme="dark"][data-adminkit-accent]' ), 'Palette overrides outrank explicit accent presets in dark mode' );
$fixtures = array( 'base' => $base, 'custom' => $custom, 'toolbar' => $toolbar );
$profile['design_system'] = array( 'type_scale' => INF, 'space_scale' => -1, 'radius_scale' => 999, 'colors' => array( 'light' => array( 'text' => '#fff;}</style><script>', 'unknown' => '#fff' ) ) );
$invalid = AdminKit_Design_System::css();
ak_assert( false === strpos( $invalid, '<script>' ) && false === strpos( $invalid, '--ak-unknown' ), 'CSS injection rejected' );
ak_assert( false !== strpos( $invalid, '--ak-type-scale:1;' ), 'Invalid scales use defaults' );
$profile = array( 'accent_source' => 'custom', 'brand_accent' => '#e31c1c' );
$neutral = AdminKit_Design_System::css();
ak_assert( false === strpos( $neutral, '--ak-bg:' ) && false === strpos( $neutral, '--ak-text:' ), 'Custom accent must leave neutrals untouched' );
if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( $fixtures, JSON_PRETTY_PRINT ) ); }
echo "OK: runtime palettes, scales, contrast aliases, toolbar containment and invalid input\n";

// Text sizes must not silently become fixed again in an owned component.
foreach ( array( '/assets/css', '/inc/integrations' ) as $directory ) {
 $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . $directory, FilesystemIterator::SKIP_DOTS ) );
 foreach ( $files as $file ) {
  if ( 'css' !== $file->getExtension() ) { continue; }
  $css = preg_replace( '~/\*.*?\*/~s', '', file_get_contents( $file->getPathname() ) );
  ak_assert( ! preg_match( '/(?<![-\w])font-size\s*:\s*[0-9.]+(?:px|rem)\b/', $css ), 'Fixed text size: ' . $file->getPathname() );
 }
}
echo "OK: owned stylesheet text sizes follow the shared scale\n";
