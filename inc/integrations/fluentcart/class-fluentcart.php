<?php
/**
 * FluentCart integration.
 *
 * FluentCart's admin is a single hash-routed SPA (one WP screen,
 * `toplevel_page_fluent-cart`) built on Element Plus. Element Plus is
 * fully CSS-variable driven and FluentCart declares its palette on
 * `:root`, so admin.css doesn't override components by selector — it
 * remaps the `--el-*` (and FluentCart's own `--color-*`) variables to
 * AdminKit tokens at the body scope. Element Plus then repaints itself,
 * and because the tokens flip with `data-adminkit-theme`, the whole
 * plugin follows AdminKit dark mode without touching FluentCart's own
 * `.dark` theme. The remap sits on `body` (not the app root) so Element
 * Plus overlays teleported to `<body>` (dropdowns, dialogs, tooltips)
 * inherit it too.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluentcart extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluentcart';
	}

	/**
	 * FluentCart defines FLUENTCART_VERSION in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENTCART_VERSION' );
	}

	/**
	 * Match every FluentCart admin screen. The top-level menu slug is
	 * `fluent-cart` (screen `toplevel_page_fluent-cart`); the whole UI
	 * is a hash-routed SPA on that one page. A substring check also
	 * covers any future sub-page that keeps the `fluent-cart` slug
	 * fragment in its id.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'fluent-cart' );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluentcart-admin',
			'src'       => 'inc/integrations/fluentcart/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on FluentCart screens —
	 * Element Plus ships its own input/button/table styling and our
	 * components/*.css would double-style its widgets. admin.css drives
	 * the Element Plus surfaces through tokens instead. Also slave
	 * FluentCart's own light/dark theme to AdminKit's mode.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fc' ) );
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ) );
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
	 * Slave FluentCart's theme to AdminKit's.
	 *
	 * FluentCart ships its own light/dark theme: a `fluent_theme_dark`
	 * class (toggled from `localStorage.fluent_theme_mode`, default
	 * `system` → follows the OS) that paints a hardcoded navy palette
	 * directly over Element Plus and its own chrome. Left alone it
	 * desyncs from AdminKit — e.g. navy FluentCart while the rest of
	 * wp-admin is light. We hide FluentCart's toggle (admin.css) and
	 * mirror AdminKit's mode onto its storage key + theme class instead.
	 *
	 * The storage write lands in <head>, before FluentCart's app bundle
	 * (enqueued in the footer) boots, so it starts in the right theme
	 * with no flash. A one-way MutationObserver on `data-adminkit-theme`
	 * keeps it in step when the user flips AdminKit's switch. It only
	 * reads that attribute and writes FluentCart's class — never the
	 * reverse — so there's no sync loop.
	 *
	 * admin.css then recolors FluentCart's navy (now only present in
	 * AdminKit dark) to the AdminKit dark tokens.
	 *
	 * @return void
	 */
	public static function sync_theme() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::owns_screen( $screen ) ) {
			return;
		}
		?>
<script id="adminkit-fluentcart-theme-sync">
(function(){
	function akMode(){
		var a=document.documentElement.getAttribute('data-adminkit-theme');
		if(a){return a==='dark'?'dark':'light';}
		try{var s=localStorage.getItem('adminkit-theme');if(s){return s==='dark'?'dark':'light';}}catch(e){}
		return (window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';
	}
	function sync(){
		var m=akMode();
		try{localStorage.setItem('fluent_theme_mode',m);localStorage.setItem('fcart_admin_theme',m);}catch(e){}
		[document.documentElement,document.body,document.getElementById('wpbody-content')].forEach(function(el){
			if(el){el.classList.toggle('fluent_theme_dark',m==='dark');}
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
