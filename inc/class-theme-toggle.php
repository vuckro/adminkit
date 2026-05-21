<?php
/**
 * Dark / light theme toggle.
 *
 * A sun/moon button in the admin bar that flips between light and dark
 * mode. The choice is persisted in localStorage and applied as an
 * attribute on <html> so CSS variables can re-cascade without a reload.
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
		add_action( 'login_head', array( __CLASS__, 'print_login_logo_style' ) );

		add_filter( 'login_headerurl', static function () { return home_url( '/' ); } );
		add_filter( 'login_headertext', static function () { return get_bloginfo( 'name' ); } );
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
	 * @return void
	 */
	public static function print_script() {
		$attr        = self::attribute();
		$dataset_key = self::attribute_to_dataset( $attr );
		$key         = self::storage_key();
		?>
<script id="adminkit-theme">
(function(){
	var d = document.documentElement;
	var DS = <?php echo wp_json_encode( $dataset_key ); ?>;
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
