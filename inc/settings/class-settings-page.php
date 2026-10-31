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
	 * Enqueue the SPA assets on the AdminKit submenu screen only, and hand
	 * the app its data. The style depends on `adminkit-tokens` so
	 * `var(--ak-*)` resolves; the script depends on `wp-api-fetch` (which
	 * also wires the REST nonce).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		self::enqueue_app( self::boot_data( 'main' ) );
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
		// The main screen's tabs live in their own files (assets/js/settings/<part>.js).
		$deps = array( 'wp-api-fetch' );
		$navigation_deps = array();
		foreach ( array( 'controls', 'menu', 'toolbar' ) as $part ) {
			$part_js = 'assets/js/settings/navigation-' . $part . '.js';
			$part_handle = self::HANDLE . '-navigation-' . $part;
			$part_deps = 'controls' === $part ? array() : array( self::HANDLE . '-navigation-controls' );
			wp_enqueue_script( $part_handle, ADMINKIT_URL . $part_js, $part_deps, self::ver( $part_js ), true );
			$navigation_deps[] = $part_handle;
		}
		if ( 'navigation' === $module ) {
			$deps = array_merge( $deps, $navigation_deps );
		}
		if ( 'main' === $module ) {
			$nav_css = 'assets/css/settings/navigation.css';
			wp_enqueue_style( self::HANDLE . '-navigation', ADMINKIT_URL . $nav_css, array( self::HANDLE ), self::ver( $nav_css ) );
			foreach ( array( 'design', 'features', 'plugins', 'navigation' ) as $part ) {
				$part_js = 'assets/js/settings/' . $part . '.js';
				wp_enqueue_script( $screen_handle . '-' . $part, ADMINKIT_URL . $part_js, 'navigation' === $part ? $navigation_deps : array(), self::ver( $part_js ), true );
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

		$saved = AdminKit_Storage::save_option( AdminKit_Settings::OPTION_KEY, $final );
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
	 * @return array
	 */
	public static function boot_data( $screen = 'main' ) {
		$is_main    = ( 'main' === $screen );
		$is_menu    = ( 'menu' === $screen );
		$is_toolbar = ( 'toolbar' === $screen );

		// Brand / Features / Plugins data — only the Settings ("main") screen needs
		// the feature list + the (relatively costly) installed-plugins scan. Other
		// screens ship empty arrays; the engine's `|| []` guards no-op on them.
		$features     = array();
		$integrations = array();
		if ( $is_main ) {
			$schema = AdminKit_Settings::schema();
			foreach ( AdminKit_Settings_Catalog::features() as $f ) {
				$features[] = array(
					'key'     => $f['key'],
					'group'   => isset( $f['group'] ) ? $f['group'] : '',
					'label'   => $f['label'],
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
			// Plugins tab — every installed plugin (plus AdminKit's active theme
			// adapters); supported ones are badged "Native" (a tuned AdminKit adapter),
			// the rest carry no badge and ride the generic base layer.
			$integrations = AdminKit_Settings_Catalog::plugins_list();
		}

		// Menu editor — the captured admin-menu tree (only built on the AdminKit screen).
		// Also included on the legacy standalone toolbar screen so the shared icon picker
		// (icons + dashicons) is available for toolbar items too.
		$menu_manager = ( $is_main || $is_menu || $is_toolbar ) ? AdminKit_Menu_Manager::editor_data() : array();

		// Toolbar editor — lightweight boot (just the REST route + enabled); the live
		// admin-bar nodes are fetched over REST when the Toolbar tab is opened.
		$toolbar = ( ( $is_main || $is_toolbar ) && class_exists( 'AdminKit_Toolbar_Manager' ) )
			? AdminKit_Toolbar_Manager::boot_data()
			: array( 'enabled' => false );

		$data = array(
			'screen'       => $screen,
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
			'appearance'   => array( 'ui_font' => AdminKit_Settings::get( 'ui_font' ) ),
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
			'i18n'         => array(
				'foundationInvalidColor' => __( 'Use a hex color such as #1549ab.', 'adminkit' ),
				'foundationSections' => __( 'Design system sections', 'adminkit' ),
				'previewAction' => __( 'Primary action', 'adminkit' ),
				'foundationColorsHelp' => __( 'Choose colors for light and dark. Custom lets you choose the accent and edit each palette color. Save to apply your changes.', 'adminkit' ),
				'foundationReset' => __( 'Reset', 'adminkit' ),
				'foundationBackground' => __( 'Background', 'adminkit' ),
				'foundationSurface' => __( 'Surface', 'adminkit' ),
				'foundationText' => __( 'Text', 'adminkit' ),
				'foundationMuted' => __( 'Secondary text', 'adminkit' ),
				'foundationBorder' => __( 'Border', 'adminkit' ),
				'foundationAccent' => __( 'Accent', 'adminkit' ),
				'foundationOnAccent' => __( 'Text on accent', 'adminkit' ),
				'foundationPaletteReset' => __( 'Reset palette', 'adminkit' ),
				'foundationInherited' => __( 'Inherited', 'adminkit' ),
				'foundationCustom' => __( 'Customized', 'adminkit' ),
				'foundationSuccess' => __( 'Success text', 'adminkit' ),
				'foundationWarning' => __( 'Warning text', 'adminkit' ),
				'foundationDanger' => __( 'Danger text', 'adminkit' ),
				'foundationInfo' => __( 'Info text', 'adminkit' ),
				'studioFont' => __( 'Font family', 'adminkit' ),
				'fontSystem' => __( 'System', 'adminkit' ),
				'studioTypeScale' => __( 'Text scale', 'adminkit' ),
				'foundationScope' => __( 'These settings apply to WordPress administration and supported extensions after saving. Your public website keeps its own design.', 'adminkit' ),
				'studioColors' => __( 'Colors', 'adminkit' ),
				'studioType' => __( 'Typography', 'adminkit' ),
				'designPreview' => __( 'Preview', 'adminkit' ),
				'componentHighlight' => __( 'Top-edge highlight', 'adminkit' ),
				'componentOff' => __( 'Off', 'adminkit' ),
				'componentHover' => __( 'On hover', 'adminkit' ),
				'componentAlways' => __( 'Always', 'adminkit' ),
				'componentDisabled' => __( 'Disabled', 'adminkit' ),
				'studioShapes' => __( 'Shapes & elevation', 'adminkit' ),
				'studioTypeHint' => __( 'Font family and text scale.', 'adminkit' ),
				'studioRadiusScale' => __( 'Corner scale', 'adminkit' ),
				'studioBorder' => __( 'Outline width', 'adminkit' ),
				'shapeHint' => __( 'Corner shapes, outlines and depth for buttons, fields, cards and tables.', 'adminkit' ),
				'shapeHelp' => __( 'Preview changes here, then save to apply them. Elevation affects cards and tables; focus indicators stay visible.', 'adminkit' ),
				'shapeCard' => __( 'Card', 'adminkit' ),
				'shapeTable' => __( 'Table', 'adminkit' ),
				'studioElevation' => __( 'Elevation', 'adminkit' ),
				'studioFlat' => __( 'Flat', 'adminkit' ),
				'studioSubtle' => __( 'Subtle', 'adminkit' ),
				'studioRaised' => __( 'Raised', 'adminkit' ),
				'studioSample' => __( 'Sample content — preview only', 'adminkit' ),
				'studioField' => __( 'Site name', 'adminkit' ),
				'studioSecondary' => __( 'Secondary action', 'adminkit' ),
				'pluginInactive' => __( 'Plugin inactive', 'adminkit' ),
				'noResults' => __( 'No results. Try another search term.', 'adminkit' ),
				'designSystem' => __( 'Design system', 'adminkit' ),
				'designIntro' => __( 'Customize the foundations and components of your interface.', 'adminkit' ),
				'defaultPreset' => __( 'Default', 'adminkit' ),
				'features'          => __( 'Features', 'adminkit' ),
				'plugins'           => __( 'Plugins', 'adminkit' ),
				'navigation'        => __( 'Navigation', 'adminkit' ),
				'navigationIntro'   => __( 'Customize the WordPress sidebar menu and top admin toolbar.', 'adminkit' ),
				'menu'              => __( 'Sidebar menu', 'adminkit' ),

				// Toolbar manager tab.
				'toolbarTab'        => __( 'Toolbar', 'adminkit' ),
				'toolbarIntro'      => __( 'Reorder, rename, change icons or hide toolbar items. Hiding an item does not block access to it in WordPress.', 'adminkit' ),
				'toolbarReset'      => __( 'Reset toolbar', 'adminkit' ),
				'toolbarEmpty'      => __( 'No toolbar items found.', 'adminkit' ),
				'toolbarLeft'       => __( 'Left', 'adminkit' ),
				'toolbarRight'      => __( 'Right', 'adminkit' ),
				'toolbarAdminTitle' => __( 'Toolbar — Administration', 'adminkit' ),
				'toolbarFrontendTitle' => __( 'Toolbar — Public site', 'adminkit' ),
				'toolbarAdminScope' => __( 'You are editing the toolbar inside the WordPress administration.', 'adminkit' ),
				'toolbarFrontendScope' => __( 'You are editing the WordPress toolbar on the public site, normally visible only to logged-in users. These settings do not change your public pages or site menus.', 'adminkit' ),
				'toolbarSaveScope' => __( 'The two views have independent layouts. Saving applies pending changes from both views.', 'adminkit' ),
				'toolbarContext'    => __( 'Toolbar context', 'adminkit' ),
				'toolbarAdmin'      => __( 'Administration', 'adminkit' ),
				'toolbarFrontend'   => __( 'Public site', 'adminkit' ),

				// Menu manager tab.
				'menuIntro'         => __( 'Reorder the admin menu and submenus, change icons, and hide entries. Drag to reorder; changes apply after you save. Hiding removes an item from the menu only — it does not block direct access.', 'adminkit' ),
				'menuReset'         => __( 'Reset menu', 'adminkit' ),
				'menuIconPick'      => __( 'Choose an icon', 'adminkit' ),
				'menuIconNone'      => __( 'Default', 'adminkit' ),
				'menuIconWp'        => __( 'WordPress icon', 'adminkit' ),
				'menuIconWpSet'     => __( 'WordPress icons', 'adminkit' ),
				'menuIconSearch'    => __( 'Search…', 'adminkit' ),
				'menuIconEmpty'     => __( 'No icons found.', 'adminkit' ),
				'menuHide'          => __( 'Hide', 'adminkit' ),
				'menuShow'          => __( 'Show', 'adminkit' ),
				'menuSubmenus'      => __( 'submenus', 'adminkit' ),
				'menuSubmenusToggle' => __( 'Show / hide submenus', 'adminkit' ),
				'menuDragHint'      => __( 'Drag to reorder', 'adminkit' ),
				'menuMoveUp'        => __( 'Move up', 'adminkit' ),
				'menuMoveDown'      => __( 'Move down', 'adminkit' ),
				'menuIconCustom'    => __( 'Paste an SVG or a data:image/svg+xml URI…', 'adminkit' ),
				'menuIconHint'      => __( 'Need an icon? Copy an SVG from', 'adminkit' ),
				'menuIconApply'     => __( 'Apply', 'adminkit' ),
				'menuAddLink'       => __( 'Add link', 'adminkit' ),
				'menuAddSeparator'  => __( 'Add separator', 'adminkit' ),
				'menuExport'        => __( 'Export', 'adminkit' ),
				'menuImport'        => __( 'Import', 'adminkit' ),
				'menuExportHint'    => __( 'Download this menu layout as a JSON file', 'adminkit' ),
				'menuImportHint'    => __( 'Load a menu layout from a JSON file', 'adminkit' ),
				'menuImportError'   => __( 'That file isn’t a valid AdminKit menu export.', 'adminkit' ),
				'menuSeparator'     => __( 'Separator', 'adminkit' ),
				'menuLinkTitle'     => __( 'Label', 'adminkit' ),
				'menuLinkUrl'       => __( 'https://…', 'adminkit' ),
				'menuNewTab'        => __( 'Open in a new tab', 'adminkit' ),
				'menuRemove'        => __( 'Remove', 'adminkit' ),
				'menuRename'        => __( 'Rename — clear to restore the original', 'adminkit' ),
				'menuUrlTitle'      => __( 'Custom link', 'adminkit' ),
				'menuUrlLabel'      => __( 'Point this item at a custom URL (internal or external). Its submenu still works.', 'adminkit' ),
				'menuUrlCurrent'    => __( 'Current', 'adminkit' ),
				'featureCategories' => __( 'Feature categories', 'adminkit' ),
				'featureAllCategories' => __( 'All categories', 'adminkit' ),
				'featureChoose'     => __( 'Choose a feature', 'adminkit' ),
				'featureEffect'     => __( 'What this feature does', 'adminkit' ),
				'featureActive'     => __( 'Feature active', 'adminkit' ),
				'featureInactive'   => __( 'Feature inactive', 'adminkit' ),
				'featureUnavailable' => __( 'Unavailable', 'adminkit' ),
				'featureParentOff'  => __( 'Enable the parent feature to use this option.', 'adminkit' ),
				'featureSaveHint'   => __( 'Applies after saving changes.', 'adminkit' ),
				'featureBack'       => __( 'Back to features', 'adminkit' ),
				'featureCategoryEmpty' => __( 'No results in this category. Choose another category or clear the filters.', 'adminkit' ),
				'featureBulk'       => __( 'Bulk actions', 'adminkit' ),
				'featureBulkScope'  => __( 'These actions apply to all available features, including those hidden by filters.', 'adminkit' ),
				'featuresIntro'     => __( 'Turn AdminKit modules on or off.', 'adminkit' ),
				'enableAll'         => __( 'Enable all', 'adminkit' ),
				'disableAll'        => __( 'Disable all', 'adminkit' ),
				'resetDefaults'     => __( 'Reset to defaults', 'adminkit' ),
				'filterFeatures'    => __( 'Filter features by status', 'adminkit' ),
				'featuresFilterAll' => __( 'All features', 'adminkit' ),
				'featuresFilterActive' => __( 'Active features', 'adminkit' ),
				'featuresFilterInactive' => __( 'Inactive features', 'adminkit' ),
				'searchFeatures'    => __( 'Search features…', 'adminkit' ),
				'searchPlugins'     => __( 'Search plugins…', 'adminkit' ),
				'pluginsIntro'      => __( 'Every plugin installed on your site, plus AdminKit\'s active theme adapters. Native ones have a tuned adapter you can switch per host; the rest carry a Generic badge and inherit AdminKit\'s base styling automatically.', 'adminkit' ),
				'native'            => __( 'Native', 'adminkit' ),
				'nativeHint'        => __( 'AdminKit ships a tuned adapter for this plugin — light and dark.', 'adminkit' ),
				'system'            => __( 'System', 'adminkit' ),
				'systemHint'        => __( 'AdminKit itself — always on and not removable here.', 'adminkit' ),
				'generic'           => __( 'Generic', 'adminkit' ),
				'genericHint'       => __( 'No dedicated adapter — themed automatically by AdminKit\'s base layer.', 'adminkit' ),
				'themesLabel'       => __( 'Themes', 'adminkit' ),

				// Media frames.
				'settingsExport'     => __( 'Export', 'adminkit' ),
				'settingsImport'     => __( 'Import', 'adminkit' ),
				'settingsExportHint' => __( 'Download these settings as a JSON file', 'adminkit' ),
				'settingsImportHint' => __( 'Load settings from a JSON file', 'adminkit' ),
				'settingsImportError' => __( 'That file isn’t a valid AdminKit settings export.', 'adminkit' ),

				// Save bar.
				'save'              => __( 'Save changes', 'adminkit' ),
				'saving'            => __( 'Saving…', 'adminkit' ),
				'saved'             => __( 'Saved', 'adminkit' ),
				'error'             => __( 'Could not save', 'adminkit' ),
				'unsaved'           => __( 'Unsaved changes', 'adminkit' ),

				// --- Brand card -------------------------------------------------
				'accentLabel'         => __( 'Brand color', 'adminkit' ),
				'accentSrcMonochrome' => __( 'Monochrome', 'adminkit' ),
				'accentSrcAdminKit'   => __( 'WordPress', 'adminkit' ),
				'accentSrcBricks'     => __( 'Bricks', 'adminkit' ),
				'accentSrcCustom'     => __( 'Custom', 'adminkit' ),
				'brandPreviewLight' => __( 'Light', 'adminkit' ),
				'brandPreviewDark'  => __( 'Dark', 'adminkit' ),
			),
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
