<?php
/**
 * Settings page — the admin UI for AdminKit.
 *
 * A top-level "AdminKit" menu that mounts a small, build-free single-page app
 * (vanilla JS in `assets/js/settings.js`). The PHP side only:
 *   - registers the menu + screen,
 *   - enqueues the SPA assets and hands it its data via `window.AdminKitData`,
 *   - exposes one REST route (`adminkit/v1/settings`) the SPA POSTs to.
 *
 * The data is built from the settings registry: the semantic token taxonomy
 * (rendered read-only on the Tokens tab), the feature toggles, and the detected
 * integrations. Saving runs each known field through its registered `sanitize`
 * callback and persists only registered keys.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Settings_Page {

	/** Top-level menu + settings-page slug (screen hook: toplevel_page_adminkit). */
	const SLUG = 'adminkit';

	/** Asset handle shared by the SPA's script + style. */
	const HANDLE = 'adminkit-settings';

	/** REST namespace + route the SPA saves to. */
	const REST_NS    = 'adminkit/v1';
	const REST_ROUTE = '/settings';

	/**
	 * Hook the menu, the SPA assets and the REST route. All admin/REST-only.
	 * Called once from the orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'adminkit/integration_enabled', array( __CLASS__, 'gate_integration' ), 10, 2 );
	}

	/**
	 * Register the top-level AdminKit menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
			__( 'AdminKit', 'adminkit' ),
			__( 'AdminKit', 'adminkit' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-admin-customizer',
			81
		);
	}

	/**
	 * The SPA mount point. Everything else is rendered client-side.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap">'
			. '<h1 class="screen-reader-text">' . esc_html__( 'AdminKit', 'adminkit' ) . '</h1>'
			. '<hr class="wp-header-end">'
			. '<div id="adminkit-app" class="adminkit-app" aria-busy="true">'
			. '<noscript><p>' . esc_html__( 'AdminKit settings require JavaScript.', 'adminkit' ) . '</p></noscript>'
			. '</div></div>';
	}

	/**
	 * Enqueue the SPA assets (only on our screen) and hand the app its data.
	 * The style depends on `adminkit-tokens` so `var(--ak-*)` resolves; the
	 * script depends on `wp-api-fetch` (which also wires the REST nonce).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$css = 'assets/css/settings.css';
		$js  = 'assets/js/settings.js';

		wp_enqueue_style( self::HANDLE, ADMINKIT_URL . $css, array( AdminKit_Assets::TOKENS_HANDLE ), self::ver( $css ) );
		wp_enqueue_script( self::HANDLE, ADMINKIT_URL . $js, array( 'wp-api-fetch' ), self::ver( $js ), true );
		wp_add_inline_script( self::HANDLE, 'window.AdminKitData=' . wp_json_encode( self::boot_data() ) . ';', 'before' );
	}

	/**
	 * Register the save route. Capability is enforced in the permission
	 * callback; `wp-api-fetch` supplies the nonce automatically.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_save' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Persist posted values. Known keys are sanitised + replaced (so a cleared
	 * colour is removed → inherit); any unknown keys already in the option are
	 * preserved untouched.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_save( $request ) {
		// Reset to defaults: drop the whole option so every setting falls back
		// to its registered default (colours inherit, toggles on).
		if ( $request->get_param( 'reset' ) ) {
			delete_option( AdminKit_Settings::OPTION_KEY );
			return rest_ensure_response( array( 'ok' => true, 'reset' => true ) );
		}
		self::register_integration_toggles(); // so per-integration keys persist
		$values = $request->get_param( 'values' );
		$clean  = self::sanitize( is_array( $values ) ? $values : array() );

		$existing = get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$known     = array_keys( AdminKit_Settings::schema() );
		$preserved = array_diff_key( $existing, array_flip( $known ) );
		$final     = array_merge( $preserved, $clean );

		update_option( AdminKit_Settings::OPTION_KEY, $final );

		return rest_ensure_response( array( 'ok' => true, 'values' => $final ) );
	}

	/**
	 * Sanitise a values array against the schema: keep only known keys, run each
	 * through its registered `sanitize` callback, and drop empty values so an
	 * unset field falls back to the token's CSS chain ("inherit").
	 *
	 * @param array $input
	 * @return array
	 */
	public static function sanitize( $input ) {
		$clean  = array();
		$schema = AdminKit_Settings::schema();

		foreach ( $schema as $key => $args ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( is_callable( $args['sanitize'] ) ) {
				$value = call_user_func( $args['sanitize'], $value );
			} elseif ( is_string( $value ) ) {
				$value = sanitize_text_field( $value );
			}
			if ( '' === $value || null === $value ) {
				continue; // Empty = inherit; don't persist a blank.
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/**
	 * Build the payload handed to the SPA via `window.AdminKitData`.
	 *
	 * @return array
	 */
	private static function boot_data() {
		$stored = get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$colors = array();
		foreach ( AdminKit_Settings::color_map() as $group ) {
			$tokens = array();
			foreach ( $group['tokens'] as $t ) {
				$tokens[] = array(
					'token'  => $t['token'],
					'label'  => $t['label'],
					'bricks' => isset( $t['bricks'] ) ? $t['bricks'] : '',
					'source' => isset( $t['source'] ) ? $t['source'] : '',
					'own'    => ! empty( $t['own'] ),
				);
			}
			$colors[] = array(
				'group'  => $group['group'],
				'label'  => $group['label'],
				'desc'   => isset( $group['desc'] ) ? $group['desc'] : '',
				'tokens' => $tokens,
			);
		}

		$features = array();
		foreach ( self::feature_descriptors() as $f ) {
			$features[] = array(
				'key'    => $f['key'],
				'label'  => $f['label'],
				'desc'   => $f['desc'],
				'parent' => isset( $f['parent'] ) ? $f['parent'] : '',
				'value'  => (bool) AdminKit_Settings::get( $f['key'] ),
			);
		}

		return array(
			'route'        => self::REST_NS . self::REST_ROUTE,
			'dashboard'    => self::dashboard( $features, $stored ),
			'colors'       => $colors,
			'providers'    => self::providers(),
			'features'     => $features,
			'i18n'         => array(
				'dashboard'         => __( 'Dashboard', 'adminkit' ),
				'design'            => __( 'Tokens', 'adminkit' ),
				'features'          => __( 'Features', 'adminkit' ),
				'soon'              => __( 'Coming soon', 'adminkit' ),
				'own'               => __( 'AdminKit', 'adminkit' ),
				'ownHint'           => __( 'AdminKit-defined role (no provider equivalent).', 'adminkit' ),
				'featuresIntro'     => __( 'Turn AdminKit modules on or off.', 'adminkit' ),
				'save'              => __( 'Save changes', 'adminkit' ),
				'saving'            => __( 'Saving…', 'adminkit' ),
				'saved'             => __( 'Saved', 'adminkit' ),
				'error'             => __( 'Could not save', 'adminkit' ),
				'light'             => __( 'Light', 'adminkit' ),
				'dark'              => __( 'Dark', 'adminkit' ),
				'unsaved'           => __( 'Unsaved changes', 'adminkit' ),
			),
		);
	}

	/**
	 * Overview shown on the Dashboard tab. Data-driven + filterable so cards can
	 * be added as the plugin grows (the whole point: easy to iterate on). Each
	 * card: `label`, `value`, optional `hint`, optional `swatch` (an `--ak-*`
	 * token to preview) and optional `tab` (turns the card into a shortcut).
	 *
	 * @param array $features Built feature rows (each with a bool `value`).
	 * @param array $stored   Raw stored options.
	 * @return array
	 */
	private static function dashboard( $features, $stored ) {
		$features_on = 0;
		foreach ( $features as $f ) {
			if ( ! empty( $f['value'] ) ) {
				$features_on++;
			}
		}
		$primary = ( isset( $stored['primary_color'] ) && $stored['primary_color'] ) ? (string) $stored['primary_color'] : '';

		$cards = array(
			array(
				'label'  => __( 'Tokens', 'adminkit' ),
				'value'  => self::active_provider_label(),
				'hint'   => $primary ? sprintf( __( 'accent %s', 'adminkit' ), $primary ) : __( 'accent inherited', 'adminkit' ),
				'swatch' => '--ak-primary',
				'tab'    => 'apparence',
			),
			array(
				'label' => __( 'Features', 'adminkit' ),
				'value' => (string) $features_on,
				'hint'  => sprintf( __( 'of %d modules on', 'adminkit' ), count( $features ) ),
				'icon'  => 'features',
				'tab'   => 'features',
			),
		);

		/**
		 * Filter the AdminKit dashboard overview. Add cards by appending to
		 * `$data['cards']`, or upcoming items to `$data['next']`.
		 *
		 * @param array $data { intro, version, cards[], next[] }
		 */
		return apply_filters( 'adminkit/dashboard', array(
			'intro'         => __( 'A quick overview of your AdminKit setup.', 'adminkit' ),
			'version'       => 'v' . ADMINKIT_VERSION,
			'overviewLabel' => __( 'Overview', 'adminkit' ),
			'nextLabel'     => __( 'What\'s next', 'adminkit' ),
			'cards'         => $cards,
			'next'          => array(
				array( 'label' => __( 'Connect more frameworks', 'adminkit' ), 'hint' => __( 'Automatic.css, Core Framework, Frames, Advanced Themer — beyond Bricks', 'adminkit' ) ),
				array( 'label' => __( 'Custom dashboard widgets', 'adminkit' ), 'hint' => __( 'Replace the WP home screen: quick actions, site status, recent activity, agency contact', 'adminkit' ) ),
				array( 'label' => __( 'Brand logo & white-label', 'adminkit' ), 'hint' => __( 'Your logo on login, admin bar and footer; hide WordPress branding', 'adminkit' ) ),
				array( 'label' => __( 'Login experience', 'adminkit' ),         'hint' => __( 'Full login styling + “Remember me” as a toggle', 'adminkit' ) ),
				array( 'label' => __( 'Dark-mode behaviour', 'adminkit' ),      'hint' => __( 'Default mode: auto, light, dark or follow the system', 'adminkit' ) ),
				array( 'label' => __( 'Import / export settings', 'adminkit' ), 'hint' => __( 'Clone an AdminKit setup across client sites in one file', 'adminkit' ) ),
				array( 'label' => __( 'Roles & access', 'adminkit' ),           'hint' => __( 'Choose which roles get the skin and who may edit it', 'adminkit' ) ),
				array( 'label' => __( 'Contrast & accessibility checks', 'adminkit' ), 'hint' => __( 'Warn when a chosen colour fails legibility', 'adminkit' ) ),
			),
		) );
	}

	/**
	 * Colour providers AdminKit can inherit its palette from. Data-driven +
	 * filterable (`adminkit/providers`) so new token providers slot in without
	 * touching the UI. Each: `id`, `label`, `status` ('available' | 'soon'),
	 * `detected` (host present). Only Bricks ships today; the rest are
	 * placeholders ("coming soon"). `custom` = map every colour by hand.
	 *
	 * @return array
	 */
	private static function providers() {
		// "Connected" means the host is present AND the integration is enabled —
		// so disabling Bricks in the Integrations tab reflects everywhere.
		$bricks = class_exists( 'AdminKit_Integration_Bricks' )
			&& AdminKit_Integration_Bricks::is_active()
			&& (bool) apply_filters( 'adminkit/integration_enabled', true, 'bricks' );
		return apply_filters( 'adminkit/providers', array(
			array( 'id' => 'bricks',          'label' => 'Bricks Vanilla',                           'status' => 'available', 'detected' => $bricks ),
			array( 'id' => 'acss',            'label' => 'Automatic.css',                    'status' => 'soon',      'detected' => false ),
			array( 'id' => 'core-framework',  'label' => 'Core Framework',                   'status' => 'soon',      'detected' => false ),
			array( 'id' => 'advanced-themer', 'label' => 'Advanced Themer',                  'status' => 'soon',      'detected' => false ),
			array( 'id' => 'frames',          'label' => 'Frames',                           'status' => 'soon',      'detected' => false ),
		) );
	}

	/**
	 * Label of the active provider (a detected, available one), else "Default".
	 * Names where an un-overridden colour comes from, on the dashboard.
	 *
	 * @return string
	 */
	private static function active_provider_label() {
		foreach ( self::providers() as $p ) {
			if ( 'available' === $p['status'] && ! empty( $p['detected'] ) ) {
				return $p['label'];
			}
		}
		return __( 'Default', 'adminkit' );
	}

	/**
	 * Feature toggles shown on the Features tab, in display order. Keys match
	 * settings registered in AdminKit_Settings / AdminKit_Post_Previews.
	 *
	 * @return array
	 */
	private static function feature_descriptors() {
		return array(
			array( 'key' => 'post_previews_enabled', 'label' => __( 'Post previews', 'adminkit' ),             'desc' => __( 'Screenshot thumbnail column in post-type list tables.', 'adminkit' ) ),
			array( 'key' => 'post_previews_mshots',  'label' => __( 'Live screenshots (mShots)', 'adminkit' ), 'desc' => __( 'Use WordPress.com mShots. Off = featured image only (no external calls).', 'adminkit' ), 'parent' => 'post_previews_enabled' ),
			array( 'key' => 'theme_toggle_enabled',  'label' => __( 'Light / dark toggle', 'adminkit' ),       'desc' => __( 'Show the light/dark switch in the admin bar.', 'adminkit' ) ),
			array( 'key' => 'module_login_enabled',  'label' => __( 'Login screen', 'adminkit' ),              'desc' => __( 'Style the wp-login.php screen.', 'adminkit' ) ),
			array( 'key' => 'module_editor_enabled', 'label' => __( 'Block editor', 'adminkit' ),              'desc' => __( 'Style the Gutenberg editor.', 'adminkit' ) ),
		);
	}

	/**
	 * Discover integrations the way the orchestrator does (one folder each).
	 * Returns specs: slug, human label, type ('plugin' | 'theme'), class. Type
	 * is inferred from whether a theme with that slug is installed — no
	 * per-integration edits needed; an integration may expose a static `type()`
	 * to override. Memoised per request.
	 *
	 * @return array<int, array>
	 */
	private static function integration_specs() {
		static $specs = null;
		if ( null !== $specs ) {
			return $specs;
		}
		$specs  = array();
		$labels = array(
			'acf'               => 'Advanced Custom Fields (ACF)',
			'admin-menu-editor' => 'Admin Menu Editor',
			'bricks'            => 'Bricks',
			'fluent-booking'    => 'FluentBooking',
			'fluent-smtp'       => 'FluentSMTP',
			'fluentform'        => 'Fluent Forms',
			'flying-press'      => 'FlyingPress',
			'gutenberg'         => 'Gutenberg',
			'happyfiles'        => 'HappyFiles',
			'loco-translate'    => 'Loco Translate',
			'query-monitor'     => 'Query Monitor',
			'slim-seo'          => 'Slim SEO',
			'woocommerce'       => 'WooCommerce',
			'wp-migrate-db-pro' => 'WP Migrate DB Pro',
			'wpcode'            => 'WPCode',
		);
		$files = glob( ADMINKIT_PATH . 'inc/integrations/*/*/class-*.php' );
		if ( ! $files ) {
			return $specs;
		}
		foreach ( $files as $file ) {
			$slug  = substr( basename( $file, '.php' ), strlen( 'class-' ) );
			$class = 'AdminKit_Integration_' . str_replace( '-', '_', ucwords( $slug, '-' ) );
			if ( ! class_exists( $class ) || ! method_exists( $class, 'is_active' ) ) {
				continue;
			}
			if ( method_exists( $class, 'type' ) ) {
				$type = ( 'theme' === call_user_func( array( $class, 'type' ) ) ) ? 'theme' : 'plugin';
			} else {
				$type = wp_get_theme( $slug )->exists() ? 'theme' : 'plugin';
			}
			$specs[] = array(
				'slug'  => $slug,
				'label' => isset( $labels[ $slug ] ) ? $labels[ $slug ] : ucwords( str_replace( '-', ' ', $slug ) ),
				'type'  => $type,
				'class' => $class,
			);
		}
		return $specs;
	}

	/**
	 * Register an on/off toggle per integration so the UI can disable one in
	 * case of a conflict. Idempotent; called where the schema is needed (UI +
	 * save). Default ON.
	 *
	 * @return void
	 */
	public static function register_integration_toggles() {
		foreach ( self::integration_specs() as $s ) {
			AdminKit_Settings::register( 'integration_' . $s['slug'] . '_enabled', array(
				'type'     => 'toggle',
				'group'    => 'integrations',
				'default'  => true,
				'sanitize' => 'rest_sanitize_boolean',
			) );
		}
	}

	/**
	 * Gate bound to `adminkit/integration_enabled`: an integration runs unless
	 * the user turned it off. Reads the stored value directly so it works even
	 * before the toggle schema is registered (e.g. front-end requests).
	 *
	 * @param bool   $enabled
	 * @param string $slug
	 * @return bool
	 */
	public static function gate_integration( $enabled, $slug ) {
		$v = AdminKit_Settings::get( 'integration_' . $slug . '_enabled' );
		return ( null === $v ) ? $enabled : (bool) $v;
	}

	/**
	 * Cache-busting version: file mtime, or the plugin version as a fallback.
	 *
	 * @param string $rel Path relative to the plugin root.
	 * @return string
	 */
	private static function ver( $rel ) {
		$path = ADMINKIT_PATH . $rel;
		return file_exists( $path ) ? (string) filemtime( $path ) : ADMINKIT_VERSION;
	}
}
