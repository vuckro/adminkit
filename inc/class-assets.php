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
 *   admin    → admin_enqueue_scripts        (priority 9999)
 *   login    → login_enqueue_scripts        (priority 9999)
 *   frontend → wp_enqueue_scripts           (priority 9999, only when admin bar shows)
 *   editor   → enqueue_block_editor_assets  (priority 9999, block / site / widgets editors)
 *
 * `src` is a path relative to ADMINKIT_PATH (the plugin root). This
 * lets integration files in `inc/integrations/{slug}/css/` use the
 * registry without special-casing.
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
	const FONTS_URL     = 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap';
	const TOKENS_HANDLE = 'adminkit-tokens';
	const TOKENS_SRC    = 'assets/css/tokens.css';

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

		add_filter( 'admin_body_class', array( __CLASS__, 'add_admin_body_class' ) );
		add_filter( 'login_body_class', array( __CLASS__, 'add_login_body_class' ) );

		add_filter( 'wp_resource_hints', array( __CLASS__, 'resource_hints' ), 10, 2 );
	}

	/**
	 * Declare an asset. Multiple calls with the same handle in the
	 * same context are allowed (wp_enqueue_style is idempotent on the
	 * handle, so no double-load).
	 *
	 * @param array $args {
	 *   @type string        $handle    REQUIRED. WP style handle, e.g. `adminkit-themes`.
	 *   @type string        $src       REQUIRED. Path relative to ADMINKIT_PATH, e.g. `assets/css/screens/themes.css`.
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

		self::enqueue_fonts();
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
	 *   2. `adminkit/enqueue_{handle}`  (new in 1.1, per-asset filter)
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
	 * Enqueue the Google Fonts stylesheet.
	 *
	 * @return void
	 */
	private static function enqueue_fonts() {
		wp_enqueue_style( self::FONTS_HANDLE, self::FONTS_URL, array(), null );
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
		$extra = apply_filters( 'adminkit/extra_tokens_handle', null, $context );
		$deps  = array_filter( array_merge( $core_deps, array( $extra ) ) );

		$path = ADMINKIT_PATH . self::TOKENS_SRC;
		wp_enqueue_style(
			self::TOKENS_HANDLE,
			ADMINKIT_URL . self::TOKENS_SRC,
			$deps,
			file_exists( $path ) ? (string) filemtime( $path ) : ADMINKIT_VERSION
		);

		/**
		 * Fires right after `adminkit-tokens` is enqueued for a context.
		 * Hook here with `wp_add_inline_style` to inject dynamic CSS
		 * variable overrides (AdminKit_Settings uses this to apply the
		 * user's primary color).
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
	 * @param string   $name Filename without extension (e.g. `core`).
	 * @param string[] $deps
	 * @return void
	 */
	public static function enqueue( $name, $deps = array() ) {
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

	/**
	 * Preconnect to Google Fonts so the first paint isn't blocked on DNS.
	 *
	 * @param array  $hints
	 * @param string $relation_type
	 * @return array
	 */
	public static function resource_hints( $hints, $relation_type ) {
		if ( 'preconnect' === $relation_type ) {
			$hints[] = array( 'href' => 'https://fonts.googleapis.com', 'crossorigin' => 'anonymous' );
			$hints[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' );
		}
		return $hints;
	}
}
