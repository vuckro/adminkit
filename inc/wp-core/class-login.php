<?php
/**
 * Core login — the wp-login.php screen.
 *
 * Owns three things for the login page, all gated by the "Login screen" feature
 * (module_login_enabled):
 *   1. the login stylesheet (assets/css/login.css);
 *   2. the login LOGO — the brand logo or the site icon, per the SAME `wp_logo`
 *      setting that drives the admin-bar mark, so one choice brands both;
 *   3. a light / dark SWITCH on the page (shown only when the dark-mode feature is
 *      on), persisted with the SAME attribute + storage key as the rest of AdminKit
 *      so the choice carries between the login screen and wp-admin.
 *
 * The pre-paint theme bootstrap (the inline <head> script that sets
 * data-adminkit-theme before first paint, avoiding a flash) lives in
 * AdminKit_Theme_Toggle and already runs on login_head — this class only adds the
 * visible CONTROL + its click handler.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Login {

	const TOGGLE_ID = 'ak-login-theme-toggle';

	/**
	 * Register the login stylesheet and wire the login-screen hooks. Called once
	 * from the plugin orchestrator after `AdminKit_Assets::init()`.
	 *
	 * The hooks are wired UNCONDITIONALLY and each callback does its own
	 * feature-flag check: this method runs at plugin-load, BEFORE
	 * AdminKit_Settings::init() has registered the toggle defaults, so a flag read
	 * here would wrongly see an unregistered (null) value. The callbacks fire on
	 * login_head / login_footer — long after settings are initialised — so the
	 * gating is correct there.
	 *
	 * @return void
	 */
	public static function register() {
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-login',
			'src'     => 'assets/css/login.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'login',
		) );

		// Login branding (logo + the link/text under it) is part of the "Login
		// screen" feature; the callbacks/filters below no-op when it's off.
		add_action( 'login_head', array( __CLASS__, 'print_login_logo_style' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'filter_header_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'filter_header_text' ) );
		// Tag the body with which mark is shown so login.css can frame it correctly
		// (a square rounded tile for a favicon, a wide rounded card for a wordmark)
		// — that's what makes the radius actually read on the logo.
		add_filter( 'login_body_class', array( __CLASS__, 'filter_body_class' ) );

		// The login light/dark switch (also gated inside, on BOTH the login feature
		// and the dark-mode feature — the toggle only makes sense when dark mode is
		// available, the same flag the admin-bar toggle uses).
		add_action( 'login_footer', array( __CLASS__, 'print_theme_toggle' ) );
	}

	/**
	 * Whether AdminKit should brand the login screen ("Login screen" feature).
	 * Read inside the late login_* callbacks so it sees the initialised default.
	 *
	 * @return bool
	 */
	private static function is_enabled() {
		return (bool) AdminKit_Settings::get( 'module_login_enabled' );
	}

	/**
	 * Point the login-logo link at the site home (only when AdminKit brands the
	 * login screen; otherwise leave WP's default — wordpress.org).
	 *
	 * @param string $url
	 * @return string
	 */
	public static function filter_header_url( $url ) {
		return self::is_enabled() ? home_url( '/' ) : $url;
	}

	/**
	 * Set the login-logo link text to the site name (only when AdminKit brands the
	 * login screen).
	 *
	 * @param string $text
	 * @return string
	 */
	public static function filter_header_text( $text ) {
		return self::is_enabled() ? get_bloginfo( 'name' ) : $text;
	}

	/**
	 * Add a body class describing which mark the login screen shows, so login.css
	 * can frame it for a visible radius: `ak-login-mark--favicon` (a square rounded
	 * tile) or `ak-login-mark--logo` (a wide rounded card). No class when no mark is
	 * configured (WordPress's own logo then shows, unstyled by us).
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function filter_body_class( $classes ) {
		if ( ! self::is_enabled() ) {
			return $classes;
		}
		$type = self::login_mark_type();
		if ( '' !== $type ) {
			$classes[] = 'ak-login-mark--' . $type;
		}
		return $classes;
	}

	/**
	 * Which mark the login screen will show: 'logo' (a configured brand wordmark,
	 * only when wp_logo is "logo"), else 'favicon' (the site icon), else '' (none).
	 * Mirrors the resolution order in print_login_logo_style().
	 *
	 * @return string 'logo' | 'favicon' | ''
	 */
	private static function login_mark_type() {
		if ( 'logo' === AdminKit_Settings::get( 'wp_logo' ) ) {
			$logo = AdminKit_Settings::brand_logo( 'light' );
			if ( '' === $logo ) {
				$logo = AdminKit_Settings::brand_logo( 'dark' );
			}
			if ( '' !== $logo ) {
				return 'logo';
			}
		}
		return '' !== get_site_icon_url( 200 ) ? 'favicon' : '';
	}

	/**
	 * The login logo — the brand logo or the site icon (favicon), shown `contain`
	 * (sizing + the tokenised border-radius live in login.css, #login h1 a; here we
	 * only inject the image URL(s)). The choice follows the `wp_logo` setting:
	 *   - `logo` → the brand logo (light/dark variants; the dark one swaps under the
	 *              theme flag), shown `contain` so a wide wordmark fits uncropped;
	 *   - anything else (`favicon`, `hide`, legacy) → the site icon.
	 * Note `hide` falls back to the favicon here ON PURPOSE: it hides the admin-bar
	 * mark (declutters the toolbar) but the login screen is its own surface that
	 * always reads better WITH a brand mark — and this preserves the long-standing
	 * "favicon on the login screen" behaviour. No-ops silently when neither a brand
	 * logo nor a site icon is configured (WordPress's own logo then stays).
	 *
	 * @return void
	 */
	public static function print_login_logo_style() {
		if ( ! self::is_enabled() ) {
			return;
		}
		$mode = AdminKit_Settings::get( 'wp_logo' );

		$light = '';
		$dark  = '';

		if ( 'logo' === $mode ) {
			$light = self::css_url( AdminKit_Settings::brand_logo( 'light' ) );
			$dark  = self::css_url( AdminKit_Settings::brand_logo( 'dark' ) );
		}

		// `favicon` / `hide` / legacy, or `logo` with no brand logo configured → fall
		// back to the site icon (a single square image, same in both themes).
		if ( '' === $light && '' === $dark ) {
			$icon  = self::css_url( get_site_icon_url( 200 ) );
			$light = $icon;
			$dark  = $icon;
		}

		if ( '' === $light && '' === $dark ) {
			return;
		}
		if ( '' === $light ) {
			$light = $dark;
		}
		if ( '' === $dark ) {
			$dark = $light;
		}

		// `!important` mirrors WP core's own login-logo rule (it ships an inline-ish
		// background-image on #login h1 a we have to beat); the dark override needs to
		// out-specify the light one, hence the attribute-scoped selector + !important.
		$css = '#login h1 a{background-image:' . $light . ' !important}';
		if ( $dark !== $light ) {
			$css .= ':root[data-adminkit-theme="dark"] #login h1 a{background-image:' . $dark . ' !important}';
		}
		echo '<style id="adminkit-login-logo">' . $css . "</style>\n";
	}

	/**
	 * Print the login light/dark switch — a real <button> (accessible: aria-label +
	 * aria-pressed) carrying both the sun and moon glyphs (CSS shows the right one
	 * per theme), plus a small inline handler that flips and PERSISTS the choice.
	 *
	 * Persistence is identical to the admin-bar toggle — the same attribute
	 * (data-adminkit-theme on <html>) and the same localStorage key — read from
	 * AdminKit_Theme_Toggle so the two controls stay in lockstep and the choice
	 * carries between the login screen and wp-admin. The pre-paint script (printed by
	 * AdminKit_Theme_Toggle on login_head) has already set the attribute before this
	 * runs, so aria-pressed is seeded from the live attribute.
	 *
	 * @return void
	 */
	public static function print_theme_toggle() {
		// Shown only when the login screen is branded AND dark mode is available.
		if ( ! self::is_enabled() || ! AdminKit_Settings::get( 'theme_toggle_enabled' ) ) {
			return;
		}
		$attr        = AdminKit_Theme_Toggle::attribute();
		$dataset_key = self::attribute_to_dataset( $attr );
		$key         = AdminKit_Theme_Toggle::storage_key();
		$label       = esc_attr__( 'Toggle light / dark mode', 'adminkit' );

		// Sun + moon (Streamline, CC BY 4.0) — the same marks the admin-bar toggle
		// uses; CSS in login.css shows the one matching the current theme.
		$sun  = '<svg class="ak-login-theme-icon ak-theme-light" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2.25a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-1.5 0V3a.75.75 0 0 1 .75-.75ZM7.5 12a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0Zm11.394-5.834a.75.75 0 0 0-1.06-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM21.75 12a.75.75 0 0 1-.75.75h-2.25a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 .75.75Zm-3.916 6.894a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 1 0-1.061 1.06l1.59 1.591ZM12 18a.75.75 0 0 1 .75.75V21a.75.75 0 0 1-1.5 0v-2.25A.75.75 0 0 1 12 18Zm-4.242-.697a.75.75 0 0 0-1.061-1.06l-1.591 1.59a.75.75 0 0 0 1.06 1.061l1.591-1.59ZM6 12a.75.75 0 0 1-.75.75H3a.75.75 0 0 1 0-1.5h2.25A.75.75 0 0 1 6 12Zm.697-4.243a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 0 0-1.061 1.06l1.59 1.591Z"/></svg>';
		$moon = '<svg class="ak-login-theme-icon ak-theme-dark" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd"/></svg>';

		printf(
			'<button type="button" id="%1$s" class="ak-login-theme-toggle" aria-label="%2$s" title="%2$s" aria-pressed="false">%3$s%4$s</button>',
			esc_attr( self::TOGGLE_ID ),
			$label,
			$sun,
			$moon
		);
		?>
<script id="adminkit-login-theme-toggle">
(function(){
	var d = document.documentElement;
	var DS = <?php echo wp_json_encode( $dataset_key ); ?>;
	var KEY = <?php echo wp_json_encode( $key ); ?>;
	var btn = document.getElementById(<?php echo wp_json_encode( self::TOGGLE_ID ); ?>);
	if (!btn) return;
	function sync(){ btn.setAttribute('aria-pressed', d.dataset[DS] === 'dark' ? 'true' : 'false'); }
	sync();
	btn.addEventListener('click', function(){
		var n = d.dataset[DS] === 'dark' ? 'light' : 'dark';
		d.dataset[DS] = n;
		try { localStorage.setItem(KEY, n); } catch (e) {}
		sync();
	});
})();
</script>
		<?php
	}

	/**
	 * Wrap a URL for safe use inside a CSS `url()` in a <style> block —
	 * `esc_url_raw()` (NOT esc_url(), which entity-encodes `&` and breaks a CSS
	 * parser on query-string image URLs). Returns '' for an empty URL.
	 *
	 * @param string $url
	 * @return string  e.g. `url("https://…")`, or '' when empty.
	 */
	private static function css_url( $url ) {
		$url = esc_url_raw( (string) $url );
		return ( '' === $url ) ? '' : 'url("' . $url . '")';
	}

	/**
	 * Convert a `data-x-y-z` attribute name to its camelCase dataset accessor
	 * (`xYZ`) — mirrors AdminKit_Theme_Toggle so the two controls write the same
	 * dataset key.
	 *
	 * @param string $attr
	 * @return string
	 */
	private static function attribute_to_dataset( $attr ) {
		$name = preg_replace( '/^data-/', '', $attr );
		return lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', $name ) ) ) );
	}
}
