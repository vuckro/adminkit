<?php
/**
 * FluentAffiliate integration — Tier-A-leaning adapter (Vue 3 SPA on Element Plus).
 *
 * FluentAffiliate renders its admin as a Vue 3 SPA. Like FluentBooking (and unlike
 * FluentCRM), it themes itself through TWO INDEPENDENT custom-property layers:
 *
 *   1. its own --fla-* tokens, declared on `body` (light) and `html.dark body`
 *      (dark) — surfaces (--fla-primary-bg = card/white, --fla-secondary-bg =
 *      page, --fla-active-bg = hover/zebra), text (--fla-primary-text = heading,
 *      --fla-secondary-text = body), borders (--fla-primary-border,
 *      --fla-secondary-border), a filled-control colour (--fla-primary-button,
 *      the near-black brand) and a neutral badge fill (--fla-badge-color);
 *
 *   2. Element Plus --el-* tokens. FluentAffiliate sets ONLY --el-color-primary
 *      to its brand near-black #19283a on `body`; the rest of the EP ramp
 *      (light-3/5/7/8/9, dark-2, the -rgb channel) is left at EP's stock blue,
 *      and `html.dark` reshuffles the WHOLE EP layer to dark stock-blue values.
 *      So the EP layer is unrelated to --fla-* and must be remapped on its own.
 *
 * That is the whole adapter (css §1): point both layers at AdminKit --ak-*, and
 * because --ak-* flip with AdminKit's dark mode FluentAffiliate gets a dark mode
 * carried entirely by the remap. Crucially, almost every brand literal in the
 * compiled CSS is a `var(--fla-*, #hex)` FALLBACK (e.g. `var(--fla-primary-text,
 * #19283a)`), so once the var is remapped the literal never fires — there is no
 * hardcoded-brand sweep to do. The only true escapees are two stock-blue input
 * focus borders (#3f9eff / #409eff) and the unset --el-color-primary-rgb focus
 * ring; css §3 catches those.
 *
 * The brand. FluentAffiliate parks a near-black #19283a / #2b2e33 in
 * --fla-primary-button + --el-color-primary (filled buttons, checked controls).
 * AdminKit has one brand, so it routes to --ak-primary with white pinned on
 * filled surfaces via --ak-on-accent.
 *
 * NO theme sync. FluentAffiliate DOES ship its own dark mode (an `html.dark`
 * class flipped by its in-app toggle, toggleColorMode()), but we never add that
 * class — the --fla-* / --el-* remap alone carries both light and dark, so the
 * host's own `html.dark body { --fla-*: <dark hex> }` block never needs to fire.
 * The redundant in-app moon/sun toggle is hidden dark-only (css §3).
 *
 * Loaded only on toplevel_page_fluent-affiliate (the SPA owns every sub-route via
 * hash routing under that one WP page).
 *
 * Tier B safety: FluentAffiliate bakes its brand + Element Plus defaults into
 * compiled CSS, which a new major may reshuffle, so the skin is version-gated on
 * the major and drops to FluentAffiliate's native UI past the tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Affiliate extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-affiliate';
	}

	/**
	 * FluentAffiliate registers its top-level menu under the slug `fluent-affiliate`
	 * (== slug()), so the base wordmark resolver needs no override.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_AFFILIATE_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_AFFILIATE_VERSION' ) ? FLUENT_AFFILIATE_VERSION : null;
	}

	/**
	 * Verified against FluentAffiliate 1.4.x. A new major may rename the --fla-* /
	 * --el-* tokens or reshuffle the brand literals this adapter targets, so gate
	 * on the major — register_assets() falls back to native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '1.4.0';
	}

	/**
	 * FluentAffiliate registers a top-level menu with slug `fluent-affiliate` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluent-affiliate`.
	 * Every sub-page is a hash route under that same screen, so one id covers the
	 * entire SPA.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-affiliate' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentAffiliate's native
		// UI shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-affiliate-admin',
			'src'       => 'inc/integrations/plugins/fluent-affiliate/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentAffiliate screen —
	 * Element Plus ships its own input/select/textarea styling, so our
	 * components/*.css would double-border its widgets (a native border inside the
	 * `.el-input__wrapper` that already has one). admin.css themes the Element Plus
	 * surfaces directly via the token remap instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fla' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the FluentCRM adapter.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fla( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentAffiliate's menu icon for a banknotes glyph.
	 *
	 * FluentAffiliate registers its top-level menu with a base64 data-URI SVG icon
	 * (AdminMenuHandler::getMenuIcon()), so its `.wp-menu-image` carries no
	 * dashicon class and AdminKit_Core_Menu_Icons (keyed by dashicon) never reaches
	 * it. Drop the inline background-image and mask a "banknotes" Heroicon (an
	 * affiliate program is about commissions / payouts) into the icon box — same
	 * technique menu_css() uses, so it tracks the menu foreground colour in
	 * light/dark/hover/current. Inlined here so the adapter owns its own mark.
	 * Gated exactly like the FluentCRM adapter: only when the icon toggle is on AND
	 * the global should_load pause hasn't disabled AdminKit. The menu shows on
	 * every admin page, so there is no screen gate.
	 *
	 * @return void
	 */
	public static function print_menu_icon() {
		if ( ! class_exists( 'AdminKit_Settings' ) || ! AdminKit_Settings::get( 'replace_icons_enabled' ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		if ( ! class_exists( 'AdminKit_Icons' ) ) {
			return;
		}
		// Heroicons (solid) "banknotes" — commissions / payouts. Fill is irrelevant
		// (painted as a mask; the visible colour is currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path d="M12 7.5a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5Z"/><path fill-rule="evenodd" d="M1.5 4.875C1.5 3.839 2.34 3 3.375 3h17.25c1.035 0 1.875.84 1.875 1.875v9.75c0 1.036-.84 1.875-1.875 1.875H3.375A1.875 1.875 0 0 1 1.5 14.625v-9.75ZM8.25 9.75a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0ZM18.75 9a.75.75 0 0 0-.75.75v.008c0 .414.336.75.75.75h.008a.75.75 0 0 0 .75-.75V9.75a.75.75 0 0 0-.75-.75h-.008ZM4.5 9.75A.75.75 0 0 1 5.25 9h.008a.75.75 0 0 1 .75.75v.008a.75.75 0 0 1-.75.75H5.25a.75.75 0 0 1-.75-.75V9.75Z" clip-rule="evenodd"/><path d="M2.25 18a.75.75 0 0 0 0 1.5c5.4 0 10.63.722 15.6 2.075 1.19.324 2.4-.558 2.4-1.82V18.75a.75.75 0 0 0-.75-.75H2.25Z"/></svg>';
		$box  = '#adminmenu #toplevel_page_fluent-affiliate .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-affiliate-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
