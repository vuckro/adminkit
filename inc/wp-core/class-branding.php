<?php
/**
 * Branding — the brand logo across AdminKit surfaces.
 *
 * The WordPress admin-bar logo (top-left), per the `wp_logo` setting:
 *   - `logo`    → the configured brand logo (Settings → Features → Branding,
 *                 light + dark), injected as a real <img> so a wide wordmark
 *                 auto-sizes to its aspect ratio (tight, no empty box) and the
 *                 border-radius rounds the actual image;
 *   - `favicon` → the site icon, as a rounded chip (a background box);
 *   - `hide`    → removed.
 * `logo` falls back to `favicon`, then WordPress's own, when nothing is set; either
 * branded mode also hides the now-redundant site-name "house" glyph. Applied in
 * wp-admin AND on the front-end toolbar (logged-in users see it there too).
 *
 * The Bricks builder reads the SAME source (AdminKit_Settings::brand_logo()), so
 * one configuration drives the brand everywhere. The whole output is gated by the
 * adminkit/should_load filter, so any veto pauses it along with the rest of AdminKit.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Branding {

	/**
	 * Marker class added to the #wp-admin-bar-wp-logo node when we inject the brand
	 * <img> — so the CSS targets ONLY the branded state and leaves the favicon / WP
	 * default untouched.
	 */
	const LOGO_NODE_CLASS = 'ak-has-brand-logo';

	/**
	 * Wire the hooks. The admin-bar logo treatment runs in BOTH wp-admin and on
	 * the front end (logged-in users see the toolbar there too).
	 *
	 * The `admin_bar_menu` hook (priority 80, after core registers the wp-logo node
	 * at 10) injects the brand <img> into the node for the `logo` mode; the CSS that
	 * sizes it — and the favicon / hide treatments — is printed on admin_head/wp_head.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'inject_brand_logo' ), 80 );
		add_action( 'admin_head', array( __CLASS__, 'print_admin' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_frontend' ), 20 );
	}

	/**
	 * Inject the brand logo as real <img> element(s) into the admin-bar wp-logo
	 * node — but ONLY when the mode is `logo` AND a brand logo is configured. An
	 * <img> is a replaced element, so `height:<fixed>;width:auto` (set in CSS) makes
	 * it auto-size to the wordmark's aspect ratio: tight, no empty box, and the
	 * border-radius rounds the actual image. A CSS background (the old approach)
	 * couldn't do that — a fixed-width box left a gap and rounded the box, not the
	 * white-tile logo.
	 *
	 * Both the light and the dark variant are rendered; CSS shows the right one per
	 * the theme flag (an <img src> can't be swapped from CSS). The node's `title` is
	 * replaced (preserving its submenu + the screen-reader label) and a marker class
	 * scopes the CSS to this branded state. Gated by the same should_load veto + the
	 * showing/context guard the rest of the branding uses.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function inject_brand_logo( $bar ) {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$context = is_admin() ? 'admin' : 'frontend';
		if ( ! apply_filters( 'adminkit/should_load', true, $context ) ) {
			return;
		}
		if ( 'logo' !== AdminKit_Settings::get( 'wp_logo' ) ) {
			return;
		}

		$light = AdminKit_Settings::brand_logo( 'light' );
		$dark  = AdminKit_Settings::brand_logo( 'dark' );
		if ( '' === $light && '' === $dark ) {
			return; // logo mode but nothing configured → admin_bar_logo_css() falls back to favicon.
		}
		if ( '' === $light ) {
			$light = $dark;
		}
		if ( '' === $dark ) {
			$dark = $light;
		}

		$node = $bar->get_node( 'wp-logo' );
		if ( ! $node ) {
			return; // user can't see the wp-logo node (e.g. no `read` cap).
		}

		// Decorative images (alt=""); the screen-reader span carries the label. Two
		// <img>s, theme-toggled in CSS. esc_url for the src; the class names are static.
		$img  = '<img class="ak-brand-logo ak-brand-logo--light" src="' . esc_url( $light ) . '" alt="" />';
		$img .= '<img class="ak-brand-logo ak-brand-logo--dark" src="' . esc_url( $dark ) . '" alt="" />';

		// Keep the node's accessible name (core sets this node's label to "About
		// WordPress"); fall back to that core string if the menu_title meta is absent.
		$label = ( isset( $node->meta['menu_title'] ) && '' !== $node->meta['menu_title'] )
			? $node->meta['menu_title']
			: __( 'About WordPress' );

		$meta          = is_array( $node->meta ) ? $node->meta : array();
		$meta['class'] = ( isset( $meta['class'] ) && '' !== $meta['class'] )
			? $meta['class'] . ' ' . self::LOGO_NODE_CLASS
			: self::LOGO_NODE_CLASS;

		// Re-add the node with the same id → REPLACE it in place: only the title (and
		// the marker class) change, so the submenu and href are preserved.
		$bar->add_node( array(
			'id'    => 'wp-logo',
			'title' => $img . '<span class="screen-reader-text">' . esc_html( $label ) . '</span>',
			'href'  => $node->href,
			'meta'  => $meta,
		) );
	}

	/**
	 * wp-admin: the admin-bar logo treatment.
	 * Skipped when AdminKit styling is paused (any adminkit/should_load veto).
	 *
	 * @return void
	 */
	public static function print_admin() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		self::print_css( self::admin_bar_logo_css() );
	}

	/**
	 * Front end: the SAME admin-bar logo treatment, for logged-in users who see
	 * the toolbar there — PLUS a front-end-only touch: the Site Icon shown as a
	 * small rounded chip next to the site-title node (see site_name_favicon_css()).
	 *
	 * @return void
	 */
	public static function print_frontend() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'frontend' ) ) {
			return;
		}
		self::print_css( self::admin_bar_logo_css() . self::site_name_favicon_css() );
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

	/**
	 * The WordPress admin-bar logo treatment — identical in wp-admin and on the
	 * front-end toolbar. `hide` removes it; `logo` shows the configured brand logo;
	 * `favicon` shows the site icon. `logo` with nothing configured falls through to
	 * `favicon`, which itself no-ops (WP logo stays) when there's no Site Icon.
	 *
	 * @return string
	 */
	private static function admin_bar_logo_css() {
		$mode = AdminKit_Settings::get( 'wp_logo' );

		if ( 'hide' === $mode ) {
			return '#wpadminbar #wp-admin-bar-wp-logo{display:none}';
		}

		if ( 'logo' === $mode ) {
			$css = self::brand_logo_css();
			if ( '' !== $css ) {
				return $css;
			}
			$mode = 'favicon'; // no brand logo configured → fall back to the site icon
		}

		if ( 'favicon' === $mode ) {
			return self::favicon_css();
		}

		return '';
	}

	/**
	 * CSS for the brand logo in the admin-bar wp-logo slot — sizes the real <img>
	 * element(s) injected by inject_brand_logo(). An <img> is a REPLACED element, so
	 * `height:22px;width:auto` makes it auto-size to the wordmark's intrinsic aspect
	 * ratio: tight (no empty box), never cropped, and `border-radius` rounds the
	 * actual image (the white-tile logo) — exactly what a CSS background could not do.
	 *
	 * The light variant shows by default; the dark variant under the theme flag (an
	 * <img src> can't be swapped from CSS, so both are rendered and toggled here with
	 * `display`). The injection is gated on `wp_logo === 'logo'` AND a configured
	 * logo, so the marker class (.ak-has-brand-logo) is the signal that an <img> is
	 * present; '' here when nothing is configured (admin_bar_logo_css() then falls
	 * back to favicon_css()).
	 *
	 * `object-fit:contain` + a `max-width` cap guard against an oversized source ever
	 * overflowing the bar. We neutralise WP core's left/right padding on the .ab-item
	 * link (the injected title carries the <img>s directly, no .ab-icon span) so the
	 * logo sits flush with just its own small gutter. Specificity beats WP core's own
	 * wp-logo rule `#wpadminbar #wp-admin-bar-wp-logo > .ab-item` (2,1,0) — the
	 * .ak-has-brand-logo class on the node lifts our selectors past it cleanly.
	 *
	 * @return string
	 */
	private static function brand_logo_css() {
		$light = AdminKit_Settings::brand_logo( 'light' );
		$dark  = AdminKit_Settings::brand_logo( 'dark' );
		if ( '' === $light && '' === $dark ) {
			return '';
		}
		// The node carries .ak-has-brand-logo (added by inject_brand_logo); scope all
		// the logo CSS to it so the favicon / WP-default states are never touched.
		$item = '#wpadminbar #wp-admin-bar-wp-logo.' . self::LOGO_NODE_CLASS . ' > .ab-item';
		$img  = $item . ' .ak-brand-logo';
		return
			// Kill core's left/right padding on the link so the logo sits flush.
			$item . '{display:flex;align-items:center;padding:0}'
			// The replaced <img>: fixed height, auto width (aspect-true wordmark),
			// rounded on the image itself, a max-width cap against an outsized source.
			. $img . '{display:none;height:22px;width:auto;max-width:160px;margin:0 8px;padding:0;'
			. 'object-fit:contain;border-radius:var(--ak-radius-s,6px)}'
			// Show the light variant by default, the dark one under the theme flag.
			. $item . ' .ak-brand-logo--light{display:block}'
			. ':root[data-adminkit-theme="dark"] ' . $item . ' .ak-brand-logo--light{display:none}'
			. ':root[data-adminkit-theme="dark"] ' . $item . ' .ak-brand-logo--dark{display:block}'
			. self::hide_site_name_glyph();
	}

	/**
	 * Site icon (favicon) in the admin-bar wp-logo slot — painted onto the
	 * .ab-icon::BEFORE box (WP forces background-image:none on .ab-icon itself, but
	 * its ::before is exempt), as a 24px rounded chip painted `center/cover` and
	 * FLEX-centred in the 32px bar. '' when there's no Site Icon.
	 *
	 * @return string
	 */
	private static function favicon_css() {
		$favicon = self::css_url( get_site_icon_url( 96 ) );
		if ( '' === $favicon ) {
			return '';
		}
		// Double `.ab-icon` → specificity (2,3,0), beating WP core's own wp-logo rule
		// `#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon` (2,2,0, loaded after
		// us) which would otherwise re-apply its height:20px/padding:6px 0 5px and
		// overflow the bar.
		$sel = '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon.ab-icon';
		return $sel . '{display:flex;align-items:center;justify-content:center;width:auto;height:32px;padding:0;margin-right:0}'
			. $sel . '::before{content:"";display:block;box-sizing:border-box;width:24px;height:24px;margin:0;top:0;'
			. 'transform:translateY(-3px);'
			. 'border-radius:var(--ak-radius-s,5px);'
			. 'background:' . $favicon . ' center/cover no-repeat}'
			. self::hide_site_name_glyph();
	}

	/**
	 * FRONT END only: show the Site Icon (favicon) as a small rounded chip next to
	 * the site-title node (`#wp-admin-bar-site-name`), replacing WordPress's "house"
	 * glyph there. Gated exactly like the rest of the branding: nothing when the
	 * brand mode is `hide` (the user opted out of a brand mark), and nothing when no
	 * Site Icon is configured — so it degrades cleanly.
	 *
	 * Painted as a `background-image` on the ::before (NOT content:url): a content
	 * image renders at its INTRINSIC size — browsers ignore width/height on a
	 * content:url() pseudo-element, which blew the favicon up to full size. WP forces
	 * `background-image:none !important` on `.ab-item:before`, so the background needs
	 * one justified `!important` to win (the admin bar legitimately needs a few).
	 * `content:""` keeps the ::before rendering (and still beats hide_site_name_glyph()'s
	 * `content:none`); `width/height:18px` + `background-size:cover` scale the square
	 * icon into a rounded 18px chip, vertically centred in the 32px bar (7px top/bottom),
	 * with a 6px gap before the site title. Same image in both themes.
	 *
	 * The selector is front-end-scoped (`body:not(.wp-admin)`) and out-specifies
	 * hide_site_name_glyph()'s front-end `content:none` (2,1,1 → this is 2,2,1) so
	 * the chip wins over the hide; printed only by print_frontend(), so wp-admin is
	 * untouched. '' when gated off.
	 *
	 * @return string
	 */
	private static function site_name_favicon_css() {
		if ( 'hide' === AdminKit_Settings::get( 'wp_logo' ) ) {
			return ''; // user opted out of a brand mark entirely.
		}
		$favicon = self::css_url( get_site_icon_url( 96 ) );
		if ( '' === $favicon ) {
			return ''; // no Site Icon configured → leave the node as-is.
		}
		$sel = 'body:not(.wp-admin) #wpadminbar #wp-admin-bar-site-name > .ab-item::before';
		return $sel . '{content:"";display:block;box-sizing:border-box;'
			. 'float:left;width:18px;height:18px;padding:0;margin:7px 6px 7px 0;top:0;'
			. 'background-image:' . $favicon . ' !important;background-position:center;background-repeat:no-repeat;background-size:cover;'
			. 'border-radius:var(--ak-radius-s,5px)}';
	}

	/**
	 * Hide WordPress's "house"/site glyph next to the site name — redundant once the
	 * bar carries a brand mark. Two selectors: the .wp-admin-prefixed one beats WP's
	 * wp-admin rule; the plain one covers the front end.
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
