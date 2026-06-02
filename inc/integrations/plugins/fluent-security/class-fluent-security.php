<?php
/**
 * FluentSecurity / FluentAuth integration — small Tier B-gated adapter (Vue 3
 * SPA on Element Plus).
 *
 * FluentAuth renders its admin as a Vue 3 SPA built on Element Plus, and the
 * component styles are bundled INSIDE dist/admin/app.js (stock `--el-*` tokens,
 * no separate admin CSS file). So this adapter is almost entirely a token remap:
 * point Element Plus's `--el-*` layer at AdminKit's `--ak-*` and the whole UI —
 * surfaces, text, borders, every Element Plus component — follows, dark mode
 * included. FluentAuth ships NO dark mode of its own, so the `--ak-*` flip is
 * what gives it one; there is no host dark class to fight, hence no theme-sync
 * JS here. This is a SMALL plugin, so the adapter is modest by design (the
 * `--el-*` remap + a few literal overrides — that's the whole surface).
 *
 * Frontend out of scope: dist/public/login_customizer.css (the `--fls-*` login
 * skin) is a FRONTEND surface, not the admin app — untouched here.
 *
 * Loaded only on toplevel_page_fluent-auth (the SPA owns every sub-route via
 * hash routing under that one WP page — Logs, Settings, Forms, Redirects, …).
 *
 * Tier B safety: the skin leans on Element Plus's `--el-*` token names + a
 * couple of brand literals, which a new major may reshuffle, so it is
 * version-gated on the major and drops to FluentAuth's native UI past the
 * tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Security extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-security';
	}

	/**
	 * FluentAuth registers its top-level menu under the slug `fluent-auth` (not
	 * the plugin slug `fluent-security`), so point the wordmark resolver at that
	 * menu title.
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return 'fluent-auth';
	}

	/**
	 * FluentAuth defines `FLUENT_AUTH_VERSION` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_AUTH_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_AUTH_VERSION' ) ? FLUENT_AUTH_VERSION : null;
	}

	/**
	 * Verified against FluentAuth 2.x. A new major may rename the `--el-*` tokens
	 * or reshuffle the brand literals this adapter targets, so gate on the major —
	 * register_assets() falls back to native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '2.1.2';
	}

	/**
	 * FluentAuth registers a top-level menu with slug `fluent-auth` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluent-auth`. Every
	 * sub-page (Logs, Settings, Forms, Redirects, WP Emails, Scans) is a hash
	 * route under the same screen, so one id covers the entire SPA.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-auth' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentAuth's native UI
		// shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-security-admin',
			'src'       => 'inc/integrations/plugins/fluent-security/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentAuth screen — Element
	 * Plus ships its own input/select/textarea styling, so our components/*.css
	 * would double-border its widgets (a native border inside the
	 * `.el-input__wrapper` that already has one). admin.css themes the Element
	 * Plus surfaces directly via the token remap instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fauth' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the FluentCRM adapter.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fauth( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentAuth's menu icon for a shield-check glyph.
	 *
	 * FluentAuth registers its top-level menu with an inline SVG data-URI icon,
	 * so its `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop the inline background-image and
	 * mask a shield-check Heroicon (login security) into the icon box — same
	 * technique menu_css() uses, so it tracks the menu foreground colour in
	 * light/dark/hover/current. Inlined here so the adapter owns its own mark.
	 * Gated exactly like the FluentCRM adapter: only when the icon toggle is on
	 * AND the global should_load pause hasn't disabled AdminKit. The menu shows on
	 * every admin page, so there is no screen gate.
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
		// Heroicons (solid) shield-check — login security. Fill is irrelevant
		// (painted as a mask; the visible colour is currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M12.516 2.17a.75.75 0 0 0-1.032 0 11.209 11.209 0 0 1-7.877 3.08.75.75 0 0 0-.722.515A12.74 12.74 0 0 0 2.25 9.75c0 5.942 4.064 10.933 9.563 12.348a.749.749 0 0 0 .374 0c5.499-1.415 9.563-6.406 9.563-12.348 0-1.39-.223-2.73-.635-3.985a.75.75 0 0 0-.722-.516l-.143.001c-2.996 0-5.717-1.17-7.734-3.08Zm3.094 8.016a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/></svg>';
		$box  = '#adminmenu #toplevel_page_fluent-auth .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-security-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
