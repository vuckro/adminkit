<?php
/**
 * Bricks integration — optional adapter.
 *
 * AdminKit doesn't depend on Bricks. This file is the bridge that makes both
 * systems play nicely when the Bricks theme is active:
 *
 *  - **Token inheritance.** Bricks writes its global design variables to
 *    /uploads/bricks/css/style-manager.min.css whenever the user saves a token
 *    in the builder. We enqueue that file and pin it as a dep of
 *    `adminkit-tokens`, so a green changed to red in Bricks flows through to
 *    every wp-admin button. See `provide_tokens()`.
 *
 *  - **Builder chrome restyle (opt-in).** When the "Bricks builder" feature is
 *    on (Settings → Features) the builder's panels / toolbar / structure tree
 *    get AdminKit-themed chrome via `builder.css`. The general AdminKit admin
 *    restyle is suppressed in the builder context so the two don't fight.
 *    See `enqueue_builder()` + `bypass_builder()`.
 *
 *  - **Frontend admin-bar theme sync.** On the front end the admin bar follows
 *    Bricks's site mode (`data-brx-theme`) and pushes AdminKit's own toggle
 *    flips back into Bricks. See `print_theme_bridge()` for the full rationale.
 *
 * Removing this folder removes Bricks support entirely; nothing else in the
 * plugin references Bricks.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Bricks extends AdminKit_Integration_Base {

	const TOKENS_HANDLE = 'adminkit-bricks-tokens';
	const TOKENS_REL    = '/bricks/css/style-manager.min.css';

	/**
	 * Heroicons solid `squares-2x2`. Shared between the toolbar `editor_mode`
	 * node, the admin-menu icon replacement, and any future Bricks UI surface
	 * we want to align with the AdminKit icon set. The fill colour is
	 * irrelevant — both consumers use the SVG as a CSS mask.
	 */
	const LAYOUT_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M3 6a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3V6ZM3 15.75a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-2.25Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3v-2.25Z" clip-rule="evenodd"/></svg>';

	/**
	 * @return string
	 */
	public static function slug() {
		return 'bricks';
	}

	/**
	 * Whether the Bricks theme is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'BRICKS_VERSION' );
	}

	/**
	 * The Bricks builder is two documents — the chrome (main frame) and the
	 * canvas (iframe). Most callers want "either", a few need to distinguish
	 * (e.g. enqueue_builder loads different sheets per frame).
	 *
	 * @return bool
	 */
	public static function is_builder() {
		return self::is_builder_main() || self::is_builder_iframe();
	}

	/**
	 * @return bool
	 */
	public static function is_builder_main() {
		if ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] ) {
			return true;
		}
		return function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main();
	}

	/**
	 * @return bool
	 */
	public static function is_builder_iframe() {
		return function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe();
	}

	/**
	 * Match Bricks's own admin pages (Getting Started, Settings,
	 * Elements, Sidebars, System Information, License, Form Submissions)
	 * plus the Templates list (`edit-bricks_template`) — the latter only so
	 * its hidden import-form wrapper gets the token bridge. The list table
	 * itself keeps standard WP styling: the adapter's table rules are scoped
	 * to `.wp-list-table.elements`, which the Templates list doesn't use. The
	 * single template edit (`bricks_template`) stays excluded.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		return 'toplevel_page_bricks' === $screen->id
			|| 0 === strpos( $screen->id, 'bricks_page_bricks-' )
			|| 'edit-bricks_template' === $screen->id
			// The Custom Fonts edit screen (bricks_fonts CPT) — for its variants metabox.
			|| 'bricks_fonts' === $screen->id;
	}

	/**
	 * Register the admin token-bridge stylesheet — loaded only on
	 * Bricks's own admin pages (see `owns_screen()`).
	 *
	 * @return void
	 */
	public static function register_assets() {
		// Bricks admin pages (Settings, Templates, …) — full token bridge that
		// re-points Bricks's hardcoded surfaces, borders, radius and table
		// chrome at our --ak-* tokens, so the pages stay coherent with the
		// AdminKit theme and flip with dark mode. Without this the pages keep
		// Bricks's stock #fff surfaces / square cells / hardcoded borders even
		// when the rest of wp-admin is themed — visibly broken next to every
		// other admin page.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-bricks-admin',
			'src'       => 'inc/integrations/themes/bricks/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );

		// Frontend dark-mode token bridge — re-points the admin bar's dark
		// tokens at Bricks's live semantics so it follows the site's mode
		// instead of the wp-admin inverse ramp (which double-inverts here).
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-bricks-frontend-mode',
			'src'     => 'inc/integrations/themes/bricks/css/frontend-mode.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'frontend',
		) );

		// "Edit with Bricks" CTAs render on post-edit screens (classic
		// editor wrapper, block editor toolbar, in-canvas notice).
		// Scoped to $screen->base === 'post' which covers post.php +
		// post-new.php for every post type (posts, pages, CPTs, Bricks
		// templates).
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-bricks-edit-button',
			'src'       => 'inc/integrations/themes/bricks/css/edit-button.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => static function ( $screen ) {
				return $screen && 'post' === $screen->base;
			},
		) );

		// Bricks injects its feedback form into the WP themes page footer
		// (admin_footer-themes.php) — outside its own admin pages, so it gets its
		// own tiny load there.
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-bricks-feedback',
			'src'       => 'inc/integrations/themes/bricks/css/feedback.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => static function ( $screen ) {
				return $screen && 'themes' === $screen->id;
			},
		) );
	}

	/**
	 * Wire the AdminKit filters + actions that integrate Bricks. Static
	 * stylesheet registrations live in register_assets().
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/should_load', array( __CLASS__, 'bypass_builder' ), 10, 2 );
		add_filter( 'adminkit/extra_tokens_handle', array( __CLASS__, 'provide_tokens' ), 10, 2 );

		// Priority 2: register the observer before Bricks's own inline mode
		// script (printed with the head scripts, ~priority 9) sets the attribute,
		// so the first flip is caught and the bar paints in sync.
		add_action( 'wp_head', array( __CLASS__, 'print_theme_bridge' ), 2 );

		// Opt-in builder restyle. Separate from bypass_builder() above (which keeps
		// the builder pristine for the rest of AdminKit): this one stylesheet is the
		// "Bricks builder" feature, gated inside enqueue_builder(). Priority 9999 so
		// it lands after Bricks's own builder CSS.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_builder' ), 9999 );

		// When the AdminKit "icons" feature is on, give Bricks's own menu item a "B"
		// mark so it matches the set (priority 22 = just after AdminKit_Core_Menu_Icons).
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 22 );

		// Bricks's front-end admin-bar nodes ("Edit with Bricks", "Rendered with
		// Bricks"). Register cohesive AdminKit glyphs for them and flag them as
		// text-only nodes so the icon CSS paints on the link's own ::before. The
		// CSS only prints when the icons feature is on, so no extra gating needed.
		add_filter( 'adminkit/toolbar_icons', array( __CLASS__, 'toolbar_icons' ) );
		add_filter( 'adminkit/toolbar_icon_ab_item_nodes', array( __CLASS__, 'toolbar_ab_item_nodes' ) );

		// Force Bricks's own "Builder mode" setting to `custom` whenever the
		// AdminKit Bricks-builder integration is on — that's the prerequisite for
		// builder.css's [data-builder-mode="custom"] block to apply. Without it,
		// Bricks ignores every --builder-* remap we write. Idempotent: only writes
		// the option when the value actually needs changing.
		add_action( 'admin_init', array( __CLASS__, 'sync_bricks_builder_mode' ) );
	}

	/**
	 * Ensure Bricks's `builderMode` global setting is `custom` so the builder
	 * actually consumes the --builder-* variables our CSS sets. Bricks supports
	 * three modes (Light / Dark / Custom) — the `[data-builder-mode="custom"]`
	 * selector that gates our mapping block only matches in the third one.
	 *
	 * Runs on `admin_init`, only when AdminKit's Bricks-builder toggle is on AND
	 * Bricks is the active theme. The option is read every admin page-load
	 * (cheap, autoloaded by WordPress) but `update_option` only fires when the
	 * stored value isn't already `custom` — so on steady state this is a no-op.
	 *
	 * Trade-off recorded: when AdminKit's Bricks-builder toggle is on, the user
	 * can no longer keep Bricks in "Dark" or "Light" builder mode (we'd flip it
	 * back next admin page-load). That's deliberate — the toggle's whole purpose
	 * is the Custom-mode restyle. Toggle off if you want Bricks's own modes back.
	 *
	 * @return void
	 */
	public static function sync_bricks_builder_mode() {
		if ( ! self::is_active() ) {
			return;
		}
		if ( ! AdminKit_Settings::get( 'bricks_builder_enabled' ) ) {
			return;
		}
		$bricks = get_option( 'bricks_global_settings', array() );
		if ( ! is_array( $bricks ) ) {
			$bricks = array();
		}
		if ( ( $bricks['builderMode'] ?? '' ) === 'custom' ) {
			return; // already correct — no write
		}
		$bricks['builderMode'] = 'custom';
		update_option( 'bricks_global_settings', $bricks );
	}

	/**
	 * Bricks admin-bar node ids that need an AdminKit icon, with raw SVG markup
	 * (same shape AdminKit_Core_Menu_Icons::svg() produces — the filter value is
	 * raw SVG used as a CSS mask, so the fill colour is irrelevant).
	 *
	 * Node ids come from Bricks's setup.php Admin_Bar registration:
	 *   - `edit_with_bricks` ("Edit with Bricks")        -> paintbrush (design/build)
	 *   - `editor_mode`      ("Rendered with Bricks/WP") -> layout grid
	 *
	 * @param array<string,string> $map
	 * @return array<string,string>
	 */
	public static function toolbar_icons( $map ) {
		// Paintbrush (Heroicons solid `paint-brush`): "design/build with Bricks",
		// distinct from the layout grid used by `editor_mode` below.
		$brush = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path d="M20.6 1.5c-.38 0-.74.11-1.06.32l-5.08 3.39a18.75 18.75 0 0 0-3.47 2.98 10.04 10.04 0 0 1 4.82 4.82 18.75 18.75 0 0 0 2.98-3.47l3.39-5.08A1.9 1.9 0 0 0 20.6 1.5Zm-8.3 14.03a18.76 18.76 0 0 0 1.9-1.21 8.03 8.03 0 0 0-4.52-4.51 18.75 18.75 0 0 0-1.2 1.9l-.28.5a5.26 5.26 0 0 1 3.6 3.6l.5-.28ZM6.75 13.5A3.75 3.75 0 0 0 3 17.25a1.5 1.5 0 0 1-1.6 1.5.75.75 0 0 0-.7 1.12 5.25 5.25 0 0 0 9.8-2.62 3.75 3.75 0 0 0-3.75-3.75Z"/></svg>';
		$map['wp-admin-bar-edit_with_bricks'] = $brush;
		$map['wp-admin-bar-editor_mode']      = self::LAYOUT_SVG;
		return $map;
	}

	/**
	 * Flag the Bricks toolbar nodes as text-only (no `.ab-icon` child) so the icon
	 * CSS paints on their link's own `> .ab-item::before`.
	 *
	 * @param array<string,bool> $nodes
	 * @return array<string,bool>
	 */
	public static function toolbar_ab_item_nodes( $nodes ) {
		$nodes['wp-admin-bar-edit_with_bricks'] = true;
		$nodes['wp-admin-bar-editor_mode']      = true;
		return $nodes;
	}

	/**
	 * Replace Bricks's admin-menu icon with a clean layout/grid glyph (masked, like
	 * the AdminKit icon set) when the icons feature is on — so it stops standing out
	 * as a base64 SVG. Bricks sets that icon as an INLINE background, so `!important`
	 * is the only way to clear it: a deliberate, scoped exception (like builder.css).
	 *
	 * @return void
	 */
	public static function print_menu_icon() {
		if ( ! AdminKit_Settings::get( 'replace_icons_enabled' ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		$uri = 'url("data:image/svg+xml,' . rawurlencode( self::LAYOUT_SVG ) . '")';
		echo '<style id="adminkit-bricks-menu-icon">'
			. '#adminmenu #toplevel_page_bricks .wp-menu-image{background-image:none!important;'
			. 'box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}'
			. '#adminmenu #toplevel_page_bricks .wp-menu-image::before{content:"";display:inline-block;'
			. 'width:20px;height:20px;vertical-align:middle;background-color:currentColor;'
			. '-webkit-mask:' . $uri . ' center/20px 20px no-repeat;mask:' . $uri . ' center/20px 20px no-repeat}'
			. "</style>\n"; // SVG is URL-encoded.
	}

	/**
	 * Print the frontend theme bridge (see the class header for the rationale).
	 *
	 * AdminKit's own toggle is AUTHORITATIVE: its inline handler (class-theme-toggle)
	 * always flips `data-adminkit-theme` + persists, so the admin bar (and the
	 * --ak-* layer) switch with or without Bricks. This bridge is purely additive —
	 * it keeps the two systems in sync, never blocks AdminKit's flip:
	 *
	 *   - on load, adopt Bricks's current mode (`data-brx-theme`) into AdminKit ONCE,
	 *     so the bar starts matching the site's mode rather than its own fallback;
	 *   - keep mirroring `data-brx-theme` -> `data-adminkit-theme` (one-way), so if
	 *     Bricks flips by other means the bar follows;
	 *   - when AdminKit's own attribute flips (the user clicked the bar toggle), push
	 *     that mode INTO Bricks (`data-brx-theme` + `brx_mode`) so Bricks repaints too.
	 *
	 * A reentrancy guard stops the two mirrors from ping-ponging. There is no click
	 * interception any more (the previous capture-phase handler that preempted
	 * AdminKit's flip was the bug: when Bricks didn't repaint — notably on the
	 * responsive bar — the bar never switched). Skipped in wp-admin and the builder.
	 *
	 * @return void
	 */
	public static function print_theme_bridge() {
		if ( is_admin() || self::is_builder() ) {
			return;
		}
		$attr = AdminKit_Theme_Toggle::attribute();
		$key  = AdminKit_Theme_Toggle::storage_key();
		?>
<script id="adminkit-bricks-theme-bridge">
(function(){
	var d = document.documentElement;
	var ATTR = <?php echo wp_json_encode( $attr ); ?>;
	var KEY = <?php echo wp_json_encode( $key ); ?>;
	var lock = false; // reentrancy guard: don't let the two mirrors feed each other.
	function ak(){ return d.getAttribute(ATTR); }
	function brx(){ return d.getAttribute('data-brx-theme'); }
	// AdminKit's own stored choice — shared front <-> back via localStorage. When
	// set, it is the single source of truth; Bricks must never override it.
	function stored(){ try { var m = localStorage.getItem(KEY); return (m === 'dark' || m === 'light') ? m : ''; } catch (e) { return ''; } }
	// AdminKit -> Bricks: push AdminKit's mode into Bricks so the SITE repaints to
	// match the mode chosen anywhere (front OR back). AdminKit stays authoritative.
	function push(){
		if (lock) return;
		var m = ak();
		if ((m === 'dark' || m === 'light') && m !== brx()) {
			lock = true;
			d.setAttribute('data-brx-theme', m);
			try { localStorage.setItem('brx_mode', m); } catch (err) {}
			lock = false;
		}
	}
	// Bricks -> AdminKit: ONLY on a first visit (no stored AdminKit choice). Adopt
	// Bricks's mode so the bar matches the site, and PERSIST it so it then stays in
	// sync across front + back. Once the user has chosen, this never fires again.
	function pull(){
		if (lock || stored()) return;
		var m = brx();
		if ((m === 'dark' || m === 'light') && m !== ak()) {
			lock = true;
			d.setAttribute(ATTR, m);
			try { localStorage.setItem(KEY, m); } catch (err) {}
			lock = false;
		}
	}
	// On load: a stored AdminKit choice wins — push it into Bricks so the front end
	// matches what was chosen in the back end (and vice versa). With no stored
	// choice, adopt Bricks once so the bar matches the site on first visit.
	if (stored()) { push(); } else { pull(); }
	new MutationObserver(push).observe(d, { attributes:true, attributeFilter:[ATTR] });
	new MutationObserver(pull).observe(d, { attributes:true, attributeFilter:['data-brx-theme'] });
})();
</script>
		<?php
	}

	/**
	 * Restyle the Bricks builder — the "Bricks builder" feature.
	 *
	 * Dedicated enqueue: the builder has no admin bar, so AdminKit's normal
	 * frontend dispatch (and its --ak-* layer) skips it. The builder is a
	 * Bricks/WaasKit surface, so the sheets map onto the live WaasKit provider
	 * variables instead (see each file's header).
	 *
	 * The builder is TWO documents, and they get DIFFERENT sheets:
	 *   - MAIN frame  → builder.css        — chrome (toolbar, panels, structure…),
	 *                                         including the --bricks-* and
	 *                                         --builder-* variable remaps.
	 *   - canvas IFRAME → builder-canvas.css — only canvas-surface tweaks, NO
	 *                                          variable remaps (those would alter
	 *                                          the rendered page itself).
	 *
	 * The CHROME restyle (panels, scrollbars, code editor, variable remaps) is opt-in
	 * via Settings → Bricks builder. The LOGO branding (toolbar favicon + preloader
	 * brand logo) always loads in the builder when a brand logo is set — it's a safe,
	 * brand-positive touch, so it doesn't require the toggle.
	 *
	 * @return void
	 */
	public static function enqueue_builder() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'builder' ) ) {
			return;
		}
		if ( ! AdminKit_Settings::get( 'bricks_builder_enabled' ) ) {
			return;
		}

		$base = 'inc/integrations/themes/bricks/css/';

		// Canvas iframe — only the canvas tweaks (no variable remaps). MUST be
		// tested first: the iframe also satisfies ?bricks=run / bricks_is_builder_main(),
		// so checking the main frame first would leak the chrome's :root remap
		// into the rendered page.
		if ( self::is_builder_iframe() ) {
			self::enqueue_style_file( 'adminkit-bricks-builder-canvas', $base . 'builder-canvas.css' );
			return;
		}

		// Main builder frame — load the single builder restyle sheet + inject the
		// resolved logo URL as a CSS variable so the toolbar and preloader pick it up.
		if ( ! self::is_builder_main() ) {
			return;
		}

		// ── Token stack ──────────────────────────────────────────────────────
		// AdminKit's normal frontend dispatch (where the token stack — WaasKit
		// baseline → Bricks's style-manager.min.css → --ak-* layer — is
		// enqueued) is gated on is_admin_bar_showing(), which is FALSE inside
		// the Bricks builder (Bricks suppresses the bar). So without an
		// explicit enqueue here, builder.css would land with NO token layer
		// underneath it: every var(--background), var(--surface), etc. would
		// be undefined and the panels paint transparent — showing through to
		// Bricks's own dark backdrop, so the entire builder UI turns black.
		// Enqueue the stack ourselves, in cascade order, so the restyle paints
		// with AdminKit defaults whether or not the user has imported a
		// Bricks Style Manager palette.
		self::enqueue_token_stack();

		$deps = wp_style_is( AdminKit_Assets::TOKENS_HANDLE, 'registered' )
			? array( AdminKit_Assets::TOKENS_HANDLE )
			: array();

		if ( ! self::enqueue_style_file( 'adminkit-bricks-builder', $base . 'builder.css', $deps ) ) {
			return;
		}

		// Two independent surfaces, each with one asset and one fallback:
		//
		//   • PRELOADER → brand logo if set, otherwise Bricks's native
		//     preloader paints untouched (animated logo + title visible).
		//
		//   • TOOLBAR LOGO → favicon if set, otherwise Bricks's native
		//     toolbar img sits in the accent frame (CSS-static rule).
		//
		// Favicon detection: get_site_icon_url() only returns a URL when the
		// user has formally set a Site Icon in Settings → General. Lots of
		// sites have a /favicon.ico at the root without ever doing that, so
		// fall back to it when present — same icon either way.
		$brand_logo = AdminKit_Settings::brand_logo( 'dark' );
		$favicon    = get_site_icon_url( 192 );
		if ( '' === $favicon && file_exists( ABSPATH . 'favicon.ico' ) ) {
			$favicon = home_url( '/favicon.ico' );
		}
		$css = '';

		if ( '' !== $brand_logo ) {
			$url  = 'url("' . esc_url_raw( $brand_logo ) . '")';
			$css .= '#bricks-preloader{background:#171717}'
				. '#bricks-preloader .bricks-logo-animated,'
				. '#bricks-preloader .title,'
				. '#bricks-preloader .sub-title{display:none}'
				. '#bricks-preloader .bricks-loading-inner{display:grid;place-items:center}'
				. '#bricks-preloader .bricks-loading-inner::before{'
				. 'content:"";width:15rem;aspect-ratio:1;'
				. 'background:' . $url . ' center/contain no-repeat;'
				. 'animation:adminkit-bricks-preloader-pulse 1.4s ease-in-out infinite}'
				. '@keyframes adminkit-bricks-preloader-pulse{50%{transform:scale(1.1)}}';
		}

		if ( '' !== $favicon ) {
			$url  = 'url("' . esc_url_raw( $favicon ) . '")';
			$css .= '#bricks-toolbar .logo{background-color:transparent;padding:0}'
				. '#bricks-toolbar .logo img{content:' . $url . ';height:22px;width:22px;border-radius:4px}';
		}

		if ( '' !== $css ) {
			wp_add_inline_style( 'adminkit-bricks-builder', $css );
		}
	}

	/**
	 * Enqueue AdminKit's full token cascade for the builder context. The
	 * normal frontend dispatch skips it (admin bar isn't showing in the
	 * builder, so dispatch_frontend bails) — so the builder restyle would
	 * otherwise reference undefined CSS vars. This recreates the same
	 * three-layer load order class-assets.php uses elsewhere:
	 *
	 *   1. WaasKit baseline   — 309 tokens (--background, --surface, ramps),
	 *                           the safety net when the user hasn't imported
	 *                           anything into Bricks's Style Manager.
	 *   2. Bricks's user palette (style-manager.min.css, via provide_tokens)
	 *                         — only loaded if the file exists on disk;
	 *                           depends on the baseline so it cascades on top.
	 *   3. adminkit-tokens.css (the --ak-* layer) — depends on whichever of
	 *                           the two above are registered.
	 *
	 * wp_enqueue_style is idempotent on handle, so if another code path has
	 * already enqueued any of these (unlikely in the builder), the call here
	 * is a no-op.
	 *
	 * @return void
	 */
	private static function enqueue_token_stack() {
		// 1. WaasKit baseline.
		$wk_path = ADMINKIT_PATH . AdminKit_Assets::WAASKIT_SRC;
		if ( file_exists( $wk_path ) ) {
			wp_enqueue_style(
				AdminKit_Assets::WAASKIT_HANDLE,
				ADMINKIT_URL . AdminKit_Assets::WAASKIT_SRC,
				array(),
				(string) filemtime( $wk_path )
			);
		}

		// 2. Bricks Style Manager palette (if present). provide_tokens()
		//    handles the file-exists check + dep-on-baseline wiring.
		self::provide_tokens( null, 'builder' );

		// 3. adminkit-tokens (--ak-* layer). Depends on whichever of the
		//    two above are registered so the cascade resolves.
		$tk_path = ADMINKIT_PATH . AdminKit_Assets::TOKENS_SRC;
		$deps    = array_filter(
			array( AdminKit_Assets::WAASKIT_HANDLE, self::TOKENS_HANDLE ),
			static function ( $h ) { return wp_style_is( $h, 'registered' ); }
		);
		wp_enqueue_style(
			AdminKit_Assets::TOKENS_HANDLE,
			ADMINKIT_URL . AdminKit_Assets::TOKENS_SRC,
			$deps,
			file_exists( $tk_path ) ? (string) filemtime( $tk_path ) : ADMINKIT_VERSION
		);
	}

	/**
	 * Enqueue one of the integration's stylesheets by repo-relative path, with a
	 * filemtime cache-bust. Returns false if the file is missing.
	 *
	 * @param string   $handle
	 * @param string   $rel    Path relative to the plugin root.
	 * @param string[] $deps   Optional handles to depend on (must already be registered).
	 * @return bool
	 */
	private static function enqueue_style_file( $handle, $rel, array $deps = array() ) {
		$path = ADMINKIT_PATH . $rel;
		if ( ! file_exists( $path ) ) {
			return false;
		}
		wp_enqueue_style( $handle, ADMINKIT_URL . $rel, $deps, (string) filemtime( $path ) );
		return true;
	}

	/**
	 * Bail out of every admin context that's rendering the Bricks
	 * builder. Login / frontend / non-builder admin pages are untouched.
	 *
	 * @param bool   $should_load
	 * @param string $context
	 * @return bool
	 */
	public static function bypass_builder( $should_load, $context ) {
		if ( 'admin' === $context && self::is_builder() ) {
			return false;
		}
		return $should_load;
	}

	/**
	 * Enqueue Bricks' generated tokens stylesheet and return its handle
	 * so AdminKit pins it as a dep of its own tokens.
	 *
	 * @param string|null $handle
	 * @param string      $context
	 * @return string|null
	 */
	public static function provide_tokens( $handle, $context ) {
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . self::TOKENS_REL;
		if ( ! file_exists( $path ) ) {
			return $handle;
		}
		// Depend on AdminKit's shipped WaasKit baseline so Bricks' live tokens load
		// AFTER it and win the cascade — a Bricks site that customises the palette
		// still overrides the bundled defaults. BUT only when that baseline is
		// actually present: it's intentionally skipped on the frontend (and can be
		// disabled via adminkit/enqueue_baseline). WP 6.9.1+ DROPS any stylesheet —
		// and everything that transitively depends on it (adminkit-tokens → the
		// whole admin bar) — if it lists an unregistered dependency, so guard it.
		$deps = wp_style_is( AdminKit_Assets::WAASKIT_HANDLE, 'registered' )
			? array( AdminKit_Assets::WAASKIT_HANDLE )
			: array();
		wp_enqueue_style(
			self::TOKENS_HANDLE,
			$upload['baseurl'] . self::TOKENS_REL,
			$deps,
			(string) filemtime( $path )
		);
		return self::TOKENS_HANDLE;
	}
}
