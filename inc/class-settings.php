<?php
/**
 * Settings registry — the data layer behind the settings page.
 *
 * Exposes a typed getter and the schema of declared settings. The admin UI
 * lives in `AdminKit_Settings_Page`; values land in the option
 * `adminkit_settings` (written by that UI) or via the `adminkit/setting/{key}`
 * filter. `color_map()` is the semantic token taxonomy the Tokens tab renders
 * read-only.
 *
 * Public API:
 *   AdminKit_Settings::init()                   // wire defaults + hooks (call once)
 *   AdminKit_Settings::register( $key, $args )  // declare a setting
 *   AdminKit_Settings::get( $key )              // read (option → default → filter)
 *   AdminKit_Settings::schema()                 // the registered schema
 *   AdminKit_Settings::color_map()              // semantic token taxonomy (display)
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
		self::bind_modules();
	}

	/**
	 * The semantic colour taxonomy — mirrors WaasKit's locked 23-token SEMANTIC
	 * layer 1:1 (surface / border / text / accent / input / focus / overlay +
	 * the notification set with each -subtle) so a user moving between Bricks and
	 * AdminKit sees the same roles. The few roles WaasKit doesn't expose as a
	 * token — secondary text and the hover tint — AdminKit defines itself, flagged
	 * `own` so the UI can badge it. (Inverse text is done with a .scheme-* scope
	 * class, not a token, so it's not listed.) See docs/WAASKIT-DESIGN-SYSTEM.md.
	 *
	 * Per token: `token` (--ak-* var), `bricks` (provider semantic it bridges, ''
	 * when AdminKit-own), `source` (the primitive it ultimately resolves from),
	 * `label`, and optional `own`. Consumed by the settings SPA (Tokens tab) as a
	 * read-only reference. Runs on display so `__()` resolves.
	 *
	 * @return array
	 */
	public static function color_map() {
		return array(
			array( 'group' => 'surface', 'label' => __( 'Surfaces', 'adminkit' ), 'desc' => __( 'Page and panel backgrounds — behind everything.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-bg',       'bricks' => '--background', 'source' => '--neutral-l-1', 'label' => __( 'Background', 'adminkit' ) ),
				array( 'token' => '--ak-surface',  'bricks' => '--surface',    'source' => '--neutral-l-2', 'label' => __( 'Surface', 'adminkit' ) ),
				array( 'token' => '--ak-elevated', 'bricks' => '--elevated',   'source' => '--neutral-l-3', 'label' => __( 'Elevated', 'adminkit' ) ),
				array( 'token' => '--ak-input-bg', 'bricks' => '--input',      'source' => '--neutral-l-1', 'label' => __( 'Input field', 'adminkit' ) ),
			) ),
			array( 'group' => 'border', 'label' => __( 'Borders', 'adminkit' ), 'desc' => __( 'Outlines and dividers.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-border',        'bricks' => '--border',        'source' => '--neutral-l-4', 'label' => __( 'Border', 'adminkit' ) ),
				array( 'token' => '--ak-border-strong', 'bricks' => '--border-strong', 'source' => '--neutral-l-5', 'label' => __( 'Border strong', 'adminkit' ) ),
			) ),
			array( 'group' => 'text', 'label' => __( 'Text', 'adminkit' ), 'desc' => __( 'Headings, body copy and captions.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-heading',    'bricks' => '--heading',    'source' => '--neutral-l-9', 'label' => __( 'Heading', 'adminkit' ) ),
				array( 'token' => '--ak-text',       'bricks' => '--text',       'source' => '--neutral-l-8', 'label' => __( 'Body text', 'adminkit' ) ),
				array( 'token' => '--ak-text-muted', 'bricks' => '--text-muted', 'source' => '--neutral-l-7', 'label' => __( 'Muted text', 'adminkit' ) ),
			) ),
			array( 'group' => 'accent', 'label' => __( 'Accent', 'adminkit' ), 'desc' => __( 'Brand — buttons, links, highlights. Hover, subtle and focus derive from it automatically.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-primary',        'bricks' => '--accent',        'source' => '--primary',     'label' => __( 'Accent', 'adminkit' ) ),
				array( 'token' => '--ak-primary-hover',  'bricks' => '--accent-hover',  'source' => '--primary-d-1', 'label' => __( 'Accent hover', 'adminkit' ) ),
				array( 'token' => '--ak-primary-subtle', 'bricks' => '--accent-subtle', 'source' => '--primary-l-9', 'label' => __( 'Accent subtle', 'adminkit' ) ),
				array( 'token' => '--ak-on-accent',      'bricks' => '--accent-on',     'source' => '--primary-d-9', 'label' => __( 'On accent', 'adminkit' ) ),
				array( 'token' => '--ak-secondary',      'bricks' => '',                'source' => '--secondary',   'label' => __( 'Secondary', 'adminkit' ), 'own' => true ),
			) ),
			array( 'group' => 'state', 'label' => __( 'State', 'adminkit' ), 'desc' => __( 'Hover and focus feedback.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-hover-bg', 'bricks' => '',        'source' => '--neutral-t-2', 'label' => __( 'Hover', 'adminkit' ), 'own' => true ),
				array( 'token' => '--ak-focus',    'bricks' => '--focus', 'source' => '--primary', 'label' => __( 'Focus ring', 'adminkit' ) ),
			) ),
			array( 'group' => 'overlay', 'label' => __( 'Overlay', 'adminkit' ), 'desc' => __( 'Scrim behind modals and drawers.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-overlay', 'bricks' => '--overlay', 'source' => '--black-t-7', 'label' => __( 'Overlay', 'adminkit' ) ),
			) ),
			array( 'group' => 'status', 'label' => __( 'Status', 'adminkit' ), 'desc' => __( 'Info, success, warning and error — and their subtle fills — from the framework\'s notification roles.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-info',           'bricks' => '--info',           'source' => '--info',        'label' => __( 'Info', 'adminkit' ) ),
				array( 'token' => '--ak-info-subtle',    'bricks' => '--info-subtle',    'source' => '--info-l-9',    'label' => __( 'Info subtle', 'adminkit' ) ),
				array( 'token' => '--ak-success',        'bricks' => '--success',        'source' => '--success',     'label' => __( 'Success', 'adminkit' ) ),
				array( 'token' => '--ak-success-subtle', 'bricks' => '--success-subtle', 'source' => '--success-l-9', 'label' => __( 'Success subtle', 'adminkit' ) ),
				array( 'token' => '--ak-warning',        'bricks' => '--warning',        'source' => '--warning',     'label' => __( 'Warning', 'adminkit' ) ),
				array( 'token' => '--ak-warning-subtle', 'bricks' => '--warning-subtle', 'source' => '--warning-l-9', 'label' => __( 'Warning subtle', 'adminkit' ) ),
				array( 'token' => '--ak-error',          'bricks' => '--error',          'source' => '--error',       'label' => __( 'Error', 'adminkit' ) ),
				array( 'token' => '--ak-error-subtle',   'bricks' => '--error-subtle',   'source' => '--error-l-9',   'label' => __( 'Error subtle', 'adminkit' ) ),
			) ),
		);
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
			'module_editor_enabled',
			'theme_toggle_enabled',
			'post_previews_mshots',
		);
		foreach ( $toggles as $key ) {
			self::register( $key, array(
				'type'     => 'toggle',
				'group'    => 'features',
				'default'  => true,
				'sanitize' => 'rest_sanitize_boolean',
			) );
		}
	}

	/**
	 * Bind the module toggles to AdminKit's existing enqueue filters and the
	 * post-previews provider — reusing the current gating, no new mechanism.
	 * A toggle left ON keeps current behaviour; OFF forces the feature off.
	 *
	 * Intentionally no "admin chrome" master switch: gating the whole admin
	 * context would also drop the tokens that style this settings page, so the
	 * UI needed to switch it back on would itself be unstyled.
	 *
	 * @return void
	 */
	private static function bind_modules() {
		$contexts = array(
			'module_login_enabled'  => 'adminkit/enqueue_login',
			'module_editor_enabled' => 'adminkit/enqueue_editor',
		);
		foreach ( $contexts as $key => $filter ) {
			add_filter( $filter, static function ( $enabled ) use ( $key ) {
				return $enabled && (bool) self::get( $key );
			} );
		}

		// "Live screenshots (mShots)" off → force the featured-image provider.
		add_filter( 'adminkit/post_previews/provider', static function ( $provider ) {
			return self::get( 'post_previews_mshots' ) ? $provider : 'featured';
		} );
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
