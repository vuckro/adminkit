<?php
/**
 * Menu & toolbar icons — swap WordPress's native dashicons for a cohesive AdminKit
 * icon set (Heroicons). On by default via the `replace_icons_enabled` setting.
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
	 * wp-admin printer: menu + toolbar icon CSS — gated by the icon toggle and
	 * the should_load pause.
	 *
	 * @return void
	 */
	public static function print_styles() {
		if ( ! self::enabled( 'admin' ) ) {
			return;
		}
		self::emit( self::menu_css() . self::slug_css() . self::toolbar_css() );
	}

	/**
	 * Front-end printer: TOOLBAR icon CSS only (no #adminmenu on the front end).
	 * Runs only when the admin bar is actually showing for this request, gated by
	 * the icon toggle and the frontend should_load pause.
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
	 * Whether the icon feature should print for this context: the icon toggle is
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
			'dashicons-dashboard'        => AdminKit_Icons::svg( 'home' ),
			'dashicons-admin-home'       => AdminKit_Icons::svg( 'home' ),
			'dashicons-admin-post'       => AdminKit_Icons::svg( 'article' ),
			'dashicons-admin-media'      => AdminKit_Icons::svg( 'photo' ),
			'dashicons-admin-page'       => AdminKit_Icons::svg( 'document' ),
			'dashicons-admin-comments'   => AdminKit_Icons::svg( 'chat' ),
			'dashicons-admin-appearance' => AdminKit_Icons::svg( 'brush' ),
			'dashicons-admin-plugins'    => AdminKit_Icons::svg( 'plugin' ),
			'dashicons-admin-users'      => AdminKit_Icons::svg( 'users' ),
			'dashicons-admin-tools'      => AdminKit_Icons::svg( 'wrench' ),
			'dashicons-admin-settings'   => AdminKit_Icons::svg( 'cog' ),
			'dashicons-admin-customizer' => AdminKit_Icons::svg( 'swatch' ),
			// NOTE: `dashicons-admin-generic` (the default gear a plugin gets when it
			// sets no icon) is intentionally NOT mapped — those generic plugins keep
			// their native gear rather than all collapsing to one AdminKit glyph.
			'dashicons-admin-network'    => AdminKit_Icons::svg( 'globe' ),
			'dashicons-admin-site'       => AdminKit_Icons::svg( 'globe' ),
			'dashicons-admin-site-alt3'  => AdminKit_Icons::svg( 'globe' ),
			// --- Common PLUGIN top-level dashicons. Related variants deliberately
			// share one glyph (e.g. every chart-* and analytics → 'chart'; all
			// email* → 'email'; shield/shield-alt/lock → security glyphs; both id*
			// → 'id'; both book* → 'book'; both money* → 'money'; both calendar* →
			// 'calendar'; gallery/images-alt2 → 'gallery'; screenoptions/layout →
			// 'app'-family grids). Keep core entries above untouched.
			'dashicons-cart'             => AdminKit_Icons::svg( 'cart' ),
			'dashicons-store'            => AdminKit_Icons::svg( 'store' ),
			'dashicons-products'         => AdminKit_Icons::svg( 'tag' ),
			'dashicons-chart-bar'        => AdminKit_Icons::svg( 'chart' ),
			'dashicons-chart-area'       => AdminKit_Icons::svg( 'chart' ),
			'dashicons-chart-pie'        => AdminKit_Icons::svg( 'chart' ),
			'dashicons-chart-line'       => AdminKit_Icons::svg( 'chart' ),
			'dashicons-analytics'        => AdminKit_Icons::svg( 'chart' ),
			'dashicons-email'            => AdminKit_Icons::svg( 'email' ),
			'dashicons-email-alt'        => AdminKit_Icons::svg( 'email' ),
			'dashicons-email-alt2'       => AdminKit_Icons::svg( 'email' ),
			'dashicons-megaphone'        => AdminKit_Icons::svg( 'megaphone' ),
			'dashicons-shield'           => AdminKit_Icons::svg( 'shield' ),
			'dashicons-shield-alt'       => AdminKit_Icons::svg( 'shield' ),
			'dashicons-lock'             => AdminKit_Icons::svg( 'lock' ),
			'dashicons-database'         => AdminKit_Icons::svg( 'database' ),
			'dashicons-cloud'            => AdminKit_Icons::svg( 'cloud' ),
			'dashicons-performance'      => AdminKit_Icons::svg( 'bolt' ),
			'dashicons-money'            => AdminKit_Icons::svg( 'money' ),
			'dashicons-money-alt'        => AdminKit_Icons::svg( 'money' ),
			'dashicons-calendar'         => AdminKit_Icons::svg( 'calendar' ),
			'dashicons-calendar-alt'     => AdminKit_Icons::svg( 'calendar' ),
			'dashicons-forms'            => AdminKit_Icons::svg( 'forms' ),
			'dashicons-feedback'         => AdminKit_Icons::svg( 'feedback' ),
			'dashicons-translation'      => AdminKit_Icons::svg( 'translation' ),
			'dashicons-search'           => AdminKit_Icons::svg( 'search' ),
			'dashicons-book'             => AdminKit_Icons::svg( 'book' ),
			'dashicons-book-alt'         => AdminKit_Icons::svg( 'book' ),
			'dashicons-id'               => AdminKit_Icons::svg( 'id' ),
			'dashicons-id-alt'           => AdminKit_Icons::svg( 'id' ),
			'dashicons-groups'           => AdminKit_Icons::svg( 'users' ),
			'dashicons-businessman'      => AdminKit_Icons::svg( 'users' ),
			'dashicons-tag'              => AdminKit_Icons::svg( 'tag' ),
			'dashicons-tickets-alt'      => AdminKit_Icons::svg( 'ticket' ),
			'dashicons-location'         => AdminKit_Icons::svg( 'location' ),
			'dashicons-art'              => AdminKit_Icons::svg( 'palette' ),
			'dashicons-format-gallery'   => AdminKit_Icons::svg( 'gallery' ),
			'dashicons-images-alt2'      => AdminKit_Icons::svg( 'gallery' ),
			'dashicons-list-view'        => AdminKit_Icons::svg( 'list' ),
			'dashicons-screenoptions'    => AdminKit_Icons::svg( 'app' ),
			'dashicons-layout'           => AdminKit_Icons::svg( 'app' ),
		);
		return (array) apply_filters( 'adminkit/menu_icons', $map );
	}

	/**
	 * The dashicon classes AdminKit remaps to its own glyphs — exposed so the Menu
	 * manager can EXCLUDE them from its "WordPress icons" picker. The mask is keyed
	 * by dashicon class, so picking one as a per-item override would still render the
	 * AdminKit glyph (not the WP dashicon); offering only the UNmapped ones lets the
	 * manager set `$menu[$pos][6]` and have WordPress paint the dashicon natively.
	 *
	 * @return string[]
	 */
	public static function mapped_classes() {
		return array_keys( self::menu_icon_map() );
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
			'wp-admin-bar-comments'    => AdminKit_Icons::svg( 'chat' ),
			'wp-admin-bar-new-content' => AdminKit_Icons::svg( 'plus' ),
			'wp-admin-bar-updates'     => AdminKit_Icons::svg( 'update' ),
			// WP's command-palette (⌘K) node — a search/magnifier (`.ab-icon` with a
			// dashicons `\f179` glyph), in the default group, shown on the mobile bar
			// too. Map it onto the same `search` glyph so it matches the rest.
			'wp-admin-bar-command-palette' => AdminKit_Icons::svg( 'search' ),
			// Front-end / edit-screen core nodes. These paint via `> .ab-item::before`.
			// `edit` (edit this page/post) → a document-with-pencil, a more telling
			// "edit page" mark; it stays distinct from Articles (newspaper) and Pages
			// (a plain document). Same node in the front-end toolbar and the back office.
			'wp-admin-bar-edit'        => AdminKit_Icons::svg( 'document-pencil' ),
			'wp-admin-bar-customize'   => AdminKit_Icons::svg( 'sliders' ),
			// `archive` → WP's "View Posts"/"View Pages" node on a post-type LIST screen
			// (edit.php): a plain-text link out to the post-type archive on the front
			// end. An eye reads as "view/look at the live listing" — distinct from the
			// menu's Articles (newspaper) / Pages (document) and from `edit`'s pencil.
			'wp-admin-bar-archive'     => AdminKit_Icons::svg( 'eye' ),
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
			// "View Posts"/"View Pages" on a list screen — a plain-text link with no
			// `.ab-icon` span, so its glyph is created on `> .ab-item::before`.
			'wp-admin-bar-archive'   => true,
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
				. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		}

		// No generic fallback: a top-level item whose stock dashicon ISN'T in the map
		// keeps its native dashicon. Only the entries above (core menus + the common
		// plugin dashicons + whatever integrations register via `adminkit/menu_icons`)
		// are ever replaced — so unsupported plugins look like themselves, and an
		// Admin-Menu-Editor / custom-image icon (no `.dashicons-before`) stays untouched.
		return $css;
	}

	/**
	 * Top-level menu icons keyed by the menu SLUG (the `toplevel_page_<slug>` id),
	 * for plugins that ship a base64 / custom-image icon WordPress paints as a
	 * `background-image` — those carry no dashicon class, so menu_icon_map() (keyed
	 * by dashicon) can't reach them. Themed plugins set their own icon from their
	 * adapter (print_menu_icon); this map is for plugins AdminKit doesn't theme but
	 * still wants on-brand in the menu. Filter `adminkit/menu_slug_icons`.
	 *
	 * @return array<string,string> menu-slug => SVG markup ('' = skip)
	 */
	private static function slug_icon_map() {
		return apply_filters( 'adminkit/menu_slug_icons', array(
			// FluentSnippets (easy-code-manager) — ships a base64 data-URI icon.
			'fluent-snippets' => AdminKit_Icons::svg( 'bolt' ),
		) );
	}

	/**
	 * Build the slug-keyed menu CSS: drop the plugin's background-image icon and
	 * mask our SVG into the same 36×34 box (same technique the adapters' own
	 * print_menu_icon uses), so it tracks the menu foreground colour.
	 *
	 * @return string
	 */
	private static function slug_css() {
		$css = '';
		foreach ( self::slug_icon_map() as $slug => $svg ) {
			if ( '' === $svg || ! is_string( $svg ) ) {
				continue;
			}
			$box  = '#adminmenu #toplevel_page_' . $slug . ' .wp-menu-image';
			$css .= $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
			$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
				. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
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
		$desktop = '';
		$mobile  = '';
		$ab_item = self::ab_item_nodes();
		foreach ( self::toolbar_icon_map() as $id => $svg ) {
			if ( '' === $svg || ! is_string( $svg ) ) {
				continue;
			}
			if ( isset( $ab_item[ $id ] ) ) {
				$desktop .= self::toolbar_ab_item_css( $id, $svg );
				$mobile  .= self::toolbar_ab_item_mobile_css( $id, $svg );
			} else {
				$desktop .= self::toolbar_ab_icon_css( $id, $svg );
				$mobile  .= self::toolbar_ab_icon_mobile_css( $id, $svg );
			}
		}
		$css = '';
		// Desktop: WP's compact 32px bar — the 20px masks already used there.
		if ( '' !== $desktop ) {
			$css .= '@media screen and (min-width:783px){' . $desktop . '}';
		}
		// Mobile (≤782px): WP's 46px touch bar — without this the mapped nodes fall
		// back to stock dashicons at WP's 40px mobile glyph size, jarring next to
		// AdminKit's own 22px SVG nodes (theme-toggle / view-site / notifications).
		// The mobile variants centre a 22px mask in WP's native 52×46 cell so the
		// whole bar reads as one cohesive set.
		if ( '' !== $mobile ) {
			$css .= '@media screen and (max-width:782px){' . $mobile . '}';
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
			. 'position:static;top:auto;' . AdminKit_Icons::mask( $svg )
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
			. 'padding:6px 0;margin-right:6px;position:static;top:auto;' . AdminKit_Icons::mask( $svg )
			. '}';
	}

	/**
	 * Mobile (≤782px) icon CSS for an `.ab-icon`-span node. WP's touch bar renders
	 * these as 52×46 cells with a 40px dashicon glyph; here we normalise the cell to
	 * that size, drop the glyph (`content:""`) and centre a 22px mask in it — the
	 * same 22px AdminKit uses for its own mobile toolbar SVGs, so the set stays
	 * cohesive. `mask()` bakes 20px (the desktop size); we override the mask-size to
	 * 22px while reusing its data-URI. Out-specifies WP's mobile per-node rules
	 * (e.g. comments / new-content `.ab-icon:before`) via the #wp-toolbar id chain +
	 * doubled class; the lone `height:46px !important` WP forces on new-content lands
	 * on our 46px cell anyway, so it's harmless.
	 *
	 * @param string $id
	 * @param string $svg
	 * @return string
	 */
	private static function toolbar_ab_icon_mobile_css( $id, $svg ) {
		$sel  = '#wpadminbar #wp-toolbar #' . $id . ' .ab-icon.ab-icon';
		$css  = $sel . '{box-sizing:border-box;width:52px;height:46px;margin:0;padding:0}';
		$css .= $sel . '::before{content:"";display:block;width:52px;height:46px;margin:0;'
			. 'position:static;top:auto;' . AdminKit_Icons::mask( $svg )
			. '-webkit-mask-size:22px 22px;mask-size:22px 22px}';
		return $css;
	}

	/**
	 * Mobile (≤782px) icon CSS for a node that paints on its link's own
	 * `> .ab-item::before` (edit, customize, …). The `> .ab-item` keeps WP's mobile
	 * treatment (52px wide, text indented away, overflow hidden) so the label stays
	 * hidden; we only fill that cell with the masked `::before` and reset the
	 * inherited text-indent / desktop float. 22px mask, centred — matching the
	 * `.ab-icon` variant above.
	 *
	 * @param string $id
	 * @param string $svg
	 * @return string
	 */
	private static function toolbar_ab_item_mobile_css( $id, $svg ) {
		$sel = '#wpadminbar #wp-toolbar #' . $id . ' > .ab-item.ab-item::before';
		return $sel . '{content:"";box-sizing:border-box;display:block;float:none;'
			. 'width:52px;height:46px;margin:0;text-indent:0;position:static;top:auto;'
			. AdminKit_Icons::mask( $svg )
			. '-webkit-mask-size:22px 22px;mask-size:22px 22px}';
	}

}
