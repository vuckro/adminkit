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
	 * Hook the printers. In wp-admin (admin_head, priority 21 — just after
	 * branding) we print the FULL set: menu + toolbar. On the FRONT END the admin
	 * bar exists for logged-in users too, but #adminmenu does not — so on wp_head
	 * (same priority 21) we print ONLY the toolbar half, gated for the frontend.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'print_styles' ), 21 );
		add_action( 'wp_head', array( __CLASS__, 'print_toolbar_styles' ), 21 );
	}

	/**
	 * wp-admin printer: menu + toolbar icon CSS — gated by the opt-in toggle and
	 * the should_load pause.
	 *
	 * @return void
	 */
	public static function print_styles() {
		if ( ! self::enabled( 'admin' ) ) {
			return;
		}
		self::emit( self::menu_css() . self::toolbar_css() );
	}

	/**
	 * Front-end printer: TOOLBAR icon CSS only (no #adminmenu on the front end).
	 * Runs only when the admin bar is actually showing for this request, gated by
	 * the opt-in toggle and the frontend should_load pause.
	 *
	 * @return void
	 */
	public static function print_toolbar_styles() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		if ( ! self::enabled( 'frontend' ) ) {
			return;
		}
		self::emit( self::toolbar_css() );
	}

	/**
	 * Whether the icon feature should print for this context: the opt-in toggle is
	 * on AND the global should_load pause hasn't disabled AdminKit here.
	 *
	 * @param string $context 'admin' | 'frontend'
	 * @return bool
	 */
	private static function enabled( $context ) {
		if ( ! AdminKit_Settings::get( 'replace_icons_enabled' ) ) {
			return false;
		}
		return (bool) apply_filters( 'adminkit/should_load', true, $context );
	}

	/**
	 * Echo a <style> block for the built CSS (skipping an empty build).
	 *
	 * @param string $css
	 * @return void
	 */
	private static function emit( $css ) {
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
			'dashicons-admin-post'       => self::svg( 'article' ),
			'dashicons-admin-media'      => self::svg( 'photo' ),
			'dashicons-admin-page'       => self::svg( 'document' ),
			'dashicons-admin-comments'   => self::svg( 'chat' ),
			'dashicons-admin-appearance' => self::svg( 'brush' ),
			'dashicons-admin-plugins'    => self::svg( 'plugin' ),
			'dashicons-admin-users'      => self::svg( 'users' ),
			'dashicons-admin-tools'      => self::svg( 'wrench' ),
			'dashicons-admin-settings'   => self::svg( 'cog' ),
			'dashicons-admin-customizer' => self::svg( 'swatch' ),
			'dashicons-admin-generic'    => self::svg( 'cog' ),
			'dashicons-admin-network'    => self::svg( 'globe' ),
			'dashicons-admin-site'       => self::svg( 'globe' ),
			'dashicons-admin-site-alt3'  => self::svg( 'globe' ),
			// --- Common PLUGIN top-level dashicons. Related variants deliberately
			// share one glyph (e.g. every chart-* and analytics → 'chart'; all
			// email* → 'email'; shield/shield-alt/lock → security glyphs; both id*
			// → 'id'; both book* → 'book'; both money* → 'money'; both calendar* →
			// 'calendar'; gallery/images-alt2 → 'gallery'; screenoptions/layout →
			// 'app'-family grids). Keep core entries above untouched.
			'dashicons-cart'             => self::svg( 'cart' ),
			'dashicons-store'            => self::svg( 'store' ),
			'dashicons-products'         => self::svg( 'tag' ),
			'dashicons-chart-bar'        => self::svg( 'chart' ),
			'dashicons-chart-area'       => self::svg( 'chart' ),
			'dashicons-chart-pie'        => self::svg( 'chart' ),
			'dashicons-chart-line'       => self::svg( 'chart' ),
			'dashicons-analytics'        => self::svg( 'chart' ),
			'dashicons-email'            => self::svg( 'email' ),
			'dashicons-email-alt'        => self::svg( 'email' ),
			'dashicons-email-alt2'       => self::svg( 'email' ),
			'dashicons-megaphone'        => self::svg( 'megaphone' ),
			'dashicons-shield'           => self::svg( 'shield' ),
			'dashicons-shield-alt'       => self::svg( 'shield' ),
			'dashicons-lock'             => self::svg( 'lock' ),
			'dashicons-database'         => self::svg( 'database' ),
			'dashicons-cloud'            => self::svg( 'cloud' ),
			'dashicons-performance'      => self::svg( 'bolt' ),
			'dashicons-money'            => self::svg( 'money' ),
			'dashicons-money-alt'        => self::svg( 'money' ),
			'dashicons-calendar'         => self::svg( 'calendar' ),
			'dashicons-calendar-alt'     => self::svg( 'calendar' ),
			'dashicons-forms'            => self::svg( 'forms' ),
			'dashicons-feedback'         => self::svg( 'feedback' ),
			'dashicons-translation'      => self::svg( 'translation' ),
			'dashicons-search'           => self::svg( 'search' ),
			'dashicons-book'             => self::svg( 'book' ),
			'dashicons-book-alt'         => self::svg( 'book' ),
			'dashicons-id'               => self::svg( 'id' ),
			'dashicons-id-alt'           => self::svg( 'id' ),
			'dashicons-groups'           => self::svg( 'users' ),
			'dashicons-businessman'      => self::svg( 'users' ),
			'dashicons-tag'              => self::svg( 'tag' ),
			'dashicons-tickets-alt'      => self::svg( 'ticket' ),
			'dashicons-location'         => self::svg( 'location' ),
			'dashicons-art'              => self::svg( 'palette' ),
			'dashicons-format-gallery'   => self::svg( 'gallery' ),
			'dashicons-images-alt2'      => self::svg( 'gallery' ),
			'dashicons-list-view'        => self::svg( 'list' ),
			'dashicons-screenoptions'    => self::svg( 'app' ),
			'dashicons-layout'           => self::svg( 'app' ),
		);
		return (array) apply_filters( 'adminkit/menu_icons', $map );
	}

	/**
	 * Admin-bar icons, keyed by node id. Filter `adminkit/toolbar_icons`.
	 *
	 * Covers both wp-admin AND the front-end admin bar (logged-in users):
	 *   - comments / new-content / updates render an `.ab-icon` child span.
	 *   - edit (pencil) / customize (Customizer) paint their glyph on the link's
	 *     own `> .ab-item::before` instead — toolbar_css() detects which form a
	 *     node uses (see $ab_item_nodes there) and emits the matching selector.
	 * Integrations register their own plugin's node via this filter (Bricks, QM).
	 *
	 * @return array<string,string> node-id => SVG markup ('' = skip)
	 */
	private static function toolbar_icon_map() {
		$map = array(
			'wp-admin-bar-comments'    => self::svg( 'chat' ),
			'wp-admin-bar-new-content' => self::svg( 'plus' ),
			'wp-admin-bar-updates'     => self::svg( 'update' ),
			// Front-end / edit-screen core nodes. These paint via `> .ab-item::before`.
			// `edit` (edit this page/post) → a document-with-pencil, a more telling
			// "edit page" mark; it stays distinct from Articles (newspaper) and Pages
			// (a plain document). Same node in the front-end toolbar and the back office.
			'wp-admin-bar-edit'        => self::svg( 'document-pencil' ),
			'wp-admin-bar-customize'   => self::svg( 'sliders' ),
		);
		return (array) apply_filters( 'adminkit/toolbar_icons', $map );
	}

	/**
	 * Node ids whose icon is painted on the link's own `> .ab-item::before` rather
	 * than on a child `.ab-icon` span. Two cases:
	 *   - WP core nodes that ALREADY paint a dashicon FONT glyph there (edit,
	 *     customize) — we out-specify and replace it with our mask.
	 *   - nodes with a PLAIN-TEXT title and NO icon element at all (e.g. Bricks's
	 *     "Edit with Bricks" / "Rendered with Bricks") — here the same `::before`
	 *     rule CREATES the icon, prepending the masked glyph before the label.
	 * A node NOT listed here is assumed to carry an `.ab-icon` child span.
	 *
	 * Filterable so integrations can declare their own text-only node uses this
	 * form (return the node id => true). Mirrors `adminkit/toolbar_icons`.
	 *
	 * @return array<string,bool> node-id => true
	 */
	private static function ab_item_nodes() {
		$nodes = array(
			'wp-admin-bar-edit'      => true,
			'wp-admin-bar-customize' => true,
		);
		return (array) apply_filters( 'adminkit/toolbar_icon_ab_item_nodes', $nodes );
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
			// Set the icon box EXPLICITLY (don't depend on inherited WP rules that
			// can vary), then centre an inline-block masked ::before in it — reliably
			// on both axes: text-align:center handles horizontal (the mechanism WP's
			// own glyph uses), line-height + vertical-align:middle the vertical.
			// (margin:auto centring jammed icons left when the box wasn't a definite
			// width; this no longer relies on that.)
			$sel  = '#adminmenu .wp-menu-image.' . $class;
			$css .= $sel . '{box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
			// `vertical-align:middle` centres against the font's x-height, which sits a
			// hair low next to the menu label; a 2px relative lift optically aligns the
			// icon with the text (purely visual — `top` doesn't shift layout).
			$css .= $sel . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
				. 'vertical-align:middle;position:relative;top:-2px;' . self::mask( $svg ) . '}';
		}

		// GENERIC FALLBACK — give a cohesive neutral glyph (an "app/squares" grid) to
		// ANY top-level plugin menu item still rendered as a dashicon FONT glyph that
		// isn't specifically mapped above, instead of leaving a stray native dashicon.
		//
		// Specificity (must stay BELOW every per-class rule so mapped icons always win):
		//   fallback ::before  `#adminmenu .dashicons-before::before`
		//        = 1 id + 1 class + 1 pseudo-element            → (1,1,1)
		//   per-class ::before `#adminmenu .wp-menu-image.dashicons-<x>::before`
		//        = 1 id + 2 classes + 1 pseudo-element          → (1,2,1)
		//   (1,2,1) > (1,1,1) on the class column, so a MAPPED item's rule always
		//   outranks the fallback regardless of source order — the mapped glyph wins.
		//
		// Targeting `.dashicons-before` (the class WP puts on dashicon-FONT icons)
		// naturally EXCLUDES:
		//   - custom-image items  — their `.wp-menu-image` carries an inline
		//     background-image / <img>, NOT `.dashicons-before`, so this never hits them;
		//   - `.wp-menu-separator` — has no `.dashicons-before` either.
		// The container box is set at an even lower specificity ((1,1,0) < the per-class
		// (1,2,0)) so mapped items keep their own box rule untouched.
		$fb       = self::svg( 'app' );
		$fallback = '#adminmenu .dashicons-before';
		$css     .= $fallback . '{box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css     .= $fallback . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . self::mask( $fb ) . '}';

		return $css;
	}

	/**
	 * Build the admin-bar CSS: same idea on `.ab-icon::before` (WP forces
	 * background-image:none on `.ab-icon` itself, but the ::before is exempt).
	 *
	 * @return string
	 */
	private static function toolbar_css() {
		$css     = '';
		$ab_item = self::ab_item_nodes();
		foreach ( self::toolbar_icon_map() as $id => $svg ) {
			if ( '' === $svg || ! is_string( $svg ) ) {
				continue;
			}
			$css .= isset( $ab_item[ $id ] )
				? self::toolbar_ab_item_css( $id, $svg )
				: self::toolbar_ab_icon_css( $id, $svg );
		}
		if ( '' !== $css ) {
			$css = '@media screen and (min-width:783px){' . $css . '}';
		}
		return $css;
	}

	/**
	 * Icon CSS for a node that renders a child `.ab-icon` span (comments,
	 * new-content, updates, and most plugin nodes).
	 *
	 * WP core paints `.ab-icon` with `padding:4px 0; float:left` through a
	 * THREE-id selector (`#wpadminbar>#wp-toolbar>#wp-admin-bar-root-default
	 * .ab-icon`, specificity 3,1,0) and nudges the glyph with
	 * `position:relative; top:Npx`. A 2-id selector loses that tie, so the
	 * padding/top survived and shoved our icon ~4px low, overflowing the bar.
	 * Out-specify it: mirror the #wp-toolbar id chain AND double the class
	 * (`.ab-icon.ab-icon` → 3,2,0) so we win no matter the load order. Then
	 * model the box as exactly the 32px bar and centre a 20px mask in it
	 * (padding 6px → 6+20+6 = 32). Reset core's relative `top` on ::before.
	 *
	 * @param string $id
	 * @param string $svg
	 * @return string
	 */
	private static function toolbar_ab_icon_css( $id, $svg ) {
		$sel  = '#wpadminbar #wp-toolbar #' . $id . ' .ab-icon.ab-icon';
		$css  = $sel . '{box-sizing:border-box;height:32px;width:20px;padding:6px 0;margin-right:6px}';
		$css .= $sel . '::before{content:"";display:block;width:20px;height:20px;margin:0;'
			. 'position:static;top:auto;' . self::mask( $svg )
			. '}';
		return $css;
	}

	/**
	 * Icon CSS for a node whose glyph WP paints on the link's own
	 * `> .ab-item::before` as a dashicon FONT glyph (edit, customize, …) — there's
	 * no `.ab-icon` span here. WP's rule
	 * `#wpadminbar #wp-admin-bar-<id> > .ab-item:before { content:"\fXXX"; top:2px }`
	 * is (2,1,1); float/padding come from the (1,1,1) base rule. Out-specify both
	 * by mirroring the #wp-toolbar id chain and doubling the class
	 * (`> .ab-item.ab-item::before` → 3,2,1): drop the font glyph (`content:""`),
	 * model the same 32px box (the floated ::before IS the box here) and mask a
	 * 20px glyph in it, resetting core's relative `top`.
	 *
	 * @param string $id
	 * @param string $svg
	 * @return string
	 */
	private static function toolbar_ab_item_css( $id, $svg ) {
		$sel = '#wpadminbar #wp-toolbar #' . $id . ' > .ab-item.ab-item::before';
		return $sel . '{content:"";box-sizing:border-box;float:left;height:32px;width:20px;'
			. 'padding:6px 0;margin-right:6px;position:static;top:auto;' . self::mask( $svg )
			. '}';
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
			// Pencil-in-a-square — "edit this document" (kept as an alternate edit mark),
			// distinct from the bare `pencil` and from the article glyph below.
			'pencil-square' => '<path d="M5.43 13.92l1.27-3.16a4 4 0 0 1 .88-1.34L14.5 2.5a2.12 2.12 0 0 1 3 3l-6.92 6.92c-.38.38-.84.69-1.34.89l-3.15 1.26a.5.5 0 0 1-.66-.65Z"/><path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10a.75.75 0 0 0 0-1.5H4.75A2.75 2.75 0 0 0 2 5.75v13.5A2.75 2.75 0 0 0 4.75 22h13.5A2.75 2.75 0 0 0 21 19.25V14a.75.75 0 0 0-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25V5.75Z"/>',
			// Document-with-pencil — "edit THIS page" (toolbar `wp-admin-bar-edit`): a
			// folded-corner page with a small pencil writing across its lower half. Reads
			// as "edit page" at a glance and stays clearly distinct from `document`
			// (Pages: a plain lined page) and `article` (newspaper). The page body reuses
			// the proven `document` outline (minus its text lines, where the pencil now
			// sits). Front-end toolbar AND back office share the same `edit` node, so this
			// one mapping covers both contexts.
			'document-pencil' => '<path d="M5.63 1.5c-1.04 0-1.88.84-1.88 1.88v17.25c0 1.03.84 1.88 1.88 1.88h6.43a3.73 3.73 0 0 1 .01-1.45l.53-2.12a4.5 4.5 0 0 1 1.18-2.06l4.97-4.97a3.66 3.66 0 0 1 1.5-.91V12.75A3.75 3.75 0 0 0 16.5 9h-1.88a1.88 1.88 0 0 1-1.87-1.88V5.25A3.75 3.75 0 0 0 9 1.5H5.63Z"/><path d="M12.97 1.82A5.23 5.23 0 0 1 14.25 5.25v1.88c0 .2.17.37.38.37H16.5a5.23 5.23 0 0 1 3.43 1.28 9.77 9.77 0 0 0-6.96-6.96Z"/><path d="M19.18 11.42a2.1 2.1 0 0 1 2.97 2.97l-.53.53-2.97-2.97.53-.53Zm-1.59 1.59 2.97 2.97-4.62 4.62a2.6 2.6 0 0 1-1.1.66l-1.86.53a.6.6 0 0 1-.74-.74l.53-1.86a2.6 2.6 0 0 1 .66-1.1l4.62-4.62Z"/>',
			// Newspaper — "Articles" (`dashicons-admin-post`). A news/article mark,
			// distinct from `document` (Pages) and from the edit glyphs.
			'article'  => '<path fill-rule="evenodd" d="M4.13 3C3.09 3 2.25 3.84 2.25 4.88V18a3 3 0 0 0 3 3h15a3 3 0 0 1-3-3V4.88C17.25 3.84 16.41 3 15.38 3H4.13ZM12 9.75a.75.75 0 0 0 0 1.5h1.5a.75.75 0 0 0 0-1.5H12Zm-.75-2.25a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 0 1.5H12a.75.75 0 0 1-.75-.75ZM6 12.75a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5H6Zm-.75 3.75a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5H6a.75.75 0 0 1-.75-.75ZM6 6.75a.75.75 0 0 0-.75.75v3c0 .41.34.75.75.75h3a.75.75 0 0 0 .75-.75v-3A.75.75 0 0 0 9 6.75H6Z" clip-rule="evenodd"/><path d="M18.75 6.75h1.88c.62 0 1.12.5 1.12 1.13V18a1.5 1.5 0 0 1-3 0V6.75Z"/>',
			'photo'    => '<path fill-rule="evenodd" d="M1.5 6A2.25 2.25 0 0 1 3.75 3.75h16.5A2.25 2.25 0 0 1 22.5 6v12a2.25 2.25 0 0 1-2.25 2.25H3.75A2.25 2.25 0 0 1 1.5 18V6ZM3 16.06V18c0 .41.34.75.75.75h16.5A.75.75 0 0 0 21 18v-1.94l-2.69-2.69a1.5 1.5 0 0 0-2.12 0l-.88.88.97.97a.75.75 0 1 1-1.06 1.06l-5.16-5.16a1.5 1.5 0 0 0-2.12 0L3 16.06Zm10.13-7.81a1.13 1.13 0 1 1 2.25 0 1.13 1.13 0 0 1-2.25 0Z" clip-rule="evenodd"/>',
			'document' => '<path fill-rule="evenodd" d="M5.63 1.5c-1.04 0-1.88.84-1.88 1.88v17.25c0 1.03.84 1.88 1.88 1.88h12.75c1.03 0 1.88-.84 1.88-1.88V12.75A3.75 3.75 0 0 0 16.5 9h-1.88a1.88 1.88 0 0 1-1.87-1.88V5.25A3.75 3.75 0 0 0 9 1.5H5.63ZM7.5 15a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 7.5 15Zm.75 2.25a.75.75 0 0 0 0 1.5H12a.75.75 0 0 0 0-1.5H8.25Z" clip-rule="evenodd"/><path d="M12.97 1.82A5.23 5.23 0 0 1 14.25 5.25v1.88c0 .2.17.37.38.37H16.5a5.23 5.23 0 0 1 3.43 1.28 9.77 9.77 0 0 0-6.96-6.96Z"/>',
			'chat'     => '<path fill-rule="evenodd" d="M4.85 2.77A49.14 49.14 0 0 1 12 2.25c2.43 0 4.82.18 7.15.52 1.98.29 3.35 2.02 3.35 3.97v6.02c0 1.95-1.37 3.68-3.35 3.97-1.15.17-2.31.3-3.48.38a.39.39 0 0 0-.3.17l-2.75 4.13a.75.75 0 0 1-1.25 0l-2.75-4.13a.39.39 0 0 0-.3-.17 48.9 48.9 0 0 1-3.48-.38C2.87 16.44 1.5 14.7 1.5 12.76V6.74c0-1.95 1.37-3.68 3.35-3.97Z" clip-rule="evenodd"/>',
			'brush'    => '<path fill-rule="evenodd" d="M20.6 1.5c-.38 0-.74.11-1.06.32l-5.08 3.39a18.75 18.75 0 0 0-3.47 2.98 10.04 10.04 0 0 1 4.82 4.82 18.75 18.75 0 0 0 2.98-3.47l3.39-5.08A1.9 1.9 0 0 0 20.6 1.5Zm-8.3 14.03a18.76 18.76 0 0 0 1.9-1.21 8.03 8.03 0 0 0-4.52-4.51 18.75 18.75 0 0 0-1.2 1.9l-.28.5a5.26 5.26 0 0 1 3.6 3.6l.5-.28ZM6.75 13.5A3.75 3.75 0 0 0 3 17.25a1.5 1.5 0 0 1-1.6 1.5.75.75 0 0 0-.7 1.12 5.25 5.25 0 0 0 9.8-2.62 3.75 3.75 0 0 0-3.75-3.75Z" clip-rule="evenodd"/>',
			'plugin'   => '<path d="M11.25 5.34c0-.36-.19-.68-.4-.96a1.65 1.65 0 0 1-.35-1c0-1.04 1.01-1.88 2.25-1.88s2.25.84 2.25 1.88c0 .37-.13.71-.35 1-.21.28-.4.6-.4.96 0 .33.28.6.61.58 1.91-.11 3.79-.34 5.63-.68a.75.75 0 0 1 .88.65c.22 1.8.35 3.62.38 5.45a.66.66 0 0 1-.66.66c-.36 0-.68-.19-.96-.4a1.65 1.65 0 0 0-1-.35c-1.04 0-1.88 1.01-1.88 2.25s.84 2.25 1.88 2.25c.37 0 .71-.13 1-.35.28-.21.6-.4.96-.4.31 0 .56.26.53.57-.12 1.62-.32 3.23-.6 4.85a.75.75 0 0 1-.6.6c-1.62.3-3.27.51-4.95.63a.72.72 0 0 1-.78-.71c0-.36.19-.68.4-.96.22-.28.35-.63.35-1 0-1.04-.84-1.88-1.88-1.88s-1.87.84-1.87 1.88c0 .37.13.72.34 1 .22.29.41.6.4.96a.72.72 0 0 1-.78.71c-1.66-.12-3.31-.33-4.94-.63a.75.75 0 0 1-.61-.6c-.28-1.6-.48-3.21-.59-4.83a.71.71 0 0 1 .79-.78c.36.03.71.16 1 .39.27.22.6.4.94.4 1.04 0 1.88-.84 1.88-1.87S6.43 12.75 5.4 12.75c-.34 0-.67.19-.94.4-.29.24-.64.37-1 .39a.71.71 0 0 1-.79-.78c.11-1.62.31-3.23.59-4.83a.75.75 0 0 1 .61-.61c1.59-.29 3.23-.5 4.9-.62a.72.72 0 0 0 .78-.71Z"/>',
			'users'    => '<path d="M4.5 6.38a4.13 4.13 0 1 1 8.25 0 4.13 4.13 0 0 1-8.25 0ZM14.25 8.63a3.38 3.38 0 1 1 6.75 0 3.38 3.38 0 0 1-6.75 0ZM1.5 19.13a7.13 7.13 0 0 1 14.25 0v.12a.75.75 0 0 1-.36.63 13.07 13.07 0 0 1-6.76 1.87c-2.47 0-4.79-.68-6.76-1.87a.75.75 0 0 1-.37-.63v-.12ZM17.25 19.13v.14a2.25 2.25 0 0 1-.24.96 10.09 10.09 0 0 0 5.06-1.01.75.75 0 0 0 .42-.64 4.88 4.88 0 0 0-6.96-4.61 8.59 8.59 0 0 1 1.72 5.16Z"/>',
			'wrench'   => '<path fill-rule="evenodd" d="M12 6.75a5.25 5.25 0 0 1 6.78-5.03.75.75 0 0 1 .31 1.25l-3.32 3.32c.06.48.28.93.64 1.3.37.36.83.58 1.3.64l3.32-3.32a.75.75 0 0 1 1.25.31 5.25 5.25 0 0 1-5.47 6.76c-1.02-.09-1.87.1-2.31.63L7.34 21.3A3.3 3.3 0 1 1 2.7 16.66l8.68-7.15c.53-.44.72-1.29.64-2.31A5.34 5.34 0 0 1 12 6.75ZM4.12 19.13a.75.75 0 0 1 .75-.75h.01a.75.75 0 0 1 .75.75v.01a.75.75 0 0 1-.75.75h-.01a.75.75 0 0 1-.75-.75v-.01Z" clip-rule="evenodd"/>',
			'cog'      => '<path fill-rule="evenodd" d="M11.08 2.25c-.92 0-1.7.66-1.85 1.57l-.18 1.07c-.02.12-.11.26-.3.35-.34.16-.67.35-.98.57-.17.11-.34.13-.45.08l-1.02-.38a1.88 1.88 0 0 0-2.28.82l-.92 1.6a1.88 1.88 0 0 0 .43 2.38l.84.7c.1.07.17.22.15.42a7.6 7.6 0 0 0 0 1.14c.02.2-.06.35-.15.43l-.84.7a1.88 1.88 0 0 0-.43 2.38l.92 1.6a1.88 1.88 0 0 0 2.28.82l1.02-.38c.11-.05.28-.04.45.08.31.22.64.41.98.57.19.09.28.23.3.35l.18 1.07c.15.9.93 1.57 1.85 1.57h1.84c.92 0 1.7-.66 1.85-1.57l.18-1.07c.02-.12.11-.26.3-.35.34-.16.67-.36.98-.57.17-.11.34-.13.45-.08l1.02.38a1.88 1.88 0 0 0 2.28-.82l.92-1.6a1.88 1.88 0 0 0-.43-2.38l-.84-.7c-.1-.08-.17-.23-.15-.43a7.62 7.62 0 0 0 0-1.14c-.02-.2.06-.35.15-.43l.84-.69c.71-.58.89-1.59.43-2.39l-.92-1.6a1.88 1.88 0 0 0-2.28-.81l-1.02.38c-.11.04-.28.03-.45-.08-.31-.22-.64-.41-.98-.57-.18-.09-.28-.23-.3-.35l-.18-1.07a1.88 1.88 0 0 0-1.85-1.57h-1.84ZM12 15.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z" clip-rule="evenodd"/>',
			'swatch'   => '<path fill-rule="evenodd" d="M2.25 4.125c0-1.036.84-1.875 1.875-1.875h5.25c1.036 0 1.875.84 1.875 1.875V17.25a4.5 4.5 0 1 1-9 0V4.125Zm4.5 14.625a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Z" clip-rule="evenodd"/><path d="M10.72 21.75h9.155c1.036 0 1.875-.84 1.875-1.875v-5.25c0-1.036-.84-1.875-1.875-1.875h-.14l-8.743 8.743c-.09.089-.18.175-.273.257ZM12.738 17.625l6.474-6.474a1.875 1.875 0 0 0 0-2.651L15.5 4.787a1.875 1.875 0 0 0-2.651 0l-.1.099V17.25c0 .126 0 .251-.01.375Z"/>',
			'globe'    => '<path fill-rule="evenodd" d="M12 2.25c-5.39 0-9.75 4.36-9.75 9.75s4.36 9.75 9.75 9.75 9.75-4.36 9.75-9.75S17.39 2.25 12 2.25Zm4.95 7.5h2.6a8.25 8.25 0 0 0-4.6-5.36 14.3 14.3 0 0 1 2 5.36Zm-1.53 0a12.6 12.6 0 0 0-2.67-5.92.75.75 0 0 0-1.5 0A12.6 12.6 0 0 0 8.58 9.75h6.84Zm-6.95 1.5a13.3 13.3 0 0 0 0 1.5h7.06a13.3 13.3 0 0 0 0-1.5H8.47Zm.11 3a12.6 12.6 0 0 0 2.67 5.92.75.75 0 0 0 1.5 0 12.6 12.6 0 0 0 2.67-5.92H8.58Zm6.37 5.36a14.3 14.3 0 0 0 2-5.36h2.6a8.25 8.25 0 0 1-4.6 5.36ZM9.05 4.39a14.3 14.3 0 0 0-2 5.36h-2.6a8.25 8.25 0 0 1 4.6-5.36Zm-4.6 9.86h2.6a14.3 14.3 0 0 0 2 5.36 8.25 8.25 0 0 1-4.6-5.36Z" clip-rule="evenodd"/>',
			'plus'     => '<path fill-rule="evenodd" d="M12 3.75a.75.75 0 0 1 .75.75v6.75h6.75a.75.75 0 0 1 0 1.5h-6.75v6.75a.75.75 0 0 1-1.5 0v-6.75H4.5a.75.75 0 0 1 0-1.5h6.75V4.5a.75.75 0 0 1 .75-.75Z" clip-rule="evenodd"/>',
			'update'   => '<path fill-rule="evenodd" d="M4.76 10.06a7.5 7.5 0 0 1 12.55-3.37l1.9 1.91h-3.18a.75.75 0 1 0 0 1.5h4.99a.75.75 0 0 0 .75-.75V4.36a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.3 9.67a.75.75 0 1 0 1.46.39Zm15.41 3.35a.75.75 0 0 0-.92.53 7.5 7.5 0 0 1-12.55 3.37l-1.9-1.91h3.18a.75.75 0 0 0 0-1.5H2.98a.75.75 0 0 0-.75.75v4.99a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.06-4.03.75.75 0 0 0-.53-.92Z" clip-rule="evenodd"/>',
			// --- Plugin-oriented glyphs (added so common plugin top-level dashicons
			// get a cohesive icon). Some roles are intentionally shared by several
			// dashicon variants (see menu_icon_map()).
			'cart'        => '<path d="M2.25 2.25a.75.75 0 0 0 0 1.5h1.39l.59 2.35a.78.78 0 0 0 .03.12l1.66 6.64a3 3 0 0 0 2.91 2.27h7.84a3 3 0 0 0 2.91-2.27l1.65-6.6a.75.75 0 0 0-.73-.93H6.46l-.34-1.36A1.5 1.5 0 0 0 4.67 2.25H2.25Z"/><path d="M3.75 21a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0ZM16.5 21a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0Z"/>',
			'store'       => '<path fill-rule="evenodd" d="M5.23 2.25a3 3 0 0 0-2.77 1.84L1.3 6.86a3.75 3.75 0 0 0 1.45 4.45V18.75a1.5 1.5 0 0 0 1.5 1.5h15.5a1.5 1.5 0 0 0 1.5-1.5v-7.44a3.75 3.75 0 0 0 1.45-4.45l-1.16-2.77a3 3 0 0 0-2.77-1.84H5.23ZM15 12.75a3 3 0 0 1-6 0 .75.75 0 0 0-1.5 0 3 3 0 0 1-5.6 1.49.75.75 0 0 0-.15-.18v4.69h15.5v-4.69a.75.75 0 0 0-.15.18 3 3 0 0 1-5.6-1.49.75.75 0 0 0-1.5 0Z" clip-rule="evenodd"/>',
			'tag'         => '<path fill-rule="evenodd" d="M5.25 2.25A3 3 0 0 0 2.25 5.25v5.84a3 3 0 0 0 .88 2.12l8.69 8.69a2.25 2.25 0 0 0 3.18 0l5.84-5.84a2.25 2.25 0 0 0 0-3.18l-8.69-8.69a3 3 0 0 0-2.12-.88H5.25ZM6.75 7.5a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" clip-rule="evenodd"/>',
			'chart'       => '<path fill-rule="evenodd" d="M2.25 13.5a.75.75 0 0 1 .75-.75h2.25a.75.75 0 0 1 .75.75v6a.75.75 0 0 1-.75.75H3a.75.75 0 0 1-.75-.75v-6Zm6.75-4.5a.75.75 0 0 1 .75-.75h2.25a.75.75 0 0 1 .75.75v10.5a.75.75 0 0 1-.75.75H9.75a.75.75 0 0 1-.75-.75V9Zm6.75-4.5a.75.75 0 0 1 .75-.75h2.25a.75.75 0 0 1 .75.75v15a.75.75 0 0 1-.75.75H16.5a.75.75 0 0 1-.75-.75v-15Z" clip-rule="evenodd"/>',
			'email'       => '<path d="M1.5 8.67v8.58a3 3 0 0 0 3 3h15a3 3 0 0 0 3-3V8.67l-9.9 5.94a2.25 2.25 0 0 1-2.2 0L1.5 8.67Z"/><path d="M22.5 6.91v-.16a3 3 0 0 0-3-3h-15a3 3 0 0 0-3 3v.16l10.67 6.4a.75.75 0 0 0 .66 0l10.67-6.4Z"/>',
			'megaphone'   => '<path fill-rule="evenodd" d="M16.88 3.55a.75.75 0 0 1 .37.65v15.6a.75.75 0 0 1-1.18.61l-4.6-3.22a.75.75 0 0 0-.43-.14H8.62l1.06 4.07a.75.75 0 0 1-.73.94H6.6a.75.75 0 0 1-.72-.55L4.6 17.02a3.38 3.38 0 0 1-.85-6.66V8.7c0-.5.34-.94.83-1.07l9.7-2.6.86-.6a.75.75 0 0 1 .79-.07c.32.15.62.31.95.19Z" clip-rule="evenodd"/>',
			'shield'      => '<path fill-rule="evenodd" d="M12.52 2.31a.75.75 0 0 0-1.04 0 17.25 17.25 0 0 1-7.57 4.04.75.75 0 0 0-.56.65 18.75 18.75 0 0 0 8.04 16.45.75.75 0 0 0 .82 0 18.75 18.75 0 0 0 8.04-16.45.75.75 0 0 0-.56-.65 17.25 17.25 0 0 1-7.17-4.04Zm3.23 6.97a.75.75 0 0 0-1.22-.86l-3.24 4.53-1.6-1.6a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.1l3.73-5.23Z" clip-rule="evenodd"/>',
			'lock'        => '<path fill-rule="evenodd" d="M12 1.5a5.25 5.25 0 0 0-5.25 5.25v3h-.38a2.62 2.62 0 0 0-2.62 2.62v6.76a2.62 2.62 0 0 0 2.62 2.62h11.26a2.62 2.62 0 0 0 2.62-2.62v-6.76a2.62 2.62 0 0 0-2.62-2.62h-.38v-3A5.25 5.25 0 0 0 12 1.5Zm3.75 8.25v-3a3.75 3.75 0 1 0-7.5 0v3h7.5Z" clip-rule="evenodd"/>',
			'database'    => '<path d="M21 6.38c0 2-4.03 3.62-9 3.62S3 8.38 3 6.38 7.03 2.75 12 2.75s9 1.62 9 3.63Z"/><path d="M12 11.5c2.97 0 5.78-.54 7.92-1.55.4-.18.78-.4 1.08-.65v3.07c0 2-4.03 3.63-9 3.63s-9-1.62-9-3.63V9.3c.3.25.68.47 1.08.65C6.22 10.96 9.03 11.5 12 11.5Z"/><path d="M12 17c2.97 0 5.78-.54 7.92-1.55.4-.18.78-.4 1.08-.65v2.82c0 2-4.03 3.63-9 3.63s-9-1.62-9-3.63V14.8c.3.25.68.47 1.08.65C6.22 16.46 9.03 17 12 17Z"/>',
			'cloud'       => '<path fill-rule="evenodd" d="M4.5 9.75a6 6 0 0 1 11.57-2.23 4.13 4.13 0 0 1 4.69 5.95 3.75 3.75 0 0 1-1.76 7.03H6.75a5.25 5.25 0 0 1-2.61-9.8 6.03 6.03 0 0 1 .36-.95Z" clip-rule="evenodd"/>',
			'bolt'        => '<path fill-rule="evenodd" d="M14.62 2.27a.75.75 0 0 1 .43.84L13.65 9.6h5.6a.75.75 0 0 1 .58 1.22l-9.75 12a.75.75 0 0 1-1.33-.62l1.4-6.49H4.55a.75.75 0 0 1-.58-1.22l9.75-12a.75.75 0 0 1 .9-.22Z" clip-rule="evenodd"/>',
			'money'       => '<path fill-rule="evenodd" d="M12 2.25c-5.39 0-9.75 4.36-9.75 9.75s4.36 9.75 9.75 9.75 9.75-4.36 9.75-9.75S17.39 2.25 12 2.25Zm.75 4.5a.75.75 0 0 0-1.5 0v.26c-.43.08-.84.23-1.2.46-.64.42-1.18 1.12-1.18 2.03 0 .87.5 1.5 1.1 1.9.55.36 1.24.58 1.85.77l.08.03c.66.2 1.14.36 1.45.57.27.18.3.32.3.45 0 .17-.08.34-.3.49-.24.16-.62.27-1.05.27-.6 0-1.08-.2-1.34-.41a.75.75 0 0 0-.95 1.16c.4.32.94.55 1.49.66v.28a.75.75 0 0 0 1.5 0v-.27c.43-.08.84-.23 1.2-.46.64-.42 1.18-1.13 1.18-2.03 0-.88-.5-1.51-1.1-1.91-.55-.36-1.24-.57-1.85-.76l-.08-.03c-.66-.2-1.14-.36-1.45-.57-.27-.18-.3-.31-.3-.45 0-.16.08-.34.3-.48.24-.16.62-.27 1.05-.27.6 0 1.08.2 1.34.4a.75.75 0 1 0 .95-1.15 3.27 3.27 0 0 0-1.49-.66v-.28Z" clip-rule="evenodd"/>',
			'calendar'    => '<path fill-rule="evenodd" d="M6.75 2.25A.75.75 0 0 1 7.5 3v1.5h9V3a.75.75 0 0 1 1.5 0v1.5h.75a3 3 0 0 1 3 3v11.25a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3V7.5a3 3 0 0 1 3-3H6V3a.75.75 0 0 1 .75-.75Zm13.5 9a1.5 1.5 0 0 0-1.5-1.5H5.25a1.5 1.5 0 0 0-1.5 1.5v7.5a1.5 1.5 0 0 0 1.5 1.5h13.5a1.5 1.5 0 0 0 1.5-1.5v-7.5Z" clip-rule="evenodd"/>',
			'forms'       => '<path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.75 20.1a8.25 8.25 0 0 1 16.5 0 .75.75 0 0 1-.46.71A18.66 18.66 0 0 1 12 22.5c-2.79 0-5.45-.6-7.84-1.69a.75.75 0 0 1-.46-.7Z" clip-rule="evenodd"/>',
			'feedback'    => '<path fill-rule="evenodd" d="M12 2.25c-5.39 0-9.75 3.6-9.75 8.05 0 2.06.94 3.93 2.48 5.35a8.96 8.96 0 0 1-1.97 3.16.75.75 0 0 0 .73 1.25 12.6 12.6 0 0 0 3.96-1.66 11.4 11.4 0 0 0 4.55.95c5.39 0 9.75-3.6 9.75-8.05S17.39 2.25 12 2.25Z" clip-rule="evenodd"/>',
			'translation' => '<path fill-rule="evenodd" d="M9 2.25a.75.75 0 0 1 .75.75v.75h3.75a.75.75 0 0 1 0 1.5h-.66c-.51 2.42-1.66 4.6-3.27 6.34a13.7 13.7 0 0 0 2.06 1.5.75.75 0 0 1-.75 1.3 15.2 15.2 0 0 1-2.38-1.74 15.2 15.2 0 0 1-4.06 2.6.75.75 0 1 1-.6-1.38 13.7 13.7 0 0 0 3.56-2.27 13.78 13.78 0 0 1-2.13-3.05.75.75 0 0 1 1.34-.68c.46.93 1.04 1.78 1.72 2.55a11.27 11.27 0 0 0 2.6-5.17H3a.75.75 0 0 1 0-1.5h5.25V3A.75.75 0 0 1 9 2.25Zm6.75 8.25a.75.75 0 0 1 .69.46l4.5 10.5a.75.75 0 0 1-1.38.58l-1.1-2.54h-5.42l-1.1 2.54a.75.75 0 0 1-1.38-.58l4.5-10.5a.75.75 0 0 1 .69-.46Zm-1.96 7.5h3.92l-1.96-4.57-1.96 4.57Z" clip-rule="evenodd"/>',
			'search'      => '<path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 1 0 0 13.5 6.75 6.75 0 0 0 0-13.5ZM2.25 10.5a8.25 8.25 0 1 1 14.59 5.28l4.69 4.69a.75.75 0 1 1-1.06 1.06l-4.69-4.69A8.25 8.25 0 0 1 2.25 10.5Z" clip-rule="evenodd"/>',
			'book'        => '<path d="M11.25 4.53A9.7 9.7 0 0 0 6 3a9.7 9.7 0 0 0-3.66.71.75.75 0 0 0-.46.69v12.66a.75.75 0 0 0 1.04.69A8.2 8.2 0 0 1 6 17.25c1.95 0 3.76.66 5.25 1.76V4.53ZM12.75 19.01a8.96 8.96 0 0 1 5.25-1.76c1.05 0 2.06.18 3.08.5a.75.75 0 0 0 1.04-.69V4.4a.75.75 0 0 0-.46-.69A9.7 9.7 0 0 0 18 3a9.7 9.7 0 0 0-5.25 1.53v14.48Z"/>',
			'id'          => '<path fill-rule="evenodd" d="M1.5 6a3 3 0 0 1 3-3h15a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-15a3 3 0 0 1-3-3V6Zm6 1.5a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5Zm-3.6 9.32a4.5 4.5 0 0 1 7.2 0 .75.75 0 0 1-.27.97A6.2 6.2 0 0 1 7.5 18.75a6.2 6.2 0 0 1-3.33-.96.75.75 0 0 1-.27-.97ZM14.25 9a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 0 1.5h-3a.75.75 0 0 1-.75-.75Zm0 3a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 0 1.5h-3a.75.75 0 0 1-.75-.75Zm0 3a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 0 1.5h-3a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/>',
			'location'    => '<path fill-rule="evenodd" d="M11.54 22.35a.75.75 0 0 0 .92 0c.78-.61 1.52-1.27 2.2-1.96A14.25 14.25 0 0 0 19.5 9.75a7.5 7.5 0 1 0-15 0 14.25 14.25 0 0 0 4.84 10.64c.68.69 1.42 1.35 2.2 1.96ZM12 12.75a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/>',
			'ticket'      => '<path fill-rule="evenodd" d="M1.5 6.38A2.62 2.62 0 0 1 4.12 3.75h15.76a2.62 2.62 0 0 1 2.62 2.63v2.16a.75.75 0 0 1-.55.72 2.25 2.25 0 0 0 0 4.34.75.75 0 0 1 .55.72v2.16a2.62 2.62 0 0 1-2.62 2.62H4.12a2.62 2.62 0 0 1-2.62-2.62v-2.16a.75.75 0 0 1 .55-.72 2.25 2.25 0 0 0 0-4.34.75.75 0 0 1-.55-.72V6.38Zm14.25.37a.75.75 0 0 1 .75.75v.75a.75.75 0 0 1-1.5 0V7.5a.75.75 0 0 1 .75-.75Zm.75 5.25a.75.75 0 0 0-1.5 0v.75a.75.75 0 0 0 1.5 0v-.75Zm-.75 4.5a.75.75 0 0 1 .75.75v.75a.75.75 0 0 1-1.5 0v-.75a.75.75 0 0 1 .75-.75Z" clip-rule="evenodd"/>',
			'palette'     => '<path fill-rule="evenodd" d="M12 2.25c-5.39 0-9.75 4.36-9.75 9.75 0 4.97 3.72 9.07 8.52 9.68.62.08 1.1-.42 1.13-.96.03-.5-.27-.97-.6-1.35-.34-.4-.55-.87-.55-1.37a2.25 2.25 0 0 1 2.25-2.25h2.65a4.6 4.6 0 0 0 4.6-4.6c0-4.92-4.18-8.9-8.25-8.9ZM6.75 12a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm3-3.75a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4.5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3ZM17.25 12a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z" clip-rule="evenodd"/>',
			'gallery'     => '<path fill-rule="evenodd" d="M1.5 6A2.25 2.25 0 0 1 3.75 3.75h16.5A2.25 2.25 0 0 1 22.5 6v9a2.25 2.25 0 0 1-2.25 2.25H3.75A2.25 2.25 0 0 1 1.5 15V6Zm14.13 2.25a1.13 1.13 0 1 0 0 2.25 1.13 1.13 0 0 0 0-2.25ZM3 13.06l3.69-3.69a1.5 1.5 0 0 1 2.12 0l3.07 3.07.94-.94a1.5 1.5 0 0 1 2.12 0L21 16.44V15.75H3v-2.69Z" clip-rule="evenodd"/><path d="M3.75 19.5a.75.75 0 0 0 0 1.5h16.5a.75.75 0 0 0 0-1.5H3.75Z"/>',
			'list'        => '<path fill-rule="evenodd" d="M2.62 6.75a1.12 1.12 0 1 1 2.25 0 1.12 1.12 0 0 1-2.25 0ZM7.5 6.75a.75.75 0 0 1 .75-.75h12a.75.75 0 0 1 0 1.5h-12a.75.75 0 0 1-.75-.75ZM2.62 12a1.12 1.12 0 1 1 2.25 0 1.12 1.12 0 0 1-2.25 0ZM7.5 12a.75.75 0 0 1 .75-.75h12a.75.75 0 0 1 0 1.5h-12A.75.75 0 0 1 7.5 12ZM2.62 17.25a1.12 1.12 0 1 1 2.25 0 1.12 1.12 0 0 1-2.25 0ZM7.5 17.25a.75.75 0 0 1 .75-.75h12a.75.75 0 0 1 0 1.5h-12a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/>',
			'sliders'     => '<path fill-rule="evenodd" d="M10.5 3.75a.75.75 0 0 0-1.5 0v6.51a3 3 0 0 0 0 5.98v4.01a.75.75 0 0 0 1.5 0v-4.01a3 3 0 0 0 0-5.98V3.75ZM5.25 3.75a.75.75 0 0 0-1.5 0v.51a3 3 0 0 0 0 5.98V20.25a.75.75 0 0 0 1.5 0V10.24a3 3 0 0 0 0-5.98V3.75ZM18.75 3.75a.75.75 0 0 0-1.5 0V13.76a3 3 0 0 0 0 5.98v.51a.75.75 0 0 0 1.5 0v-.51a3 3 0 0 0 0-5.98V3.75Z" clip-rule="evenodd"/>',
			// Generic neutral fallback for unmapped plugin menu items (a "squares/app"
			// grid). Used ONLY by the low-specificity fallback rule in menu_css().
			'app'         => '<path fill-rule="evenodd" d="M4.5 3.75a.75.75 0 0 0-.75.75v4.5c0 .41.34.75.75.75H9a.75.75 0 0 0 .75-.75v-4.5A.75.75 0 0 0 9 3.75H4.5ZM15 3.75a.75.75 0 0 0-.75.75v4.5c0 .41.34.75.75.75h4.5a.75.75 0 0 0 .75-.75v-4.5a.75.75 0 0 0-.75-.75H15ZM4.5 14.25a.75.75 0 0 0-.75.75v4.5c0 .41.34.75.75.75H9a.75.75 0 0 0 .75-.75V15a.75.75 0 0 0-.75-.75H4.5ZM15 14.25a.75.75 0 0 0-.75.75v4.5c0 .41.34.75.75.75h4.5a.75.75 0 0 0 .75-.75V15a.75.75 0 0 0-.75-.75H15Z" clip-rule="evenodd"/>',
		);
	}
}
