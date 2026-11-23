<?php
/**
 * AdminKit_Integration_Elementor — Elementor (Free + Pro) admin skin.
 *
 * Tier A: Elementor drives its admin UI — both the classic pages (Settings,
 * Tools, Role Manager, System Info…) and the React "app" (the Home dashboard) —
 * almost entirely from its own CSS variables (--e-*, --app-*, --body-*, the
 * --gray-* ramp, status + brand). css/admin.css redeclares those variables as
 * AdminKit --ak-* tokens, scoped under body.adminkit (a nearer ancestor than
 * Elementor's :root, so it wins) and under .eps-theme-dark (Elementor's own dark
 * class). The --ak-* tokens already flip with AdminKit's light/dark, so Elementor
 * follows for free — no !important, no flash. The colour→token map was
 * bootstrapped by dev/adapter-scan.php then hand-tuned.
 *
 * The live builder/editor canvas is intentionally NOT themed (it previews the
 * front-end): owns_screen only matches Elementor's wp-admin screens (which carry
 * 'elementor' in the screen id) — the editor runs on a 'post' screen, excluded.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Elementor extends AdminKit_Integration_Base {

	public static function slug() {
		return 'elementor';
	}

	/** @return string Human-readable host name shown in Settings → Plugins. */
	public static function label() {
		return 'Elementor';
	}

	/** @return string[] Host plugin file(s) this adapter themes. */
	public static function host_files() {
		return array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' );
	}

	/**
	 * Elementor Free defines ELEMENTOR_VERSION at boot (Pro requires Free).
	 */
	public static function is_active() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Elementor admin screens: the top-level "Elementor" / "Website Builder" menu,
	 * every elementor_page_* sub-page, Elementor CPT screens (`elementor_library`,
	 * `elementor_font`, `elementor_icons`, `elementor_snippet`, `e-floating-buttons`),
	 * and Pro sub-pages (`e-form-submissions`, `e-custom-*`) regardless of how a
	 * locale translates the parent menu title. The live frontend visual editor runs
	 * on a `post` screen (or `?action=elementor`), so it stays excluded.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		$id        = (string) $screen->id;
		$post_type = property_exists( $screen, 'post_type' ) ? (string) $screen->post_type : '';
		if ( false !== strpos( $id, 'elementor' ) || false !== strpos( $post_type, 'elementor' ) ) {
			return true;
		}
		return (bool) preg_match( '/(?:^|_page_|edit-)(?:e-floating-buttons|e-form-submissions|e-custom-fonts|e-custom-icons|e-custom-code)(?:$|\b)/', $id )
			|| 'e-floating-buttons' === $post_type;
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
	}

	/**
	 * Tier A version gate (majors only — see the base class). Pinned to the
	 * tested major; revisit if Elementor reshuffles its variable names.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '4';
	}

	/**
	 * Elementor's full-screen App (`admin.php?page=elementor-app`, Theme Builder /
	 * Kit Library / Import-Export) renders its own `<!DOCTYPE html>` and exits on
	 * `admin_init` before `admin_enqueue_scripts` or `admin_head` run. Hook its
	 * `elementor/app/init` lifecycle so AdminKit's theme bootstrap and token skin
	 * load there too.
	 *
	 * @return void
	 */
	protected static function boot() {
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		add_action( 'elementor/app/init', array( __CLASS__, 'setup_elementor_app' ) );
	}

	/**
	 * Enqueue AdminKit tokens + Elementor skin inside `admin.php?page=elementor-app`.
	 *
	 * @return void
	 */
	public static function setup_elementor_app() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) || ! apply_filters( 'adminkit/appearance_enabled', true, 'admin' ) ) {
			return;
		}
		add_action( 'wp_print_styles', array( __CLASS__, 'print_elementor_app_assets' ), 1 );
	}

	/**
	 * Runs right inside `elementor/app/view.php` (`wp_print_styles()`), after
	 * `App::enqueue_assets()` has registered `elementor-app`.
	 *
	 * @return void
	 */
	public static function print_elementor_app_assets() {
		AdminKit_Theme_Toggle::print_script();
		wp_enqueue_style( AdminKit_Assets::FONTS_HANDLE, ADMINKIT_URL . AdminKit_Assets::FONTS_SRC, array(), (string) filemtime( ADMINKIT_PATH . AdminKit_Assets::FONTS_SRC ) );
		wp_enqueue_style( AdminKit_Assets::WAASKIT_HANDLE, ADMINKIT_URL . AdminKit_Assets::WAASKIT_SRC, array(), (string) filemtime( ADMINKIT_PATH . AdminKit_Assets::WAASKIT_SRC ) );
		wp_enqueue_style( AdminKit_Assets::TOKENS_HANDLE, ADMINKIT_URL . AdminKit_Assets::TOKENS_SRC, array( AdminKit_Assets::WAASKIT_HANDLE ), (string) filemtime( ADMINKIT_PATH . AdminKit_Assets::TOKENS_SRC ) );
		AdminKit_Assets::inject_accent_family( 'admin' );
		$src  = 'inc/integrations/plugins/elementor/css/admin.css';
		$deps = wp_style_is( 'elementor-app', 'registered' ) ? array( AdminKit_Assets::TOKENS_HANDLE, 'elementor-app' ) : array( AdminKit_Assets::TOKENS_HANDLE );
		wp_enqueue_style( 'adminkit-elementor-admin', ADMINKIT_URL . $src, $deps, (string) filemtime( ADMINKIT_PATH . $src ) );
		if ( wp_script_is( 'elementor-app', 'registered' ) ) {
			wp_add_inline_script(
				'elementor-app',
				'(function(){var d=document.documentElement.getAttribute("data-adminkit-theme")==="dark";document.body.classList.add("adminkit");document.body.classList.toggle("eps-theme-dark",d);})();',
				'before'
			);
		}
	}

	/**
	 * Screens where Elementor's `ai-media-library` mounts AI triggers (`upload.php`
	 * and screens enqueuing `wp-mediaelement` / media modals alongside Elementor AI).
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_media_ai_screen( $screen ) {
		if ( wp_script_is( 'elementor-ai-media-library', 'enqueued' ) ) {
			return true;
		}
		return $screen && in_array( $screen->id, array( 'upload', 'attachment' ), true );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		if ( ! static::host_within_tested_range() ) {
			return; // past the tested major — fall back to Elementor's native UI.
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-elementor-admin',
			'src'       => 'inc/integrations/plugins/elementor/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-elementor-media-ai',
			'src'       => 'inc/integrations/plugins/elementor/css/media-ai.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_media_ai_screen' ),
		) );
	}
}
