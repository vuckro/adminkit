<?php
/**
 * WordPress.org Plugin Directory compliance, GDPR font privacy & Blueprint checks.
 *
 * Verifies:
 *   1. `readme.txt` documents `== External services ==` (Google Fonts + mShots) and
 *      `== Credits ==` (Geist OFL 1.1, Lucide ISC/MIT, Heroicons MIT).
 *   2. Zero non-GPLv2 `Streamline` / `CC BY` references in `inc/` or `assets/`.
 *   3. Guideline 11: `assets/css/wp-screens/plugins.css` never hides third-party
 *      plugin action/meta links (`elu.to`, `sponsors`, `Check Update`, `data-plugin=`).
 *   4. `.wordpress-org/blueprints/blueprint.json` installs from `wordpress.org/plugins`
 *      and `dev/playground-blueprint.json` is valid JSON.
 *   5. GDPR: `remote_fonts_enabled` defaults to `false`, legacy `'classic'` maps to
 *      local `'geist'`, and `AdminKit_Design_System::css()` falls back Google Fonts
 *      to Geist when `remote_fonts_enabled` is `false` or on `wp-login.php`.
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ADMINKIT_PATH', dirname( __DIR__ ) . '/' );

$root = dirname( __DIR__ );
chdir( $root );

function ak_compliance_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Compliance check failed: ' . $message );
	}
}

// 1. readme.txt sections & credits.
$readme = (string) file_get_contents( 'readme.txt' );
ak_compliance_assert( false !== strpos( $readme, '== External services ==' ), 'readme.txt must include == External services ==' );
ak_compliance_assert( false !== strpos( $readme, 'fonts.googleapis.com' ) && false !== strpos( $readme, 's0.wordpress.com/mshots/v1/' ), 'readme.txt must disclose Google Fonts and mShots endpoints' );
ak_compliance_assert( false !== strpos( $readme, '== Credits ==' ), 'readme.txt must include == Credits ==' );
foreach ( array( 'Geist', 'SIL Open Font License', 'Lucide', 'Heroicons' ) as $credit ) {
	ak_compliance_assert( false !== strpos( $readme, $credit ), 'readme.txt missing credit: ' . $credit );
}

// 2. Zero CC-BY / Streamline references in runtime files.
foreach ( array( 'inc', 'assets' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! in_array( $file->getExtension(), array( 'php', 'js', 'css', 'svg' ), true ) ) {
			continue;
		}
		$text = (string) file_get_contents( $file->getPathname() );
		ak_compliance_assert(
			! preg_match( '/Streamline|CC[- ]BY/i', $text ),
			'Non-GPLv2 asset attribution in ' . $file->getPathname()
		);
	}
}

// 3. Guideline 11 — never hide third-party plugin links in plugins.css.
$plugins_css = (string) file_get_contents( 'assets/css/wp-screens/plugins.css' );
ak_compliance_assert(
	! preg_match( '/elu\.to|sponsors\/johnbillion|Check Update|data-plugin=/i', $plugins_css ),
	'plugins.css must not hide third-party plugin row links (WP.org Guideline 11)'
);

// 4. Blueprints validity.
$wporg_bp = json_decode( (string) file_get_contents( '.wordpress-org/blueprints/blueprint.json' ), true, 512, JSON_THROW_ON_ERROR );
ak_compliance_assert(
	isset( $wporg_bp['steps'][0]['pluginData']['resource'], $wporg_bp['steps'][0]['pluginData']['slug'] )
	&& 'wordpress.org/plugins' === $wporg_bp['steps'][0]['pluginData']['resource']
	&& 'adminkit' === $wporg_bp['steps'][0]['pluginData']['slug'],
	'.wordpress-org/blueprints/blueprint.json must target wordpress.org/plugins slug "adminkit"'
);
$dev_bp = json_decode( (string) file_get_contents( 'dev/playground-blueprint.json' ), true, 512, JSON_THROW_ON_ERROR );
ak_compliance_assert( is_array( $dev_bp ) && ! empty( $dev_bp['steps'] ), 'dev/playground-blueprint.json must be valid Blueprint JSON' );

// 5. GDPR Google Fonts runtime behavior.
$mock_options = array();
function get_option( $key, $default = false ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}
function update_option( $key, $val, $autoload = null ) {
	global $mock_options;
	$mock_options[ $key ] = $val;
	return true;
}
function apply_filters( $tag, $value ) {
	return $value;
}
function do_action( $tag ) {}
function rest_sanitize_boolean( $val ) {
	return (bool) $val;
}
function sanitize_hex_color( $color ) {
	return preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', (string) $color ) ? $color : '';
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}
function add_action( $tag, $cb, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) {}
function __( $text, $domain = 'default' ) {
	return $text;
}

require_once ADMINKIT_PATH . 'inc/class-plugin.php';
require_once ADMINKIT_PATH . 'inc/class-assets.php';
require_once ADMINKIT_PATH . 'inc/class-design-system.php';
require_once ADMINKIT_PATH . 'inc/integrations/abstract-integration.php';
require_once ADMINKIT_PATH . 'inc/settings/class-settings-catalog.php';
require_once ADMINKIT_PATH . 'inc/settings/class-settings.php';
AdminKit_Settings::init();

ak_compliance_assert( false === AdminKit_Settings::get( 'remote_fonts_enabled' ), 'remote_fonts_enabled must default to false' );

$mock_options['adminkit_settings'] = array( 'ui_font' => 'classic' );
ak_compliance_assert( 'geist' === AdminKit_Settings::get( 'ui_font' ), 'legacy "classic" font must resolve to local "geist", not remote Google Font' );

$mock_options['adminkit_settings'] = array(
	'ui_font'              => 'inter',
	'remote_fonts_enabled' => false,
);
$css_off = AdminKit_Design_System::css();
ak_compliance_assert( false === strpos( $css_off, '"Inter"' ) && false !== strpos( $css_off, '"Geist"' ), 'Google Font must fall back to Geist when remote_fonts_enabled is false' );

$mock_options['adminkit_settings'] = array(
	'ui_font'              => 'inter',
	'remote_fonts_enabled' => true,
);
$css_on = AdminKit_Design_System::css();
ak_compliance_assert( false !== strpos( $css_on, '"Inter"' ), 'Google Font stack activates once remote_fonts_enabled is true' );

$GLOBALS['pagenow'] = 'wp-login.php';
$css_login          = AdminKit_Design_System::css();
ak_compliance_assert( false === strpos( $css_login, '"Inter"' ) && false !== strpos( $css_login, '"Geist"' ), 'wp-login.php must never reference remote Google Fonts' );

echo "OK: WP.org compliance, GPLv2 credits, Guideline 11, Blueprints, and GDPR Google Font opt-in verified.\n";
