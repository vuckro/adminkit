<?php
/**
 * Dark / light theme toggle.
 *
 * A sun/moon button in the admin bar that flips between light and dark
 * mode. The choice is persisted in localStorage and applied as an
 * attribute on <html> so CSS variables can re-cascade without a reload.
 * It also carries that mode into the classic editor's content iframe (a
 * separate document the page CSS can't reach) via TinyMCE content styles.
 *
 * Identifiers (kept in `data-` / localStorage so integrations can mirror
 * them with other systems):
 *   attribute:     data-adminkit-theme="dark" | "light"
 *   storage key:   adminkit-theme
 *
 * Filters:
 *   adminkit/theme_attribute    string  override the HTML attribute name
 *   adminkit/theme_storage_key  string  override the localStorage key
 *
 * The init script runs in <head> with priority 1 so the attribute is
 * set before paint (no flash of wrong colors).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Theme_Toggle {

	const NODE_ID = 'ak-theme-toggle';

	/**
	 * Wire the hooks. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'print_script' ), 1 );
		add_action( 'login_head', array( __CLASS__, 'print_script' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_script' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_node' ), 999 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_view_site_node' ), 998 );

		// Login-screen branding (site-icon logo + its link/text) is part of the
		// "Login screen" feature, so it follows that toggle — with it off, AdminKit
		// leaves wp-login.php entirely WP-default (no stylesheet, no logo override).
		if ( AdminKit_Settings::get( 'module_login_enabled' ) ) {
			add_action( 'login_head', array( __CLASS__, 'print_login_logo_style' ) );
			add_filter( 'login_headerurl', static function () { return home_url( '/' ); } );
			add_filter( 'login_headertext', static function () { return get_bloginfo( 'name' ); } );
		}

		// Carry dark mode into the classic editor's content iframe (a separate
		// document the page CSS can't reach).
		add_filter( 'tiny_mce_before_init', array( __CLASS__, 'editor_content_style' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_editor_bridge' ), 20 );
	}

	/**
	 * HTML attribute name. AdminKit owns its own attribute so no
	 * third-party JS can race or overwrite it. Filterable for builder
	 * integrations that want to mirror to their own attribute.
	 *
	 * @return string
	 */
	public static function attribute() {
		return apply_filters( 'adminkit/theme_attribute', 'data-adminkit-theme' );
	}

	/**
	 * localStorage key for the persisted choice. Same rationale as
	 * `attribute()` — owned, filterable.
	 *
	 * @return string
	 */
	public static function storage_key() {
		return apply_filters( 'adminkit/theme_storage_key', 'adminkit-theme' );
	}

	/**
	 * Print the inline init + click-handler script.
	 *
	 * Done inline (rather than a separate JS file) so we can:
	 *   1. Apply the stored theme BEFORE first paint.
	 *   2. Pass PHP-filtered identifiers in a single place.
	 *
	 * When the "Dark mode" feature is off, this forces light and ignores any
	 * saved/system preference — disabling the feature disables dark mode.
	 *
	 * @return void
	 */
	public static function print_script() {
		$attr        = self::attribute();
		$dataset_key = self::attribute_to_dataset( $attr );
		$key         = self::storage_key();
		$enabled     = (bool) AdminKit_Settings::get( 'theme_toggle_enabled' );
		?>
<script id="adminkit-theme">
(function(){
	var d = document.documentElement;
	var DS = <?php echo wp_json_encode( $dataset_key ); ?>;
<?php if ( ! $enabled ) : ?>
	d.dataset[DS] = 'light'; /* "Dark mode" feature off → force light, ignore any saved/system preference. */
	return;
<?php endif; ?>
	var KEY = <?php echo wp_json_encode( $key ); ?>;
	var m;
	try {
		m = localStorage.getItem(KEY);
		// Honor a provider's stored mode as a fallback signal (read-only —
		// we never write back, so no race with their JS at runtime).
		if (m !== 'dark' && m !== 'light') m = localStorage.getItem('brx_mode');
	} catch (e) {}
	if (m !== 'dark' && m !== 'light') {
		m = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
	}
	d.dataset[DS] = m;
	function bind() {
		var a = document.querySelector('#wp-admin-bar-<?php echo esc_js( self::NODE_ID ); ?> a');
		if (!a) return;
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var n = d.dataset[DS] === 'dark' ? 'light' : 'dark';
			d.dataset[DS] = n;
			try { localStorage.setItem(KEY, n); } catch (e) {}
		});
	}
	document.readyState === 'loading'
		? document.addEventListener('DOMContentLoaded', bind)
		: bind();
})();
</script>
		<?php
	}

	/**
	 * Register the admin bar node — sun + moon SVGs from Streamline
	 * (https://streamlinehq.com), CC BY 4.0.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function register_node( $bar ) {
		if ( ! AdminKit_Settings::get( 'theme_toggle_enabled' ) ) {
			return; // "Light / dark toggle" feature switched off.
		}
		$sun  = '<svg class="ak-theme-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.25a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-1.5 0V3a.75.75 0 0 1 .75-.75ZM7.5 12a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0Zm11.394-5.834a.75.75 0 0 0-1.06-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM21.75 12a.75.75 0 0 1-.75.75h-2.25a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 .75.75Zm-3.916 6.894a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 1 0-1.061 1.06l1.59 1.591ZM12 18a.75.75 0 0 1 .75.75V21a.75.75 0 0 1-1.5 0v-2.25A.75.75 0 0 1 12 18Zm-4.242-.697a.75.75 0 0 0-1.061-1.06l-1.591 1.59a.75.75 0 0 0 1.06 1.061l1.591-1.59ZM6 12a.75.75 0 0 1-.75.75H3a.75.75 0 0 1 0-1.5h2.25A.75.75 0 0 1 6 12Zm.697-4.243a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 0 0-1.061 1.06l1.59 1.591Z"/></svg>';
		$moon = '<svg class="ak-theme-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd"/></svg>';

		$bar->add_node( array(
			'id'     => self::NODE_ID,
			'parent' => 'top-secondary',
			'title'  => '<span class="ak-theme-light">' . $sun . '</span><span class="ak-theme-dark">' . $moon . '</span>',
			'href'   => '#',
			'meta'   => array(
				'class' => 'ak-theme-toggle',
				'title' => __( 'Toggle light / dark mode', 'adminkit' ),
			),
		) );
	}

	/**
	 * Admin-bar shortcut to the site frontend — an eye icon sitting beside the
	 * light/dark toggle. wp-admin only (on the frontend it would be redundant).
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function register_view_site_node( $bar ) {
		if ( ! is_admin() ) {
			return;
		}
		$eye = '<svg class="ak-theme-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12 3.75s9.19 3.226 10.677 7.697c.12.362.12.752 0 1.113C21.19 17.024 16.973 20.25 12 20.25S2.81 17.024 1.323 12.553a1.762 1.762 0 0 1 0-1.106Z" clip-rule="evenodd"/></svg>';
		$bar->add_node( array(
			'id'     => 'ak-view-site',
			'parent' => 'top-secondary',
			'title'  => $eye,
			'href'   => home_url( '/' ),
			'meta'   => array(
				'class' => 'ak-view-site',
				'title' => __( 'View site', 'adminkit' ),
			),
		) );
	}

	/**
	 * Use the WP site icon (favicon) as the login logo. Falls back
	 * silently to WP's default WordPress logo if no site icon is set.
	 *
	 * @return void
	 */
	public static function print_login_logo_style() {
		$icon = get_site_icon_url( 200 );
		if ( ! $icon ) {
			return;
		}
		printf(
			'<style id="adminkit-login-logo">#login h1 a{background-image:url(%s) !important}</style>',
			esc_url( $icon )
		);
	}

	/**
	 * Inject dark editor-content CSS into TinyMCE.
	 *
	 * The Visual editor renders post content inside an <iframe> — a separate
	 * document the admin stylesheet can't style. WP's `tiny_mce_before_init`
	 * lets us append CSS (`content_style`) to that document's <head>. The rules
	 * are gated behind an `.ak-editor-dark` class on the iframe's <html>, which
	 * print_editor_bridge() toggles to mirror AdminKit's mode — so light mode
	 * keeps the normal white editor and only dark mode recolors it.
	 *
	 * Values are literal (the iframe has no access to tokens.css) and track the
	 * AdminKit dark neutrals: bg #121212, surface #1c1c1c, text #bfbfbf, heading
	 * #dbdbdb, border #2e2e2e, elevated #242424. The editing well uses the bg tone
	 * (recessed below the surface .wp-editor-container) so it reads with relief in
	 * dark mode, mirroring the white-on-grey relief light mode gets for free.
	 * Links use a readable blue rather than the brand accent, which is poor as
	 * body-copy link color.
	 *
	 * @param array $init TinyMCE init settings.
	 * @return array
	 */
	public static function editor_content_style( $init ) {
		if ( ! is_admin() ) {
			return $init;
		}
		$css = 'html.ak-editor-dark{background-color:#121212}'
			. 'html.ak-editor-dark body#tinymce{background-color:#121212;color:#bfbfbf}'
			. 'html.ak-editor-dark a{color:#6aa0ff}'
			. 'html.ak-editor-dark h1,html.ak-editor-dark h2,html.ak-editor-dark h3,html.ak-editor-dark h4,html.ak-editor-dark h5,html.ak-editor-dark h6{color:#dbdbdb}'
			. 'html.ak-editor-dark hr{border-color:#2e2e2e}'
			. 'html.ak-editor-dark table td,html.ak-editor-dark table th{border-color:#2e2e2e}'
			. 'html.ak-editor-dark blockquote{border-left-color:#3a3a3a;color:#a8a8a8}'
			. 'html.ak-editor-dark code,html.ak-editor-dark pre{background-color:#242424;color:#dbdbdb}';

		$init['content_style'] = isset( $init['content_style'] )
			? $init['content_style'] . ' ' . $css
			: $css;
		return $init;
	}

	/**
	 * Print the JS that mirrors AdminKit's mode into each TinyMCE iframe.
	 *
	 * Adds/removes `.ak-editor-dark` on the iframe's <html> on editor init
	 * (via WP's `tinymce-editor-init` jQuery event) and re-applies to every
	 * editor when the page's theme attribute flips. Reads the attribute, never
	 * writes it — no loop with the toggle handler.
	 *
	 * @return void
	 */
	public static function print_editor_bridge() {
		$attr = self::attribute();
		?>
<script id="adminkit-editor-theme">
(function(){
	var ATTR = <?php echo wp_json_encode( $attr ); ?>;
	function isDark(){ return document.documentElement.getAttribute(ATTR) === 'dark'; }
	function apply(ed){
		try { var d = ed.getDoc(); if (d && d.documentElement) d.documentElement.classList.toggle('ak-editor-dark', isDark()); } catch (e) {}
	}
	function applyAll(){ if (window.tinymce && tinymce.editors) tinymce.editors.forEach(apply); }
	if (window.jQuery) { jQuery(document).on('tinymce-editor-init', function(e, ed){ apply(ed); }); }
	new MutationObserver(applyAll).observe(document.documentElement, { attributes:true, attributeFilter:[ATTR] });
})();
</script>
		<?php
	}

	/**
	 * Convert a `data-x-y-z` attribute name to the camelCase dataset
	 * accessor (`xYZ`).
	 *
	 * @param string $attr
	 * @return string
	 */
	private static function attribute_to_dataset( $attr ) {
		$name = preg_replace( '/^data-/', '', $attr );
		return lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', $name ) ) ) );
	}
}
