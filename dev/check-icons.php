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

// Both editors share one strict contract, including legacy raw/encoded/base64 inputs.
$valid='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 2L3 4" fill="none" stroke="currentColor"/></svg>';
foreach(array($valid,'data:image/svg+xml,'.rawurlencode($valid),'data:image/svg+xml;base64,'.base64_encode($valid)) as $input){
 $clean=AdminKit_Icons::sanitize_icon($input);
 if(strpos($clean,'data:image/svg+xml,')!==0||strpos(AdminKit_Icons::icon_mask($input),'mask:')===false)throw new RuntimeException('Safe SVG rejected');
}
foreach(array('<script/>','<foreignObject/>','<image href="https://x.test"/>','<path onload="alert(1)"/>','<path style="fill:red"/>','<path fill="url(https://x.test)"/>','<use href="#x"/>','<animate/>')as$markup){
 $svg='<svg xmlns="http://www.w3.org/2000/svg">'.$markup.'</svg>';
 foreach(array($svg,'data:image/svg+xml,'.rawurlencode($svg),'data:image/svg+xml;base64,'.base64_encode($svg))as$input){
  if(AdminKit_Icons::sanitize_icon($input)!==''||AdminKit_Icons::icon_mask($input)!=='')throw new RuntimeException('Unsafe SVG accepted: '.$markup);
 }
}
foreach(array('data:image/svg+xml,</style><script>x</script>','<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>','data:image/svg+xml;base64,???','<svg><path></svg>',str_repeat('x',16385))as$input){if(AdminKit_Icons::sanitize_icon($input)!=='')throw new RuntimeException('Malformed/oversized icon accepted');}
foreach(AdminKit_Icons::names()as$name){if(AdminKit_Icons::validated_svg(AdminKit_Icons::svg($name))==='')throw new RuntimeException('Bundled SVG incompatible with shared validator: '.$name);}
echo "OK: shared SVG canonicalization, legacy encodings, strict rejection and all bundled vectors.\n";
