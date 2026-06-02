<?php
/**
 * FluentCommunity integration — ADMIN screen only (the onboarding/settings app).
 *
 * SCOPE. FluentCommunity's main product is a FRONTEND community portal (rendered
 * headless at /portal). AdminKit themes wp-admin, NOT the frontend, so this
 * adapter targets ONLY the one wp-admin screen FluentCommunity registers —
 * `toplevel_page_fluent-community` — which mounts a small Vue onboarding/settings
 * wizard into `#fcom_onboarding_app`. The frontend portal (`#fluent_com_portal` /
 * the `/portal/admin` Portal-Settings SPA that loads `admin_app.css`) is OUT of
 * scope and never touched.
 *
 * That admin screen loads its OWN bundle — `assets/onboarding.css` (NOT the
 * frontend `admin_app.css`). onboarding.css ships the STOCK Element Plus `--el-*`
 * token layer in `:root` (unmodified EP defaults: #409eff primary, #303133 text,
 * …) and declares NO `--fcom-*` layer of its own. So the bulk of this adapter is
 * the canonical `--el-*` remap (§1) — point EP's tokens at AdminKit `--ak-*` and
 * the wizard's Element Plus widgets follow, dark mode included. The wizard ALSO
 * paints a handful of literals past those vars (its `.fcom_button_primary` is a
 * solid #000 / #2B2E33 brand button, its panel is a hard-coded #f4f4f5, near-black
 * #0e121b / #020817 text, #E1E4EA borders, a #f8d7da error notice); §2–§4 catch
 * those.
 *
 * NO theme sync. onboarding.css has NO dark mode of its own (no `html.dark`, no
 * `data-color-mode`, no storage key — those live in the FRONTEND portal bundle,
 * out of scope here), so there is nothing to slave to AdminKit's switch — the
 * `--ak-*` remap alone carries both light and dark on this screen. The admin
 * wizard renders no dark toggle either, so there is none to hide.
 *
 * Loaded only on `toplevel_page_fluent-community`. fluent-community-pro extends the
 * same screen (same menu slug, same `#fcom_onboarding_app`), so this one adapter
 * covers it too.
 *
 * Tier B safety: the wizard bakes its brand literals + Element Plus defaults into
 * compiled CSS, which a new major may reshuffle, so the skin is version-gated on
 * the major and drops to FluentCommunity's native UI past the tested one.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Community extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-community';
	}

	/**
	 * FluentCommunity registers its top-level menu under the `fluent-community`
	 * slug (same as slug()), so the base wordmark resolver finds it without an
	 * override — but the in-app wizard logo is white-labelled in CSS anyway.
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return 'fluent-community';
	}

	/**
	 * FluentCommunity defines `FLUENT_COMMUNITY_PLUGIN_VERSION` in its bootstrap
	 * (fluent-community.php).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' ) ? FLUENT_COMMUNITY_PLUGIN_VERSION : null;
	}

	/**
	 * Verified against FluentCommunity 2.5.x. A new major may rename the wizard's
	 * `.fcom_*` classes or reshuffle the brand literals / Element Plus defaults
	 * this adapter targets, so gate on the major — register_assets() falls back to
	 * native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '2.5.0';
	}

	/**
	 * FluentCommunity registers a top-level menu with slug `fluent-community` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluent-community`.
	 * That screen renders the onboarding/settings wizard (`#fcom_onboarding_app`).
	 * fluent-community-pro extends the SAME screen, so this one id covers both.
	 *
	 * The frontend portal (`/portal`, `/portal/admin`) is NOT a wp-admin screen
	 * and so is never matched here — exactly the intended scope.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-community' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentCommunity's native
		// UI shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-community-admin',
			'src'       => 'inc/integrations/plugins/fluent-community/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentCommunity screen — the
	 * wizard is an Element Plus app that ships its own input/select/checkbox/radio
	 * styling, so our components/*.css would double-border its widgets (a native
	 * border inside the `.el-input__wrapper` that already has one). admin.css
	 * themes the Element Plus surfaces directly via the token remap instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fcom' ) );
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the other Fluent adapters.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fcom( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentCommunity's menu icon for a community (user-group) glyph.
	 *
	 * FluentCommunity registers its top-level menu with a base64 data-URI icon, so
	 * its `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop the inline background-image and
	 * mask a user-group Heroicon (a community is a group of people) into the icon
	 * box — same technique menu_css() uses, so it tracks the menu foreground colour
	 * in light/dark/hover/current. Inlined here so the adapter owns its own mark.
	 * Gated exactly like the other Fluent adapters: only when the icon toggle is on
	 * AND the global should_load pause hasn't disabled AdminKit. The menu shows on
	 * every admin page, so there is no screen gate. (Covers fluent-community-pro
	 * too — same menu, same screen.)
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
		// AdminKit icon set — "globe" (a social/community network; "chat" duplicated
		// the Comments menu icon, so route to a distinct glyph).
		$svg = AdminKit_Icons::svg( 'globe' );
		$box  = '#adminmenu #toplevel_page_fluent-community .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluent-community-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
