<?php
/**
 * AdminKit integration adapter for FluentBoards.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integrates FluentBoards and FluentCRM contact tasks widget with AdminKit tokens.
 */
class AdminKit_Integration_Fluent_Boards extends AdminKit_Integration_Base {

	/**
	 * Integration slug.
	 *
	 * @return string
	 */
	public static function slug() {
		return 'fluent-boards';
	}

	/**
	 * Human-readable integration label.
	 *
	 * @return string
	 */
	public static function label() {
		return 'FluentBoards';
	}

	/**
	 * Whether FluentBoards is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_BOARDS_PLUGIN_VERSION' );
	}

	/**
	 * Host plugin files covered by compatibility metadata.
	 *
	 * @return string[]
	 */
	public static function host_files() {
		return array(
			'fluent-boards/fluent-boards.php',
			'fluent-boards-pro/fluent-boards-pro.php',
		);
	}

	/**
	 * Installed FluentBoards version.
	 *
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_BOARDS_PLUGIN_VERSION' ) ? FLUENT_BOARDS_PLUGIN_VERSION : null;
	}

	/**
	 * Maximum tested FluentBoards host version.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '2.3.0';
	}

	/**
	 * Whether the current admin screen belongs to FluentBoards.
	 *
	 * @param mixed $screen Current WordPress screen.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen instanceof WP_Screen ) {
			return false;
		}

		$screen_id = (string) $screen->id;

		return 'toplevel_page_fluent-boards' === $screen_id
			|| '_page_fluent-boards' === substr( $screen_id, -19 );
	}

	/**
	 * Whether AdminKit FluentBoards CSS should load on the current screen.
	 *
	 * Covers standalone FluentBoards, CRM-nested submenu screens, and the
	 * FluentBoards contact widget rendered inside FluentCRM.
	 *
	 * @return bool
	 */
	public static function should_load_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::owns_screen( $screen ) ) {
			return true;
		}

		return class_exists( 'AdminKit_Integration_Fluent_Crm' )
			&& AdminKit_Integration_Fluent_Crm::owns_screen( $screen );
	}

	/**
	 * Register FluentBoards override stylesheet.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( ! static::host_within_tested_range() ) {
			return;
		}

		AdminKit_Assets::register(
			array(
				'handle'    => 'adminkit-fluent-boards-admin',
				'src'       => 'inc/integrations/plugins/fluent-boards/css/admin.css',
				'deps'      => array( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Assets::ELEMENT_PLUS_HANDLE ),
				'context'   => 'admin',
				'condition' => array( __CLASS__, 'should_load_styles' ),
			)
		);
	}

	/**
	 * Hook runtime integration behavior on owned screens.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fbs' ), 10, 2 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * Prevent core WP form overrides from fighting Element Plus inputs inside FluentBoards.
	 *
	 * @param bool  $enqueue Whether core form stylesheet is enabled.
	 * @param mixed $screen  Optional screen object.
	 * @return bool
	 */
	public static function bail_forms_on_fbs( $enqueue, $screen = null ) {
		if ( ! $screen && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
		}

		if ( self::owns_screen( $screen ) ) {
			return false;
		}

		return $enqueue;
	}

	/**
	 * Append integration marker class to admin body.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::owns_screen( $screen ) ) {
			$classes .= ' ak-fluent-boards';
		}

		return $classes;
	}

	/**
	 * Replace FluentBoards WP sidebar menu icon with AdminKit kanban glyph.
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

		$svg = AdminKit_Icons::svg( 'square-kanban' );
		if ( '' === $svg ) {
			return;
		}

		$box  = '#adminmenu #toplevel_page_fluent-boards .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . ' img{display:none !important}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static selectors and URL-encoded SVG in mask().
		echo '<style id="adminkit-fluent-boards-menu-icon">' . $css . "</style>\n";
	}

	/**
	 * Keep FluentBoards Vue dark mode and TinyMCE iframes synchronized with AdminKit.
	 *
	 * @return void
	 */
	public static function sync_theme() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::owns_screen( $screen ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) || ! apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) {
			return;
		}

		$editor_rel  = 'inc/integrations/plugins/fluent-boards/css/editor.css';
		$editor_path = ADMINKIT_PATH . $editor_rel;
		$editor_ver  = file_exists( $editor_path ) ? (string) filemtime( $editor_path ) : ADMINKIT_VERSION;
		$editor_href = ADMINKIT_URL . $editor_rel . '?ver=' . rawurlencode( $editor_ver );
		?>
<script id="adminkit-fluent-boards-theme-sync">
(function(){
	var editorHref = <?php echo wp_json_encode( $editor_href ); ?>;
	function akMode(){
		var attr = document.documentElement.getAttribute('data-adminkit-theme');
		if(attr){return attr === 'dark' ? 'dark' : 'light';}
		try{
			var saved = localStorage.getItem('adminkit-theme');
			if(saved){return saved === 'dark' ? 'dark' : 'light';}
		}catch(e){}
		return (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
	}
	function syncEditor(frame){
		try{
			var doc = frame && frame.contentDocument;
			if(!doc || !doc.head || !doc.body || !doc.body.isContentEditable){return;}
			var m = akMode();
			doc.documentElement.setAttribute('data-adminkit-theme', m);
			doc.documentElement.setAttribute('data-adminkit-accent', document.documentElement.getAttribute('data-adminkit-accent') || '');
			doc.documentElement.style.colorScheme = m;
			['adminkit-fonts-css','adminkit-waaskit-css','adminkit-tokens-css'].forEach(function(id){
				var source = document.getElementById(id);
				if(source && !doc.getElementById(id)){doc.head.appendChild(source.cloneNode(true));}
			});
			var accentSrc = document.getElementById('adminkit-tokens-inline-css');
			if(accentSrc){
				var accentDst = doc.getElementById('adminkit-tokens-inline-css');
				if(!accentDst){
					doc.head.appendChild(accentSrc.cloneNode(true));
				}else if(accentDst.textContent !== accentSrc.textContent){
					accentDst.textContent = accentSrc.textContent;
				}
			}
			var style = doc.getElementById('adminkit-fluent-boards-editor');
			if(!style){
				style = doc.createElement('link');
				style.id = 'adminkit-fluent-boards-editor';
				style.rel = 'stylesheet';
				style.href = editorHref;
				doc.head.appendChild(style);
			}
		}catch(e){}
	}
	function syncEditors(){
		document.querySelectorAll('.mce-edit-area iframe').forEach(syncEditor);
	}
	function sync(){
		var m = akMode();
		var dark = m === 'dark';
		try{localStorage.setItem('fluent_theme_mode', m);}catch(e){}
		[document.documentElement, document.body, document.getElementById('wpbody-content')].forEach(function(el){
			if(!el){return;}
			el.classList.toggle('fluent_theme_dark', dark);
			el.classList.toggle('fluent_boards_theme_dark', dark);
			el.classList.toggle('fbs-dark', dark);
			el.classList.toggle('fbs-light', !dark);
		});
		document.documentElement.style.colorScheme = m;
		window.dispatchEvent(new CustomEvent('onFbsThemeChange', {detail: {theme: m, effective: m}}));
		syncEditors();
	}
	document.addEventListener('load', function(event){
		if(event.target && event.target.matches && event.target.matches('.mce-edit-area iframe')){syncEditor(event.target);}
	}, true);
	sync();
	document.addEventListener('DOMContentLoaded', function(){
		sync();
		if(window.tinymce && window.tinymce.on){
			window.tinymce.on('AddEditor', function(event){
				if(event && event.editor && event.editor.on){
					event.editor.on('init', function(){syncEditor(event.editor.iframeElement);});
				}
			});
		}
		if(window.MutationObserver){
			new MutationObserver(sync).observe(document.documentElement, {attributes: true, attributeFilter: ['data-adminkit-theme', 'data-adminkit-accent']});
		}
	});
})();
</script>
		<?php
	}
}
