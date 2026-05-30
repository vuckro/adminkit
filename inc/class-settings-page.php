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

class AdminKit_Settings_Page {

	/** `?page=` slug for the top-level menu + the `toplevel_page_{slug}` screen id. */
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
		add_menu_page(
			'AdminKit',
			'AdminKit',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-customizer',
			81
		);
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
		?>
		<div class="wrap">
			<h1 class="screen-reader-text">AdminKit</h1>
			<div id="adminkit-app" class="adminkit-app" aria-busy="true"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue the SPA assets on the AdminKit submenu screen only, and hand
	 * the app its data. The style depends on `adminkit-tokens` so
	 * `var(--ak-*)` resolves; the script depends on `wp-api-fetch` (which
	 * also wires the REST nonce). `wp_enqueue_media()` powers the brand-logo
	 * + favicon pickers on the Brand card.
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

		wp_enqueue_media(); // WordPress media frame, for the Brand-card pickers.
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
		AdminKit_Settings_Catalog::register_integration_toggles(); // so per-integration keys persist
		$values = $request->get_param( 'values' );
		$values = is_array( $values ) ? $values : array();

		$clean = self::sanitize( $values );

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

		$schema   = AdminKit_Settings::schema();
		$features = array();
		foreach ( AdminKit_Settings_Catalog::features() as $f ) {
			$features[] = array(
				'key'     => $f['key'],
				'group'   => isset( $f['group'] ) ? $f['group'] : '',
				'label'   => $f['label'],
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
				// isn't met (e.g. Bricks builder when the Bricks theme isn't active).
				'available'       => ! isset( $f['available'] ) || (bool) $f['available'],
				'unavailableHint' => isset( $f['unavailableHint'] ) ? $f['unavailableHint'] : '',
			);
		}

		// Plugins tab — every installed plugin (plus AdminKit's active theme
		// adapters); supported ones are badged "Native" (a tuned AdminKit adapter),
		// the rest carry no badge and ride the generic base layer.
		AdminKit_Settings_Catalog::register_integration_toggles();
		$integrations = AdminKit_Settings_Catalog::plugins_list();

		return array(
			'route'        => self::REST_NS . self::REST_ROUTE,
			'features'     => $features,
			'integrations' => $integrations,
			'logos'        => array(
				'light' => (string) AdminKit_Settings::get( 'logo_light' ),
				'dark'  => (string) AdminKit_Settings::get( 'logo_dark' ),
			),
			'wpLogo'       => (string) AdminKit_Settings::get( 'wp_logo' ),
			'loginLogo'    => (string) AdminKit_Settings::get( 'login_logo' ),
			'brandAccent'  => (string) AdminKit_Settings::get( 'brand_accent' ),
			// Effective accent source (resolved at read time — see accent_source()).
			// One of 'adminkit' | 'bricks' | 'custom'. Drives the segmented picker
			// in the Brand card and the Source pill colour for accent-family tokens.
			'accentSource' => AdminKit_Settings::accent_source(),
			// Default AdminKit accent (WordPress Blue). The SPA seeds the swatch
			// with this when source = 'adminkit' before getComputedStyle resolves.
			'adminkitBlue' => AdminKit_Assets::ADMINKIT_BLUE,
			// Bricks detection — true when the Bricks theme is the active theme.
			// `bricksConnected` adds the Bricks integration toggle to the equation
			// (so a user who disables the integration in the Plugins tab also
			// disconnects the Design-tab UI bits that depend on Bricks tokens).
			// Token count is parsed from style-manager.min.css when connected, so
			// the status row can show how many tokens are actually flowing.
			'bricksDetected'   => AdminKit_Settings_Catalog::bricks_detected(),
			'bricksConnected'  => AdminKit_Settings_Catalog::bricks_connected(),
			'bricksTokenCount' => AdminKit_Settings_Catalog::bricks_connected() ? AdminKit_Settings_Catalog::bricks_token_count() : 0,
			'i18n'         => array(
				// Tab labels (the only three the SPA strip prints).
				'dashboard'         => __( 'Dashboard', 'adminkit' ),
				// "Features" matches the tab content (feature toggles like
				// dark mode, post previews, login screen restyle, etc.).
				'features'          => __( 'Features', 'adminkit' ),
				'plugins'           => __( 'Plugins', 'adminkit' ),

				// Features tab — intro + bulk row + per-plugin descriptors.
				'featuresIntro'     => __( 'Turn AdminKit modules on or off.', 'adminkit' ),
				'enableAll'         => __( 'Enable all', 'adminkit' ),
				'disableAll'        => __( 'Disable all', 'adminkit' ),
				'resetDefaults'     => __( 'Reset to defaults', 'adminkit' ),
				'pluginsIntro'      => __( 'Every plugin installed on your site, plus AdminKit\'s active theme adapters. Native ones have a tuned adapter you can switch per host; the rest carry a Generic badge and inherit AdminKit\'s base styling automatically.', 'adminkit' ),
				'native'            => __( 'Native', 'adminkit' ),
				'nativeHint'        => __( 'AdminKit ships a tuned adapter for this plugin — light and dark.', 'adminkit' ),
				'system'            => __( 'System', 'adminkit' ),
				'systemHint'        => __( 'AdminKit itself — always on and not removable here.', 'adminkit' ),
				'generic'           => __( 'Generic', 'adminkit' ),
				'genericHint'       => __( 'No dedicated adapter — themed automatically by AdminKit\'s base layer.', 'adminkit' ),
				'themesLabel'       => __( 'Themes', 'adminkit' ),

				// Media frames.
				'mediaTitle'        => __( 'Select a logo', 'adminkit' ),
				'mediaButton'       => __( 'Use this image', 'adminkit' ),

				// Save bar.
				'save'              => __( 'Save changes', 'adminkit' ),
				'saving'            => __( 'Saving…', 'adminkit' ),
				'saved'             => __( 'Saved', 'adminkit' ),
				'error'             => __( 'Could not save', 'adminkit' ),
				'unsaved'           => __( 'Unsaved changes', 'adminkit' ),

				// --- Brand card -------------------------------------------------
				'brandTitle'          => __( 'Marque', 'adminkit' ),
				'brandSyncStatus'     => __( 'Tokens synced with Bricks Builder', 'adminkit' ),
				/* translators: %d: number of CSS custom properties exposed by Bricks. */
				'brandSyncStatusCount' => __( '%d tokens', 'adminkit' ),
				// Slot titles — the two logo slots (light / dark mode).
				'slotLight'           => __( 'Logo Light Mode', 'adminkit' ),
				'slotLightSub'        => __( 'SVG · PNG ≥ 400×100', 'adminkit' ),
				'slotDark'            => __( 'Logo Dark Mode', 'adminkit' ),
				'slotDarkSub'         => __( 'SVG · PNG ≥ 400×100', 'adminkit' ),
				'slotUpload'          => __( 'Upload', 'adminkit' ),
				'slotRemove'          => __( 'Remove', 'adminkit' ),

				// Accent picker — 3 sources (WordPress / Bricks / Custom). The
				// Bricks option only renders when the integration is connected.
				'accentLabel'         => __( 'Brand color', 'adminkit' ),
				'accentSrcAdminKit'   => __( 'WordPress', 'adminkit' ),
				'accentSrcBricks'     => __( 'Bricks', 'adminkit' ),
				'accentSrcCustom'     => __( 'Custom', 'adminkit' ),
				'displayLabel'        => __( 'Display', 'adminkit' ),

				// "Display" row — segmented controls for Admin bar + Login screen.
				'wpLogoLabel'       => __( 'WordPress', 'adminkit' ),
				'loginLogoLabel'    => __( 'Login', 'adminkit' ),
				'wpLogoBrand'       => __( 'Logo', 'adminkit' ),
				'wpLogoFavicon'     => __( 'Favicon', 'adminkit' ),

			),
		);
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
