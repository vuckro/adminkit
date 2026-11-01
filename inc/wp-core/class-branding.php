<?php
/**
 * Automatic toolbar identity: wordmark when expanded, site icon when compact.
 * Without a wordmark, use the site icon and title; WordPress is the last fallback.
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Branding {

	/**
	 * Marker class added to the #wp-admin-bar-site-name node when we inject the
	 * brand <img> — so the CSS that hides the title text and sizes the logo targets
	 * ONLY the branded `logo` state, leaving the favicon-chip / bare-title states
	 * untouched.
	 */
	const LOGO_NODE_CLASS = 'ak-has-brand-logo';

	/**
	 * Wire the hooks. The brand mark runs in BOTH wp-admin and on the front end
	 * (logged-in users see the toolbar there too).
	 *
	 * The `admin_bar_menu` hook (priority 80, after core registers the site-name
	 * node at 10) injects the brand <img> into that node for the `logo` mode; the
	 * CSS — the wp-logo hide, the favicon chip, the <img> sizing — is printed on
	 * admin_head/wp_head.
	 *
	 * @return void
	 */
	const LOGO_UPLOAD_NONCE = 'adminkit_logo_upload';

	public static function init() {
		add_filter( 'upload_mimes', array( __CLASS__, 'logo_upload_mimes' ) );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'validate_logo_upload' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( __CLASS__, 'validate_logo_upload' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'inject_brand_logo' ), 80 );
		add_action( 'admin_head', array( __CLASS__, 'print_admin' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_frontend' ), 20 );
	}

	/** Whether the current user and request context may upload a Branding SVG logo. */
	private static function can_upload_logo_svg( $require_upload_nonce = false ) {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return false;
		}
		if ( class_exists( 'AdminKit_Settings' ) && ! AdminKit_Settings::get( 'branding_enabled' ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			return false;
		}
		if ( ! $require_upload_nonce ) {
			global $pagenow;
			if ( isset( $pagenow ) && 'options-general.php' === $pagenow ) {
				return true;
			}
		}
		if ( empty( $_REQUEST['adminkit_logo_upload'] ) || '1' !== (string) $_REQUEST['adminkit_logo_upload'] ) {
			return false;
		}
		$nonce = isset( $_REQUEST['_ak_logo_nonce'] ) && is_string( $_REQUEST['_ak_logo_nonce'] )
			? ( function_exists( 'wp_unslash' ) ? wp_unslash( $_REQUEST['_ak_logo_nonce'] ) : $_REQUEST['_ak_logo_nonce'] )
			: '';
		return '' !== $nonce && function_exists( 'wp_verify_nonce' ) && (bool) wp_verify_nonce( $nonce, self::LOGO_UPLOAD_NONCE );
	}

	/** SVG upload is scoped strictly to the Branding logo picker for admins with unfiltered_html. */
	public static function logo_upload_mimes( $mimes ) {
		if ( self::can_upload_logo_svg( false ) ) {
			$mimes['svg'] = 'image/svg+xml';
		}
		return $mimes;
	}

	public static function validate_logo_upload( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) ) {
			return $file;
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			return $file;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return $file;
		}
		// Only intercept when SVG was uploaded through our Branding flow or when no other plugin owns SVG.
		$is_branding_upload = ! empty( $_REQUEST['adminkit_logo_upload'] );
		if ( ! $is_branding_upload && ! self::can_upload_logo_svg( false ) ) {
			return $file;
		}
		if ( 'svgz' === $ext || ! self::can_upload_logo_svg( $is_branding_upload ) || ! isset( $file['size'], $file['tmp_name'] ) || $file['size'] > 262144 || ! is_readable( $file['tmp_name'] ) ) {
			$file['error'] = __( 'Use a simple SVG containing only vector shapes, without scripts or embedded content.', 'adminkit' );
			return $file;
		}
		$canonical = self::canonical_logo_svg( (string) file_get_contents( $file['tmp_name'] ) );
		if ( '' === $canonical || false === file_put_contents( $file['tmp_name'], $canonical ) ) {
			$file['error'] = __( 'Use a simple SVG containing only vector shapes, without scripts or embedded content.', 'adminkit' );
			return $file;
		}
		$file['size'] = strlen( $canonical );
		return $file;
	}

	/** Validate without ever rendering user-authored SVG markup inline. */
	public static function safe_logo_svg( $svg ) {
		return '' !== self::canonical_logo_svg( $svg );
	}

	/**
	 * Parse, strictly allowlist and re-serialize an SVG document to canonical UTF-8 XML.
	 * Rejects non-UTF-8 BOMs (UTF-16/UTF-32), DTDs, XML processing instructions (e.g. XSLT),
	 * scripts, event handlers and external URL references while allowing internal #id gradients/clips.
	 *
	 * @param mixed $svg Raw SVG bytes.
	 * @return string Canonicalized `<svg>...</svg>` XML, or '' when invalid/unsafe.
	 */
	public static function canonical_logo_svg( $svg ) {
		if ( ! is_string( $svg ) || '' === $svg || strlen( $svg ) > 262144 || ! class_exists( 'DOMDocument' ) ) {
			return '';
		}
		// Reject null bytes and UTF-16 / UTF-32 BOMs that bypass ASCII regexes.
		if ( false !== strpos( $svg, "\x00" ) || 0 === strncmp( $svg, "\xFF\xFE", 2 ) || 0 === strncmp( $svg, "\xFE\xFF", 2 ) ) {
			return '';
		}
		if ( preg_match( '/<!DOCTYPE|<!ENTITY|<\?(?!xml\s)/i', $svg ) ) {
			return '';
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || null !== $doc->doctype || ! $doc->documentElement || 'svg' !== $doc->documentElement->localName || 'http://www.w3.org/2000/svg' !== $doc->documentElement->namespaceURI ) {
			return '';
		}
		foreach ( $doc->childNodes as $top_node ) {
			if ( XML_ELEMENT_NODE !== $top_node->nodeType && XML_COMMENT_NODE !== $top_node->nodeType ) {
				return '';
			}
			if ( XML_COMMENT_NODE === $top_node->nodeType ) {
				return '';
			}
		}
		$tags       = array( 'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'polygon', 'polyline', 'line', 'defs', 'clipPath', 'linearGradient', 'radialGradient', 'stop', 'title', 'desc' );
		$attributes = array( 'id', 'viewBox', 'width', 'height', 'fill', 'fill-rule', 'clip-rule', 'clip-path', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'fill-opacity', 'stroke-opacity', 'stop-color', 'stop-opacity', 'offset', 'gradientUnits', 'gradientTransform', 'cx', 'cy', 'fx', 'fy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'd', 'points', 'transform', 'preserveAspectRatio', 'version' );
		$url_attrs  = array( 'fill', 'stroke', 'clip-path' );

		foreach ( $doc->getElementsByTagName( '*' ) as $element ) {
			if ( 'http://www.w3.org/2000/svg' !== $element->namespaceURI || ! in_array( $element->localName, $tags, true ) ) {
				return '';
			}
			foreach ( $element->attributes as $attr ) {
				if ( null !== $attr->namespaceURI && '' !== $attr->namespaceURI ) {
					return '';
				}
				if ( ! in_array( $attr->name, $attributes, true ) ) {
					return '';
				}
				$val = trim( (string) $attr->value );
				if ( 'id' === $attr->name && ! preg_match( '/^[a-zA-Z0-9_-]+$/', $val ) ) {
					return '';
				}
				if ( preg_match( '/(?:javascript|data|vbscript|https?|file):|@import|[<>\\\\]/i', $val ) ) {
					return '';
				}
				if ( preg_match( '/url\s*\(/i', $val ) ) {
					if ( ! in_array( $attr->name, $url_attrs, true ) || ! preg_match( '/^url\(\s*#[a-zA-Z0-9_-]+\s*\)$/i', $val ) ) {
						return '';
					}
				}
			}
			foreach ( $element->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType ) {
					continue;
				}
				if ( XML_TEXT_NODE === $child->nodeType ) {
					if ( '' !== trim( $child->textContent ) && ! in_array( $element->localName, array( 'title', 'desc' ), true ) ) {
						return '';
					}
					continue;
				}
				return '';
			}
		}
		$serialized = $doc->saveXML( $doc->documentElement );
		return is_string( $serialized ) ? $serialized : '';
	}

	/** Keep native toolbar navigation and accessible names while adding both marks. */
	public static function inject_brand_logo( $bar ) {
		if ( ! AdminKit_Settings::get( 'branding_enabled' ) || ! is_admin_bar_showing() ) {
			return;
		}
		$context = is_admin() ? 'admin' : 'frontend';
		if ( ! apply_filters( 'adminkit/should_load', true, $context ) ) {
			return;
		}

		$light = AdminKit_Settings::brand_logo( 'light' );
		$dark  = AdminKit_Settings::brand_logo( 'dark' );
		if ( '' === $light && '' === $dark ) {
			return; // No wordmark: the site icon and title remain available.
		}
		if ( '' === $light ) {
			$light = $dark;
		}
		if ( '' === $dark ) {
			$dark = $light;
		}

		$node = $bar->get_node( 'site-name' );
		if ( ! $node ) {
			return; // user can't see the site-name node.
		}

		// The wordmark IS the site name — give the (hidden) link an accessible name.
		$label = get_bloginfo( 'name', 'display' );

		// Each variant carries the same name; CSS exposes only the active mark.
		$img  = '<img class="ak-brand-logo ak-brand-logo--light" src="' . esc_url( $light ) . '" alt="' . esc_attr( $label ) . '" />';
		$img .= '<img class="ak-brand-logo ak-brand-logo--dark' . AdminKit_Settings::dynamic_logo_class() . '" src="' . esc_url( $dark ) . '" alt="' . esc_attr( $label ) . '" />';
		$icon = get_site_icon_url( 96 );
		$img .= $icon
			? '<img class="ak-brand-compact" src="' . esc_url( $icon ) . '" alt="' . esc_attr( $label ) . '" />'
			: '<span class="ak-brand-compact dashicons dashicons-wordpress" role="img" aria-label="' . esc_attr( $label ) . '"></span>';

		$meta          = is_array( $node->meta ) ? $node->meta : array();
		$meta['class'] = ( isset( $meta['class'] ) && '' !== $meta['class'] )
			? $meta['class'] . ' ' . self::LOGO_NODE_CLASS
			: self::LOGO_NODE_CLASS;

		// Re-add the node with the same id → REPLACE it in place: only the title (and
		// the marker class) change, so the submenu and href are preserved. The title
		// is wrapped so CSS can hide just the text and leave the <img>s — kept verbatim
		// (core already produced display-safe markup via wp_html_excerpt, e.g. a
		// trailing &hellip;; re-escaping it would double-encode the entity).
		$bar->add_node( array(
			'id'    => 'site-name',
			'title' => $img . '<span class="ak-site-name-text">' . $node->title . '</span>',
			'href'  => $node->href,
			'meta'  => $meta,
		) );
	}

	/**
	 * wp-admin: print the brand-mark CSS.
	 * Skipped when AdminKit styling is paused (any adminkit/should_load veto).
	 *
	 * @return void
	 */
	public static function print_admin() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		self::print_css( self::css() );
	}

	/**
	 * Front end: the SAME brand-mark CSS, for logged-in users who see the toolbar
	 * there. Gated on the toolbar actually showing.
	 *
	 * @return void
	 */
	public static function print_frontend() {
		if ( ! AdminKit_Settings::get( 'branding_enabled' ) || ! is_admin_bar_showing() ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'frontend' ) ) {
			return;
		}
		self::print_css( self::css() );
	}

	/**
	 * Echo the collected branding CSS in a single <style>. URLs are escaped in
	 * css_url(); the rest is static.
	 *
	 * @param string $css
	 * @return void
	 */
	private static function print_css( $css ) {
		if ( '' !== $css ) {
			echo '<style id="adminkit-branding">' . $css . "</style>\n";
		}
	}

	/** Hide duplicate core marks and apply the automatic toolbar identity. */
	private static function css() {
		if ( ! AdminKit_Settings::get( 'branding_enabled' ) ) { return ''; }
		// The top-left WordPress logo is ALWAYS hidden now — the brand mark lives at
		// the site name instead. Two selectors: the .wp-admin one beats WP's wp-admin
		// rule, the plain one covers the front end.
		$css  = '.wp-admin #wpadminbar #wp-admin-bar-wp-logo,';
		$css .= '#wpadminbar #wp-admin-bar-wp-logo{display:none}';
		// Core also prepends a site-icon image; AdminKit supplies its own mark.
		$css .= '#wpadminbar #wp-admin-bar-site-name > .ab-item > img.site-icon{display:none}';

		return $css . self::site_name_mark_css();
	}

	/** Prefer a configured logo, then site icon, then the WordPress mark. */
	private static function site_name_mark_css() {
		if ( AdminKit_Settings::brand_logo( 'light' ) || AdminKit_Settings::brand_logo( 'dark' ) ) {
			$css = self::brand_logo_css();
			if ( '' !== $css ) {
				return $css;
			}
			// No brand logo configured → fall through to the favicon chip.
		}

		$css = self::favicon_chip_css();
		if ( '' !== $css ) {
			return $css;
		}

		// Nothing configured anywhere → WP logomark fallback.
		return self::wp_logomark_css();
	}

	/** Theme-aware wordmark sizing; native compact menu states switch to the icon. */
	private static function brand_logo_css() {
		$light = AdminKit_Settings::brand_logo( 'light' );
		$dark  = AdminKit_Settings::brand_logo( 'dark' );
		if ( '' === $light && '' === $dark ) {
			return '';
		}
		// The node carries .ak-has-brand-logo (added by inject_brand_logo); scope all
		// the logo CSS to it so the favicon-chip / bare-title states are never touched.
		$item = '#wpadminbar #wp-admin-bar-site-name.' . self::LOGO_NODE_CLASS . ' > .ab-item';
		$img  = $item . ' .ak-brand-logo';
		return
			// Lay the link out as a flex row so the <img> sits flush.
			$item . '{display:flex;align-items:center}'
			// The wordmark replaces the name: hide the title text.
			. $item . ' .ak-site-name-text{display:none}'
			// The replaced <img>: fixed height, auto width (aspect-true wordmark),
			// rounded on the image itself, a max-width cap against an outsized source.
			. $img . '{display:none;height:20px;width:auto;max-width:160px;'
			. 'object-fit:contain;border-radius:var(--ak-radius-s,6px)}'
			// Show the light variant by default, the dark one under the theme flag.
			. $item . ' .ak-brand-logo--light{display:block}'
			. ':root[data-adminkit-theme="dark"] ' . $item . ' .ak-brand-logo--light{display:none}'
			. ':root[data-adminkit-theme="dark"] ' . $item . ' .ak-brand-logo--dark{display:block}'
			// Mobile (≤782px): WP collapses the site-name cell to a 52px icon
			// (text-indent:100%; overflow:hidden; width:52px), which clips a wordmark.
			// Let the cell size to the logo and show it in full on the 46px bar — kept
			// compact (height/max-width) so it doesn't crowd the right-hand icons.
			. '@media screen and (max-width:782px){'
			. $item . '{width:auto;text-indent:0;overflow:visible;padding:0 10px}'
			. $img . '{height:24px;max-width:100px}'
			. '}'
			. self::compact_mark_css( $item )
			. self::hide_site_name_glyph();
	}

	/** CSS follows WordPress's native folded and responsive menu states. */
	private static function compact_mark_css( $item ) {
		$compact = $item . ' .ak-brand-compact';
		$folded = 'body.folded ' . $item;
		$auto = 'body.auto-fold ' . $item;
		$rules = static function ( $selector ) {
			return $selector . ' .ak-brand-logo{display:none!important}'
				. $selector . ' .ak-brand-compact{display:block;width:20px;height:20px;object-fit:contain;font-size:20px}'
				. $selector . '{width:36px;justify-content:center;padding:0;text-indent:0}';
		};
		return $compact . '{display:none}' . $rules( $folded )
			. '@media(min-width:783px) and (max-width:960px){' . $rules( $auto ) . '}'
			. '@media(max-width:782px){' . $rules( $item ) . $item . '{width:52px}}';
	}

	/**
	 * Site Icon (favicon) as a small rounded chip before the site title, at the
	 * site-name node — replacing WordPress's "house" glyph there but KEEPING the
	 * title text. '' when no Site Icon is configured, so it degrades cleanly (bare
	 * title). Used when no wordmark is configured.
	 *
	 * Painted as a `background-image` on the ::before (NOT content:url): a content
	 * image renders at its INTRINSIC size — browsers ignore width/height on a
	 * content:url() pseudo-element, which blew the favicon up to full size. WP forces
	 * `background-image:none !important` on `.ab-item:before`, so the background needs
	 * one justified `!important` to win (the admin bar legitimately needs a few).
	 * `content:""` keeps the ::before rendering (and beats hide_site_name_glyph()'s
	 * `content:none`); `width/height:18px` + `background-size:cover` scale the square
	 * icon into a rounded 18px chip, vertically centred in the 32px bar (7px top/bottom),
	 * with a 6px gap before the site title. Same image in both themes.
	 *
	 * Two selectors (.wp-admin-prefixed + plain), each with a DOUBLED `.ab-item`
	 * class so it out-specifies hide_site_name_glyph()'s `content:none` — plain chip
	 * (2,2,1) beats plain hide (2,1,1); wp-admin chip (2,3,1) beats wp-admin hide
	 * (2,2,1) — so the chip wins in BOTH wp-admin and the front end regardless of
	 * source order.
	 *
	 * @return string
	 */
	private static function favicon_chip_css() {
		$favicon = self::css_url( get_site_icon_url( 96 ) );
		if ( '' === $favicon ) {
			return ''; // no Site Icon configured → leave the node as-is (bare title).
		}
		$decl =
			'{content:"";display:block;box-sizing:border-box;'
			. 'float:left;width:18px;height:18px;padding:0;margin:7px 6px 7px 0;top:0;'
			. 'background-image:' . $favicon . ' !important;background-position:center;background-repeat:no-repeat;background-size:cover;'
			. 'border-radius:0}';
		// Mobile (≤782px): WP turns the site-name node into a 52px-wide, position:relative
		// cell with the title text hidden (text-indent:100%). The desktop chip (an 18px
		// float pinned left, tuned to the 32px bar) then reads tiny + off-centre — the
		// reported "favicon tout petit cassé". Re-centre it as a 26px chip, absolutely
		// placed in the cell, so the favicon reads as the site icon on the 46px touch bar.
		$mobile =
			'{float:none;position:absolute;top:50%;left:50%;width:26px;height:26px;margin:0;'
			. 'transform:translate(-50%,-50%)}';
		return self::hide_site_name_glyph()
			. '.wp-admin #wpadminbar #wp-admin-bar-site-name > .ab-item.ab-item::before' . $decl
			. '#wpadminbar #wp-admin-bar-site-name > .ab-item.ab-item::before' . $decl
			. '@media screen and (max-width:782px){'
			. '.wp-admin #wpadminbar #wp-admin-bar-site-name > .ab-item.ab-item::before' . $mobile
			. '#wpadminbar #wp-admin-bar-site-name > .ab-item.ab-item::before' . $mobile
			. '}';
	}

	/**
	 * Fallback site-name mark — swap WP's default house dashicon (\f102) for the
	 * WordPress logomark (\f120). Used when nothing is configured (no brand logo,
	 * no Site Icon), so the bar still feels like WordPress rather than a generic
	 * "home". The Dashicons font is already loaded with the admin bar in both
	 * wp-admin and the front-end, so no extra enqueue is needed.
	 *
	 * Doubled `.ab-item` for specificity, mirroring favicon_chip_css() — beats
	 * WP core's own .ab-item:before content rule in both contexts.
	 *
	 * @return string
	 */
	private static function wp_logomark_css() {
		$decl = '{content:"\\f120" !important}';
		return '.wp-admin #wpadminbar #wp-admin-bar-site-name > .ab-item.ab-item::before' . $decl
			. '#wpadminbar #wp-admin-bar-site-name > .ab-item.ab-item::before' . $decl;
	}

	/**
	 * Hide WordPress's "house"/site glyph next to the site name — redundant once the
	 * node carries a brand mark. Two selectors: the .wp-admin-prefixed one beats WP's
	 * wp-admin rule; the plain one covers the front end. (The favicon chip and the
	 * logo <img> out-specify this so the mark still renders.)
	 *
	 * @return string
	 */
	private static function hide_site_name_glyph() {
		return '.wp-admin #wpadminbar #wp-admin-bar-site-name > .ab-item::before,'
			. '#wpadminbar #wp-admin-bar-site-name > .ab-item::before{content:none}';
	}

	/**
	 * Wrap a URL for safe use inside a CSS `url()` in a <style> block.
	 *
	 * `esc_url()` targets HTML and entity-encodes ampersands (`&` → `&#038;`),
	 * which a CSS parser does NOT decode — so a site-icon / logo URL carrying a
	 * query string (Jetpack/Photon resize, CDN params…) silently fails to load.
	 * `esc_url_raw()` keeps the URL clean and strips characters that could break
	 * out of `url("…")` (quotes, parentheses). Returns '' for an empty URL.
	 *
	 * @param string $url
	 * @return string  e.g. `url("https://…")`, or '' when empty.
	 */
	private static function css_url( $url ) {
		$url = esc_url_raw( (string) $url );
		return ( '' === $url ) ? '' : 'url("' . $url . '")';
	}
}
