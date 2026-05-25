<?php
/**
 * Bricks integration — optional adapter.
 *
 * AdminKit doesn't depend on Bricks. This file is the bridge that
 * makes both systems play nicely when the Bricks theme is active:
 *
 *  - **Token inheritance.** Bricks writes its global design variables
 *    to /uploads/bricks/css/style-manager.min.css whenever the user
 *    saves a token in the builder. We enqueue that file and pin it as
 *    a dep of `adminkit-tokens`, so a green changed to red in the
 *    Bricks builder flows through to every wp-admin button.
 *
 *  - **Builder bypass.** When the user is inside the Bricks Builder
 *    (?bricks=run, builder iframe, etc.) we skip every restyle so the
 *    builder UI stays pristine.
 *
 *  - **Frontend theme sync.** Bricks owns the site's light/dark mode on
 *    the frontend via `data-brx-theme` + `brx_mode`. Left alone, AdminKit's
 *    admin-bar mode runs on its own `data-adminkit-theme` and drifts out of
 *    sync (it falls back to `prefers-color-scheme` while Bricks uses the
 *    project's default mode, so the bar can read inverted). print_theme_bridge()
 *    keeps them in step on the frontend — but AdminKit's own toggle stays
 *    AUTHORITATIVE: its inline handler always flips `data-adminkit-theme` (so the
 *    bar switches with or without Bricks), and this bridge is purely additive. It
 *    adopts Bricks's mode on load, mirrors `data-brx-theme` -> `data-adminkit-theme`
 *    going forward, and pushes AdminKit's own flips back into Bricks
 *    (`data-brx-theme` + `brx_mode`) so the site repaints too. A reentrancy guard
 *    stops the mirrors looping. The bridge no longer intercepts the toggle click —
 *    that interception was the bug (when Bricks didn't repaint, e.g. on the
 *    responsive bar, the bar never flipped). In wp-admin (where Bricks doesn't
 *    theme anything) AdminKit's own toggle is untouched.
 *
 * Removing this folder removes Bricks support entirely; nothing else
 * in the plugin references Bricks.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Bricks extends AdminKit_Integration_Base {

	const TOKENS_HANDLE = 'adminkit-bricks-tokens';
	const TOKENS_REL    = '/bricks/css/style-manager.min.css';

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
	 * Whether the current request is rendering the Bricks Builder UI.
	 *
	 * @return bool
	 */
	public static function is_builder() {
		if ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] ) {
			return true;
		}
		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			return true;
		}
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			return true;
		}
		return false;
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
		// Bricks admin pages (Settings, Templates, …) — full token bridge.
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
	 * Wire the two AdminKit filters that integrate Bricks. The token
	 * piping + builder bypass; CSS overrides come from register_assets().
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
		// Paintbrush (Heroicons solid `paint-brush`): "design/build with Bricks", and
		// distinct from the layout grid used by `editor_mode` below.
		$brush  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path d="M20.6 1.5c-.38 0-.74.11-1.06.32l-5.08 3.39a18.75 18.75 0 0 0-3.47 2.98 10.04 10.04 0 0 1 4.82 4.82 18.75 18.75 0 0 0 2.98-3.47l3.39-5.08A1.9 1.9 0 0 0 20.6 1.5Zm-8.3 14.03a18.76 18.76 0 0 0 1.9-1.21 8.03 8.03 0 0 0-4.52-4.51 18.75 18.75 0 0 0-1.2 1.9l-.28.5a5.26 5.26 0 0 1 3.6 3.6l.5-.28ZM6.75 13.5A3.75 3.75 0 0 0 3 17.25a1.5 1.5 0 0 1-1.6 1.5.75.75 0 0 0-.7 1.12 5.25 5.25 0 0 0 9.8-2.62 3.75 3.75 0 0 0-3.75-3.75Z"/></svg>';
		$layout = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M3 6a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3V6ZM3 15.75a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-2.25Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3v-2.25Z" clip-rule="evenodd"/></svg>';
		$map['wp-admin-bar-edit_with_bricks'] = $brush;
		$map['wp-admin-bar-editor_mode']      = $layout;
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
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M3 6a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3V6ZM3 15.75a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-2.25Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3v-2.25Z" clip-rule="evenodd"/></svg>';
		$uri = 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';
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
		// Respect the global asset gate (adminkit/should_load).
		if ( ! apply_filters( 'adminkit/should_load', true, 'builder' ) ) {
			return;
		}
		$restyle = (bool) AdminKit_Settings::get( 'bricks_builder_enabled' );
		$base    = 'inc/integrations/themes/bricks/css/';

		// Canvas IFRAME first. It renders the real page, so it gets ONLY the canvas
		// sheet (no variable remaps) — and ONLY under the opt-in restyle (it recolours
		// the rendered page). MUST be tested before the main-frame check: the iframe
		// request also satisfies ?bricks=run / bricks_is_builder_main(), so testing
		// "main" first would leak the chrome's :root { --bricks-* } remap into the page.
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			if ( $restyle ) {
				self::enqueue_style_file( 'adminkit-bricks-builder-canvas', $base . 'builder-canvas.css' );
			}
			return;
		}

		// Builder MAIN frame.
		$is_main = ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] )
			|| ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() );
		if ( ! $is_main ) {
			return;
		}

		// 1) Essentials — ALWAYS, independent of the restyle toggle: dark scrollbars +
		//    the brand logo (toolbar favicon + preloader). Carried on a REAL stylesheet
		//    (builder-essentials.css) — a real file reliably prints in the builder,
		//    whereas a src=false inline-only handle may be dropped. The dynamic logo
		//    CSS attaches to it as inline. The WaasKit provider vars these read are
		//    present in the builder via Bricks's style-manager, so it works toggle-off.
		if ( self::enqueue_style_file( 'adminkit-bricks-essentials', $base . 'builder-essentials.css' ) ) {
			$logo_css = self::builder_logo_css();
			if ( '' !== $logo_css ) {
				wp_add_inline_style( 'adminkit-bricks-essentials', $logo_css );
			}
		}

		// 2) Chrome restyle — opt-in (panels, code editor, variable remaps).
		if ( $restyle ) {
			self::enqueue_builder_fallback_tokens();
			self::enqueue_style_file( 'adminkit-bricks-builder', $base . 'builder.css' );
		}
	}

	/**
	 * Load AdminKit's shipped WaasKit baseline as a FALLBACK palette in the builder.
	 *
	 * builder.css maps Bricks's --builder-* variables onto WaasKit provider
	 * variables (--surface, --background, --neutral-l-*, …). Those normally come
	 * from Bricks's saved colours (bricks-style-manager). If the user clears every
	 * colour in Bricks, those variables vanish and the builder would lose its look.
	 *
	 * So we enqueue the baseline (which defines all of them) and make Bricks's
	 * style-manager DEPEND on it — Bricks therefore loads AFTER and wins whenever
	 * colours exist, while the baseline stays as the fallback when they don't.
	 * AdminKit transparently takes over; nothing breaks.
	 *
	 * @return void
	 */
	private static function enqueue_builder_fallback_tokens() {
		$path = ADMINKIT_PATH . AdminKit_Assets::WAASKIT_SRC;
		if ( ! file_exists( $path ) ) {
			return;
		}
		wp_enqueue_style(
			AdminKit_Assets::WAASKIT_HANDLE,
			ADMINKIT_URL . AdminKit_Assets::WAASKIT_SRC,
			array(),
			(string) filemtime( $path )
		);

		// Print Bricks's live colours after the baseline so they override it.
		$styles = wp_styles();
		if ( isset( $styles->registered['bricks-style-manager'] )
			&& ! in_array( AdminKit_Assets::WAASKIT_HANDLE, $styles->registered['bricks-style-manager']->deps, true )
		) {
			$styles->registered['bricks-style-manager']->deps[] = AdminKit_Assets::WAASKIT_HANDLE;
		}
	}

	/**
	 * Enqueue one of the integration's stylesheets by repo-relative path, with a
	 * filemtime cache-bust. Returns false if the file is missing.
	 *
	 * @param string $handle
	 * @param string $rel    Path relative to the plugin root.
	 * @return bool
	 */
	private static function enqueue_style_file( $handle, $rel ) {
		$path = ADMINKIT_PATH . $rel;
		if ( ! file_exists( $path ) ) {
			return false;
		}
		wp_enqueue_style( $handle, ADMINKIT_URL . $rel, array(), (string) filemtime( $path ) );
		return true;
	}

	/**
	 * Build the CSS that brands the builder toolbar + preloader.
	 *
	 * Toolbar: the site FAVICON as a fixed rounded SQUARE (28px, centred, cover) so
	 * the radius reads on the chip; first-letter mark when no Site Icon is set.
	 * Preloader: the configured brand LOGO in a wide ROUNDED box (contain → the whole
	 * logo, undistorted), centred with a gentle pulse on the fixed dark splash;
	 * Bricks's own loader shows when no logo is set.
	 *
	 * @return string Inline CSS.
	 */
	private static function builder_logo_css() {
		$css = '';

		// Toolbar → favicon on the accent chip, rounded. `content:url()` on the real
		// <img> scales reliably; the accent background covers transparent icons.
		$favicon = get_site_icon_url( 192 );
		if ( '' !== $favicon ) {
			$url  = self::css_url( $favicon );
			// Favicon as a FIXED 34px rounded SQUARE, CENTRED in the wide-short slot so
			// the radius reads on the chip itself (contain floated it small, leaving the
			// radius in empty space). `cover` fills the 34px box — the site icon is
			// square so nothing is cropped. No chip colour — Bricks's yellow is
			// overridden to transparent so nothing shows behind/around it.
			$css .= '#bricks-toolbar .logo{background-color:transparent!important;display:flex;align-items:center;justify-content:center}';
			$css .= '#bricks-toolbar .logo a{display:flex;align-items:center;justify-content:center;width:100%;height:100%}';
			$css .= '#bricks-toolbar .logo img{content:' . $url . ';display:block;width:34px;height:34px;'
				. 'box-sizing:border-box;padding:0;object-fit:cover;border-radius:6px}';
		} else {
			$css .= self::builder_toolbar_letter_css();
		}

		// Preloader → replace Bricks's animated logo with the configured brand logo.
		$logo = AdminKit_Settings::brand_logo( 'dark' );
		if ( '' === $logo ) {
			$logo = AdminKit_Settings::brand_logo( 'light' );
		}
		if ( '' !== $logo ) {
			// THE builder preloader renders the brand mark on `.bricks-logo-animated`
			// (the white "bricks" box in the splash) — NOT on `.bricks-loading-inner::before`
			// (that's the frontend theme's structure, absent in the builder). So we restyle
			// THAT element: override its box, paint the configured brand logo as its
			// background, and hide its inner cubes/wordmark (> *) + any direct text
			// (font-size/text-indent) + the .title/.sub-title. Centre it via the inner
			// wrapper's grid (beats Bricks on specificity). A softly-rounded chip (faint
			// fixed bg + padding) makes the border-radius read for a transparent/contain
			// logo, never cropped. Colours are fixed — the splash paints before tokens
			// load (same reason #bricks-preloader is #171717). No !important.
			$css .= '#bricks-preloader .title,#bricks-preloader .sub-title{display:none}';
			$css .= '#bricks-preloader .bricks-loading-inner{display:grid;place-items:center}';
			$css .= '#bricks-preloader .bricks-logo-animated{'
				. 'box-sizing:border-box;width:16rem;height:6rem;max-width:70vw;margin:0;padding:1rem;'
				. 'background-color:rgba(255,255,255,0.10);'
				. 'background-image:' . self::css_url( $logo ) . ';'
				. 'background-position:center;background-repeat:no-repeat;background-size:contain;background-origin:content-box;'
				. 'border-radius:16px;font-size:0;text-indent:-9999px;overflow:hidden;'
				. 'animation:ak-bricks-preload 1.4s ease-in-out infinite}';
			$css .= '#bricks-preloader .bricks-logo-animated > *{display:none}';
			$css .= '@keyframes ak-bricks-preload{50%{transform:scale(1.05)}}';
		}
		return $css;
	}

	/**
	 * The toolbar logo as a first-letter text mark on the accent chip. '' when the
	 * site title has no usable letter.
	 *
	 * @return string
	 */
	private static function builder_toolbar_letter_css() {
		$name   = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$letter = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		$letter = preg_replace( '/[^\p{L}\p{N}]/u', '', (string) $letter ); // CSS-safe: letters/digits only
		$letter = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letter ) : strtoupper( $letter );
		if ( '' === $letter ) {
			return '';
		}
		return '#bricks-toolbar li.logo{background-color:var(--accent,#ffd64f);'
			. 'display:flex;align-items:center;justify-content:center;min-width:34px}'
			. '#bricks-toolbar li.logo img{display:none}'
			. '#bricks-toolbar li.logo::after{content:"' . $letter . '";'
			. 'color:var(--accent-on,#18181b);font-weight:700;font-size:15px;line-height:1}';
	}

	/**
	 * Wrap a URL for safe use inside a CSS url() (esc_url_raw keeps query-string
	 * ampersands intact, unlike esc_url which entity-encodes them and breaks CSS).
	 *
	 * @param string $url
	 * @return string url("…") or ''.
	 */
	private static function css_url( $url ) {
		$url = esc_url_raw( (string) $url );
		return ( '' === $url ) ? '' : 'url("' . $url . '")';
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
