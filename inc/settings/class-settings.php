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
		AdminKit_Settings_Catalog::register_integration_toggles();
		self::register_branding();
		self::register_appearance();
		self::register( 'design_system', array( 'type' => 'object', 'group' => 'appearance', 'default' => self::sanitize_design_system( array() ), 'sanitize' => array( __CLASS__, 'sanitize_design_system' ) ) );
		self::bind_modules();
	}

	/**
	 * Branding settings — the single optional brand logo URL (empty by default, no
	 * asset shipped), plus the accent choice. The logo is edited on Settings →
	 * General; consumers read it through brand_logo().
	 *
	 * @return void
	 */
	private static function register_branding() {
		self::register( 'logo_url', array(
			'type'     => 'text',
			'group'    => 'branding',
			'default'  => '',
			'sanitize' => static function ( $v ) {
				if ( ! is_string( $v ) ) {
					return '';
				}
				$v = trim( $v );
				if ( '' === $v ) {
					return '';
				}
				return function_exists( 'esc_url_raw' ) ? esc_url_raw( $v, array( 'http', 'https' ) ) : $v;
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

		// Monochrome is explicit and independent of installed themes/providers.
		self::register( 'accent_source', array(
			'type' => 'select', 'group' => 'branding', 'default' => 'monochrome',
			'sanitize' => static function ( $v ) {
				return in_array( $v, array( 'monochrome', 'adminkit', 'bricks', 'custom' ), true ) ? $v : 'monochrome';
			},
		) );
	}

	/** Interface font preset and explicit GDPR opt-in for remote Google Fonts. */
	private static function register_appearance() {
		self::register( 'ui_font', array(
			'type'     => 'select',
			'group'    => 'appearance',
			'default'  => 'geist',
			'sanitize' => static function ( $value ) {
				$value = (string) $value;
				if ( 'humanist' === $value || 'classic' === $value ) {
					return 'geist';
				}
				$allowed = class_exists( 'AdminKit_Design_System' )
					? array_keys( AdminKit_Design_System::fonts() )
					: array( 'geist', 'system', 'inter', 'plus-jakarta-sans', 'dm-sans', 'manrope', 'outfit', 'poppins', 'space-grotesk', 'ibm-plex-sans' );
				return in_array( $value, $allowed, true ) ? $value : 'geist';
			},
		) );
		self::register( 'remote_fonts_enabled', array(
			'type'     => 'toggle',
			'group'    => 'appearance',
			'default'  => false,
			'sanitize' => 'rest_sanitize_boolean',
		) );
	}

	/** Advanced studio settings are persisted for previews; global adoption is separate. */
	public static function sanitize_design_system( $input ) {
		$input = is_array( $input ) ? $input : array();
		$result = array( 'radius_scale' => 1, 'type_scale' => 1, 'border_width' => 1, 'elevation' => 'default', 'components' => array( 'buttons' => array( 'highlight' => 'always' ) ), 'colors' => array( 'light' => array(), 'dark' => array() ) );
		foreach ( array( 'radius_scale' => array( 0, 2 ), 'type_scale' => array( 0.75, 1.5 ) ) as $key => $bounds ) {
			$value = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (float) $input[ $key ] : null;
			if ( null !== $value && is_finite( $value ) && $value >= $bounds[0] && $value <= $bounds[1] ) {
				$result[ $key ] = round( $value, 2 );
			}
		}
		if ( isset( $input['border_width'] ) && in_array( $input['border_width'], array( 0, 1, 2, '0', '1', '2' ), true ) ) {
			$result['border_width'] = (int) $input['border_width'];
		}
		if ( isset( $input['elevation'] ) && in_array( $input['elevation'], array( 'default', 'flat', 'subtle', 'raised' ), true ) ) {
			$result['elevation'] = $input['elevation'];
		}
		// Component settings are explicitly allowlisted; unknown future keys are ignored.
		$components = isset( $input['components'] ) && is_array( $input['components'] ) ? $input['components'] : array();
		$buttons = isset( $components['buttons'] ) && is_array( $components['buttons'] ) ? $components['buttons'] : array();
		if ( isset( $buttons['highlight'] ) && in_array( $buttons['highlight'], array( 'off', 'hover', 'always' ), true ) ) {
			$result['components']['buttons']['highlight'] = $buttons['highlight'];
		}
		$roles = array( 'bg', 'surface', 'text', 'text-muted', 'border', 'accent', 'on-accent', 'success-text', 'warning-text', 'danger-text', 'info-text' );
		$colors = isset( $input['colors'] ) && is_array( $input['colors'] ) ? $input['colors'] : array();
		foreach ( array( 'light', 'dark' ) as $mode ) {
			$palette = isset( $colors[ $mode ] ) && is_array( $colors[ $mode ] ) ? $colors[ $mode ] : array();
			foreach ( $roles as $role ) {
				$value = isset( $palette[ $role ] ) && is_string( $palette[ $role ] ) ? sanitize_hex_color( $palette[ $role ] ) : null;
				if ( $value ) { $result['colors'][ $mode ][ $role ] = strtolower( $value ); }
			}
		}
		return $result;
	}

	/** Resolve the accent source; unset / unknown values use Monochrome. */
	public static function accent_source() {
		$source = (string) self::get( 'accent_source' );
		return in_array( $source, array( 'monochrome', 'adminkit', 'bricks', 'custom' ), true ) ? $source : 'monochrome';
	}

	/**
	/**
	 * CSS class suffix for dynamic SVG logo coloring (`ak-logo-dynamic`).
	 *
	 * @return string
	 */
	public static function dynamic_logo_class() {
		$url = (string) self::get( 'logo_url' );
		return preg_match( '/\.svg$/i', (string) parse_url( $url, PHP_URL_PATH ) ) ? ' ak-logo-dynamic' : '';
	}

	/**
	 * Resolve the brand logo URL for a mode — the single source of truth used
	 * everywhere AdminKit shows a logo.
	 *
	 * Order: the Branding setting (logo_url) wins; otherwise the
	 * `adminkit/brand_logo` filter (a URL string, or an array keyed
	 * light / dark / preloader). Returns '' when nothing is set, so each consumer
	 * can no-op cleanly (no asset is ever shipped).
	 *
	 * @param string $mode 'light' | 'dark'.
	 * @return string Raw URL, or '' when unset.
	 */
	public static function brand_logo( $mode ) {
		$single = trim( (string) self::get( 'logo_url' ) );
		if ( '' !== $single ) { return $single; }
		$mode = ( 'light' === $mode ) ? 'light' : 'dark';
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
	 * Declare boolean feature/module toggles and their sub-options. They
	 * carry no `__()` labels — those live in AdminKit_Settings_Page so they
	 * resolve on the admin screen — so this can run at load, before the `init`
	 * action, which is when modules read these values to gate themselves.
	 *
	 * `post_previews_enabled` is registered by AdminKit_Post_Previews itself.
	 *
	 * @return void
	 */
	private static function register_feature_catalog() {
		// Native skin capabilities (always on unless filtered/overridden).
		foreach ( array( 'branding_enabled', 'replace_icons_enabled' ) as $native_key ) {
			self::register( $native_key, array(
				'type'     => 'toggle',
				'group'    => 'features',
				'default'  => true,
				'sanitize' => 'rest_sanitize_boolean',
			) );
		}

		// Baseline defaults and sub-options preserve each parent's prior behavior.
		foreach ( array(
			'admin_theme_enabled',
			'theme_toggle_enabled',
			'clean_chrome_enabled',
			'clean_chrome_hide_footer',
			'clean_chrome_hide_help',
			'active_items_first_content_default',
			'active_items_first_plugins_default',
			'active_items_first_sort',
		) as $default_on ) {
			self::register( $default_on, array(
				'type'     => 'toggle',
				'group'    => 'features',
				'default'  => true,
				'sanitize' => 'rest_sanitize_boolean',
			) );
		}

		// Opt-in login styling and workflow enhancements keep native screens
		// untouched until explicitly enabled.
		foreach ( array( 'module_login_enabled', 'active_items_first_enabled', 'quick_edit_users_enabled', 'username_changer_enabled' ) as $opt_in ) {
			self::register( $opt_in, array(
				'type'     => 'toggle',
				'group'    => 'features',
				'default'  => false,
				'sanitize' => 'rest_sanitize_boolean',
			) );
		}
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
		$is_bool = isset( $args['default'] ) && is_bool( $args['default'] );
		$args    = wp_parse_args( $args, array(
			'type'     => $is_bool ? 'toggle' : 'text',
			'group'    => $is_bool ? 'features' : 'general',
			'label'    => '',
			'desc'     => '',
			'choices'  => null,
			'default'  => null,
			'sanitize' => $is_bool ? 'rest_sanitize_boolean' : null,
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
		$stored  = is_array( $stored ) ? $stored : array();
		$default = isset( self::$schema[ $key ]['default'] ) ? self::$schema[ $key ]['default'] : null;
		$value   = isset( $stored[ $key ] ) ? $stored[ $key ] : $default;

		// An empty string in the stored option is "unset", not "off" — older saves
		// or schema renames can leave keys behind with no value. Fall back to the
		// declared default so a renamed toggle doesn't silently disable a feature.
		if ( '' === $value && null !== $default ) {
			$value = $default;
		}

		// Normalize legacy select/toggle values before consumers cast them. In
		// particular, a stored string "false" must not enable a boolean feature.
		if ( in_array( self::$schema[ $key ]['type'] ?? '', array( 'select', 'toggle' ), true )
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
