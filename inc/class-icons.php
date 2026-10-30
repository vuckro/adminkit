<?php
/**
 * Lucide outline icons, bundled locally under ISC/MIT. See assets/icons/LUCIDE-LICENSE.
 * Stable role aliases preserve saved menu layouts. No remote assets or runtime JS.
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Icons {
	public static function names() { return array_keys( self::paths() ); }
	public static function has( $name ) { return is_string( $name ) && isset( self::paths()[ $name ] ); }
	public static function svg( $name ) { return self::render( $name, '#000' ); }
	public static function display_svg( $name ) { return self::render( $name, 'currentColor' ); }
	private static function render( $name, $stroke ) {
		$paths = self::paths();
		if ( ! is_string( $name ) || ! isset( $paths[ $name ] ) ) { return ''; }
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="' . $stroke . '" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
	public static function set() {
		$out = array();
		foreach ( self::names() as $name ) { $out[ $name ] = self::display_svg( $name ); }
		return $out;
	}
	public static function mask( $svg ) {
		$uri = 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';
		return 'background-color:currentColor;-webkit-mask:' . $uri . ' center/20px 20px no-repeat;mask:' . $uri . ' center/20px 20px no-repeat;';
	}
	private static function paths() {
		static $paths = null;
		if ( null !== $paths ) { return $paths; }
		$catalog = json_decode( (string) file_get_contents( ADMINKIT_PATH . 'assets/icons/lucide.json' ), true );
		if ( ! is_array( $catalog ) ) { $paths = array(); return $paths; }
		$aliases = array(
			'home' => 'house',
			'pencil-square' => 'square-pen',
			'document-pencil' => 'file-pen-line',
			'article' => 'newspaper',
			'photo' => 'image',
			'document' => 'file-text',
			'chat' => 'message-square',
			'plugin' => 'plug',
			'cog' => 'settings',
			'swatch' => 'swatch-book',
			'update' => 'refresh-cw',
			'cart' => 'shopping-cart',
			'chart' => 'chart-no-axes-combined',
			'email' => 'mail',
			'bolt' => 'zap',
			'money' => 'banknote',
			'forms' => 'list-checks',
			'feedback' => 'messages-square',
			'translation' => 'languages',
			'id' => 'contact-round',
			'location' => 'map-pin',
			'gallery' => 'images',
			'app' => 'layout-dashboard',
			'sliders' => 'sliders-horizontal',
			'edit' => 'square-pen',
			'page' => 'file',
			'external' => 'external-link',
			'post' => 'newspaper',
			'comment' => 'message-square',
			'alert' => 'triangle-alert',
			'appearance' => 'palette',
			'tools' => 'wrench',
		);
		$paths = array();
		foreach ( $aliases as $role => $name ) {
			if ( isset( $catalog[ $name ] ) ) { $paths[ $role ] = $catalog[ $name ]; }
		}
		$paths += $catalog;
		return $paths;
	}
}
