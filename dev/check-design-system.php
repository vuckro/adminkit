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
$profile = array( 'ui_font' => 'system', 'design_system' => array( 'type_scale' => 1.5, 'space_scale' => 2, 'radius_scale' => 0, 'elevation' => 'raised', 'colors' => array( 'light' => array( 'bg' => '#eef6ef', 'surface' => '#ffffff', 'text' => '#142b20', 'accent' => '#276044', 'border' => '#819d8b' ), 'dark' => array( 'bg' => '#11251b', 'surface' => '#183425', 'text' => '#f0fff4', 'accent' => '#9bdfb6', 'success-text' => '#aaddbb' ) ) ) );
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
// Generated neutrals remain readable even for saturated or extreme seed colors.
function ak_neutral_luminance( $hex ) {
 $rgb = sscanf( $hex, '#%02x%02x%02x' );
 foreach ( $rgb as &$c ) { $c /= 255; $c = $c <= .04045 ? $c / 12.92 : pow( ( $c + .055 ) / 1.055, 2.4 ); }
 return .2126 * $rgb[0] + .7152 * $rgb[1] + .0722 * $rgb[2];
}
$count = 0;
for ( $r = 0; $r <= 255; $r += 17 ) {
 for ( $g = 0; $g <= 255; $g += 17 ) {
  for ( $b = 0; $b <= 255; $b += 17 ) {
   $seed = sprintf( '#%02x%02x%02x', $r, $g, $b );
   foreach ( array( 'light', 'dark' ) as $mode ) {
    $colors = AdminKit_Design_System::custom_neutrals( $seed, $mode );
    foreach ( array( 'text', 'text-muted' ) as $ink ) {
     foreach ( array( 'bg', 'surface' ) as $background ) {
      $a = ak_neutral_luminance( $colors[ $ink ] ); $z = ak_neutral_luminance( $colors[ $background ] );
      ak_assert( ( max( $a, $z ) + .05 ) / ( min( $a, $z ) + .05 ) >= 4.5, 'Generated neutral contrast: ' . $seed );
     }
    }
   }
   $count++;
  }
 }
}
ak_assert( array() === AdminKit_Design_System::custom_neutrals( 'url(evil)', 'light' ), 'Invalid automatic palette seed' );
$profile = array( 'accent_source' => 'custom', 'brand_accent' => '#b64720', 'design_system' => array( 'colors' => array( 'light' => array( 'bg' => '#abcdef' ) ) ) );
$automatic = AdminKit_Design_System::css();
ak_assert( false !== strpos( $automatic, '--ak-bg:#abcdef;' ), 'Manual roles override generated neutrals' );
$profile['design_system'] = array();
$fixtures['auto'] = AdminKit_Design_System::css();
$fixtures['neutralRecipe'] = AdminKit_Design_System::neutral_recipe();
$fixtures['neutralExpected'] = AdminKit_Design_System::custom_neutrals( '#b64720', 'light' );
echo "OK: $count automatic neutral palettes pass light/dark text contrast; explicit overrides preserved\n";
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
