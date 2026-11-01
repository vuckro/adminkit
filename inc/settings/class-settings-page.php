<?php
/**
 * Settings page — the admin UI for AdminKit.
 *
 * Mounts AdminKit as a TOP-LEVEL admin menu entry (sibling of Plugins /
 * Users / Tools / Settings) that hosts the SPA. The PHP side here only:
 *   - registers the menu + renders an empty `<div id="adminkit-app">`
 *     host that `settings.js` builds into,
 *   - enqueues the SPA assets on that screen + hands the app its data via
 *     `window.AdminKitData`,
 *   - exposes one REST route (`adminkit/v1/settings`) the SPA POSTs to.
 *
 * The data is built from the settings registry: the feature toggles and the
 * detected integrations. Saving runs each known field through its registered
 * `sanitize` callback and persists only registered keys.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-settings-i18n.php';

class AdminKit_Settings_Page {

	/** `?page=` slug for the top-level menu + the `toplevel_page_{slug}` screen id. */
	const SLUG = 'adminkit';

	/** Asset handle shared by the SPA's script + style. */
	const HANDLE = 'adminkit-settings';

	/** Native WordPress page hooks mapped to their content pane. */
	private static $page_tabs = array();

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
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		AdminKit_Settings_Gate::init();
	}

	/**
	 * Register AdminKit as a TOP-LEVEL admin menu entry — same level as
	 * Plugins / Users / Tools / Settings, not nested under Settings. The
	 * screen hook ends up as `toplevel_page_adminkit`; the enqueue gate
	 * below matches that.
	 *
	 * Position 81 = right after Settings (which sits at 80). Icon is the
	 * built-in dashicons palette-and-brush so no asset to ship.
	 *
	 * @return void
	 */
	public static function add_submenu() {
		$root_hook = add_menu_page(
			'AdminKit',
			'AdminKit',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-customizer',
			81
		);
		self::$page_tabs[ $root_hook ] = 'overview';
		$pages = array(
			self::SLUG => array( 'overview', __( 'Overview', 'adminkit' ) ),
			'adminkit-features' => array( 'features', __( 'Features', 'adminkit' ) ),
			'adminkit-design' => array( 'brand', __( 'Design system', 'adminkit' ) ),
			'adminkit-navigation' => array( 'navigation', __( 'Navigation', 'adminkit' ) ),
			'adminkit-configuration' => array( 'configuration', __( 'Configuration', 'adminkit' ) ),
		);
		foreach ( $pages as $slug => $page ) {
			$hook = add_submenu_page( self::SLUG, $page[1] . ' — AdminKit', $page[1], 'manage_options', $slug, array( __CLASS__, 'render_page' ) );
			self::$page_tabs[ $hook ] = $page[0];
		}

	}

	/**
	 * Render the page shell. The SPA owns the inside — we just print a host
	 * element (`<div id="adminkit-app">`) plus a heading WP screen-reader users
	 * expect on every admin page. `aria-busy` flips to `false` once the JS
	 * finishes its first paint.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::render_host( 'main' );
	}

	/**
	 * Print the SPA host for a given screen. Every AdminKit admin surface (the
	 * Settings SPA, the Menu editor, the Toolbar editor) mounts the SAME
	 * `settings.js` engine into this host; the `data-screen` attribute tells the
	 * engine which surface to build. Modules call this from their own page
	 * callbacks, so the shell markup lives in exactly one place.
	 *
	 * @param string $screen 'main' | 'menu' | 'toolbar'.
	 * @return void
	 */
	public static function render_host( $screen ) {
		$screen = preg_replace( '/[^a-z0-9_-]/', '', (string) $screen );
		?>
		<div class="wrap">
			<h1 class="screen-reader-text">AdminKit</h1>
			<div id="adminkit-app" class="adminkit-app" data-screen="<?php echo esc_attr( $screen ); ?>" aria-busy="true"></div>
		</div>
		<?php
	}

	/**
	 * Whether a WordPress `$hook_suffix` belongs to one of AdminKit's 5 native
	 * settings screens (toplevel + 4 submenu pages).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return bool
	 */
	public static function is_settings_hook( $hook ) {
		$hook = (string) $hook;
		if ( isset( self::$page_tabs[ $hook ] ) || 'toplevel_page_' . self::SLUG === $hook ) {
			return true;
		}
		foreach ( array( 'adminkit-features', 'adminkit-design', 'adminkit-navigation', 'adminkit-configuration' ) as $slug ) {
			if ( self::SLUG . '_page_' . $slug === $hook ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enqueue the SPA assets on the AdminKit submenu screen only, and hand
	 * the app its data. The style depends on `adminkit-tokens` so
	 * `var(--ak-*)` resolves; the script depends on `wp-api-fetch` (which
	 * also wires the REST nonce).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( ! self::is_settings_hook( $hook ) ) {
			return;
		}
		$initial_tab = isset( self::$page_tabs[ $hook ] ) ? self::$page_tabs[ $hook ] : 'overview';
		$data        = self::boot_data( 'main', $initial_tab );
		$data['initialTab'] = $initial_tab;
		$titles = array( 'overview' => __( 'Overview', 'adminkit' ), 'features' => __( 'Features', 'adminkit' ), 'brand' => __( 'Design system', 'adminkit' ), 'navigation' => __( 'Navigation', 'adminkit' ), 'configuration' => __( 'Configuration', 'adminkit' ) );
		$data['pageTitle'] = $titles[ $initial_tab ];
		$data['pages'] = array();
		foreach ( array( 'overview' => 'adminkit', 'features' => 'adminkit-features', 'brand' => 'adminkit-design', 'navigation' => 'adminkit-navigation', 'configuration' => 'adminkit-configuration' ) as $tab => $slug ) {
			$data['pages'][ $tab ] = admin_url( 'admin.php?page=' . $slug );
		}
		self::enqueue_app( $data );
	}

	/**
	 * Enqueue the small host plus the current screen's script and styles; hand
	 * it a boot payload. Every surface module (Settings, Menu, Toolbar) calls
	 * this from its own enqueue hook, passing the data ITS screen needs — so each
	 * page ships only its own slice (no menu snapshot on the main settings page, for example.).
	 *
	 * The style depends on `adminkit-tokens` so `var(--ak-*)` resolves; the script
	 * depends on `wp-api-fetch` (which wires the REST nonce). The shared HANDLE is
	 * safe because only one AdminKit screen is ever on-page at a time.
	 *
	 * @param array $data Boot payload, already screen-scoped.
	 * @return void
	 */
	public static function enqueue_app( array $data ) {
		$screen = isset( $data['screen'] ) ? $data['screen'] : 'main';
		$module = in_array( $screen, array( 'menu', 'toolbar' ), true ) ? 'navigation' : $screen;
		if ( ! in_array( $module, array( 'main', 'navigation' ), true ) ) {
			return;
		}
		$css = 'assets/css/settings.css';
		$screen_css = 'assets/css/settings/' . $module . '.css';
		$js = 'assets/js/settings.js';
		$screen_js = 'assets/js/settings/' . $module . '.js';
		$screen_handle = self::HANDLE . '-' . $module;
		wp_enqueue_style( self::HANDLE, ADMINKIT_URL . $css, array( AdminKit_Assets::TOKENS_HANDLE ), self::ver( $css ) );
		wp_enqueue_style( $screen_handle, ADMINKIT_URL . $screen_css, array( self::HANDLE ), self::ver( $screen_css ) );
		$pages_css = 'assets/css/settings/pages.css';
		wp_enqueue_style( self::HANDLE . '-pages', ADMINKIT_URL . $pages_css, array( $screen_handle ), self::ver( $pages_css ) );
		// The main screen's tabs live in their own files (assets/js/settings/<part>.js).
		// When `pages` is populated (5 native WordPress submenu pages), load only the
		// factories needed by `initialTab`; when `pages` is null (single-host mode), load all.
		$paged_tab  = ( 'main' === $module && ! empty( $data['pages'] ) && ! empty( $data['initialTab'] ) ) ? (string) $data['initialTab'] : '';
		$needs_nav  = ( 'navigation' === $module ) || ( '' === $paged_tab || 'navigation' === $paged_tab );
		$deps       = array( 'wp-api-fetch' );
		$navigation_deps = array();
		if ( $needs_nav ) {
			foreach ( array( 'controls', 'menu-model', 'menu', 'toolbar' ) as $part ) {
				$part_js = 'assets/js/settings/navigation-' . $part . '.js';
				$part_handle = self::HANDLE . '-navigation-' . $part;
				if ( 'controls' === $part || 'menu-model' === $part ) {
					$part_deps = array();
				} elseif ( 'menu' === $part ) {
					$part_deps = array( self::HANDLE . '-navigation-controls', self::HANDLE . '-navigation-menu-model' );
				} else {
					$part_deps = array( self::HANDLE . '-navigation-controls' );
				}
				wp_enqueue_script( $part_handle, ADMINKIT_URL . $part_js, $part_deps, self::ver( $part_js ), true );
				if ( 'menu-model' !== $part ) {
					$navigation_deps[] = $part_handle;
				}
			}
		}
		if ( 'navigation' === $module ) {
			$deps = array_merge( $deps, $navigation_deps );
		}
		if ( 'main' === $module ) {
			if ( $needs_nav ) {
				$nav_css = 'assets/css/settings/navigation.css';
				wp_enqueue_style( self::HANDLE . '-navigation', ADMINKIT_URL . $nav_css, array( self::HANDLE ), self::ver( $nav_css ) );
			}
			$parts_for_tab = array(
				'overview'      => array(),
				'features'      => array( 'features' ),
				'brand'         => array( 'design', 'plugins' ),
				'navigation'    => array( 'navigation' ),
				'configuration' => array(),
			);
			$main_parts = ( '' !== $paged_tab && isset( $parts_for_tab[ $paged_tab ] ) )
				? $parts_for_tab[ $paged_tab ]
				: array( 'design', 'features', 'plugins', 'navigation' );
			foreach ( $main_parts as $part ) {
				if ( 'design' === $part ) {
					$colors_js     = 'assets/js/settings/design-colors.js';
					$colors_handle = $screen_handle . '-design-colors';
					wp_enqueue_script( $colors_handle, ADMINKIT_URL . $colors_js, array(), self::ver( $colors_js ), true );
					$part_deps = array( $colors_handle );
				} elseif ( 'navigation' === $part ) {
					$part_deps = $navigation_deps;
				} else {
					$part_deps = array();
				}
				$part_js = 'assets/js/settings/' . $part . '.js';
				wp_enqueue_script( $screen_handle . '-' . $part, ADMINKIT_URL . $part_js, $part_deps, self::ver( $part_js ), true );
				$deps[] = $screen_handle . '-' . $part;
			}
		}
		wp_enqueue_script( $screen_handle, ADMINKIT_URL . $screen_js, $deps, self::ver( $screen_js ), true );
		wp_enqueue_script( self::HANDLE, ADMINKIT_URL . $js, array( $screen_handle ), self::ver( $js ), true );
		wp_add_inline_script( self::HANDLE, 'window.AdminKitData=' . wp_json_encode( $data ) . ';', 'before' );
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
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save( $request ) {
		$values = $request->get_param( 'values' );
		if ( ! is_array( $values ) ) {
			return AdminKit_Storage::error( 'adminkit_invalid_values', 400 );
		}

		foreach ( array_intersect_key( AdminKit_Settings::schema(), $values ) as $key => $args ) {
			$value = $values[ $key ];
			$type = $args['type'];
			$valid = in_array( $type, array( 'array', 'object' ), true ) ? is_array( $value )
				: ( 'toggle' === $type ? in_array( $value, array( true, false, 0, 1, '0', '1', 'true', 'false' ), true ) : is_string( $value ) );
			if ( ! $valid ) {
				return AdminKit_Storage::error( 'adminkit_invalid_values', 400 );
			}
		}

		if ( isset( $values['brand_accent'] ) && '' !== $values['brand_accent'] && ! sanitize_hex_color( $values['brand_accent'] ) ) {
			return AdminKit_Storage::error( 'adminkit_invalid_values', 400 );
		}
		$clean = self::sanitize( $values );

		$existing = get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		// Only the keys the client posted are replaced (an empty one is dropped →
		// inherit). Anything not posted — e.g. `logo_url`, edited on Settings →
		// General — keeps its stored value.
		$posted = array_intersect_key( $values, AdminKit_Settings::schema() );
		$final  = array_merge( array_diff_key( $existing, $posted ), $clean );

		$saved = AdminKit_Storage::save_option( AdminKit_Settings::OPTION_KEY, $final, true );
		if ( is_wp_error( $saved ) ) { return $saved; }

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
	 * @param string      $screen      Surface screen (`main` | `menu` | `toolbar`).
	 * @param string|null $initial_tab Optional active native submenu tab (`overview` | `features` | `brand` | `navigation` | `configuration`).
	 * @return array
	 */
	public static function boot_data( $screen = 'main', $initial_tab = null ) {
		$is_main    = ( 'main' === $screen );
		$is_menu    = ( 'menu' === $screen );
		$is_toolbar = ( 'toolbar' === $screen );
		$needs_plugins = $is_main && ( null === $initial_tab || 'brand' === $initial_tab );
		$needs_nav     = $is_menu || $is_toolbar || ( $is_main && ( null === $initial_tab || 'navigation' === $initial_tab ) );
		$needs_features = $is_main && ( null === $initial_tab || 'navigation' !== $initial_tab );

		// Brand / Features / Plugins data — only compute each expensive slice when the
		// target submenu page (or single-host SPA mode where $initial_tab is null) uses it.
		$features     = array();
		$integrations = array();
		if ( $needs_features ) {
			$schema = AdminKit_Settings::schema();
			foreach ( AdminKit_Settings_Catalog::features() as $f ) {
				$options = array();
				foreach ( isset( $f['options'] ) ? $f['options'] : array() as $option ) {
					// Only registered boolean settings may become nested controls.
					if ( ! isset( $schema[ $option['key'] ] ) || 'toggle' !== $schema[ $option['key'] ]['type'] ) {
						continue;
					}
					$options[] = array(
						'key' => $option['key'],
						'label' => $option['label'],
						'onLabel' => isset( $option['onLabel'] ) ? $option['onLabel'] : '',
						'offLabel' => isset( $option['offLabel'] ) ? $option['offLabel'] : '',
						'desc' => isset( $option['desc'] ) ? $option['desc'] : '',
						'value' => (bool) AdminKit_Settings::get( $option['key'] ),
						'default' => (bool) $schema[ $option['key'] ]['default'],
					);
				}
				$features[] = array(
					'key'     => $f['key'],
					'group'   => isset( $f['group'] ) ? $f['group'] : '',
					'label'   => $f['label'],
					'options' => $options,
					'iconSvg' => AdminKit_Icons::display_svg( isset( $f['icon'] ) ? $f['icon'] : 'plug' ),
					'desc'    => $f['desc'],
					'parent'  => isset( $f['parent'] ) ? $f['parent'] : '',
					'value'   => (bool) AdminKit_Settings::get( $f['key'] ),
					// Schema default — what the "Reset to defaults" bulk button restores to.
					// Falls back to false for keys not in the schema (defensive).
					'default' => isset( $schema[ $f['key'] ]['default'] ) ? (bool) $schema[ $f['key'] ]['default'] : false,
					// `bulk => false` keeps a row out of the Enable all / Disable all sweep
					// (for an override that shouldn't be toggled in bulk).
					'bulk'    => ! isset( $f['bulk'] ) || (bool) $f['bulk'],
					// `available => false` renders the row as locked + dimmed (toggle
					// disabled, optional hint tooltip). Used when a feature's prerequisite
					// isn't met (e.g. a host-specific tool when its host is inactive).
					'available'       => ! isset( $f['available'] ) || (bool) $f['available'],
					'unavailableHint' => isset( $f['unavailableHint'] ) ? $f['unavailableHint'] : '',
				);
			}
		}
		if ( $needs_plugins ) {
			// Plugins tab — every installed plugin (plus AdminKit's active theme
			// adapters); supported ones are badged "Native" (a tuned AdminKit adapter),
			// the rest carry no badge and ride the generic base layer.
			$integrations = AdminKit_Settings_Catalog::plugins_list();
		}

		// Menu editor — the captured admin-menu tree (only built when Navigation is active).
		$menu_manager = $needs_nav ? AdminKit_Menu_Manager::editor_data() : array();

		// Toolbar editor — lightweight boot (just the REST route + enabled); the live
		// admin-bar nodes are fetched over REST when the Toolbar tab is opened.
		$toolbar = ( $needs_nav && ( $is_main || $is_toolbar ) && class_exists( 'AdminKit_Toolbar_Manager' ) )
			? AdminKit_Toolbar_Manager::boot_data()
			: array( 'enabled' => false );

		$data = array(
			'screen'       => $screen,
			'pages'        => null,
			'initialTab'   => 'overview',
			'pageTitle'    => '',
			'version'      => ADMINKIT_VERSION,
			'values'       => $is_main ? array_combine( array_keys( AdminKit_Settings::schema() ), array_map( array( 'AdminKit_Settings', 'get' ), array_keys( AdminKit_Settings::schema() ) ) ) : array(),
			'route'        => self::REST_NS . self::REST_ROUTE,
			'features'     => $features,
			'integrations' => $integrations,
			'toolbar'      => $toolbar,
			'brandAccent'  => (string) AdminKit_Settings::get( 'brand_accent' ),
			// Effective accent source (resolved at read time — see accent_source()).
			// One of 'adminkit' | 'bricks' | 'custom'. Drives the segmented picker
			// in the Brand card and the Source pill colour for accent-family tokens.
			'accentSource' => AdminKit_Settings::accent_source(),
			'designSystem' => AdminKit_Settings::sanitize_design_system( AdminKit_Settings::get( 'design_system' ) ),
			'appearance'   => array(
				'ui_font'              => AdminKit_Settings::get( 'ui_font' ),
				'remote_fonts_enabled' => (bool) AdminKit_Settings::get( 'remote_fonts_enabled' ),
			),
			'fonts'        => AdminKit_Design_System::fonts(),
			// WaasKit custom-picker seed. The default accent uses native roles. The SPA seeds the swatch
			// with this when source = 'adminkit' before getComputedStyle resolves.
			'adminkitBlue' => AdminKit_Assets::ADMINKIT_BLUE,
			// True when the Bricks theme is active AND its integration toggle is on
			// (gates the Bricks accent option in the picker).
			'bricksConnected'  => AdminKit_Settings_Catalog::bricks_connected(),
			// Menu manager — captured tree, saved layout, icon set, save route.
			// Only populated on the Menu screen; empty elsewhere.
			'menuManager'  => $menu_manager,
			'i18n'         => AdminKit_Settings_I18n::strings(),
		);
		if ( ! $is_main ) {
			foreach ( array( 'designSystem', 'appearance', 'fonts', 'brandAccent', 'accentSource', 'adminkitBlue', 'bricksConnected', 'features', 'integrations' ) as $key ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
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
