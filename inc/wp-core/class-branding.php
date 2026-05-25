<?php
/**
 * Branding — the brand logo across AdminKit surfaces.
 *
 * Two things, both controllable:
 *   - WordPress admin-bar logo: replaced with the site icon (favicon — which also
 *     hides the redundant "house" glyph next to the site name), hidden, or left
 *     as-is, per the `wp_logo` setting (Settings → Features → Branding).
 *   - When a brand logo is configured (Settings → Features → Branding, light +
 *     dark), prints it as a contained card at the top of the admin menu (full
 *     width, with a background + border so most logos fit), switching per
 *     light/dark mode. With nothing configured, the menu is left untouched —
 *     nothing is added, and no asset is ever shipped.
 *
 * The Bricks builder reads the SAME source (AdminKit_Settings::brand_logo()), so
 * one configuration drives the brand everywhere. The whole output is paused by
 * the "WordPress default UI" master switch via the adminkit/should_load filter.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Branding {

	/**
	 * Wire the hook. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'print_styles' ), 20 );
	}

	/**
	 * Print the branding CSS. Skipped when AdminKit styling is paused (the
	 * "WordPress default UI" switch / any adminkit/should_load veto).
	 *
	 * @return void
	 */
	public static function print_styles() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		$css = '';

		// WordPress admin-bar logo — replace with the site icon, hide, or leave as-is.
		$mode = AdminKit_Settings::get( 'wp_logo' );
		if ( 'hide' === $mode ) {
			$css .= '#wpadminbar #wp-admin-bar-wp-logo{display:none}';
		} elseif ( 'favicon' === $mode ) {
			$favicon = self::css_url( get_site_icon_url( 64 ) );
			if ( '' !== $favicon ) {
				// Paint the site icon onto the .ab-icon::BEFORE box — NOT .ab-icon
				// itself: WP core forces `background-image:none !important` on .ab-icon
				// (that was the bug — our image was silently killed), but its ::before
				// is exempt. Clear WP's glyph (content:""), size the box, and drop the
				// +2px glyph nudge so it sits straight.
				$css .= '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon{width:20px}';
				$css .= '#wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon::before{'
					. 'content:"";display:block;width:20px;height:20px;top:0;'
					. 'background:' . $favicon . ' center/contain no-repeat}';
				// The site icon now brands the bar, so hide the redundant "house"
				// glyph WordPress shows next to the site name. WP's wp-admin rule
				// carries a `.wp-admin` prefix, so we match it to win on specificity.
				$css .= '.wp-admin #wpadminbar #wp-admin-bar-site-name > .ab-item::before{content:none}';
			}
		}
		// 'default' (or favicon with no site icon set) → leave the WP logo untouched.

		// Brand logo above the admin menu — a contained card (background + border +
		// radius) so most logos sit well whatever their shape/colour, in light + dark.
		// Only when one is configured; otherwise the menu is left untouched.
		$light = self::css_url( AdminKit_Settings::brand_logo( 'light' ) );
		$dark  = self::css_url( AdminKit_Settings::brand_logo( 'dark' ) );
		if ( '' !== $light || '' !== $dark ) {
			if ( '' === $light ) {
				$light = $dark;
			}
			if ( '' === $dark ) {
				$dark = $light;
			}
			// Full menu width, the logo contained within the card (auto width, 62% of
			// the card height) so any aspect ratio is centred with breathing room.
			$css .= '#adminmenu::before{content:"";display:block;height:54px;margin:10px 8px;'
				. 'background:var(--ak-elevated) ' . $light . ' center/auto 62% no-repeat;'
				. 'border:1px solid var(--ak-border);border-radius:var(--ak-radius-m)}';
			$css .= ':root[data-adminkit-theme="dark"] #adminmenu::before{background-image:' . $dark . '}';
			// Collapsed menu (folded / responsive auto-fold): a compact square card.
			$css .= '.folded #adminmenu::before,.auto-fold.folded #adminmenu::before{height:34px;margin:8px 4px;background-size:auto 64%}';
		}

		if ( '' !== $css ) {
			echo '<style id="adminkit-branding">' . $css . "</style>\n"; // URLs escaped above; rest is static.
		}
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
