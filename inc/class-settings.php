<?php
/**
 * Settings registry — stub for the future settings page.
 *
 * Today this class only exposes a typed getter and an `inline_tokens()`
 * helper that emits a `:root { --ak-primary: <hex>; }` rule when the
 * user has chosen a primary color. There is **no admin UI**; values
 * land in the registered option `adminkit_settings` (when a future UI
 * writes to it) or via filters in the meantime.
 *
 * When Bricks is active its `adminkit/extra_tokens_handle` filter
 * already overrides `--ak-primary` upstream, so the value stored here
 * is effectively a fallback for non-Bricks sites.
 *
 * Public API:
 *   AdminKit_Settings::init()                        // wire defaults + hooks (call once)
 *   AdminKit_Settings::register( $key, $args )      // declare a setting
 *   AdminKit_Settings::get( $key )                  // read (option → default → filter)
 *   AdminKit_Settings::inline_tokens()              // CSS string for wp_add_inline_style
 *
 * Filters:
 *   adminkit/setting/{$key}    final value passes through this filter
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Settings {

	const OPTION_KEY = 'adminkit_settings';

	/**
	 * Registered settings schema. Keyed by setting id, value is an
	 * args array with at least a `default` key.
	 *
	 * @var array<string, array>
	 */
	private static $schema = array();

	/**
	 * Declare default settings + wire the inline-token injection.
	 * Called once from the plugin orchestrator. No admin UI is mounted
	 * — that arrives with the future settings page.
	 *
	 * @return void
	 */
	public static function init() {
		self::register( 'primary_color', array( 'default' => null ) );

		add_action( 'adminkit/tokens_enqueued', array( __CLASS__, 'apply_inline_tokens' ) );
	}

	/**
	 * Apply the inline `:root { --ak-primary: ... }` override on top
	 * of the tokens stylesheet. No-op when no primary color is set.
	 *
	 * `adminkit/tokens_enqueued` fires once per context (admin / login /
	 * frontend / editor). Block-editor pages dispatch BOTH admin and
	 * editor contexts in the same request, so we guard with a static
	 * flag — `wp_add_inline_style` appends, and we don't want the rule
	 * twice on the page.
	 *
	 * @return void
	 */
	public static function apply_inline_tokens() {
		static $applied = false;
		if ( $applied ) {
			return;
		}
		$css = self::inline_tokens();
		if ( '' === $css ) {
			return;
		}
		wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, $css );
		$applied = true;
	}

	/**
	 * Declare a setting. Idempotent — last call wins for a given key.
	 *
	 * @param string $key  Setting id (e.g. `primary_color`).
	 * @param array  $args Supports `default` (mixed) and `sanitize` (callable).
	 * @return void
	 */
	public static function register( $key, array $args = array() ) {
		$args            = wp_parse_args( $args, array(
			'default'  => null,
			'sanitize' => null,
		) );
		self::$schema[ $key ] = $args;
	}

	/**
	 * Read a setting. Resolution order:
	 *   1. Stored value in `wp_options['adminkit_settings'][$key]`
	 *   2. Schema default
	 *   3. `adminkit/setting/{$key}` filter (always applied last)
	 *
	 * @param string $key
	 * @return mixed
	 */
	public static function get( $key ) {
		$stored  = get_option( self::OPTION_KEY, array() );
		$default = isset( self::$schema[ $key ]['default'] ) ? self::$schema[ $key ]['default'] : null;
		$value   = isset( $stored[ $key ] ) ? $stored[ $key ] : $default;
		return apply_filters( "adminkit/setting/{$key}", $value );
	}

	/**
	 * Snapshot of the registered schema. Future settings UI reads this
	 * to know which fields to render.
	 *
	 * @return array<string, array>
	 */
	public static function schema() {
		return self::$schema;
	}

	/**
	 * Build the inline CSS to inject into `:root` so the user's chosen
	 * primary color overrides the default token. Returns an empty
	 * string when nothing is set (no need to inline anything).
	 *
	 * Wired by the orchestrator via `wp_add_inline_style( 'adminkit-tokens', ... )`.
	 *
	 * @return string
	 */
	public static function inline_tokens() {
		$primary = self::get( 'primary_color' );
		if ( empty( $primary ) || ! is_string( $primary ) ) {
			return '';
		}
		$primary = sanitize_hex_color( $primary );
		if ( ! $primary ) {
			return '';
		}
		return sprintf( ':root{--ak-primary:%s;}', $primary );
	}
}
