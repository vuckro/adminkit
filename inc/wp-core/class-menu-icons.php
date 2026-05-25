<?php
/**
 * Menu & toolbar icons — swap WordPress's native dashicons for a cohesive AdminKit
 * icon set (Heroicons). Opt-in via the `replace_icons_enabled` setting (OFF by
 * default).
 *
 * Designed to be NON-DESTRUCTIVE and fully overridable — never a brute override:
 *   - The menu map is keyed by dashicon CLASS (`dashicons-admin-post` …), so it
 *     hits core AND plugin items that still use a stock dashicon — and ONLY those.
 *     An item whose icon was already customised (Admin Menu Editor, a plugin's own
 *     image/SVG) has no matching dashicon class, so our selector never applies and
 *     its icon is left untouched.
 *   - Pure CSS: `content:""` drops the glyph, then `mask-image` + `currentColor`
 *     paints our SVG in the SAME colour the dashicon used (default / hover /
 *     current, light + dark). No recolouring, NO `!important`, modest specificity —
 *     so Admin Menu Editor or any later rule still wins.
 *   - Both maps are filterable: `adminkit/menu_icons` (dashicon-class => SVG) and
 *     `adminkit/toolbar_icons` (node-id => SVG). Integrations register their
 *     plugin's icon there; return '' for an entry to skip it.
 *
 * SVGs are inlined as URL-encoded mask data-URIs; the SVG fill colour is irrelevant
 * (a mask uses alpha only), so the visible colour always comes from currentColor.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Menu_Icons {

	/**
	 * Hook the printer. admin_head priority 21 (just after branding). Admin only.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'print_styles' ), 21 );
	}

	/**
	 * Print the icon CSS — gated by the opt-in toggle and the should_load pause.
	 *
	 * @return void
	 */
	public static function print_styles() {
		if ( ! AdminKit_Settings::get( 'replace_icons_enabled' ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		$css = self::menu_css() . self::toolbar_css();
		if ( '' !== $css ) {
			echo '<style id="adminkit-icons">' . $css . "</style>\n"; // SVGs are URL-encoded in mask().
		}
	}

	/**
	 * Admin-menu icons, keyed by the dashicon class WordPress puts on
	 * `.wp-menu-image`. Filter `adminkit/menu_icons` to add/replace/remove.
	 *
	 * @return array<string,string> dashicon-class => SVG markup ('' = skip)
	 */
	private static function menu_icon_map() {
		$map = array(
			'dashicons-dashboard'        => self::svg( 'home' ),
			'dashicons-admin-home'       => self::svg( 'home' ),
			'dashicons-admin-post'       => self::svg( 'pencil' ),
			'dashicons-admin-media'      => self::svg( 'photo' ),
			'dashicons-admin-page'       => self::svg( 'document' ),
			'dashicons-admin-comments'   => self::svg( 'chat' ),
			'dashicons-admin-appearance' => self::svg( 'brush' ),
			'dashicons-admin-plugins'    => self::svg( 'plugin' ),
			'dashicons-admin-users'      => self::svg( 'users' ),
			'dashicons-admin-tools'      => self::svg( 'wrench' ),
			'dashicons-admin-settings'   => self::svg( 'cog' ),
			'dashicons-admin-generic'    => self::svg( 'cog' ),
			'dashicons-admin-network'    => self::svg( 'globe' ),
			'dashicons-admin-site'       => self::svg( 'globe' ),
			'dashicons-admin-site-alt3'  => self::svg( 'globe' ),
		);
		return (array) apply_filters( 'adminkit/menu_icons', $map );
	}

	/**
	 * Admin-bar icons, keyed by node id. Filter `adminkit/toolbar_icons`.
	 *
	 * @return array<string,string> node-id => SVG markup ('' = skip)
	 */
	private static function toolbar_icon_map() {
		$map = array(
			'wp-admin-bar-comments'    => self::svg( 'chat' ),
			'wp-admin-bar-new-content' => self::svg( 'plus' ),
			'wp-admin-bar-updates'     => self::svg( 'update' ),
		);
		return (array) apply_filters( 'adminkit/toolbar_icons', $map );
	}

	/**
	 * Build the admin-menu CSS: drop the dashicon glyph and mask our SVG into the
	 * exact icon box WordPress uses (36×34, glyph inset 7px), so it sits where the
	 * dashicon did and inherits its colour.
	 *
	 * @return string
	 */
	private static function menu_css() {
		$css = '';
		foreach ( self::menu_icon_map() as $class => $svg ) {
			if ( '' === $svg || ! is_string( $svg ) ) {
				continue;
			}
			// Drop the glyph and let a block ::before fill WP's icon box (36px wide ×
			// 34px), with a 20px mask centred in it. Simple + predictable — no
			// positioning tricks — so the icon lands exactly where the dashicon was.
			$css .= '#adminmenu .wp-menu-image.' . $class . '::before{'
				. 'content:"";display:block;height:34px;'
				. self::mask( $svg )
				. '}';
		}
		return $css;
	}

	/**
	 * Build the admin-bar CSS: same idea on `.ab-icon::before` (WP forces
	 * background-image:none on `.ab-icon` itself, but the ::before is exempt).
	 *
	 * @return string
	 */
	private static function toolbar_css() {
		$css = '';
		foreach ( self::toolbar_icon_map() as $id => $svg ) {
			if ( '' === $svg || ! is_string( $svg ) ) {
				continue;
			}
			$css .= '#wpadminbar #' . $id . ' .ab-icon::before{'
				. 'content:"";display:block;width:20px;height:20px;margin:6px 0;'
				. self::mask( $svg )
				. '}';
		}
		return $css;
	}

	/**
	 * The mask declarations for one SVG: currentColor fill + the SVG as an alpha
	 * mask (URL-encoded data-URI). 20px, centred, no-repeat.
	 *
	 * @param string $svg
	 * @return string
	 */
	private static function mask( $svg ) {
		$uri = 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';
		return 'background-color:currentColor;'
			. '-webkit-mask:' . $uri . ' center/20px 20px no-repeat;'
			. 'mask:' . $uri . ' center/20px 20px no-repeat;';
	}

	/**
	 * Wrap a named icon's paths into a complete SVG. Fill is a solid colour only so
	 * the shape is opaque for masking — the visible colour is currentColor.
	 *
	 * @param string $name
	 * @return string SVG markup, or '' if unknown.
	 */
	private static function svg( $name ) {
		$paths = self::paths();
		if ( empty( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000">'
			. $paths[ $name ] . '</svg>';
	}

	/**
	 * Heroicons (solid, 24×24) path data, keyed by role.
	 *
	 * @return array<string,string>
	 */
	private static function paths() {
		return array(
			'home'     => '<path d="M11.47 3.84a.75.75 0 0 1 1.06 0l8.69 8.69a.75.75 0 1 0 1.06-1.06l-8.69-8.69a2.25 2.25 0 0 0-3.18 0l-8.69 8.69a.75.75 0 1 0 1.06 1.06l8.69-8.69Z"/><path d="m12 5.43 8.16 8.16c.03.02.05.05.08.09v6.2A1.88 1.88 0 0 1 18.37 22H15a.75.75 0 0 1-.75-.75v-4.5a.75.75 0 0 0-.75-.75h-3a.75.75 0 0 0-.75.75v4.5A.75.75 0 0 1 9 22H5.63a1.88 1.88 0 0 1-1.88-1.88v-6.2l.09-.09L12 5.43Z"/>',
			'pencil'   => '<path d="M21.73 2.27a2.63 2.63 0 0 0-3.71 0L16.86 3.43l3.71 3.71 1.16-1.16a2.63 2.63 0 0 0 0-3.71ZM19.51 8.2 15.8 4.49 3.65 16.64a5.25 5.25 0 0 0-1.32 2.21l-.8 2.69a.75.75 0 0 0 .93.93l2.69-.8a5.25 5.25 0 0 0 2.21-1.32L19.51 8.2Z"/>',
			'photo'    => '<path fill-rule="evenodd" d="M1.5 6A2.25 2.25 0 0 1 3.75 3.75h16.5A2.25 2.25 0 0 1 22.5 6v12a2.25 2.25 0 0 1-2.25 2.25H3.75A2.25 2.25 0 0 1 1.5 18V6ZM3 16.06V18c0 .41.34.75.75.75h16.5A.75.75 0 0 0 21 18v-1.94l-2.69-2.69a1.5 1.5 0 0 0-2.12 0l-.88.88.97.97a.75.75 0 1 1-1.06 1.06l-5.16-5.16a1.5 1.5 0 0 0-2.12 0L3 16.06Zm10.13-7.81a1.13 1.13 0 1 1 2.25 0 1.13 1.13 0 0 1-2.25 0Z" clip-rule="evenodd"/>',
			'document' => '<path fill-rule="evenodd" d="M5.63 1.5c-1.04 0-1.88.84-1.88 1.88v17.25c0 1.03.84 1.88 1.88 1.88h12.75c1.03 0 1.88-.84 1.88-1.88V12.75A3.75 3.75 0 0 0 16.5 9h-1.88a1.88 1.88 0 0 1-1.87-1.88V5.25A3.75 3.75 0 0 0 9 1.5H5.63ZM7.5 15a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 7.5 15Zm.75 2.25a.75.75 0 0 0 0 1.5H12a.75.75 0 0 0 0-1.5H8.25Z" clip-rule="evenodd"/><path d="M12.97 1.82A5.23 5.23 0 0 1 14.25 5.25v1.88c0 .2.17.37.38.37H16.5a5.23 5.23 0 0 1 3.43 1.28 9.77 9.77 0 0 0-6.96-6.96Z"/>',
			'chat'     => '<path fill-rule="evenodd" d="M4.85 2.77A49.14 49.14 0 0 1 12 2.25c2.43 0 4.82.18 7.15.52 1.98.29 3.35 2.02 3.35 3.97v6.02c0 1.95-1.37 3.68-3.35 3.97-1.15.17-2.31.3-3.48.38a.39.39 0 0 0-.3.17l-2.75 4.13a.75.75 0 0 1-1.25 0l-2.75-4.13a.39.39 0 0 0-.3-.17 48.9 48.9 0 0 1-3.48-.38C2.87 16.44 1.5 14.7 1.5 12.76V6.74c0-1.95 1.37-3.68 3.35-3.97Z" clip-rule="evenodd"/>',
			'brush'    => '<path fill-rule="evenodd" d="M20.6 1.5c-.38 0-.74.11-1.06.32l-5.08 3.39a18.75 18.75 0 0 0-3.47 2.98 10.04 10.04 0 0 1 4.82 4.82 18.75 18.75 0 0 0 2.98-3.47l3.39-5.08A1.9 1.9 0 0 0 20.6 1.5Zm-8.3 14.03a18.76 18.76 0 0 0 1.9-1.21 8.03 8.03 0 0 0-4.52-4.51 18.75 18.75 0 0 0-1.2 1.9l-.28.5a5.26 5.26 0 0 1 3.6 3.6l.5-.28ZM6.75 13.5A3.75 3.75 0 0 0 3 17.25a1.5 1.5 0 0 1-1.6 1.5.75.75 0 0 0-.7 1.12 5.25 5.25 0 0 0 9.8-2.62 3.75 3.75 0 0 0-3.75-3.75Z" clip-rule="evenodd"/>',
			'plugin'   => '<path d="M11.25 5.34c0-.36-.19-.68-.4-.96a1.65 1.65 0 0 1-.35-1c0-1.04 1.01-1.88 2.25-1.88s2.25.84 2.25 1.88c0 .37-.13.71-.35 1-.21.28-.4.6-.4.96 0 .33.28.6.61.58 1.91-.11 3.79-.34 5.63-.68a.75.75 0 0 1 .88.65c.22 1.8.35 3.62.38 5.45a.66.66 0 0 1-.66.66c-.36 0-.68-.19-.96-.4a1.65 1.65 0 0 0-1-.35c-1.04 0-1.88 1.01-1.88 2.25s.84 2.25 1.88 2.25c.37 0 .71-.13 1-.35.28-.21.6-.4.96-.4.31 0 .56.26.53.57-.12 1.62-.32 3.23-.6 4.85a.75.75 0 0 1-.6.6c-1.62.3-3.27.51-4.95.63a.72.72 0 0 1-.78-.71c0-.36.19-.68.4-.96.22-.28.35-.63.35-1 0-1.04-.84-1.88-1.88-1.88s-1.87.84-1.87 1.88c0 .37.13.72.34 1 .22.29.41.6.4.96a.72.72 0 0 1-.78.71c-1.66-.12-3.31-.33-4.94-.63a.75.75 0 0 1-.61-.6c-.28-1.6-.48-3.21-.59-4.83a.71.71 0 0 1 .79-.78c.36.03.71.16 1 .39.27.22.6.4.94.4 1.04 0 1.88-.84 1.88-1.87S6.43 12.75 5.4 12.75c-.34 0-.67.19-.94.4-.29.24-.64.37-1 .39a.71.71 0 0 1-.79-.78c.11-1.62.31-3.23.59-4.83a.75.75 0 0 1 .61-.61c1.59-.29 3.23-.5 4.9-.62a.72.72 0 0 0 .78-.71Z"/>',
			'users'    => '<path d="M4.5 6.38a4.13 4.13 0 1 1 8.25 0 4.13 4.13 0 0 1-8.25 0ZM14.25 8.63a3.38 3.38 0 1 1 6.75 0 3.38 3.38 0 0 1-6.75 0ZM1.5 19.13a7.13 7.13 0 0 1 14.25 0v.12a.75.75 0 0 1-.36.63 13.07 13.07 0 0 1-6.76 1.87c-2.47 0-4.79-.68-6.76-1.87a.75.75 0 0 1-.37-.63v-.12ZM17.25 19.13v.14a2.25 2.25 0 0 1-.24.96 10.09 10.09 0 0 0 5.06-1.01.75.75 0 0 0 .42-.64 4.88 4.88 0 0 0-6.96-4.61 8.59 8.59 0 0 1 1.72 5.16Z"/>',
			'wrench'   => '<path fill-rule="evenodd" d="M12 6.75a5.25 5.25 0 0 1 6.78-5.03.75.75 0 0 1 .31 1.25l-3.32 3.32c.06.48.28.93.64 1.3.37.36.83.58 1.3.64l3.32-3.32a.75.75 0 0 1 1.25.31 5.25 5.25 0 0 1-5.47 6.76c-1.02-.09-1.87.1-2.31.63L7.34 21.3A3.3 3.3 0 1 1 2.7 16.66l8.68-7.15c.53-.44.72-1.29.64-2.31A5.34 5.34 0 0 1 12 6.75ZM4.12 19.13a.75.75 0 0 1 .75-.75h.01a.75.75 0 0 1 .75.75v.01a.75.75 0 0 1-.75.75h-.01a.75.75 0 0 1-.75-.75v-.01Z" clip-rule="evenodd"/>',
			'cog'      => '<path fill-rule="evenodd" d="M11.08 2.25c-.92 0-1.7.66-1.85 1.57l-.18 1.07c-.02.12-.11.26-.3.35-.34.16-.67.35-.98.57-.17.11-.34.13-.45.08l-1.02-.38a1.88 1.88 0 0 0-2.28.82l-.92 1.6a1.88 1.88 0 0 0 .43 2.38l.84.7c.1.07.17.22.15.42a7.6 7.6 0 0 0 0 1.14c.02.2-.06.35-.15.43l-.84.7a1.88 1.88 0 0 0-.43 2.38l.92 1.6a1.88 1.88 0 0 0 2.28.82l1.02-.38c.11-.05.28-.04.45.08.31.22.64.41.98.57.19.09.28.23.3.35l.18 1.07c.15.9.93 1.57 1.85 1.57h1.84c.92 0 1.7-.66 1.85-1.57l.18-1.07c.02-.12.11-.26.3-.35.34-.16.67-.36.98-.57.17-.11.34-.13.45-.08l1.02.38a1.88 1.88 0 0 0 2.28-.82l.92-1.6a1.88 1.88 0 0 0-.43-2.38l-.84-.7c-.1-.08-.17-.23-.15-.43a7.62 7.62 0 0 0 0-1.14c-.02-.2.06-.35.15-.43l.84-.69c.71-.58.89-1.59.43-2.39l-.92-1.6a1.88 1.88 0 0 0-2.28-.81l-1.02.38c-.11.04-.28.03-.45-.08-.31-.22-.64-.41-.98-.57-.18-.09-.28-.23-.3-.35l-.18-1.07a1.88 1.88 0 0 0-1.85-1.57h-1.84ZM12 15.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z" clip-rule="evenodd"/>',
			'globe'    => '<path fill-rule="evenodd" d="M12 2.25c-5.39 0-9.75 4.36-9.75 9.75s4.36 9.75 9.75 9.75 9.75-4.36 9.75-9.75S17.39 2.25 12 2.25Zm4.95 7.5h2.6a8.25 8.25 0 0 0-4.6-5.36 14.3 14.3 0 0 1 2 5.36Zm-1.53 0a12.6 12.6 0 0 0-2.67-5.92.75.75 0 0 0-1.5 0A12.6 12.6 0 0 0 8.58 9.75h6.84Zm-6.95 1.5a13.3 13.3 0 0 0 0 1.5h7.06a13.3 13.3 0 0 0 0-1.5H8.47Zm.11 3a12.6 12.6 0 0 0 2.67 5.92.75.75 0 0 0 1.5 0 12.6 12.6 0 0 0 2.67-5.92H8.58Zm6.37 5.36a14.3 14.3 0 0 0 2-5.36h2.6a8.25 8.25 0 0 1-4.6 5.36ZM9.05 4.39a14.3 14.3 0 0 0-2 5.36h-2.6a8.25 8.25 0 0 1 4.6-5.36Zm-4.6 9.86h2.6a14.3 14.3 0 0 0 2 5.36 8.25 8.25 0 0 1-4.6-5.36Z" clip-rule="evenodd"/>',
			'plus'     => '<path fill-rule="evenodd" d="M12 3.75a.75.75 0 0 1 .75.75v6.75h6.75a.75.75 0 0 1 0 1.5h-6.75v6.75a.75.75 0 0 1-1.5 0v-6.75H4.5a.75.75 0 0 1 0-1.5h6.75V4.5a.75.75 0 0 1 .75-.75Z" clip-rule="evenodd"/>',
			'update'   => '<path fill-rule="evenodd" d="M4.76 10.06a7.5 7.5 0 0 1 12.55-3.37l1.9 1.91h-3.18a.75.75 0 1 0 0 1.5h4.99a.75.75 0 0 0 .75-.75V4.36a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.3 9.67a.75.75 0 1 0 1.46.39Zm15.41 3.35a.75.75 0 0 0-.92.53 7.5 7.5 0 0 1-12.55 3.37l-1.9-1.91h3.18a.75.75 0 0 0 0-1.5H2.98a.75.75 0 0 0-.75.75v4.99a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.06-4.03.75.75 0 0 0-.53-.92Z" clip-rule="evenodd"/>',
		);
	}
}
