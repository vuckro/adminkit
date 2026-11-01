<?php
/**
 * Asset registry + dispatcher.
 *
 * Each module (core, integrations) declares its CSS via
 * `AdminKit_Assets::register()`. The registry runs once per request,
 * on the appropriate WP hook for each context, and enqueues only the
 * entries whose `condition` closure matches (or that have no
 * condition — always-load).
 *
 * Contexts:
 *   admin     → admin_enqueue_scripts               (priority 9999)
 *   login     → login_enqueue_scripts               (priority 9999)
 *   frontend  → wp_enqueue_scripts                  (priority 9999, only when admin bar shows)
 *   editor    → enqueue_block_editor_assets         (priority 9999, block / site / widgets editors)
 *   customize → customize_controls_enqueue_scripts  (priority 9999, the Customizer controls pane)
 *
 * `src` is a path relative to ADMINKIT_PATH (the plugin root). This
 * lets integration files in `inc/integrations/{plugins|themes}/{slug}/css/`
 * use the registry without special-casing.
 *
 * Public API:
 *   AdminKit_Assets::register([ ... ])  declare an asset
 *   AdminKit_Assets::enqueue( $name )   legacy 1.0 helper, looks under assets/css/
 *
 * Filters (preserved from 1.0):
 *   adminkit/should_load          (bool, string $context)
 *   adminkit/enqueue_{$context}   (bool)
 *   adminkit/enqueue_{$section}   (bool)            per-section bail
 *   adminkit/extra_tokens_handle  (string|null, $context)
 *
 * Filters (new in 1.1):
 *   adminkit/enqueue_{$handle}    (bool)            per-asset bail
 *
 * Actions (preserved):
 *   adminkit/enqueued_{$context}  fires after dispatch completes
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Assets {

	const FONTS_HANDLE  = 'adminkit-fonts';
	const FONTS_SRC     = 'assets/css/fonts.css';
	const TOKENS_HANDLE = 'adminkit-tokens';
	const TOKENS_SRC    = 'assets/css/tokens.css';

	/** Default hex seed for the custom-color picker; native accent uses WaasKit roles. */
	const ADMINKIT_BLUE = '#1549AB';

	// Built-in WaasKit token baseline (generated from tokens/palettes/waaskit.json
	// by php tokens/build.php). Shipped so AdminKit is brand-complete with
	// no provider; a live provider (Bricks) loads AFTER it and overrides it.
	const WAASKIT_HANDLE = 'adminkit-waaskit';
	const WAASKIT_SRC    = 'assets/css/waaskit-tokens.css';

	/** Shared Element Plus `--el-*` → `--ak-*` bridge registered on admin screens for Fluent adapters. */
	const ELEMENT_PLUS_HANDLE = 'adminkit-vendor-element-plus';
	const ELEMENT_PLUS_SRC    = 'assets/css/vendor/element-plus.css';


	/**
	 * Registered asset entries.
	 *
	 * Each entry: array{
	 *   handle:    string,
	 *   src:       string,        // path relative to ADMINKIT_PATH
	 *   deps:      string[],
	 *   context:   string,        // admin | login | frontend | editor
	 *   section:   string,        // for the per-section back-compat filter
	 *   condition: callable|null, // null = always; closure(WP_Screen|null) = conditional
	 * }
	 *
	 * @var array<int, array>
	 */
	private static $registry = array();

	/**
	 * Wire the dispatch hooks. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'dispatch_admin' ), 9999 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'dispatch_login' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dispatch_frontend' ), 9999 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'dispatch_editor' ), 9999 );
		// The Customizer (customize.php) skips admin_enqueue_scripts, so its own
		// enqueue hook gets the dispatch — fonts + tokens + every `customize`-context entry.
		add_action( 'customize_controls_enqueue_scripts', array( __CLASS__, 'dispatch_customize' ), 9999 );

		add_filter( 'admin_body_class', array( __CLASS__, 'add_admin_body_class' ) );
		add_filter( 'login_body_class', array( __CLASS__, 'add_login_body_class' ) );


		// Accent family override — emits a dual-block (light :root + dark
		// :root[data-adminkit-theme="dark"]) inline rule on the tokens
		// stylesheet so every UI surface that consumes --ak-primary &
		// derivatives follows an explicit user override in BOTH
		// modes. Bricks source is a no-op (Bricks's stylesheet rules the
		// cascade on its own). See inject_accent_family() for the cascade rationale.
		add_action( 'adminkit/tokens_enqueued', array( __CLASS__, 'inject_accent_family' ) );
	}

	/**
	 * Emit only explicit custom/provider accent overrides after the token sheet.
	 * The default source inherits WaasKit's native light and dark roles.
	 * @param string $context Asset context.
	 */
	public static function inject_accent_family( $context ) {
		$css = self::accent_family_css();
		if ( in_array( $context, array( 'admin', 'login', 'editor', 'customize' ), true ) ) {
			$css .= AdminKit_Design_System::css();
		}
		if ( 'frontend' === $context ) {
			$css .= AdminKit_Design_System::css( true );
		}
		if ( '' === $css ) {
			return;
		}
		// One concatenated string → ONE wp_add_inline_style() call. Splitting across
		// two calls is not ordering-guaranteed on every WP version.
		wp_add_inline_style( self::TOKENS_HANDLE, $css );
	}

	/**
	 * Custom colors and the opt-in legacy Bricks provider remain supported.
	 * An empty string leaves the default WaasKit accent, hover and focus intact.
	 * Also consumed by the Gutenberg canvas.
	 * @return string CSS without a style wrapper.
	 */
	public static function accent_family_css() {
		$source = AdminKit_Settings::accent_source();
		if ( 'bricks' === $source ) {
			return ':root[data-adminkit-accent="bricks"],:root[data-adminkit-accent="bricks"] #wpadminbar{--ak-bg:var(--background,var(--wk-bg));--ak-surface:var(--surface,var(--wk-surface));--ak-primary:var(--accent,var(--wk-accent));--ak-primary-hover:var(--accent-hover,var(--wk-accent-hover));--ak-on-accent:var(--accent-on,var(--wk-on-accent))}'
				. ':root[data-adminkit-accent="bricks"][data-adminkit-theme="dark"],:root[data-adminkit-accent="bricks"][data-adminkit-theme="dark"] #wpadminbar{--ak-bg:var(--neutral-d-1,var(--wk-bg));--ak-surface:var(--neutral-d-2,var(--wk-surface))}';
		}
		if ( 'custom' !== $source ) {
			return '';
		}
		$hex = (string) sanitize_hex_color( (string) AdminKit_Settings::get( 'brand_accent' ) );
		if ( '' === $hex ) {
			return '';
		}
		// Dynamic values only: the complete family lives in tokens/appearance.css.
		$channels = self::hex_channels( $hex );
		return ':root,#wpadminbar{--ak-custom-accent:' . $hex . ';--ak-custom-on-accent:' . self::contrast_text_for( $hex )
			. ';--ak-custom-on-hover-light:' . self::hover_foreground( $channels, false ) . ';--ak-custom-on-hover-dark:' . self::hover_foreground( $channels, true )
			. ';--ak-custom-r:' . $channels[0] . ';--ak-custom-g:' . $channels[1] . ';--ak-custom-b:' . $channels[2] . '}';
	}

	/**
	 * Choose the stronger WCAG contrast against a custom hex fill.
	 * Pure white/black guarantee at least 4.5:1 for an opaque sRGB background.
	 * Mirrored in settings/main.js for the unsaved custom-color preview.
	 * @param string $hex Sanitised #rgb or #rrggbb.
	 * @return string Foreground hex.
	 */
	public static function contrast_text_for( $hex ) {
		$channels = self::hex_channels( $hex );
		if ( ! $channels ) {
			return '#ffffff';
		}
		$srgb = static function ( $byte ) {
			$c = $byte / 255;
			return ( $c <= 0.04045 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		$r = $srgb( $channels[0] );
		$g = $srgb( $channels[1] );
		$b = $srgb( $channels[2] );
		$lum = ( 0.2126 * $r ) + ( 0.7152 * $g ) + ( 0.0722 * $b );
		return 1.05 / ( $lum + 0.05 ) >= ( $lum + 0.05 ) / 0.05 ? '#ffffff' : '#000000';
	}

	/** Contrast for the exact two custom hover fills declared in appearance.css. */
	private static function hover_foreground( array $channels, $dark ) {
		$mix = array_map( static function ( $channel ) use ( $dark ) {
			return (int) round( $channel * 0.82 + ( $dark ? 255 * 0.18 : 0 ) );
		}, $channels );
		return self::contrast_text_for( sprintf( '#%02x%02x%02x', $mix[0], $mix[1], $mix[2] ) );
	}

	/** Parse the same sRGB channels used by contrast and native WP RGB aliases. */
	private static function hex_channels( $hex ) {
		$h = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return 6 === strlen( $h ) && ctype_xdigit( $h ) ? array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) ) : array();
	}

	/**
	 * Declare an asset. Multiple calls with the same handle in the
	 * same context are allowed (wp_enqueue_style is idempotent on the
	 * handle, so no double-load).
	 *
	 * @param array $args {
	 *   @type string        $handle    REQUIRED. WP style handle, e.g. `adminkit-themes`.
	 *   @type string        $src       REQUIRED. Path relative to ADMINKIT_PATH, e.g. `assets/css/wp-screens/themes.css`.
	 *   @type string[]      $deps      Style handles this depends on.
	 *   @type string        $context   admin | login | frontend | editor.
	 *   @type string|null   $section   Section name for the back-compat
	 *                                  `adminkit/enqueue_{section}` filter.
	 *                                  Defaults to handle minus `adminkit-` prefix.
	 *   @type callable|null $condition Closure receiving the current WP_Screen|null.
	 *                                  Return true to enqueue. Null = always.
	 * }
	 * @return void
	 */
	public static function register( array $args ) {
		$args = wp_parse_args( $args, array(
			'handle'    => '',
			'src'       => '',
			'deps'      => array(),
			'context'   => 'admin',
			'section'   => null,
			'condition' => null,
		) );

		if ( empty( $args['handle'] ) || empty( $args['src'] ) ) {
			return;
		}

		if ( null === $args['section'] ) {
			$args['section'] = preg_replace( '/^adminkit-/', '', $args['handle'] );
		}

		self::$registry[] = $args;
	}

	/**
	 * Dispatch the `admin` context.
	 *
	 * @return void
	 */
	public static function dispatch_admin() {
		self::dispatch_context( 'admin', array( 'wp-admin', 'colors' ) );
	}

	/**
	 * Dispatch the `login` context.
	 *
	 * @return void
	 */
	public static function dispatch_login() {
		self::dispatch_context( 'login', array( 'login' ) );
	}

	/**
	 * Dispatch the `frontend` context. Only fires when an admin bar is
	 * actually showing for the current user.
	 *
	 * @return void
	 */
	public static function dispatch_frontend() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		self::dispatch_context( 'frontend' );
	}

	/**
	 * Dispatch the `editor` context (Gutenberg / Site / Widgets editors).
	 * Fires AFTER `admin_enqueue_scripts`, so tokens enqueued by the
	 * admin dispatch are already available as a dependency.
	 *
	 * @return void
	 */
	public static function dispatch_editor() {
		self::dispatch_context( 'editor' );
	}

	/**
	 * Dispatch the `customize` context (wp-admin/customize.php). The
	 * Customizer does NOT fire `admin_enqueue_scripts`, so it has its own
	 * enqueue hook (`customize_controls_enqueue_scripts`). This brings the
	 * --ak-* tokens (+ fonts) and our customize.css to the Customizer
	 * controls pane.
	 *
	 * @return void
	 */
	public static function dispatch_customize() {
		self::dispatch_context( 'customize' );
	}

	/**
	 * Run the shared prelude (filter guards + fonts + tokens), then
	 * walk the registry and enqueue every entry matching the context.
	 *
	 * @param string   $context
	 * @param string[] $core_deps Extra deps pinned on `adminkit-tokens`
	 *                            so its cascade order resolves against
	 *                            the matching WP core stylesheet.
	 * @return void
	 */
	private static function dispatch_context( $context, array $core_deps = array() ) {
		if ( ! apply_filters( 'adminkit/should_load', true, $context ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/enqueue_' . $context, true ) ) {
			return;
		}

		self::enqueue_fonts( $context );
		self::enqueue_tokens( $context, $core_deps );

		$screen = AdminKit_Screen::get();

		foreach ( self::$registry as $entry ) {
			if ( $entry['context'] !== $context ) {
				continue;
			}
			if ( ! self::should_enqueue( $entry, $screen ) ) {
				continue;
			}
			self::do_enqueue( $entry );
		}

		do_action( 'adminkit/enqueued_' . $context );
	}

	/**
	 * Decide whether to enqueue a registry entry — three gates:
	 *   1. `adminkit/enqueue_{section}` (back-compat per-section filter)
	 *   2. `adminkit/enqueue_{handle}`  (per-asset filter, added in 1.1)
	 *   3. The entry's own `condition` closure (or always-on if null)
	 *
	 * @param array            $entry
	 * @param \WP_Screen|null  $screen
	 * @return bool
	 */
	private static function should_enqueue( array $entry, $screen ) {
		if ( ! apply_filters( 'adminkit/enqueue_' . $entry['section'], true ) ) {
			return false;
		}
		if ( ! apply_filters( 'adminkit/enqueue_' . $entry['handle'], true ) ) {
			return false;
		}
		if ( is_callable( $entry['condition'] ) ) {
			return (bool) call_user_func( $entry['condition'], $screen );
		}
		return true;
	}

	/**
	 * Enqueue a single registry entry. Mtime-stamped so edits skip
	 * the browser cache.
	 *
	 * @param array $entry
	 * @return void
	 */
	private static function do_enqueue( array $entry ) {
		$path = ADMINKIT_PATH . $entry['src'];
		wp_enqueue_style(
			$entry['handle'],
			ADMINKIT_URL . $entry['src'],
			$entry['deps'],
			file_exists( $path ) ? (string) filemtime( $path ) : ADMINKIT_VERSION
		);
	}

	/**
	 * Enqueue the locally hosted WaasKit font families, plus the active Google Font
	 * when explicitly opted in (and never on unauthenticated `wp-login.php`).
	 *
	 * @param string $context Current dispatch context (`admin`, `login`, `frontend`, `editor`, `customize`).
	 * @return void
	 */
	private static function enqueue_fonts( $context = 'admin' ) {
		wp_enqueue_style( self::FONTS_HANDLE, ADMINKIT_URL . self::FONTS_SRC, array(), (string) filemtime( ADMINKIT_PATH . self::FONTS_SRC ) );
		if ( 'login' !== $context && class_exists( 'AdminKit_Design_System' ) && AdminKit_Settings::get( 'remote_fonts_enabled' ) ) {
			$fonts = AdminKit_Design_System::fonts();
			$font  = (string) AdminKit_Settings::get( 'ui_font' );
			if ( ! empty( $fonts[ $font ]['google'] ) ) {
				wp_enqueue_style(
					self::FONTS_HANDLE . '-google',
					'https://fonts.googleapis.com/css2?family=' . $fonts[ $font ]['google'] . '&display=swap',
					array(),
					null
				);
			}
		}
	}

	/**
	 * Enqueue the design-token stylesheet. Integrations can inject
	 * their own token CSS via the `adminkit/extra_tokens_handle`
	 * filter — whatever handle they return is set as a dep so the
	 * cascade resolves cleanly.
	 *
	 * @param string   $context
	 * @param string[] $core_deps
	 * @return void
	 */
	private static function enqueue_tokens( $context, array $core_deps = array() ) {
		// The frontend receives toolbar-scoped tokens only; public page styles
		// remain owned by the site's theme. Admin, login and editors use :root.
		$baseline = '';
		$wk_src = 'frontend' === $context ? 'assets/css/waaskit-toolbar-tokens.css' : self::WAASKIT_SRC;
		$wk_path = ADMINKIT_PATH . $wk_src;

		/**
		 * Whether to ship the built-in WaasKit baseline tokens. Return false to
		 * drop the baseline entirely: the --ak-* layer then rides its own neutral
		 * fallbacks (the "no provider, no baseline" case) — or, with a provider
		 * active, lets that provider own the whole palette on its own.
		 *
		 * @param bool   $enabled true to load the baseline.
		 * @param string $context admin | login | frontend | editor.
		 */
		$load_baseline = (bool) apply_filters( 'adminkit/enqueue_baseline', true, $context );

		if ( $load_baseline && file_exists( $wk_path ) ) {
			wp_enqueue_style(
				self::WAASKIT_HANDLE,
				ADMINKIT_URL . $wk_src,
				$core_deps,
				(string) filemtime( $wk_path )
			);
			$baseline = self::WAASKIT_HANDLE;
		}

		$extra = apply_filters( 'adminkit/extra_tokens_handle', null, $context );

		// Only depend on handles that are actually registered. WP 6.9.1+ DROPS a
		// stylesheet — and everything that transitively depends on it — if it lists
		// a missing dependency. The baseline is skipped on the frontend, and a
		// provider could return a handle it didn't enqueue; either would otherwise
		// take the whole --ak-* layer (and the admin bar) down with it.
		$deps = array_filter(
			array_merge( $core_deps, array( $baseline, $extra ) ),
			static function ( $dep ) {
				return $dep && wp_style_is( $dep, 'registered' );
			}
		);

		$path = ADMINKIT_PATH . self::TOKENS_SRC;
		wp_enqueue_style(
			self::TOKENS_HANDLE,
			ADMINKIT_URL . self::TOKENS_SRC,
			$deps,
			file_exists( $path ) ? (string) filemtime( $path ) : ADMINKIT_VERSION
		);

		if ( 'admin' === $context && function_exists( 'wp_register_style' ) ) {
			$ep_path = ADMINKIT_PATH . self::ELEMENT_PLUS_SRC;
			if ( file_exists( $ep_path ) ) {
				wp_register_style(
					self::ELEMENT_PLUS_HANDLE,
					ADMINKIT_URL . self::ELEMENT_PLUS_SRC,
					array( self::TOKENS_HANDLE ),
					(string) filemtime( $ep_path )
				);
			}
		}

		/**
		 * Fires right after `adminkit-tokens` is enqueued for a context.
		 * Hook here with `wp_add_inline_style` to inject dynamic CSS
		 * variable overrides (e.g. a provider or integration applying
		 * runtime token values).
		 *
		 * @param string $context  admin | login | frontend | editor
		 */
		do_action( 'adminkit/tokens_enqueued', $context );
	}

	/**
	 * Legacy 1.0 helper — enqueue a stylesheet from assets/css/ without
	 * going through the registry. Kept for integrations that built their
	 * own enqueue logic against the 1.0 API; new code should use
	 * `register()`.
	 *
	 * @deprecated 1.1.0 Use `AdminKit_Assets::register()` instead.
	 * @param string   $name Filename without extension (e.g. `core`).
	 * @param string[] $deps
	 * @return void
	 */
	public static function enqueue( $name, $deps = array() ) {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '1.1.0', 'AdminKit_Assets::register' );
		}
		$src  = 'assets/css/' . $name . '.css';
		$path = ADMINKIT_PATH . $src;
		wp_enqueue_style(
			'adminkit-' . $name,
			ADMINKIT_URL . $src,
			$deps,
			file_exists( $path ) ? (string) filemtime( $path ) : ADMINKIT_VERSION
		);
	}

	/**
	 * Print an inline `<head>` anti-FOUC bootstrap that sets `$pending_class` on
	 * `<html>` before first paint and guarantees reveal (`$ready_class`) both one
	 * frame after `window.load` and after a 3-second absolute backstop if the
	 * footer script fails to run.
	 *
	 * @param string $script_id     DOM id for the `<script>` tag.
	 * @param string $pending_class Root class applied while the layout is pending.
	 * @param string $ready_class   Root class swapped in once the layout is ready.
	 * @return void
	 */
	public static function print_prepaint( $script_id, $pending_class, $ready_class ) {
		$p = wp_json_encode( (string) $pending_class );
		$r = wp_json_encode( (string) $ready_class );
		echo '<script id="' . esc_attr( (string) $script_id ) . '">' . "\n"
			. '(function () {'
			. 'var d = document.documentElement;'
			. 'd.classList.add(' . $p . ');' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded class token.
			. 'function reveal() { d.classList.remove(' . $p . '); d.classList.add(' . $r . '); }' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. 'window.addEventListener("load", function () {'
			. 'var after = function () { if (d.classList.contains(' . $p . ')) { reveal(); } };' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. 'if (window.requestAnimationFrame) { window.requestAnimationFrame(after); } else { window.setTimeout(after, 0); }'
			. '});'
			. 'setTimeout(function () { if (d.classList.contains(' . $p . ')) { reveal(); } }, 3000);' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '})();' . "\n"
			. "</script>\n";
	}

	/**
	 * Enqueue a footer script shipped under the plugin, mtime-stamped like the
	 * stylesheets. The JS counterpart of the style helpers — the wp-core feature
	 * "bricks" (profile tabs, post previews, list-table polish) keep their
	 * behaviour in their own `assets/js/*.js` file and enqueue it through here,
	 * passing any PHP data as a `before` inline bootstrap.
	 *
	 * @param string      $handle Script handle.
	 * @param string      $src    Path relative to ADMINKIT_PATH.
	 * @param string[]    $deps   Script dependencies.
	 * @param string|null $data   Optional JS printed before the file (e.g.
	 *                            `window.AdminKitX = {…};`) for config/i18n.
	 * @return void
	 */
	public static function enqueue_script( $handle, $src, array $deps = array(), $data = null ) {
		$context = is_admin() ? 'admin' : 'frontend';
		if ( ! apply_filters( 'adminkit/should_load', true, $context ) || ! apply_filters( 'adminkit/enqueue_' . $context, true ) ) { return; }
		$path = ADMINKIT_PATH . $src;
		wp_enqueue_script(
			$handle,
			ADMINKIT_URL . $src,
			$deps,
			file_exists( $path ) ? (string) filemtime( $path ) : ADMINKIT_VERSION,
			true
		);
		if ( null !== $data && '' !== $data ) {
			wp_add_inline_script( $handle, $data, 'before' );
		}
	}

	/**
	 * Add the `adminkit` body class on admin pages so every CSS rule
	 * can scope itself cleanly. Skipped when `should_load` bails.
	 *
	 * @param string $classes
	 * @return string
	 */
	public static function add_admin_body_class( $classes ) {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return $classes;
		}
		return $classes . ' adminkit';
	}

	/**
	 * Add the `adminkit` body class on the login page.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function add_login_body_class( $classes ) {
		$classes[] = 'adminkit';
		return $classes;
	}

}
