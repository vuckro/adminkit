<?php
/**
 * Settings registry — the data layer behind the settings page.
 *
 * Exposes a typed getter, the schema of declared settings, and an
 * `inline_tokens()` helper that emits a `:root { … }` block mapping every
 * colour setting onto its `--ak-*` token. The admin UI lives in
 * `AdminKit_Settings_Page`; values land in the option `adminkit_settings`
 * (written by that UI) or via the `adminkit/setting/{key}` filter.
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
	 * Catalog registration is deferred to the `init` action because its
	 * labels call `__()`: loading a textdomain before `init` is flagged by
	 * WordPress 6.7+ (`_load_textdomain_just_in_time` doing-it-wrong).
	 *
	 * @return void
	 */
	public static function init() {
		// Feature toggles carry no translated labels (those live in the settings
		// page UI), so they register immediately rather than on `init` — modules
		// read these values at load / enqueue time, before the `init` action.
		self::register_feature_catalog();
		self::bind_modules();

		// NOTE: the design-system token layer is intentionally NOT wired for now.
		// The Design system tab is a static reference (no per-token settings, no
		// palette_mode, no inline `--ak-*` overrides). The machinery below
		// (register_color_catalog / register_size_catalog / apply_inline_tokens /
		// branded_surface_map) is kept dormant — re-enable by restoring:
		//   add_action( 'init', array( __CLASS__, 'register_color_catalog' ) );
		//   add_action( 'init', array( __CLASS__, 'register_size_catalog' ) );
		//   add_action( 'adminkit/tokens_enqueued', array( __CLASS__, 'apply_inline_tokens' ) );
		// plus the palette_mode registration.
	}

	/**
	 * Declare the colour settings the settings page exposes. Each maps a
	 * user-chosen hex onto a `--ak-*` token; an empty value emits nothing, so
	 * the token's CSS fallback chain (provider/Bricks → neutral) keeps winning
	 * — that's the "inherit" behaviour. `--ak-primary` cascades through
	 * color-mix to hovers, tints and focus rings, so it recolours most of the
	 * admin on its own.
	 *
	 * Runs on the `init` action (wired in self::init()) so its `__()` labels
	 * resolve after the textdomain is available.
	 *
	 * @return void
	 */
	public static function register_color_catalog() {
		foreach ( self::color_map() as $group ) {
			foreach ( $group['tokens'] as $t ) {
				$edit = isset( $t['edit'] ) ? $t['edit'] : 'none';
				if ( empty( $t['key'] ) || 'none' === $edit ) {
					continue;
				}
				if ( 'dual' === $edit ) {
					self::register( $t['key'], array(
						'type' => 'color', 'group' => 'colors', 'token' => $t['token'],
						'mode' => 'light', 'label' => $t['label'], 'sanitize' => 'sanitize_hex_color',
					) );
					self::register( $t['key'] . '_dark', array(
						'type' => 'color', 'group' => 'colors', 'token' => $t['token'],
						'mode' => 'dark', 'label' => $t['label'], 'sanitize' => 'sanitize_hex_color',
					) );
				} else { // agnostic — one value applied to both light + dark.
					self::register( $t['key'], array(
						'type' => 'color', 'group' => 'colors', 'token' => $t['token'],
						'mode' => 'agnostic', 'label' => $t['label'], 'sanitize' => 'sanitize_hex_color',
					) );
				}
			}
		}
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
	 * In v1 the Design system tab DISPLAYS this read-only (no per-token pickers);
	 * the whole palette is driven globally: the Neutral⇄Branded toggle remaps
	 * surfaces + borders onto the provider's neutral vs PRIMARY ramp (see
	 * branded_surface_map() + inline_tokens()), and Randomise writes the accent trio.
	 *
	 * Per token: `token` (--ak-* var), `bricks` (provider semantic it bridges, ''
	 * when AdminKit-own), `source` (the primitive it ultimately resolves from),
	 * `label`, optional `own`, optional `key` (setting id) and `edit`:
	 *   - 'agnostic' — one stored value, both modes. Only the accent trio
	 *                  (primary / on-accent / secondary), written by Randomise.
	 *   - 'none'     — display-only: every surface/border/text role (Branded is
	 *                  applied globally, not per token), the derived accent
	 *                  hover/subtle, state, overlay and status.
	 *
	 * Single source of truth: drives registration (above) and the UI payload
	 * (AdminKit_Settings_Page). Runs on `init` so `__()` resolves.
	 *
	 * @return array
	 */
	public static function color_map() {
		return array(
			array( 'group' => 'surface', 'label' => __( 'Surfaces', 'adminkit' ), 'desc' => __( 'Page and panel backgrounds — behind everything.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-bg',       'bricks' => '--background', 'source' => '--neutral-l-1', 'label' => __( 'Background', 'adminkit' ),  'key' => 'bg_color',       'edit' => 'none' ),
				array( 'token' => '--ak-surface',  'bricks' => '--surface',    'source' => '--neutral-l-2', 'label' => __( 'Surface', 'adminkit' ),     'key' => 'surface_color',  'edit' => 'none' ),
				array( 'token' => '--ak-elevated', 'bricks' => '--elevated',   'source' => '--neutral-l-3', 'label' => __( 'Elevated', 'adminkit' ),    'key' => 'elevated_color', 'edit' => 'none' ),
				array( 'token' => '--ak-input-bg', 'bricks' => '--input',       'source' => '--neutral-l-1', 'label' => __( 'Input field', 'adminkit' ), 'key' => 'input_bg_color', 'edit' => 'none' ),
			) ),
			array( 'group' => 'border', 'label' => __( 'Borders', 'adminkit' ), 'desc' => __( 'Outlines and dividers.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-border',        'bricks' => '--border',        'source' => '--neutral-l-4', 'label' => __( 'Border', 'adminkit' ),        'key' => 'border_color',        'edit' => 'none' ),
				array( 'token' => '--ak-border-strong', 'bricks' => '--border-strong', 'source' => '--neutral-l-5', 'label' => __( 'Border strong', 'adminkit' ), 'key' => 'border_strong_color', 'edit' => 'none' ),
			) ),
			array( 'group' => 'text', 'label' => __( 'Text', 'adminkit' ), 'desc' => __( 'Headings, body copy and captions.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-heading',    'bricks' => '--heading',    'source' => '--neutral-l-9', 'label' => __( 'Heading', 'adminkit' ),    'key' => 'heading_color',    'edit' => 'none' ),
				array( 'token' => '--ak-text',       'bricks' => '--text',       'source' => '--neutral-l-8', 'label' => __( 'Body text', 'adminkit' ),  'key' => 'text_color',       'edit' => 'none' ),
				array( 'token' => '--ak-text-muted', 'bricks' => '--text-muted', 'source' => '--neutral-l-7', 'label' => __( 'Muted text', 'adminkit' ), 'key' => 'text_muted_color', 'edit' => 'none' ),
			) ),
			array( 'group' => 'accent', 'label' => __( 'Accent', 'adminkit' ), 'desc' => __( 'Brand — buttons, links, highlights. Hover, subtle and focus derive from it automatically.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-primary',        'bricks' => '--accent',       'source' => '--primary',      'label' => __( 'Accent', 'adminkit' ),        'key' => 'primary_color',   'edit' => 'agnostic' ),
				array( 'token' => '--ak-primary-hover',  'bricks' => '--accent-hover',  'source' => '--primary-d-1',  'label' => __( 'Accent hover', 'adminkit' ),  'edit' => 'none' ),
				array( 'token' => '--ak-primary-subtle', 'bricks' => '--accent-subtle', 'source' => '--primary-l-9', 'label' => __( 'Accent subtle', 'adminkit' ), 'edit' => 'none' ),
				array( 'token' => '--ak-on-accent',      'bricks' => '--accent-on',     'source' => '--primary-d-9',  'label' => __( 'On accent', 'adminkit' ),     'key' => 'on_accent_color', 'edit' => 'agnostic' ),
				array( 'token' => '--ak-secondary',      'bricks' => '',               'source' => '--secondary',    'label' => __( 'Secondary', 'adminkit' ),     'key' => 'secondary_color', 'edit' => 'agnostic', 'own' => true ),
			) ),
			array( 'group' => 'state', 'label' => __( 'State', 'adminkit' ), 'desc' => __( 'Hover and focus feedback.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-hover-bg', 'bricks' => '',        'source' => '--neutral-t-2', 'label' => __( 'Hover', 'adminkit' ),      'edit' => 'none', 'own' => true ),
				array( 'token' => '--ak-focus',    'bricks' => '--focus', 'source' => '--primary-t-5', 'label' => __( 'Focus ring', 'adminkit' ), 'edit' => 'none' ),
			) ),
			array( 'group' => 'overlay', 'label' => __( 'Overlay', 'adminkit' ), 'desc' => __( 'Scrim behind modals and drawers.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-overlay', 'bricks' => '--overlay', 'source' => '--black-t-7', 'label' => __( 'Overlay', 'adminkit' ), 'edit' => 'none' ),
			) ),
			array( 'group' => 'status', 'label' => __( 'Status', 'adminkit' ), 'desc' => __( 'Info, success, warning and error — and their subtle fills — from the framework\'s notification roles.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-info',           'bricks' => '--info',           'source' => '--info',        'label' => __( 'Info', 'adminkit' ),           'edit' => 'none' ),
				array( 'token' => '--ak-info-subtle',    'bricks' => '--info-subtle',    'source' => '--info-l-9',    'label' => __( 'Info subtle', 'adminkit' ),    'edit' => 'none' ),
				array( 'token' => '--ak-success',        'bricks' => '--success',        'source' => '--success',     'label' => __( 'Success', 'adminkit' ),        'edit' => 'none' ),
				array( 'token' => '--ak-success-subtle', 'bricks' => '--success-subtle', 'source' => '--success-l-9', 'label' => __( 'Success subtle', 'adminkit' ), 'edit' => 'none' ),
				array( 'token' => '--ak-warning',        'bricks' => '--warning',        'source' => '--warning',     'label' => __( 'Warning', 'adminkit' ),        'edit' => 'none' ),
				array( 'token' => '--ak-warning-subtle', 'bricks' => '--warning-subtle', 'source' => '--warning-l-9', 'label' => __( 'Warning subtle', 'adminkit' ), 'edit' => 'none' ),
				array( 'token' => '--ak-error',          'bricks' => '--error',          'source' => '--error',       'label' => __( 'Error', 'adminkit' ),          'edit' => 'none' ),
				array( 'token' => '--ak-error-subtle',   'bricks' => '--error-subtle',   'source' => '--error-l-9',   'label' => __( 'Error subtle', 'adminkit' ),   'edit' => 'none' ),
			) ),
		);
	}

	/**
	 * The "Branded" palette mapping. Neutral mode = the surfaces/borders defined
	 * in tokens.css (the provider's --neutral-* ramp). Branded mode remaps those
	 * same tokens onto the provider's PRIMARY ramp (--primary-l-* in light,
	 * --primary-d-* in dark) so the whole admin picks up the brand tint — straight
	 * from the provider primitives, no computed colours. Text stays neutral.
	 *
	 * Each entry is [ primary-step, neutral-fallback ]; emitted as
	 * `var(<primary-step>, var(<neutral-fallback>))` so it degrades to the neutral
	 * value when the provider exposes no primary ramp. Consumed by inline_tokens()
	 * (admin-wide) and mirrored by the settings SPA live preview (boot_data).
	 *
	 * @return array{light:array<string,string[]>,dark:array<string,string[]>}
	 */
	public static function branded_surface_map() {
		return array(
			'light' => array(
				'--ak-bg'            => array( '--primary-l-10', '--neutral-l-1' ),
				'--ak-surface'       => array( '--primary-l-9',  '--neutral-l-2' ),
				'--ak-elevated'      => array( '--primary-l-8',  '--neutral-l-3' ),
				'--ak-input-bg'      => array( '--primary-l-10', '--neutral-l-1' ),
				'--ak-border'        => array( '--primary-l-7',  '--neutral-l-4' ),
				'--ak-border-strong' => array( '--primary-l-6',  '--neutral-l-5' ),
			),
			'dark' => array(
				'--ak-bg'            => array( '--primary-d-10', '--neutral-d-1' ),
				'--ak-surface'       => array( '--primary-d-9',  '--neutral-d-2' ),
				'--ak-elevated'      => array( '--primary-d-8',  '--neutral-d-3' ),
				'--ak-input-bg'      => array( '--primary-d-9',  '--neutral-d-3' ),
				'--ak-border'        => array( '--primary-d-7',  '--neutral-d-4' ),
				'--ak-border-strong' => array( '--primary-d-6',  '--neutral-d-5' ),
			),
		);
	}

	/**
	 * Read-only PRIMITIVE palette for the cascade reference strip — the raw
	 * scales the semantics resolve from. Detected from the provider (Bricks);
	 * never edited in AdminKit. Each family exposes its base + l-1…l-10 ramp as
	 * `var(--…)` so the strip shows the live provider colours.
	 *
	 * @return array
	 */
	public static function primitives() {
		$family = function ( $id, $label ) {
			$steps = array();
			for ( $i = 1; $i <= 10; $i++ ) {
				$steps[] = '--' . $id . '-l-' . $i;
			}
			return array( 'id' => $id, 'label' => $label, 'base' => '--' . $id, 'steps' => $steps );
		};
		return array(
			$family( 'neutral',   __( 'Neutral', 'adminkit' ) ),
			$family( 'primary',   __( 'Primary', 'adminkit' ) ),
			$family( 'secondary', __( 'Secondary', 'adminkit' ) ),
			$family( 'tertiary',  __( 'Tertiary', 'adminkit' ) ),
			$family( 'success',   __( 'Success', 'adminkit' ) ),
			$family( 'warning',   __( 'Warning', 'adminkit' ) ),
			$family( 'error',     __( 'Error', 'adminkit' ) ),
			$family( 'info',      __( 'Info', 'adminkit' ) ),
		);
	}

	/**
	 * Sizing tokens — radius only (the Design system tab drives these via three
	 * presets: None / Default / Rounded). Same `token` mechanism as colours, so
	 * `inline_tokens()` emits them; `sanitize_size` keeps them to a safe CSS
	 * length. Typography is intentionally left to the provider.
	 *
	 * @return array
	 */
	public static function size_map() {
		return array(
			array( 'group' => 'radius', 'label' => __( 'Radius', 'adminkit' ), 'desc' => __( 'Corner roundness.', 'adminkit' ), 'tokens' => array(
				array( 'token' => '--ak-radius-s', 'label' => __( 'Small', 'adminkit' ),  'key' => 'radius_s', 'placeholder' => '6px' ),
				array( 'token' => '--ak-radius-m', 'label' => __( 'Medium', 'adminkit' ), 'key' => 'radius_m', 'placeholder' => '10px' ),
			) ),
		);
	}

	/**
	 * Register the sizing settings (radius + type). Runs on `init` (labels live
	 * in size_map). Agnostic — one value applies in both modes.
	 *
	 * @return void
	 */
	public static function register_size_catalog() {
		foreach ( self::size_map() as $group ) {
			foreach ( $group['tokens'] as $t ) {
				self::register( $t['key'], array(
					'type' => 'size', 'group' => 'sizing', 'token' => $t['token'],
					'mode' => 'agnostic', 'label' => $t['label'], 'sanitize' => array( __CLASS__, 'sanitize_size' ),
				) );
			}
		}
	}

	/**
	 * Sanitise a CSS length (px/rem/em/%). A bare number becomes px. Anything
	 * else → '' (drop, inherit the default).
	 *
	 * @param mixed $v
	 * @return string
	 */
	public static function sanitize_size( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( is_numeric( $v ) ) {
			$v .= 'px';
		}
		return preg_match( '/^[0-9]+(\.[0-9]+)?(px|rem|em|%)$/', $v ) ? $v : '';
	}

	/**
	 * Sanitise the palette-mode UI flag — one of 'neutral' | 'branded'. Pure UI
	 * state (no token); the actual tints live in the surface/border/text keys.
	 *
	 * @param mixed $v
	 * @return string
	 */
	public static function sanitize_palette_mode( $v ) {
		return in_array( $v, array( 'neutral', 'branded' ), true ) ? $v : 'neutral';
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
	 * @param array  $args {
	 *     @type string        $type     Field kind: color | toggle | select | text. Default 'text'.
	 *     @type string        $group    UI group: brand | status | modules | … . Default 'general'.
	 *     @type string        $label    Human-readable field label.
	 *     @type string        $desc     Help text shown under the field.
	 *     @type string|null   $token    For colour settings, the `--ak-*` var this overrides.
	 *     @type string        $mode     Which mode the override targets: 'agnostic' (both
	 *                                   light + dark), 'light', or 'dark'. Default 'agnostic'.
	 *     @type array|null    $choices  value => label map, for `select`.
	 *     @type mixed         $default  Returned when no stored value exists.
	 *     @type callable|null $sanitize Save-time sanitiser (also re-applied before inlining).
	 * }
	 * @return void
	 */
	public static function register( $key, array $args = array() ) {
		$args = wp_parse_args( $args, array(
			'type'     => 'text',
			'group'    => 'general',
			'label'    => '',
			'desc'     => '',
			'token'    => null,
			'mode'     => 'agnostic',
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
	 * Snapshot of the registered schema. Future settings UI reads this
	 * to know which fields to render.
	 *
	 * @return array<string, array>
	 */
	public static function schema() {
		return self::$schema;
	}

	/**
	 * Build the inline CSS injected after the tokens stylesheet so the user's
	 * chosen colours override the matching `--ak-*` tokens. Walks every
	 * registered setting that declares a `token`; a setting left empty emits
	 * nothing, so the token's CSS fallback chain (provider/Bricks → neutral)
	 * keeps winning. Returns '' when nothing is set (a no-op inline style).
	 *
	 * Overrides are bucketed by the setting's `mode` and emitted as up to two
	 * rules — `:root { … }` (light) and `:root[data-adminkit-theme="dark"] { … }`
	 * (dark). 'agnostic' values go in BOTH buckets (so the brand holds in dark,
	 * which redeclares `--ak-primary` at higher specificity, and cascades to its
	 * tints via color-mix). 'light' / 'dark' values go in their own bucket only,
	 * so a custom surface/text/border can differ per mode — mirroring the
	 * provider's light/dark palette.
	 *
	 * Wired by `apply_inline_tokens()` on `adminkit/tokens_enqueued`.
	 *
	 * @return string
	 */
	public static function inline_tokens() {
		$light = '';
		$dark  = '';
		foreach ( self::$schema as $key => $args ) {
			if ( empty( $args['token'] ) ) {
				continue;
			}
			$value = self::get( $key );
			if ( empty( $value ) || ! is_string( $value ) ) {
				continue;
			}
			// Re-sanitise before inlining — a value set via the adminkit/setting/{key}
			// filter or written directly to the option bypasses the save-time
			// sanitiser, so it can't be assumed CSS-safe here.
			if ( is_callable( $args['sanitize'] ) ) {
				$value = call_user_func( $args['sanitize'], $value );
			}
			if ( empty( $value ) ) {
				continue;
			}
			$decl = sprintf( '%s:%s;', $args['token'], $value );
			$mode = isset( $args['mode'] ) ? $args['mode'] : 'agnostic';
			if ( 'dark' === $mode ) {
				$dark .= $decl;
			} elseif ( 'light' === $mode ) {
				$light .= $decl;
			} else { // agnostic — both modes.
				$light .= $decl;
				$dark  .= $decl;
			}
		}
		// Branded palette: remap surfaces + borders onto the provider PRIMARY ramp
		// (Neutral = the tokens.css defaults). Appended last so it wins.
		if ( 'branded' === self::get( 'palette_mode' ) ) {
			$map = self::branded_surface_map();
			foreach ( $map['light'] as $token => $ref ) {
				$light .= sprintf( '%s:var(%s, var(%s));', $token, $ref[0], $ref[1] );
			}
			foreach ( $map['dark'] as $token => $ref ) {
				$dark .= sprintf( '%s:var(%s, var(%s));', $token, $ref[0], $ref[1] );
			}
		}
		$css = '';
		if ( '' !== $light ) {
			$css .= sprintf( ':root{%s}', $light );
		}
		if ( '' !== $dark ) {
			$css .= sprintf( ':root[data-adminkit-theme="dark"]{%s}', $dark );
		}
		return $css;
	}
}
