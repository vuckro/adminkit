<?php
/**
 * Shared Fluent theme intent, independent of the optional FluentHub shell.
 *
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Fluent_Theme {
	/** @var bool Prevent duplicate inline bootstraps across native hook paths. */
	private static $enqueued = false;
	/** @return void */
	public static function init() {
		add_filter( 'fluent_crm_asset_listed_slugs', array( __CLASS__, 'allow_assets' ) );
		add_filter( 'fluent_booking/asset_listed_slugs', array( __CLASS__, 'allow_assets' ) );
		add_filter( 'fluent_boards/asset_listed_slugs', array( __CLASS__, 'allow_assets' ) );
		add_action( 'current_screen', array( __CLASS__, 'enqueue' ), 10001 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 10001 );
	}

	/**
	 * Only an active, enabled and version-compatible owner may load the bridge.
	 *
	 * @param WP_Screen|null $screen Current admin screen.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		foreach ( array( 'Fluent_Auth', 'Fluent_Toolkit', 'Fluent_Smtp', 'Fluent_Snippets', 'Fluentform', 'Fluent_Crm', 'Fluent_Booking', 'Fluent_Boards', 'Fluent_Community', 'Fluent_Cart', 'Fluent_Affiliate' ) as $name ) {
			$class = 'AdminKit_Integration_' . $name;
			if ( ! class_exists( $class ) || ! $class::is_active() || ! $class::host_within_tested_range() || ! apply_filters( 'adminkit/integration_enabled', true, $class::slug() ) ) {
				continue;
			}
			if ( $class::owns_screen( $screen ) || ( 'Fluent_Toolkit' === $name && $class::has_shell() ) || ( 'Fluent_Cart' === $name && $class::owns_chrome( $screen ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Native Fluent asset isolation otherwise removes AdminKit's queued scripts.
	 * Preserve the host allowlist, adding only our exact plugin directory.
	 *
	 * @param string[] $slugs Approved host patterns.
	 * @return string[]
	 */
	public static function allow_assets( $slugs ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$owners = array(
			'fluent_crm_asset_listed_slugs'     => 'AdminKit_Integration_Fluent_Crm',
			'fluent_booking/asset_listed_slugs' => 'AdminKit_Integration_Fluent_Booking',
			'fluent_boards/asset_listed_slugs'  => 'AdminKit_Integration_Fluent_Boards',
		);
		$class = $owners[ current_filter() ] ?? '';
		// Hosts can build their loader policy before WordPress sets WP_Screen.
		$owner_ready = $class && class_exists( $class ) && $class::is_active() && $class::host_within_tested_range() && apply_filters( 'adminkit/integration_enabled', true, $class::slug() );
		if ( is_admin() && $owner_ready && ( ! $screen || $class::owns_screen( $screen ) ) && apply_filters( 'adminkit/should_load', true, 'admin' ) && apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) {
			$slugs[] = '\/adminkit\/';
		}
		return array_values( array_unique( $slugs ) );
	}

	/** @return void */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::$enqueued || ! self::owns_screen( $screen ) || ! apply_filters( 'adminkit/should_load', true, 'admin' ) || ! apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) {
			return;
		}
		self::$enqueued = true;
		$data = array(
			'attr' => AdminKit_Theme_Toggle::attribute(),
			'key' => AdminKit_Theme_Toggle::storage_key(),
			'enabled' => (bool) AdminKit_Settings::get( 'theme_toggle_enabled' ),
			'label' => __( 'Toggle light / dark mode', 'adminkit' ),
		);
		AdminKit_Assets::enqueue_script( 'adminkit-fluent-toolkit-theme', 'inc/integrations/plugins/fluent-toolkit/js/theme.js', array(), 'window.AdminKitFluentHub=' . wp_json_encode( $data ) . ';' );
	}

	/**
	 * Print a one-way inline theme bridge syncing an active Fluent SPA (plus optional
	 * same-origin TinyMCE iframes) to AdminKit's `data-adminkit-theme` attribute.
	 *
	 * @param array{
	 *   id:string,
	 *   storage_key?:string,
	 *   dark_classes?:string[],
	 *   light_classes?:string[],
	 *   include_wpbody?:bool,
	 *   custom_event?:string,
	 *   editor_css?:string,
	 *   editor_style_id?:string,
	 *   observe_immediately?:bool
	 * } $args Sync bridge options.
	 * @return void
	 */
	public static function print_spa_sync( array $args ) {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) || ! apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) {
			return;
		}

		$id          = preg_replace( '/[^a-z0-9_-]/i', '', (string) ( $args['id'] ?? 'adminkit-fluent-theme-sync' ) );
		$editor_href = '';
		$editor_rel  = isset( $args['editor_css'] ) ? ltrim( (string) $args['editor_css'], '/' ) : '';
		if ( '' !== $editor_rel ) {
			$editor_path = ADMINKIT_PATH . $editor_rel;
			$editor_ver  = file_exists( $editor_path ) ? (string) filemtime( $editor_path ) : ADMINKIT_VERSION;
			$editor_href = ADMINKIT_URL . $editor_rel . '?ver=' . rawurlencode( $editor_ver );
		}

		$config = array(
			'storageKey'    => (string) ( $args['storage_key'] ?? '' ),
			'darkClasses'   => array_values( (array) ( $args['dark_classes'] ?? array( 'fluent_theme_dark' ) ) ),
			'lightClasses'  => array_values( (array) ( $args['light_classes'] ?? array() ) ),
			'includeWpbody' => ! empty( $args['include_wpbody'] ),
			'customEvent'   => (string) ( $args['custom_event'] ?? '' ),
			'editorHref'    => $editor_href,
			'editorStyleId' => (string) ( $args['editor_style_id'] ?? 'adminkit-fluent-editor' ),
			'immediate'     => ! empty( $args['observe_immediately'] ),
		);
		?>
<script id="<?php echo esc_attr( $id ); ?>">
(function(){
	var cfg = <?php echo wp_json_encode( $config ); ?>;
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
		if(!cfg.editorHref){return;}
		try{
			var doc = frame && frame.contentDocument;
			if(!doc || !doc.head || !doc.body || !doc.body.isContentEditable){return;}
			var m = akMode();
			doc.documentElement.setAttribute('data-adminkit-theme', m);
			doc.documentElement.setAttribute('data-adminkit-accent', document.documentElement.getAttribute('data-adminkit-accent') || '');
			doc.documentElement.style.colorScheme = m;
			['adminkit-fonts-css','adminkit-waaskit-css','adminkit-tokens-css'].forEach(function(hid){
				var source = document.getElementById(hid);
				if(source && !doc.getElementById(hid)){doc.head.appendChild(source.cloneNode(true));}
			});
			var accentSrc = document.getElementById('adminkit-tokens-inline-css');
			if(accentSrc){
				var accentDst = doc.getElementById('adminkit-tokens-inline-css');
				if(!accentDst){doc.head.appendChild(accentSrc.cloneNode(true));}
				else if(accentDst.textContent !== accentSrc.textContent){accentDst.textContent = accentSrc.textContent;}
			}
			var style = doc.getElementById(cfg.editorStyleId);
			if(!style){
				style = doc.createElement('link');
				style.id = cfg.editorStyleId;
				style.rel = 'stylesheet';
				style.href = cfg.editorHref;
				doc.head.appendChild(style);
			}
		}catch(e){}
	}
	function syncEditors(){
		if(cfg.editorHref){
			document.querySelectorAll('.mce-edit-area iframe').forEach(syncEditor);
		}
	}
	function sync(){
		var m = akMode();
		var dark = m === 'dark';
		if(cfg.storageKey){
			try{localStorage.setItem(cfg.storageKey, m);}catch(e){}
		}
		var targets = [document.documentElement, document.body];
		if(cfg.includeWpbody){targets.push(document.getElementById('wpbody-content'));}
		targets.forEach(function(el){
			if(!el){return;}
			cfg.darkClasses.forEach(function(cls){el.classList.toggle(cls, dark);});
			cfg.lightClasses.forEach(function(cls){el.classList.toggle(cls, !dark);});
		});
		if(cfg.customEvent){
			document.documentElement.style.colorScheme = m;
			window.dispatchEvent(new CustomEvent(cfg.customEvent, {detail: {theme: m, effective: m}}));
		}
		syncEditors();
	}
	function observe(){
		if(window.MutationObserver){
			new MutationObserver(sync).observe(document.documentElement, {attributes: true, attributeFilter: ['data-adminkit-theme', 'data-adminkit-accent']});
		}
	}
	if(cfg.editorHref){
		document.addEventListener('load', function(event){
			if(event.target && event.target.matches && event.target.matches('.mce-edit-area iframe')){syncEditor(event.target);}
		}, true);
	}
	sync();
	if(cfg.immediate){observe();}
	document.addEventListener('DOMContentLoaded', function(){
		sync();
		if(cfg.editorHref && window.tinymce && window.tinymce.on){
			window.tinymce.on('AddEditor', function(event){
				if(event && event.editor && event.editor.on){
					event.editor.on('init', function(){syncEditor(event.editor.iframeElement);});
				}
			});
		}
		if(!cfg.immediate){observe();}
	});
})();
</script>
		<?php
	}
}
