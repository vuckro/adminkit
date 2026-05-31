<?php
/**
 * Settings registry — the data layer behind the settings page.
 *
 * Exposes a typed getter and the schema of declared settings. The admin UI
 * lives in `AdminKit_Settings_Page`; values land in the option
 * `adminkit_settings` (written by that UI) or via the `adminkit/setting/{key}`
 * filter.
 *
 * Public API:
 *   AdminKit_Settings::init()                   // wire defaults + hooks (call once)
 *   AdminKit_Settings::register( $key, $args )  // declare a setting
 *   AdminKit_Settings::get( $key )              // read (option → default → filter)
 *   AdminKit_Settings::schema()                 // the registered schema
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
	 * Declare default settings + bind the module toggles. Called once from the
	 * plugin orchestrator.
	 *
	 * Feature toggles carry no translated labels (those live in the settings
	 * page UI), so they register immediately rather than on `init` — modules
	 * read these values at load / enqueue time, before the `init` action.
	 *
	 * @return void
	 */
	public static function init() {
		self::register_feature_catalog();
		self::register_branding();
		self::bind_modules();
	}

	/**
	 * Branding settings — optional brand logo URLs for light + dark mode. Empty by
	 * default (no asset shipped). Consumed by the Bricks builder integration (and
	 * available to any integration) via AdminKit_Settings::get(). URLs are stored
	 * sanitized; an empty value is dropped so the consumer can fall back.
	 *
	 * @return void
	 */
	private static function register_branding() {
		foreach ( array( 'logo_light', 'logo_dark' ) as $key ) {
			self::register( $key, array(
				'type'     => 'text',
				'group'    => 'branding',
				'default'  => '',
				'sanitize' => 'esc_url_raw',
			) );
		}

		// The site-name brand mark (next to the site title; the top-left WordPress
		// logo is always hidden): the brand logo, the site icon (favicon), or hidden.
		// `logo` falls back to `favicon`, then to the bare site title, when no brand
		// logo is configured. Read by AdminKit_Core_Branding. Unknown / legacy values
		// degrade to `favicon`.
		self::register( 'wp_logo', array(
			'type'     => 'select',
			'group'    => 'branding',
			'default'  => 'favicon',
			// Admin bar has no `hide` — picking `favicon` when no Site Icon is set
			// already yields a bare title (favicon_chip_css() returns ''). Legacy
			// stored `'hide'` values degrade to `favicon` here. Login screen keeps
			// its `hide` (separate setting `login_logo`).
			'sanitize' => static function ( $v ) {
				return in_array( $v, array( 'logo', 'favicon' ), true ) ? $v : 'favicon';
			},
		) );

		// Login-screen mark — its OWN choice, independent of the admin bar: `logo`
		// (rectangular wordmark) or `favicon` (square site icon). Read by
		// AdminKit_Core_Login::login_mode(). Defaults to `favicon` — picking it
		// with no Site Icon set collapses the WP login logo entirely, so no
		// explicit `hide` mode is needed. Legacy stored `'hide'` degrades to
		// `favicon` here. (A legacy '' = "inherit wp_logo" is still honoured by
		// login_mode() for back-compat.)
		self::register( 'login_logo', array(
			'type'     => 'select',
			'group'    => 'branding',
			'default'  => 'favicon',
			'sanitize' => static function ( $v ) {
				return in_array( $v, array( 'logo', 'favicon' ), true ) ? $v : 'favicon';
			},
		) );

		// Brand accent — the hex used when `accent_source` === 'custom'. Ignored
		// for the other two sources (AdminKit / Bricks). When valid, an inline
		// style block on `adminkit/tokens_enqueued` injects it as
		// `:root{--ak-primary: <hex>}`, and the derived tokens (hover / subtle /
		// focus ring / on-accent) recalculate automatically through their CSS
		// `color-mix()` fallbacks. `sanitize_hex_color()` returns null for junk
		// → drop → empty → no override.
		self::register( 'brand_accent', array(
			'type'     => 'text',
			'group'    => 'branding',
			'default'  => '',
			'sanitize' => 'sanitize_hex_color',
		) );

		// Accent source — which of the three feeds `--ak-primary`:
		//   • 'adminkit' (default WordPress Blue #3858E9, forced over Bricks)
		//   • 'bricks'   (use the Bricks-provided --accent, no override)
		//   • 'custom'   (use the `brand_accent` hex)
		// Empty default = "auto" — resolved at READ time by accent_source() so the
		// effective source flips with Bricks (de)activation without burning a value
		// into the option. Once the user picks explicitly, that choice sticks.
		self::register( 'accent_source', array(
			'type'     => 'select',
			'group'    => 'branding',
			'default'  => '',
			'sanitize' => static function ( $v ) {
				return in_array( $v, array( 'adminkit', 'bricks', 'custom' ), true ) ? $v : '';
			},
		) );
	}

	/**
	 * Resolve the effective accent source — applies the "auto" default at read
	 * time. Use this everywhere instead of `get('accent_source')` so the cascade
	 * (Bricks active → 'bricks', else 'adminkit') stays correct even when the
	 * Bricks integration toggles after install.
	 *
	 * @return string 'adminkit' | 'bricks' | 'custom'
	 */
	public static function accent_source() {
		$raw = (string) self::get( 'accent_source' );
		if ( '' !== $raw ) {
			return $raw;
		}
		if ( class_exists( 'AdminKit_Integration_Bricks' ) && AdminKit_Integration_Bricks::is_active() ) {
			return 'bricks';
		}
		return 'adminkit';
	}

	/**
	 * Resolve the brand logo URL for a mode — the single source of truth used
	 * everywhere AdminKit shows a logo (admin menu, Bricks builder).
	 *
	 * Order: the Branding setting (logo_light / logo_dark) wins; otherwise the
	 * `adminkit/brand_logo` filter (a URL string, or an array keyed
	 * light / dark / preloader). Returns '' when nothing is set, so each consumer
	 * can no-op cleanly (no asset is ever shipped).
	 *
	 * @param string $mode 'light' | 'dark'.
	 * @return string Raw URL, or '' when unset.
	 */
	public static function brand_logo( $mode ) {
		$mode = ( 'light' === $mode ) ? 'light' : 'dark';
		$url  = trim( (string) self::get( 'logo_' . $mode ) );
		if ( '' !== $url ) {
			return $url;
		}
		$f = apply_filters( 'adminkit/brand_logo', '' );
		if ( is_array( $f ) && ! empty( $f[ $mode ] ) ) {
			return (string) $f[ $mode ];
		}
		if ( is_string( $f ) ) {
			return $f;
		}
		return '';
	}


	/**
	 * Declare the feature / module toggles (all boolean, default ON). They
	 * carry no `__()` labels — those live in AdminKit_Settings_Page so they
	 * resolve on the admin screen — so this can run at load, before the `init`
	 * action, which is when modules read these values to gate themselves.
	 *
	 * `post_previews_enabled` is registered by AdminKit_Post_Previews itself.
	 *
	 * @return void
	 */
	private static function register_feature_catalog() {
		$toggles = array(
			'module_login_enabled',
			'theme_toggle_enabled',
		);
		foreach ( $toggles as $key ) {
			self::register( $key, array(
				'type'     => 'toggle',
				'group'    => 'features',
				'default'  => true,
				'sanitize' => 'rest_sanitize_boolean',
			) );
		}

		// Editor content theming ("Gutenberg") — ON by default. Themes the block
		// content inside the editor canvas (iframe) to match AdminKit. Set OFF to keep
		// the canvas matching the live site exactly (a client's page layout untouched).
		// The canvas-injection wiring plugs into this flag.
		self::register( 'editor_content_theme', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );

		// Bricks builder restyle — ON by default (when the Bricks theme is the
		// active theme; the Features row is greyed-out otherwise via the
		// `available` flag, so a non-Bricks site still sees the option but can't
		// flip it). Read by AdminKit_Integration_Bricks::enqueue_builder().
		self::register( 'bricks_builder_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );

		// AdminKit icons — ON by default. Replaces WordPress's native menu + toolbar
		// dashicons with AdminKit's icon set. Read by AdminKit_Core_Menu_Icons;
		// non-destructive (only stock dashicons, no override).
		self::register( 'replace_icons_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );

		// Custom avatars — ON by default. Registers "AdminKit Portraits (Generated)"
		// in WordPress's Settings → Discussion → Default Avatar dropdown and serves
		// a unique generated portrait for users with no real Gravatar. Read by
		// AdminKit_Local_Avatars. Non-destructive — when off, AdminKit does nothing
		// to avatars and Gravatar behaviour is 100% unchanged.
		self::register( 'custom_avatars_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );

		// User quick edit — ON by default. Mirrors the post Quick Edit pattern
		// for the users list: inline form to update first name / last name /
		// email / role from users.php without opening user-edit.php. Read by
		// AdminKit_User_Quick_Edit. Off = no Quick Edit affordance; the Edit
		// link to user-edit.php still works as native WP.
		self::register( 'quick_edit_users_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );

		// Username changer — OFF by default. Lets an admin rename
		// `user_login` from profile.php / user-edit.php, a column WordPress
		// disables in its own UI. Sensitive (it invalidates auth cookies and
		// kicks the affected user out of every active session), so it ships
		// opt-in. Read by AdminKit_Username_Changer. Off = the Username field
		// stays the native read-only state.
		self::register( 'username_changer_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => false,
			'sanitize' => 'rest_sanitize_boolean',
		) );
	}

	/**
	 * Bind the module toggles to AdminKit's existing enqueue filters and the
	 * post-previews provider — reusing the current gating, no new mechanism.
	 * A toggle left ON keeps current behaviour; OFF forces the feature off.
	 *
	 * @return void
	 */
	private static function bind_modules() {
		$contexts = array(
			'module_login_enabled' => 'adminkit/enqueue_login',
		);
		foreach ( $contexts as $key => $filter ) {
			add_filter( $filter, static function ( $enabled ) use ( $key ) {
				return $enabled && (bool) self::get( $key );
			} );
		}
	}

	/**
	 * Declare a setting. Idempotent — last call wins for a given key.
	 *
	 * @param string $key  Setting id (e.g. `primary_color`).
	 * @param array  $args {
	 *     @type string        $type     Field kind: color | toggle | select | text. Default 'text'.
	 *     @type string        $group    UI group: features | … . Default 'general'.
	 *     @type string        $label    Human-readable field label.
	 *     @type string        $desc     Help text shown under the field.
	 *     @type array|null    $choices  value => label map, for `select`.
	 *     @type mixed         $default  Returned when no stored value exists.
	 *     @type callable|null $sanitize Save-time sanitiser.
	 * }
	 * @return void
	 */
	public static function register( $key, array $args = array() ) {
		$args = wp_parse_args( $args, array(
			'type'     => 'text',
			'group'    => 'general',
			'label'    => '',
			'desc'     => '',
			'choices'  => null,
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

		// An empty string in the stored option is "unset", not "off" — older saves
		// or schema renames can leave keys behind with no value. Fall back to the
		// declared default so a renamed toggle doesn't silently disable a feature.
		if ( '' === $value && null !== $default ) {
			$value = $default;
		}

		// SELECT settings: re-run the sanitiser at read time so legacy stored
		// values that are no longer in the valid set (e.g. the old 'hide' mode
		// for wp_logo / login_logo) degrade gracefully to the declared default
		// rather than leaking through as-is to every consumer. Sanitisers for
		// select fields return the default when the value is unknown, so this
		// is always safe to apply.
		if ( 'select' === ( isset( self::$schema[ $key ]['type'] ) ? self::$schema[ $key ]['type'] : '' )
			&& isset( self::$schema[ $key ]['sanitize'] )
			&& is_callable( self::$schema[ $key ]['sanitize'] ) ) {
			$value = call_user_func( self::$schema[ $key ]['sanitize'], $value );
		}

		return apply_filters( "adminkit/setting/{$key}", $value );
	}

	/**
	 * Snapshot of the registered schema. The settings UI reads this to know
	 * which fields to render and to sanitise saved values against.
	 *
	 * @return array<string, array>
	 */
	public static function schema() {
		return self::$schema;
	}
}
