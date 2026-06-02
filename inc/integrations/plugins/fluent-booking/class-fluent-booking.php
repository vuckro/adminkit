<?php
/**
 * FluentBooking integration.
 *
 * FluentBooking v2 renders its admin as a Vue 3 SPA on Element Plus. Unlike
 * FluentCRM (which aliases Element Plus's `--el-*` tokens TO its own `--fc-*`
 * layer), FluentBooking keeps the two layers independent:
 *   - its own `--fcal-*` tokens (surfaces, text, borders, plus a single
 *     blue accent pair `--fcal-color-text` / `--fcal-color-bg`);
 *   - Element Plus `--el-*` tokens set to literal hex — NOT wired to
 *     `--fcal-*` — so each layer has to be remapped on its own.
 * On top of that, FluentBooking hard-codes its brand blue (#306ae0) directly
 * into ~126 Element Plus component states (checked switches/checkboxes/radios,
 * focused inputs, active tabs, calendar selection, links, focus rings) instead
 * of routing them through `--el-color-primary` (which it reserves for the
 * near-black #19283a). Those literals can't be reached by the variable remap,
 * so admin.css pairs the token remap with a grouped `!important` override that
 * flips every blue accent to the AdminKit brand. The whole thing flips with
 * AdminKit's dark mode because the `--ak-*` tokens do.
 *
 * Loaded only on the toplevel_page_fluent-booking screen (the SPA owns every
 * sub-route via hash routing under that one page).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Booking extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-booking';
	}

	/**
	 * FluentBooking defines `FLUENT_BOOKING_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_BOOKING_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_BOOKING_VERSION' ) ? FLUENT_BOOKING_VERSION : null;
	}

	/**
	 * Verified against FluentBooking 2.x. Bump after re-checking the skin on a
	 * new major — register_assets() falls back to native UI until you do.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '2.1.1';
	}

	/**
	 * FluentBooking registers a top-level menu with slug `fluent-booking`
	 * via `add_menu_page()`, giving the screen id
	 * `toplevel_page_fluent-booking`. Every sub-page is a hash route under
	 * the same screen, so one id covers the entire SPA.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-booking' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// FluentBooking hardcodes its blue into ~126 Element Plus states; a new
		// major could rename them, leaving the override missing and the host
		// blue bleeding back. Fall back to FluentBooking's native UI instead.
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-booking-admin',
			'src'       => 'inc/integrations/plugins/fluent-booking/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentBooking screen —
	 * Element Plus ships its own input/select/textarea styling, so our
	 * components/*.css would double-border its widgets (a native `<input>`
	 * border inside the `.el-input__wrapper` that already has one). Our
	 * admin.css themes the Element Plus surfaces directly instead. Also
	 * slave FluentBooking's own light/dark toggle to AdminKit's mode.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fb' ) );
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the FluentCart adapter.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fb( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentBooking's menu icon for a calendar glyph.
	 *
	 * FluentBooking registers its top-level menu with a base64 data-URI icon, so
	 * its `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop the inline background-image and
	 * mask a calendar Heroicon into the icon box — same technique menu_css() uses,
	 * so it tracks the menu foreground colour in light/dark/hover/current. Inlined
	 * here (not in AdminKit_Icons) so the adapter owns its own mark. Gated exactly
	 * like the FluentCart adapter: only when the icon toggle is on AND the global
	 * should_load pause hasn't disabled AdminKit. The menu shows on every admin
	 * page, so there is no screen gate.
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
		// Heroicons (solid) calendar — fill is irrelevant (painted as a mask; the
		// visible colour is currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M6.75 2.25A.75.75 0 0 1 7.5 3v1.5h9V3a.75.75 0 0 1 1.5 0v1.5h.75a3 3 0 0 1 3 3v11.25a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3V7.5a3 3 0 0 1 3-3H6V3a.75.75 0 0 1 .75-.75Zm13.5 9a1.5 1.5 0 0 0-1.5-1.5H5.25a1.5 1.5 0 0 0-1.5 1.5v7.5a1.5 1.5 0 0 0 1.5 1.5h13.5a1.5 1.5 0 0 0 1.5-1.5v-7.5Z" clip-rule="evenodd"/></svg>';
		$box  = '#adminmenu #toplevel_page_fluent-booking .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-booking-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}

	/**
	 * Slave FluentBooking's theme to AdminKit's.
	 *
	 * FluentBooking ships its own light/dark switch (`toggleColorMode()`):
	 * it toggles a `dark` class on BOTH `<html>` and `#wpbody-content` and
	 * stores `localStorage.fcal_color_mode`. Left alone it desyncs from
	 * AdminKit (e.g. the user flips AdminKit dark but FluentBooking stays
	 * light, or vice-versa). We hide FluentBooking's toggle (admin.css) and
	 * mirror AdminKit's mode onto its storage key + class instead, so there
	 * is a single source of truth: AdminKit's switch.
	 *
	 * The storage write lands in <head>, before FluentBooking's app bundle
	 * (footer) boots, so it starts in the right theme with no flash. A
	 * one-way MutationObserver on `data-adminkit-theme` keeps it in step —
	 * it only reads that attribute and writes FluentBooking's class, never
	 * the reverse, so there is no sync loop. admin.css's `--fcal-*`/`--el-*`
	 * remap (scoped above FluentBooking's own `html.dark` blocks) stays
	 * authoritative, so the palette is AdminKit's in both modes.
	 *
	 * @return void
	 */
	public static function sync_theme() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::owns_screen( $screen ) ) {
			return;
		}
		?>
<script id="adminkit-fluent-booking-theme-sync">
(function(){
	function akMode(){
		var a=document.documentElement.getAttribute('data-adminkit-theme');
		if(a){return a==='dark'?'dark':'light';}
		try{var s=localStorage.getItem('adminkit-theme');if(s){return s==='dark'?'dark':'light';}}catch(e){}
		return (window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';
	}
	function sync(){
		var m=akMode();
		try{localStorage.setItem('fcal_color_mode',m);}catch(e){}
		[document.documentElement,document.getElementById('wpbody-content')].forEach(function(el){
			if(el){el.classList.toggle('dark',m==='dark');}
		});
	}
	sync();
	document.addEventListener('DOMContentLoaded',function(){
		sync();
		new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['data-adminkit-theme']});
	});
})();
</script>
		<?php
	}
}
