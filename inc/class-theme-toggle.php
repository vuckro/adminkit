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
		// The Customizer (customize.php) skips admin_head, so the pre-paint
		// bootstrap rides its own scripts hook — sets data-adminkit-theme on the
		// Customizer's <html> so the tokens' dark block applies there too.
		add_action( 'customize_controls_print_scripts', array( __CLASS__, 'print_script' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_node' ), 999 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_view_site_node' ), 998 );

		// Login-screen branding (logo + its link/text) and the login light/dark
		// switch live in AdminKit_Core_Login (the wp-login owner). This class still
		// prints the pre-paint bootstrap on login_head above so the theme attribute
		// is set before first paint there too.

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
		$action = function_exists( 'current_action' ) ? current_action() : '';
		if ( 'wp_head' === $action && ( ! is_admin_bar_showing() || ! apply_filters( 'adminkit/should_load', true, 'frontend' ) ) ) {
			return;
		}
		if ( 'login_head' === $action && ( ! AdminKit_Settings::get( 'module_login_enabled' ) || ! apply_filters( 'adminkit/should_load', true, 'login' ) ) ) {
			return;
		}
		$accent = AdminKit_Settings::accent_source();
		if ( 'custom' === $accent && ! sanitize_hex_color( (string) AdminKit_Settings::get( 'brand_accent' ) ) ) {
			$accent = 'monochrome';
		}
		$attr        = self::attribute();
		$dataset_key = self::attribute_to_dataset( $attr );
		$key         = self::storage_key();
		$enabled     = (bool) AdminKit_Settings::get( 'theme_toggle_enabled' );
		?>
<script id="adminkit-theme">
(function(){
	var d = document.documentElement;
	d.setAttribute('data-adminkit-accent', <?php echo wp_json_encode( 'adminkit' === $accent ? 'waaskit' : $accent ); ?>);
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
	// AUTHORITATIVE flip. Delegated on document (capture-phase) so it fires no
	// matter when the node mounts or how the bar re-renders — and on the front
	// end with or without any provider (e.g. Bricks). It always flips AdminKit's
	// own attribute + persists; an optional provider bridge mirrors that flip into
	// the provider afterwards, but the bar's own switch never depends on it.
	var SEL = '#wp-admin-bar-<?php echo esc_js( self::NODE_ID ); ?> a';
	function syncBtn() {
		var b = document.querySelector(SEL);
		if (!b) return;
		b.setAttribute('role', 'button');
		b.setAttribute('aria-pressed', d.dataset[DS] === 'dark' ? 'true' : 'false');
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', syncBtn);
	} else {
		syncBtn();
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest(SEL);
		if (!a) return;
		e.preventDefault();
		var n = d.dataset[DS] === 'dark' ? 'light' : 'dark';
		d.dataset[DS] = n;
		syncBtn();
		try { localStorage.setItem(KEY, n); } catch (err) {}
	}, true);
})();
</script>
		<?php
	}

	/**
	 * Register the admin bar node — sun + moon SVGs from Lucide (ISC/MIT).
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function register_node( $bar ) {
		if ( ! AdminKit_Settings::get( 'theme_toggle_enabled' ) ) {
			return; // "Light / dark toggle" feature switched off.
		}
		if ( ! apply_filters( 'adminkit/should_load', true, is_admin() ? 'admin' : 'frontend' ) ) {
			return;
		}
		$sun   = str_replace( '<svg ', '<svg class="ak-theme-icon" ', AdminKit_Icons::display_svg( 'sun' ) );
		$moon  = str_replace( '<svg ', '<svg class="ak-theme-icon" ', AdminKit_Icons::display_svg( 'moon' ) );
		$label = __( 'Toggle light / dark mode', 'adminkit' );

		$bar->add_node( array(
			'id'     => self::NODE_ID,
			'parent' => 'top-secondary',
			'title'  => '<span class="ak-theme-light">' . $sun . '</span><span class="ak-theme-dark">' . $moon . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span>',
			'href'   => '#',
			'meta'   => array(
				'class' => 'ak-theme-toggle',
				'title' => $label,
			),
		) );
	}

	/**
	 * Admin-bar shortcut between wp-admin and the live site, beside the light/dark
	 * toggle — shown in BOTH contexts with a context-appropriate icon:
	 *   - in wp-admin: an "open site" (external) icon → goes to the live site in the
	 *     SAME tab (a plain navigation, like WP's own "Visit Site");
	 *   - on the front end: a "home" (house) icon → goes to the backend (dashboard).
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function register_view_site_node( $bar ) {
		$in_admin = is_admin();
		if ( ! apply_filters( 'adminkit/should_load', true, $in_admin ? 'admin' : 'frontend' ) ) {
			return;
		}

		// wp-admin → "open site" (external) glyph; front end → house.
		$open  = str_replace( '<svg ', '<svg class="ak-theme-icon" ', AdminKit_Icons::display_svg( 'external-link' ) );
		$house = str_replace( '<svg ', '<svg class="ak-theme-icon" ', AdminKit_Icons::display_svg( 'house' ) );
		$label = $in_admin ? __( 'View site', 'adminkit' ) : __( 'Dashboard', 'adminkit' );

		$bar->add_node( array(
			'id'     => 'ak-view-site',
			'parent' => 'top-secondary',
			'title'  => ( $in_admin ? $open : $house ) . '<span class="screen-reader-text">' . esc_html( $label ) . '</span>',
			// Admin → the live site in the SAME tab (a plain navigation); front end →
			// back to the dashboard (the house is the admin's "home base" / way in).
			'href'   => $in_admin ? home_url( '/' ) : admin_url(),
			'meta'   => array(
				'class' => 'ak-view-site',
				'title' => $label,
			),
		) );
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
	 * Values are literal (the iframe has no access to tokens.css) and mirror the
	 * WaasKit dark roles in assets/css/waaskit-tokens.css: bg (--wk-bg), surface
	 * (--wk-surface), text (--wk-text / --wk-text-muted), border (--wk-border). The
	 * editing well uses the bg tone (recessed below the surface .wp-editor-container)
	 * so it reads with relief in dark mode, mirroring the white-on-grey relief light
	 * mode gets for free.
	 * Links use a readable blue rather than the brand accent, which is poor as
	 * body-copy link color.
	 *
	 * @param array $init TinyMCE init settings.
	 * @return array
	 */
	public static function editor_content_style( $init ) {
		if ( ! is_admin() || ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return $init;
		}
		$bg      = 'oklch(20.5% 0.005 258)';
		$surface = 'oklch(24.8% 0.005 258)';
		$text    = 'oklch(90% 0.006 258)';
		$strong  = 'oklch(98% 0.002 258)';
		$subtle  = 'oklch(81% 0.008 258)';
		$border  = 'oklch(34.5% 0.006 258)';
		$css = 'html.ak-editor-dark{background-color:' . $bg . '}'
			. 'html.ak-editor-dark body#tinymce{background-color:' . $bg . ';color:' . $text . '}'
			. 'html.ak-editor-dark a{color:#6aa0ff}'
			. 'html.ak-editor-dark h1,html.ak-editor-dark h2,html.ak-editor-dark h3,html.ak-editor-dark h4,html.ak-editor-dark h5,html.ak-editor-dark h6{color:' . $strong . '}'
			. 'html.ak-editor-dark hr{border-color:' . $border . '}'
			. 'html.ak-editor-dark table td,html.ak-editor-dark table th{border-color:' . $border . '}'
			. 'html.ak-editor-dark blockquote{border-left-color:' . $border . ';color:' . $subtle . '}'
			. 'html.ak-editor-dark code,html.ak-editor-dark pre{background-color:' . $surface . ';color:' . $strong . '}';

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
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
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
