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
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-booking-admin',
			'src'       => 'inc/integrations/fluent-booking/css/admin.css',
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
