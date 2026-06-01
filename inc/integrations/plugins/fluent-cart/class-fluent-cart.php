<?php
/**
 * FluentCart integration — Tier B adapter (Vue 3 SPA on Tailwind + Element Plus).
 *
 * FluentCart renders its admin as a Vue 3 SPA. Its palette comes from TWO
 * places, themed in TWO different ways — which is the whole reason this adapter
 * is shaped the way it is:
 *
 *   • Tailwind utility classes — FC compiles its own light/dark palette into
 *     them and ships a COMPLETE dark variant for every one (the
 *     `:where(.fluent_theme_dark,.fluent_theme_dark *)` rules). So the app
 *     shell, top bar, nav and cards already flip correctly — IF the
 *     `fluent_theme_dark` class is on <html>. That is what sync_theme() drives.
 *
 *   • Element Plus `--el-*` tokens — every data table, form, dialog, select,
 *     date picker, tab and pagination is Element Plus. FC ships the STOCK
 *     light `--el-*` defaults and only patches FIVE component selectors in
 *     dark mode (select popper, table header, search input, skeleton, loading
 *     mask). So FC's native dark mode leaves almost all of Element Plus on its
 *     light values — white inputs and dialogs on a dark page. FC's dark mode
 *     alone is therefore NOT enough; admin.css remaps the `--el-*` layer to
 *     AdminKit `--ak-*` tokens, which DO flip with AdminKit's mode, so Element
 *     Plus is correct in both light and dark.
 *
 * Net: sync_theme() turns ON FluentCart's native dark mode from AdminKit's
 * switch (so the Tailwind chrome flips natively, as the user expects), and
 * admin.css fills the Element-Plus dark gap + unifies the brand. Together they
 * cover the whole screen.
 *
 * Loaded only on toplevel_page_fluent-cart (the SPA owns every sub-route via
 * hash routing under that one WP page).
 *
 * Tier B → version-gated on the major: FC bakes the stock Element Plus values
 * and its own brand literals into compiled CSS, which a new major may
 * reshuffle, so past the tested major the skin drops to FC's native UI.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Cart extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-cart';
	}

	/**
	 * FluentCart defines `FLUENTCART_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENTCART_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENTCART_VERSION' ) ? FLUENTCART_VERSION : null;
	}

	/**
	 * Verified against FluentCart 1.x. A new major may reshuffle the Element
	 * Plus token names / brand literals this adapter targets, so gate on the
	 * major — register_assets() falls back to FC's native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '1.3.28';
	}

	/**
	 * FluentCart registers a top-level menu with slug `fluent-cart` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluent-cart`.
	 * Every sub-page is a hash route under the same screen, so one id covers
	 * the entire SPA.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-cart' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentCart's native
		// UI shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-cart-admin',
			'src'       => 'inc/integrations/plugins/fluent-cart/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentCart screen — Element
	 * Plus ships its own input/select/textarea styling, so our components/*.css
	 * would double-border its widgets (a native border inside the
	 * `.el-input__wrapper` that already has one). admin.css themes the Element
	 * Plus surfaces directly instead. Also slave FC's light/dark toggle to
	 * AdminKit's mode, and swap FC's custom menu icon for an AdminKit glyph.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fc' ) );
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// not just the FC screen — so it hooks admin_head globally, like
		// AdminKit_Core_Menu_Icons and the Query Monitor adapter.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fc( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Slave FluentCart's NATIVE dark mode to AdminKit's switch.
	 *
	 * FluentCart's own theming (AdminTheme.php) reads
	 * `localStorage.fluent_theme_mode`, treats it dark when
	 * `value.split(':').pop() === 'dark'`, and adds the `fluent_theme_dark`
	 * class to <html>. Every FC Tailwind utility has a
	 * `:where(.fluent_theme_dark,…)` dark variant, so once that class is on
	 * <html> the whole Tailwind chrome flips by itself — natively, no CSS
	 * override needed. We make AdminKit's switch the single source of truth:
	 *
	 *   1. write `localStorage.fluent_theme_mode` = AdminKit's mode (plain
	 *      'dark'/'light' — `split(':').pop()` parses both), and
	 *   2. add/remove `fluent_theme_dark` on <html> ourselves,
	 *
	 * both in <head> BEFORE FC's app bundle (footer) boots, so the SPA starts
	 * in the right mode with no flash. A one-way MutationObserver on
	 * `data-adminkit-theme` keeps them in step on every toggle — it only READS
	 * that attribute and WRITES FC's class + storage, never the reverse, so
	 * there is no sync loop. admin.css then hides FC's own in-app toggle so the
	 * two can't desync.
	 *
	 * Writing the class directly (not just the storage key) means we win even
	 * if FC's own <head> script already ran against a stale storage value.
	 *
	 * @return void
	 */
	public static function sync_theme() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::owns_screen( $screen ) ) {
			return;
		}
		?>
<script id="adminkit-fluent-cart-theme-sync">
(function(){
	function akMode(){
		var a=document.documentElement.getAttribute('data-adminkit-theme');
		if(a){return a==='dark'?'dark':'light';}
		try{var s=localStorage.getItem('adminkit-theme');if(s){return s==='dark'?'dark':'light';}}catch(e){}
		return (window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';
	}
	function sync(){
		var dark=akMode()==='dark';
		try{localStorage.setItem('fluent_theme_mode',dark?'dark':'light');}catch(e){}
		document.documentElement.classList.toggle('fluent_theme_dark',dark);
		if(document.body){document.body.classList.toggle('fluent_theme_dark',dark);}
	}
	sync();
	// Attach the observer NOW, not on DOMContentLoaded: documentElement already
	// exists in <head>, so the live toggle fires immediately and never depends on
	// when (or whether) DOMContentLoaded runs relative to this script. A second
	// sync on ready just catches <body>.
	new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['data-adminkit-theme']});
	document.addEventListener('DOMContentLoaded',sync);
})();
</script>
		<?php
	}

	/**
	 * Swap FluentCart's menu icon for a shopping-bag glyph.
	 *
	 * FC registers its top-level menu with a base64 data-URI icon, so its
	 * `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop FC's background-image and mask a
	 * shopping-bag Heroicon into the icon box — the same technique menu_css()
	 * uses, so it tracks the menu foreground colour in light/dark/hover/current.
	 * A bag reads more "store" than a bare cart for a full e-commerce plugin; it's
	 * inlined here (not in AdminKit_Icons) so the adapter owns its own mark.
	 *
	 * Gated exactly like that feature + the Query Monitor adapter: only when the
	 * icon toggle is on AND the global should_load pause hasn't disabled AdminKit
	 * here. The menu shows on every admin page, so there is no screen gate.
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
		// Heroicons (solid) shopping-bag — fill is irrelevant (it's painted as a
		// mask; the visible colour is currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M7.5 6v.75H5.513c-.96 0-1.764.724-1.865 1.679l-1.263 12A1.875 1.875 0 0 0 4.25 22.5h15.5a1.875 1.875 0 0 0 1.865-2.071l-1.263-12a1.875 1.875 0 0 0-1.865-1.679H16.5V6a4.5 4.5 0 1 0-9 0ZM12 3a3 3 0 0 0-3 3v.75h6V6a3 3 0 0 0-3-3Zm-3 8.25a3 3 0 1 0 6 0v-.75a.75.75 0 0 1 1.5 0v.75a4.5 4.5 0 1 1-9 0v-.75a.75.75 0 0 1 1.5 0v.75Z" clip-rule="evenodd"/></svg>';
		// Kill FC's inline background-image (an !important is needed to beat the
		// inline style WP prints), set the 36×34 icon box explicitly, then centre a
		// 20px masked ::before in it — mirrors AdminKit_Core_Menu_Icons::menu_css().
		$box  = '#adminmenu #toplevel_page_fluent-cart .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-cart-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
