<?php
/**
 * Lucide outline icons, bundled locally under ISC/MIT. See assets/icons/LUCIDE-LICENSE.
 * Stable role aliases preserve saved menu layouts. No remote assets or runtime JS.
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Icons {
	/**
	 * Core WordPress admin-menu dashicon class → Unicode codepoint, used by
	 * `AdminKit_Menu_Renderer` to restore a native glyph without `!important`
	 * when an item is explicitly pinned back to `wp-default`.
	 *
	 * @var array<string,string>
	 */
	const MENU_DASHICON_GLYPHS = array(
		'dashicons-dashboard'        => '\\f226',
		'dashicons-admin-home'       => '\\f226',
		'dashicons-admin-post'       => '\\f109',
		'dashicons-admin-media'      => '\\f104',
		'dashicons-admin-page'       => '\\f105',
		'dashicons-admin-comments'   => '\\f101',
		'dashicons-admin-appearance' => '\\f100',
		'dashicons-admin-plugins'    => '\\f106',
		'dashicons-admin-users'      => '\\f110',
		'dashicons-admin-tools'      => '\\f107',
		'dashicons-admin-settings'   => '\\f108',
		'dashicons-admin-customizer' => '\\f540',
		'dashicons-admin-network'    => '\\f112',
		'dashicons-admin-site'       => '\\f319',
		'dashicons-admin-site-alt3'  => '\\f524',
	);

	/**
	 * Core WordPress admin-bar node id → native dashicon codepoint + paint target
	 * (`ab-icon`, `ab-item`, or `search`), used by `AdminKit_Toolbar_Renderer` when
	 * a toolbar node is explicitly pinned back to `wp-default`.
	 *
	 * @var array<string,array{glyph:string,target:string}>
	 */
	const TOOLBAR_DASHICON_GLYPHS = array(
		'wp-logo'         => array( 'glyph' => '\\f120', 'target' => 'ab-icon' ),
		'site-name'       => array( 'glyph' => '\\f226', 'target' => 'ab-item' ),
		'site-editor'     => array( 'glyph' => '\\f100', 'target' => 'ab-item' ),
		'updates'         => array( 'glyph' => '\\f463', 'target' => 'ab-icon' ),
		'comments'        => array( 'glyph' => '\\f101', 'target' => 'ab-icon' ),
		'new-content'     => array( 'glyph' => '\\f132', 'target' => 'ab-icon' ),
		'edit'            => array( 'glyph' => '\\f464', 'target' => 'ab-item' ),
		'customize'       => array( 'glyph' => '\\f540', 'target' => 'ab-item' ),
		'command-palette' => array( 'glyph' => '\\f179', 'target' => 'ab-icon' ),
		'search'          => array( 'glyph' => '\\f179', 'target' => 'search' ),
		'menu-toggle'     => array( 'glyph' => '\\f333', 'target' => 'ab-icon' ),
	);

	const WP_DASHICONS = array(
		'dashicons-admin-generic', 'dashicons-admin-links', 'dashicons-admin-multisite',
		'dashicons-menu', 'dashicons-wordpress', 'dashicons-pressthis', 'dashicons-update', 'dashicons-info',
		'dashicons-welcome-write-blog', 'dashicons-welcome-add-page', 'dashicons-welcome-view-site',
		'dashicons-welcome-widgets-menus', 'dashicons-welcome-comments', 'dashicons-welcome-learn-more',
		'dashicons-format-aside', 'dashicons-format-image', 'dashicons-format-video', 'dashicons-format-status',
		'dashicons-format-quote', 'dashicons-format-chat', 'dashicons-format-audio',
		'dashicons-camera', 'dashicons-images-alt', 'dashicons-video-alt3',
		'dashicons-media-document', 'dashicons-media-spreadsheet', 'dashicons-media-interactive',
		'dashicons-media-text', 'dashicons-media-archive', 'dashicons-media-code', 'dashicons-media-audio',
		'dashicons-media-video', 'dashicons-media-default',
		'dashicons-edit', 'dashicons-trash', 'dashicons-sticky', 'dashicons-external',
		'dashicons-visibility', 'dashicons-hidden', 'dashicons-post-status',
		'dashicons-arrow-up-alt', 'dashicons-arrow-down-alt', 'dashicons-arrow-left-alt', 'dashicons-arrow-right-alt',
		'dashicons-leftright', 'dashicons-sort', 'dashicons-randomize',
		'dashicons-excerpt-view', 'dashicons-grid-view', 'dashicons-move',
		'dashicons-hammer', 'dashicons-migrate', 'dashicons-universal-access', 'dashicons-tickets',
		'dashicons-nametag', 'dashicons-clipboard', 'dashicons-heart', 'dashicons-schedule',
		'dashicons-businesswoman', 'dashicons-testimonial', 'dashicons-portfolio', 'dashicons-awards',
		'dashicons-archive', 'dashicons-tagcloud', 'dashicons-category', 'dashicons-bell',
		'dashicons-yes', 'dashicons-yes-alt', 'dashicons-no', 'dashicons-no-alt',
		'dashicons-plus', 'dashicons-plus-alt', 'dashicons-minus', 'dashicons-dismiss', 'dashicons-marker', 'dashicons-flag',
		'dashicons-star-filled', 'dashicons-star-half', 'dashicons-star-empty',
		'dashicons-location-alt', 'dashicons-vault', 'dashicons-sos', 'dashicons-slides',
		'dashicons-download', 'dashicons-upload', 'dashicons-backup', 'dashicons-clock', 'dashicons-lightbulb', 'dashicons-microphone',
		'dashicons-desktop', 'dashicons-laptop', 'dashicons-tablet', 'dashicons-smartphone', 'dashicons-phone', 'dashicons-smiley',
		'dashicons-palmtree', 'dashicons-album', 'dashicons-index-card', 'dashicons-carrot', 'dashicons-building',
	);
	const ALIASES = array(
		'home'            => 'house',
		'pencil-square'   => 'square-pen',
		'document-pencil' => 'file-pen-line',
		'article'         => 'newspaper',
		'photo'           => 'image',
		'document'        => 'file-text',
		'chat'            => 'message-square',
		'plugin'          => 'plug',
		'cog'             => 'settings',
		'swatch'          => 'swatch-book',
		'update'          => 'refresh-cw',
		'cart'            => 'shopping-cart',
		'chart'           => 'chart-no-axes-combined',
		'email'           => 'mail',
		'bolt'            => 'zap',
		'money'           => 'banknote',
		'forms'           => 'list-checks',
		'feedback'        => 'messages-square',
		'translation'     => 'languages',
		'id'              => 'contact-round',
		'location'        => 'map-pin',
		'gallery'         => 'images',
		'app'             => 'layout-dashboard',
		'sliders'         => 'sliders-horizontal',
		'edit'            => 'square-pen',
		'page'            => 'file',
		'external'        => 'external-link',
		'post'            => 'newspaper',
		'comment'         => 'message-square',
		'alert'           => 'triangle-alert',
		'appearance'      => 'palette',
		'tools'           => 'wrench',
	);

	/** Shared menu/toolbar input contract, including canonicalized safe SVG masks. */
	public static function sanitize_icon( $icon ) {
		static $cache = array();
		if ( ! is_string( $icon ) || strlen( $icon ) > 16384 ) { return ''; }
		$icon = trim( $icon );
		if ( 'wp-default' === $icon || self::has( $icon ) || in_array( $icon, self::WP_DASHICONS, true ) ) { return $icon; }
		if ( isset( $cache[ $icon ] ) ) { return $cache[ $icon ]; }
		$svg = $icon;
		if ( preg_match( '~^data:image/svg\+xml(?:;charset=utf-8)?(;base64)?,(.*)$~is', $icon, $matches ) ) {
			$svg = ! empty( $matches[1] ) ? base64_decode( $matches[2], true ) : rawurldecode( $matches[2] );
		}
		$svg = self::validated_svg( $svg );
		$out = '';
		if ( '' !== $svg ) {
			$uri = 'data:image/svg+xml,' . rawurlencode( $svg );
			$out = strlen( $uri ) <= 16384 ? $uri : '';
		}
		if ( count( $cache ) < 256 ) {
			$cache[ $icon ] = $out;
		}
		return $out;
	}

	/** Revalidate legacy stored values too; raw data URIs never reach inline CSS. */
	public static function icon_mask( $icon ) {
		$icon = self::sanitize_icon( $icon );
		if ( 0 === strpos( $icon, 'data:image/svg+xml,' ) ) {
			$uri = 'url("' . $icon . '")';
			return 'background-color:currentColor;-webkit-mask:' . $uri . ' center/20px 20px no-repeat;mask:' . $uri . ' center/20px 20px no-repeat;';
		}
		$svg = self::svg( $icon );
		return '' === $svg ? '' : self::mask( $svg );
	}

	/** Only local vector geometry and presentation attributes; no XML entities or CSS. */
	public static function validated_svg( $svg ) {
		if ( ! is_string( $svg ) || strlen( $svg ) > 16384 || ! class_exists( 'DOMDocument' )
			|| ! preg_match( '/^\s*<svg\b/i', $svg ) || preg_match( '/<!|<\?/', $svg ) ) { return ''; }
		$previous = libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$loaded = $doc->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || $doc->doctype || ! $doc->documentElement || 'svg' !== $doc->documentElement->nodeName ) { return ''; }
		$elements = array( 'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon' );
		$attributes = array( 'viewBox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'fill-opacity', 'stroke-opacity', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'points', 'transform', 'fill-rule', 'clip-rule', 'vector-effect', 'aria-hidden', 'focusable', 'role' );
		foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
			if ( ! in_array( $node->nodeName, $elements, true ) || ( $node->namespaceURI && 'http://www.w3.org/2000/svg' !== $node->namespaceURI ) ) { return ''; }
			foreach ( $node->attributes as $attribute ) {
				if ( 'xmlns' === $attribute->nodeName && 'http://www.w3.org/2000/svg' === $attribute->value ) { continue; }
				if ( ! in_array( $attribute->nodeName, $attributes, true )
					|| preg_match( '/url\s*\(|(?:https?|data|javascript):|[\\\\<>;&"\']/i', $attribute->value ) ) { return ''; }
			}
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE !== $child->nodeType && ( XML_TEXT_NODE !== $child->nodeType || '' !== trim( $child->textContent ) ) ) { return ''; }
			}
		}
		return $doc->saveXML( $doc->documentElement );
	}

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
	/** Return display SVGs only for the requested icon names (plus `'app'` fallback). */
	public static function subset( array $names ) {
		$out   = array();
		$names = array_unique( array_merge( array( 'app' ), $names ) );
		foreach ( $names as $name ) {
			if ( is_string( $name ) && '' !== $name && self::has( $name ) ) {
				$out[ $name ] = self::display_svg( $name );
			}
		}
		return $out;
	}
	public static function mask( $svg ) {
		$uri = 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';
		return 'background-color:currentColor;-webkit-mask:' . $uri . ' center/20px 20px no-repeat;mask:' . $uri . ' center/20px 20px no-repeat;';
	}
	private static function paths() {
		if ( ! class_exists( 'AdminKit_Lucide_Paths', false ) ) {
			require_once ADMINKIT_PATH . 'inc/generated/lucide-paths.php';
		}
		return AdminKit_Lucide_Paths::MAP;
	}
}
