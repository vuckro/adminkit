<?php
/**
 * AdminKit — runtime auto-theming ("the safety net").
 *
 * Native adapters (inc/integrations/) and AdminKit's wp-core sheets theme the
 * markup we can target by selector. But modern plugins increasingly paint their
 * admin UI with HARDCODED colours we can't reach from a stylesheet — via CSS-in-JS
 * (emotion / styled-components → hashed, run-time-injected class names) or inline
 * styles. Elementor 4's MUI-based Home, for instance, hardcodes
 * `background:#fff` on `.MuiPaper-root`; no sheet AdminKit ships can override it.
 *
 * So for ANY admin screen, this feature enqueues a small JS brick
 * (assets/js/wp-core/auto-theme.js) that scans the rendered DOM and *tags* the
 * surfaces / text still painted in fixed light/dark values; a dark-only companion
 * sheet (assets/css/wp-core/auto-theme.css) remaps the tagged elements to --ak-*
 * tokens. It is SELF-LIMITING — it only tags elements whose computed colour is
 * still a fixed near-white / near-black, so anything a native adapter already
 * remapped to a token is skipped. The result: unsupported plugins get a clean
 * dark mode automatically, with native adapters still winning where they exist.
 *
 * On by default; switchable via the `auto_theme_enabled` setting (self-registered
 * here so this stays a drop-in wp-core feature).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Auto_Theme {

	/** Shared handle for the brick's script + style. */
	const HANDLE = 'adminkit-auto-theme';

	/** Setting key — the on/off toggle (default ON). */
	const SETTING = 'auto_theme_enabled';

	/**
	 * Setting key — the brand-colour remap sub-toggle (default ON). Detects the
	 * host plugin's primary/brand colour (from its buttons) and remaps it to
	 * --ak-primary. Separable so it can be disabled without losing the surface
	 * mapping, e.g. add_filter( 'adminkit/setting/auto_theme_brand_enabled',
	 * '__return_false' ).
	 */
	const SETTING_BRAND = 'auto_theme_brand_enabled';

	/**
	 * Register the toggles (idempotent, default ON) and, when enabled, enqueue the
	 * brick on admin screens. Called once from the orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		$boolish = static function ( $v ) {
			return (bool) $v;
		};
		AdminKit_Settings::register( self::SETTING, array( 'default' => true, 'sanitize' => $boolish ) );
		AdminKit_Settings::register( self::SETTING_BRAND, array( 'default' => true, 'sanitize' => $boolish ) );

		if ( ! AdminKit_Settings::get( self::SETTING ) ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the dark-only paint sheet + the detection brick on every admin
	 * screen. The brick self-limits to elements still painted in fixed colours,
	 * so it's safe (a no-op) on screens an adapter already themed. Honors the
	 * global should_load veto, like every other AdminKit asset.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		$css_src  = 'assets/css/wp-core/auto-theme.css';
		$js_src   = 'assets/js/wp-core/auto-theme.js';
		$css_path = ADMINKIT_PATH . $css_src;
		$js_path  = ADMINKIT_PATH . $js_src;

		wp_enqueue_style(
			self::HANDLE,
			ADMINKIT_URL . $css_src,
			array( AdminKit_Assets::TOKENS_HANDLE ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ADMINKIT_VERSION
		);
		wp_enqueue_script(
			self::HANDLE,
			ADMINKIT_URL . $js_src,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : ADMINKIT_VERSION,
			true
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.AdminKitAuto=' . wp_json_encode( array(
				'enabled' => true,
				'brand'   => (bool) AdminKit_Settings::get( self::SETTING_BRAND ),
			) ) . ';',
			'before'
		);
	}
}
