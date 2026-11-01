<?php
/**
 * Regression check: SVG logo canonicalisation, UTF-16/XSLT rejection, sideload coverage,
 * Multisite unfiltered_html requirement and scoped upload_mimes.
 *
 * Run: php dev/check-brand-logo.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$caps    = array( 'manage_options' => true, 'unfiltered_html' => true );
$filters = array();

function current_user_can( $cap ) {
	global $caps;
	return ! empty( $caps[ $cap ] );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function add_filter( $hook, $cb ) {
	global $filters;
	$filters[ $hook ][] = $cb;
}

function add_action( $hook, $cb, $prio = 10 ) {}

function __( $s, $d = 'adminkit' ) {
	return $s;
}

function wp_verify_nonce( $nonce, $action ) {
	return 'valid-logo-nonce' === $nonce && 'adminkit_logo_upload' === $action;
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

require dirname( __DIR__ ) . '/inc/wp-core/class-branding.php';

function assert_svg( $cond, $msg ) {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

AdminKit_Core_Branding::init();
assert_svg( isset( $filters['wp_handle_upload_prefilter'] ) && isset( $filters['wp_handle_sideload_prefilter'] ), 'Both upload and sideload prefilters must be registered' );

// 1. Valid monochrome and Figma-style gradient SVGs pass.
$valid = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" fill="currentColor"><rect width="10" height="10"/><path fill="var(--wk-logo-inverse, #fff)" d="M1 1L2 2"/></svg>';
assert_svg( AdminKit_Core_Branding::safe_logo_svg( $valid ), 'Valid monochrome SVG rejected' );

$valid_gradient = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="brand-g1"><stop offset="0%" stop-color="#111"/><stop offset="100%" stop-color="#555"/></linearGradient><clipPath id="clip_1"><rect width="24" height="24"/></clipPath></defs><g clip-path="url(#clip_1)"><rect width="24" height="24" fill="url(#brand-g1)"/></g></svg>';
assert_svg( AdminKit_Core_Branding::safe_logo_svg( $valid_gradient ), 'Valid local-gradient SVG rejected' );

// 2. Unsafe payloads (XSS, external URLs, XSLT PI, UTF-16 BOM, DTD) are rejected.
foreach ( array(
	'<script/>',
	'<image href="https://example.com/x"/>',
	'<path onload="x"/>',
	'<path fill="url(https://example.com/x)"/>',
	'<path fill="url(#bad id)"/>',
	'<foreignObject/>',
	'<?xml-stylesheet href="https://example.com/x"?>',
) as $unsafe ) {
	assert_svg( ! AdminKit_Core_Branding::safe_logo_svg( '<svg xmlns="http://www.w3.org/2000/svg">' . $unsafe . '</svg>' ), 'Unsafe SVG accepted: ' . $unsafe );
}

$utf16_xslt = "\xFF\xFE" . iconv( 'UTF-8', 'UTF-16LE', '<?xml version="1.0" encoding="UTF-16"?><?xml-stylesheet type="text/xsl" href="https://evil.test/x.xsl"?><svg xmlns="http://www.w3.org/2000/svg"/>' );
assert_svg( ! AdminKit_Core_Branding::safe_logo_svg( $utf16_xslt ), 'UTF-16 XSLT payload accepted' );

$top_pi = '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="x.xsl"?><svg xmlns="http://www.w3.org/2000/svg"/>';
assert_svg( ! AdminKit_Core_Branding::safe_logo_svg( $top_pi ), 'Top-level XML processing instruction accepted' );

// 3. upload_mimes is scoped to options-general.php or signed Branding uploads, and requires unfiltered_html.
$pagenow = 'upload.php';
$_REQUEST = array();
assert_svg( array() === AdminKit_Core_Branding::logo_upload_mimes( array() ), 'Global upload.php leaked SVG MIME' );

$pagenow = 'options-general.php';
assert_svg( isset( AdminKit_Core_Branding::logo_upload_mimes( array() )['svg'] ), 'options-general.php denied SVG MIME to super/single-site admin' );

$caps['unfiltered_html'] = false; // Multisite site admin without unfiltered_html.
assert_svg( array() === AdminKit_Core_Branding::logo_upload_mimes( array() ), 'Multisite admin without unfiltered_html granted SVG MIME' );
$caps['unfiltered_html'] = true;

// 4. validate_logo_upload rewrites tmp_name with canonical XML (stripping comments/whitespace).
$pagenow  = 'async-upload.php';
$_REQUEST = array( 'adminkit_logo_upload' => '1', '_ak_logo_nonce' => 'valid-logo-nonce' );
$tmp      = tempnam( sys_get_temp_dir(), 'aksvg' );
file_put_contents( $tmp, "   \n" . $valid . "\n   " );
$checked = AdminKit_Core_Branding::validate_logo_upload(
	array(
		'name'     => 'logo.svg',
		'tmp_name' => $tmp,
		'size'     => filesize( $tmp ),
	)
);
$rewritten = (string) file_get_contents( $tmp );
unlink( $tmp );
assert_svg( empty( $checked['error'] ) && 0 === strpos( $rewritten, '<svg' ), 'Valid SVG upload failed or was not canonicalised on disk' );

echo "OK: vector SVG canonicalised; UTF-16/XSLT, scripts, sideload bypass and Multisite escalation blocked\n";
