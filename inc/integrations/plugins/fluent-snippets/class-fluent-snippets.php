<?php
/**
 * FluentSnippets integration — Tier-A-leaning adapter (Vue 3 SPA on Element Plus).
 *
 * FluentSnippets (plugin folder `easy-code-manager`) renders its admin as a Vue 3
 * SPA — a code-snippets manager whose UI is dominated by a CodeMirror 6 editor.
 * Like FluentCommunity's onboarding wizard, it leans on TWO surfaces:
 *
 *   1. Element Plus --el-* tokens, shipped STOCK. The bundle declares the EP
 *      `:root` layer at its compiled defaults (--el-color-primary:#409eff blue,
 *      #fff surfaces, #dcdfe6 / #ebeef5 borders, #909399 info-grey, …) and the
 *      raw -rgb channels (--el-color-primary-rgb:64,158,255). It declares NO
 *      --fsnip-* / --fls-* custom-property layer of its own. So css §1 is the
 *      canonical Element-Plus remap (same block as the FluentCommunity /
 *      FluentAffiliate adapters): point the --el-* tokens at AdminKit --ak-* and
 *      every EP widget the app uses — el-table, el-button, el-input, el-checkbox,
 *      el-switch, el-dialog — follows, dark mode included.
 *
 *   2. its own .fsnip_* / .fls_* classes, which paint a handful of literals PAST
 *      the EP vars: a #fff navbar + dropdown, a #9d95d8 lavender brand underline
 *      on the active nav link, near-black #000 / #909399 / #697386 nav text, a
 *      #f7fafc / #e3e8ee secondary-menu strip, neutral #606266 icon buttons, and
 *      a cluster of hard yellow status callouts (#fff06f / #ffff8e / #fff6a2 /
 *      #ff0). css §3–§5 catch those.
 *
 * The brand. FluentSnippets leaves Element Plus at its STOCK blue
 * (--el-color-primary:#409eff) and writes its real accent — a lavender #9d95d8 —
 * only into the active-nav underline. AdminKit has one brand, so §1 routes
 * --el-color-primary to --ak-primary and §3 routes the lavender underline there
 * too; filled surfaces pin white via --ak-on-accent.
 *
 * The code editor. The product is a CODE editor, so a CodeMirror 6 instance
 * dominates the screen. Its syntax-highlight theme is JS-driven (EditorView.theme
 * objects + the dynamically-injected `ͼ…` classes + light/dark gutter themes), so
 * — like Chart.js / ApexCharts colours elsewhere — the token colours are OUT of
 * pure-CSS reach. css §4 themes only the editor CONTAINER/chrome (the .fsnip_code*
 * wrapper + the .cm-editor frame) to AdminKit surfaces and leaves the syntax
 * tokens to CodeMirror's own theme.
 *
 * NO theme sync. FluentSnippets ships NO dark mode of its own (no html.dark /
 * data-theme in the bundle), so the --ak-* flip is what gives this screen one and
 * there is no host dark class to fight — hence no theme-sync JS in the PHP. Almost
 * every EP widget reads its colour through an --el-* var, so the §1 remap carries
 * the bulk and the literal sweep (§3–§5) is small and !important-light.
 *
 * Loaded only on toplevel_page_fluent-snippets (the SPA owns every sub-route via
 * hash routing under that one WP page).
 *
 * Tier B safety: FluentSnippets bakes stock Element Plus defaults + its own brand
 * literals into compiled CSS, which a new major may reshuffle, so the skin is
 * version-gated on the major and drops to FluentSnippets' native UI past the
 * tested one (10.x themed, 11.x falls back).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Snippets extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-snippets';
	}

	/**
	 * FluentSnippets registers its top-level menu under the slug `fluent-snippets`
	 * (== slug()), so the base wordmark resolver needs no override.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENT_SNIPPETS_PLUGIN_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENT_SNIPPETS_PLUGIN_VERSION' ) ? FLUENT_SNIPPETS_PLUGIN_VERSION : null;
	}

	/**
	 * Verified against FluentSnippets 10.x. A new major may rename the .fsnip_* /
	 * .fls_* classes or reshuffle the stock Element Plus defaults / brand literals
	 * this adapter targets, so gate on the major — register_assets() falls back to
	 * native UI past it.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '11.0';
	}

	/**
	 * FluentSnippets registers a top-level menu with slug `fluent-snippets` via
	 * `add_menu_page()`, giving the screen id `toplevel_page_fluent-snippets`.
	 * Every sub-page is a hash route under that same screen, so one id covers the
	 * entire SPA.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-snippets' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// Tier B: past the tested major, drop the skin so FluentSnippets' native
		// UI shows instead of a half-broken one (see host_within_tested_range).
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-snippets-admin',
			'src'       => 'inc/integrations/plugins/fluent-snippets/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FluentSnippets screen —
	 * Element Plus ships its own input/select/textarea styling, so our
	 * components/*.css would double-border its widgets (a native border inside the
	 * `.el-input__wrapper` that already has one). admin.css themes the Element Plus
	 * surfaces directly via the token remap instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fsnip' ) );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fsnip( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}
}
