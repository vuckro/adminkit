<?php
/**
 * Branding — the brand mark across AdminKit surfaces.
 *
 * AdminKit shows ONE brand mark on the admin bar, at the SITE-NAME node (next to
 * the site title — more coherent than the far-left WordPress logo). The top-left
 * `#wp-admin-bar-wp-logo` node is ALWAYS hidden (admin AND front-end toolbar),
 * which also drops its "About WordPress" submenu — intended. The `wp_logo` setting
 * now drives the SITE-NAME mark:
 *   - `favicon` → the Site Icon as a small rounded chip before the site title,
 *                 KEEPING the title text (a background box on the ::before);
 *   - `logo`    → the configured brand logo (AdminKit Dashboard branding card,
 *                 light + dark) injected as a real <img> that REPLACES the title
 *                 text (the wordmark IS the name, so logo + "SiteName" would be
 *                 redundant) — a wide wordmark auto-sizes to its aspect ratio
 *                 (tight, no empty box) and border-radius rounds the actual image;
 * `logo` falls back to `favicon` when no brand logo is configured. Either branded
 * mode hides the site-name "house" glyph so the mark replaces it; legacy `hide`
 * values degrade to `favicon`. Applied in wp-admin AND on the front-end toolbar
 * (logged-in users see it there too).
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
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'inject_brand_logo' ), 80 );
		add_action( 'admin_head', array( __CLASS__, 'print_admin' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_frontend' ), 20 );
	}

	/**
	 * Inject the brand logo as real <img> element(s) into the admin-bar SITE-NAME
	 * node — but ONLY when the mode is `logo` AND a brand logo is configured. An
	 * <img> is a replaced element, so `height:<fixed>;width:auto` (set in CSS) makes
	 * it auto-size to the wordmark's aspect ratio: tight, no empty box, and the
	 * border-radius rounds the actual image. A CSS background couldn't do that — a
	 * fixed-width box left a gap and rounded the box, not the white-tile logo.
	 *
	 * Both the light and the dark variant are rendered; CSS shows the right one per
	 * the theme flag (an <img src> can't be swapped from CSS). The wordmark REPLACES
	 * the site title (so the title text is hidden in CSS, not kept): the node's
	 * `title` becomes the two <img>s plus the original title wrapped in a
	 * .ak-site-name-text span (hidden in CSS). The visible <img> carries the site
	 * name as its `alt` for an accessible name; the off-theme one is decorative
	 * (alt=""). The node's href + submenu (meta) are preserved by re-adding with the
	 * same id, and a marker class scopes the CSS to this branded state. Gated by the
	 * same should_load veto + showing/context guard the rest of the branding uses.
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
			return; // logo mode but nothing configured → site_name_mark_css() falls back to the favicon chip.
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

		// One visible <img> carries the accessible name (alt=site name); the other is
		// decorative (alt="") — only one shows per theme. esc_url for each src.
		$img  = '<img class="ak-brand-logo ak-brand-logo--light" src="' . esc_url( $light ) . '" alt="' . esc_attr( $label ) . '" />';
		$img .= '<img class="ak-brand-logo ak-brand-logo--dark" src="' . esc_url( $dark ) . '" alt="" />';

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
		if ( ! is_admin_bar_showing() ) {
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

	/**
	 * The full brand-mark treatment — identical in wp-admin and on the front-end
	 * toolbar. Always hides the top-left wp-logo node; then, per `wp_logo`, paints
	 * the mark at the site-name node: `logo` shows the configured brand logo (or
	 * falls back to the favicon chip when nothing is configured), `favicon` shows
	 * the Site Icon chip (or nothing when no Site Icon is set — that's the
	 * implicit "hide" path; no explicit `hide` mode exists for the admin bar).
	 *
	 * @return string
	 */
	private static function css() {
		// The top-left WordPress logo is ALWAYS hidden now — the brand mark lives at
		// the site name instead. Two selectors: the .wp-admin one beats WP's wp-admin
		// rule, the plain one covers the front end.
		$css  = '.wp-admin #wpadminbar #wp-admin-bar-wp-logo,';
		$css .= '#wpadminbar #wp-admin-bar-wp-logo{display:none}';

		return $css . self::site_name_mark_css();
	}

	/**
	 * The mark at the site-name node, per `wp_logo`:
	 *   - `logo`    → size the brand <img> injected by inject_brand_logo() and hide
	 *                 the title text (the wordmark replaces it). Falls back to the
	 *                 favicon chip when no logo is configured.
	 *   - `favicon` → the Site Icon as a rounded chip before the title — or the
	 *                 WordPress logomark glyph when no Site Icon is configured.
	 * Any other value (incl. legacy stored `'hide'`) degrades to `favicon`.
	 *
	 * The WordPress logomark is the final fallback: rather than leaving WP's
	 * default house dashicon (\f102), we swap to the WP "W" mark (\f120). An
	 * unbranded install still feels owned, and the bar reads as WordPress
	 * rather than "Home of a generic site".
	 *
	 * @return string
	 */
	private static function site_name_mark_css() {
		if ( 'logo' === AdminKit_Settings::get( 'wp_logo' ) ) {
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

	/**
	 * CSS for the brand logo at the site-name node — sizes the real <img> element(s)
	 * injected by inject_brand_logo() and hides the now-redundant title text. An
	 * <img> is a REPLACED element, so `height:20px;width:auto` makes it auto-size to
	 * the wordmark's intrinsic aspect ratio: tight (no empty box), never cropped, and
	 * `border-radius` rounds the actual image — exactly what a CSS background could
	 * not do.
	 *
	 * The light variant shows by default; the dark variant under the theme flag (an
	 * <img src> can't be swapped from CSS, so both are rendered and toggled here with
	 * `display`). The injection is gated on `wp_logo === 'logo'` AND a configured
	 * logo, so the marker class (.ak-has-brand-logo) is the signal that an <img> is
	 * present; '' here when nothing is configured (site_name_mark_css() then falls
	 * back to the favicon chip).
	 *
	 * `object-fit:contain` + a `max-width` cap guard against an oversized source ever
	 * overflowing the bar. The .ak-has-brand-logo class on the node lifts our
	 * selectors past WP core's own site-name rule cleanly. The default house/site
	 * glyph is hidden via hide_site_name_glyph(); the title text is hidden here.
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
			. self::hide_site_name_glyph();
	}

	/**
	 * Site Icon (favicon) as a small rounded chip before the site title, at the
	 * site-name node — replacing WordPress's "house" glyph there but KEEPING the
	 * title text. '' when no Site Icon is configured, so it degrades cleanly (bare
	 * title). Used for `wp_logo === 'favicon'` and as the `logo` fallback.
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
			. 'border-radius:var(--ak-radius-s,5px)}';
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
