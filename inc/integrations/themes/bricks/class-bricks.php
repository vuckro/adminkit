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
 *    fixes this on the frontend only: a **one-way, read-only** mirror copies
 *    `data-brx-theme` onto `data-adminkit-theme`, and the bar's sun/moon button
 *    drives Bricks (writes `data-brx-theme` + `brx_mode`) so there is a single
 *    source of truth. It never writes back into AdminKit's own state, so the
 *    feedback loop a two-way bridge once caused can't recur. In wp-admin (where
 *    Bricks doesn't theme anything) AdminKit's own toggle is untouched.
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
			|| 'edit-bricks_template' === $screen->id;
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
	}

	/**
	 * Print the frontend theme bridge (see the class header for the rationale).
	 *
	 * One-way, read-only: `data-brx-theme` -> `data-adminkit-theme`. The admin
	 * bar's sun/moon button is rerouted (capture-phase, so it preempts AdminKit's
	 * own handler) to flip Bricks's mode instead of AdminKit's, keeping Bricks the
	 * single source of truth. Skipped in wp-admin and inside the builder.
	 *
	 * @return void
	 */
	public static function print_theme_bridge() {
		if ( is_admin() || self::is_builder() ) {
			return;
		}
		$attr = AdminKit_Theme_Toggle::attribute();
		$node = AdminKit_Theme_Toggle::NODE_ID;
		?>
<script id="adminkit-bricks-theme-bridge">
(function(){
	var d = document.documentElement;
	var ATTR = <?php echo wp_json_encode( $attr ); ?>;
	var SEL = '#wp-admin-bar-<?php echo esc_js( $node ); ?> a';
	function brx(){ return d.getAttribute('data-brx-theme'); }
	function sync(){ var m = brx(); if (m === 'dark' || m === 'light') d.setAttribute(ATTR, m); }
	sync();
	new MutationObserver(sync).observe(d, { attributes:true, attributeFilter:['data-brx-theme'] });
	document.addEventListener('click', function(e){
		var a = e.target.closest && e.target.closest(SEL);
		if (!a) return;
		e.preventDefault();
		e.stopImmediatePropagation();
		var n = brx() === 'dark' ? 'light' : 'dark';
		d.setAttribute('data-brx-theme', n);
		try { localStorage.setItem('brx_mode', n); } catch (err) {}
	}, true);
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
	 * Off by default — opt in via Settings → Features → Bricks builder.
	 *
	 * @return void
	 */
	public static function enqueue_builder() {
		if ( ! AdminKit_Settings::get( 'bricks_builder_enabled' ) ) {
			return;
		}
		// Respect the global "WordPress default UI" pause (adminkit/should_load).
		if ( ! apply_filters( 'adminkit/should_load', true, 'builder' ) ) {
			return;
		}
		$base = 'inc/integrations/themes/bricks/css/';

		// Canvas IFRAME first. It renders the real page, so it gets ONLY the canvas
		// sheet (no variable remaps). This MUST be tested before the main-frame
		// check: the iframe request also satisfies ?bricks=run / bricks_is_builder_main(),
		// so testing "main" first would leak the chrome — and its :root { --bricks-* }
		// remap — into the page and recolour it (the bug this fixes).
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			self::enqueue_style_file( 'adminkit-bricks-builder-canvas', $base . 'builder-canvas.css' );
			return;
		}

		// Otherwise → the builder MAIN frame: the chrome.
		$is_main = ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] )
			|| ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() );
		if ( $is_main ) {
			self::enqueue_builder_fallback_tokens();
			if ( self::enqueue_style_file( 'adminkit-bricks-builder', $base . 'builder.css' ) ) {
				$logo_css = self::builder_logo_css();
				if ( '' !== $logo_css ) {
					wp_add_inline_style( 'adminkit-bricks-builder', $logo_css );
				}
			}
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
	 * Toolbar logo: ALWAYS the first letter of the site title on the accent chip —
	 * clean and robust (li.logo out-specifies Bricks, display:none drops the native
	 * <img>, --accent-on keeps the letter legible). No image is shown here, so it
	 * never renders oversized or mis-fitted.
	 *
	 * Preloader: when a brand logo IS configured, it pulses on the (fixed dark)
	 * splash; otherwise Bricks's own loader shows on that dark background.
	 *
	 * @return string Inline CSS.
	 */
	private static function builder_logo_css() {
		$css = self::builder_toolbar_letter_css();

		$dark    = AdminKit_Settings::brand_logo( 'dark' );
		$light   = AdminKit_Settings::brand_logo( 'light' );
		$preload = '' !== $dark ? $dark : $light;
		if ( '' !== $preload ) {
			$css .= '#bricks-preloader .bricks-logo-animated,#bricks-preloader .title,#bricks-preloader .sub-title{display:none}';
			$css .= '#bricks-preloader .bricks-loading-inner{display:grid;place-items:center}';
			$css .= '#bricks-preloader .bricks-loading-inner::before{content:"";width:15rem;aspect-ratio:1;'
				. 'background:' . self::css_url( $preload ) . ' center/contain no-repeat;'
				. 'animation:ak-bricks-preload 1.4s ease-in-out infinite}';
			$css .= '@keyframes ak-bricks-preload{50%{transform:scale(1.1)}}';
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
