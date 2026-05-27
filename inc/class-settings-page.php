<?php
/**
 * Settings page — the admin UI for AdminKit.
 *
 * Mounts a `Settings → AdminKit` submenu page that hosts the SPA. The PHP
 * side here only:
 *   - registers the submenu + renders an empty `<div id="adminkit-app">`
 *     host that `settings.js` builds into,
 *   - enqueues the SPA assets on that screen + hands the app its data via
 *     `window.AdminKitData`,
 *   - exposes one REST route (`adminkit/v1/settings`) the SPA POSTs to.
 *
 * The data is built from the settings registry: the semantic token taxonomy
 * (rendered read-only on the Brand card), the feature toggles, and the
 * detected integrations. Saving runs each known field through its registered
 * `sanitize` callback and persists only registered keys.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Settings_Page {

	/** `?page=` slug for the submenu + the `settings_page_{slug}` screen id. */
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
		// "Settings" link in the plugin row action area (next to Deactivate). The
		// filter name is keyed on the plugin's basename so it only attaches to
		// our row, not every plugin's.
		add_filter( 'plugin_action_links_' . plugin_basename( ADMINKIT_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		add_filter( 'adminkit/integration_enabled', array( __CLASS__, 'gate_integration' ), 10, 2 );
		// Gate the admin restyle on plugin pages opted out individually in the
		// Plugins tab (see `gate_generic_theming()` + `plugin_file_for_screen()`).
		add_filter( 'adminkit/should_load', array( __CLASS__, 'gate_generic_theming' ), 10, 2 );
		// On screens owned by a native adapter, suppress auto-theme so the adapter's
		// own CSS handles theming without auto-theme re-tagging correctly-themed elements.
		add_filter( 'adminkit/suppress_auto_theme', array( __CLASS__, 'suppress_auto_theme_for_adapters' ), 10, 2 );
	}

	/**
	 * Register the submenu page under Settings. Parent slug `options-general.php`
	 * is what WordPress expects to nest under the "Settings" menu group. Screen
	 * hook ends up as `settings_page_adminkit` — that's what the enqueue gate
	 * matches below.
	 *
	 * @return void
	 */
	public static function add_submenu() {
		add_submenu_page(
			'options-general.php',
			'AdminKit',
			'AdminKit',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
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
	 * Prepend a "Settings" link to the plugin row actions on plugins.php so the
	 * admin can jump straight to AdminKit from the Plugins screen — no detour
	 * via the Settings menu.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public static function plugin_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Settings', 'adminkit' )
		);
		array_unshift( $links, $settings );
		return $links;
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
		if ( 'settings_page_' . self::SLUG !== $hook ) {
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
		self::register_integration_toggles(); // so per-integration keys persist
		$values = $request->get_param( 'values' );
		$values = is_array( $values ) ? $values : array();

		// LIGHT favicon — proxies WP's native `site_icon` option. The Brand
		// card lets the user pick a favicon from here; WP's own Site Icon row
		// on Settings → General edits the same option. Both surfaces stay in
		// sync — the SPA posts `site_icon_id` and we update the WP option
		// directly. 0 / empty means "no icon" — same convention as core.
		if ( array_key_exists( 'site_icon_id', $values ) ) {
			$icon_id = absint( $values['site_icon_id'] );
			if ( $icon_id > 0 && wp_attachment_is_image( $icon_id ) ) {
				update_option( 'site_icon', $icon_id );
			} else {
				delete_option( 'site_icon' );
			}
			unset( $values['site_icon_id'] );
		}

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
		foreach ( self::feature_descriptors() as $f ) {
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
		self::register_integration_toggles();
		$integrations = self::plugins_list();

		return array(
			'route'        => self::REST_NS . self::REST_ROUTE,
			'features'     => $features,
			'integrations' => $integrations,
			'logos'        => array(
				'light' => (string) AdminKit_Settings::get( 'logo_light' ),
				'dark'  => (string) AdminKit_Settings::get( 'logo_dark' ),
			),
			// Dark-mode favicon — light one is WP's native `site_icon` (see
			// `siteIcon` below); this is AdminKit's own paired storage with no
			// WP equivalent. Printed in <head> with `media="(prefers-color-scheme:
			// dark)"` so the browser swaps automatically.
			'faviconDark'  => (string) AdminKit_Settings::get( 'favicon_dark' ),
			// Bidirectional binding for the LIGHT favicon slot in the Brand card:
			// the SPA reads from `siteIcon.id` and writes the value back through
			// the `site_icon_id` REST proxy (see rest_save()). One source of
			// truth — the WP option `site_icon`. WordPress's own Site Icon row
			// on Settings → General edits the same option, so the two surfaces
			// stay in sync on the next page load.
			'siteIcon'     => array(
				'id'  => (int) get_option( 'site_icon', 0 ),
				'url' => (string) get_site_icon_url(),
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
			'bricksDetected'   => self::bricks_detected(),
			'bricksConnected'  => self::bricks_connected(),
			'bricksTokenCount' => self::bricks_connected() ? self::bricks_token_count() : 0,
			// Bricks-export templates loaded from disk (6 JSON files grouped into
			// 4 sections). The SPA renders these in the Export to Bricks modal.
			'bricksExports'  => self::load_bricks_exports(),
			'i18n'         => array(
				// Tab labels (the only three the SPA strip prints).
				'dashboard'         => __( 'Dashboard', 'adminkit' ),
				// "Preferences" reads distinctly from WordPress's own Settings
				// (Réglages) menu, where the AdminKit submenu sits.
				'features'          => __( 'Preferences', 'adminkit' ),
				'plugins'           => __( 'Plugins', 'adminkit' ),

				// Preferences tab — intro + bulk row + per-plugin descriptors.
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
				// Cropper modal — used by BOTH favicon slots (light = WP-native
				// site_icon, dark = AdminKit-owned). Labels stay generic so the
				// modal reads as a favicon-picker rather than a Site Icon flow.
				'mediaSiteIconTitle'  => __( 'Choose a favicon', 'adminkit' ),
				'mediaSiteIconButton' => __( 'Use this image', 'adminkit' ),

				// Save bar.
				'save'              => __( 'Save changes', 'adminkit' ),
				'saving'            => __( 'Saving…', 'adminkit' ),
				'saved'             => __( 'Saved', 'adminkit' ),
				'error'             => __( 'Could not save', 'adminkit' ),
				'unsaved'           => __( 'Unsaved changes', 'adminkit' ),

				// --- Brand card -------------------------------------------------
				'brandTitle'          => __( 'Logo', 'adminkit' ),
				'brandSyncStatus'     => __( 'Tokens synced with Bricks Builder', 'adminkit' ),
				/* translators: %d: number of CSS custom properties exposed by Bricks. */
				'brandSyncStatusCount' => __( '%d tokens', 'adminkit' ),
				// Slot titles — "<Kind> <Mode>"; mode follows kind so the eye
				// scans the kind first. The LIGHT favicon proxies WP's native
				// `site_icon`; WP's own Site Icon row edits the same value.
				'slotFavicon'         => __( 'Favicon Light Mode', 'adminkit' ),
				'slotFaviconSub'      => __( 'PNG · 512×512 · cropped', 'adminkit' ),
				'slotLight'           => __( 'Logo Light Mode', 'adminkit' ),
				'slotLightSub'        => __( 'SVG · PNG ≥ 400×100', 'adminkit' ),
				'slotFaviconDark'     => __( 'Favicon Dark Mode', 'adminkit' ),
				'slotFaviconDarkSub'  => __( 'Auto-swap via prefers-color-scheme', 'adminkit' ),
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

				// Brand-card Action — opens the Bricks-export modal.
				'actionExport'        => __( 'Export to Bricks', 'adminkit' ),
				// Export modal.
				'exportTitle'         => __( 'Export to Bricks', 'adminkit' ),
				'exportIntro'         => __( 'Follow the steps in order — open each one, copy or download the file, then import it where indicated.', 'adminkit' ),
				'exportCopy'          => __( 'Copy', 'adminkit' ),
				'exportCopied'        => __( 'Copied', 'adminkit' ),
				'exportDownload'      => __( 'Download .json', 'adminkit' ),
				'exportClose'         => __( 'Close', 'adminkit' ),
			),
		);
	}

	/**
	 * Feature toggles shown on the Settings tab, in display order. Keys match
	 * settings registered in AdminKit_Settings / AdminKit_Post_Previews.
	 *
	 * @return array
	 */
	private static function feature_descriptors() {
		// `group` is the section heading the Settings tab buckets a row under
		// (identical strings = same group; order = first-seen). A child inherits
		// its parent's group by carrying the same label. Three buckets:
		//   • Content & lists — list tables and the post screens
		//   • Appearance      — visual chrome (admin, login, editor, icons)
		//   • Users & access  — anything that touches user identity / accounts
		$content    = __( 'Content & lists', 'adminkit' );
		$appearance = __( 'Appearance', 'adminkit' );
		$users      = __( 'Users & access', 'adminkit' );
		$rows = array(
			// Content & lists
			array( 'key' => 'post_previews_enabled', 'group' => $content,    'label' => __( 'Post previews', 'adminkit' ),
				'desc' => __( 'Adds a thumbnail column to post-type list tables, using the featured image first.', 'adminkit' ) ),
			array( 'key' => 'post_previews_mshots',  'group' => $content,    'label' => __( 'Live screenshots', 'adminkit' ),
				'desc' => __( 'When no featured image is set, fetch a live screenshot via WordPress.com mShots. Off keeps the column featured-image only (no external calls).', 'adminkit' ),
				'parent' => 'post_previews_enabled' ),

			// Appearance
			array( 'key' => 'theme_toggle_enabled',  'group' => $appearance, 'label' => __( 'Dark mode', 'adminkit' ),
				'desc' => __( 'Adds a light/dark toggle to the admin bar. Off forces light mode site-wide.', 'adminkit' ) ),
			array( 'key' => 'module_login_enabled',  'group' => $appearance, 'label' => __( 'Login screen', 'adminkit' ),
				'desc' => __( 'Restyle wp-login.php to match the admin (logo, dark mode, focus states).', 'adminkit' ) ),
			array( 'key' => 'editor_content_theme',  'group' => $appearance, 'label' => __( 'Block editor', 'adminkit' ),
				'desc' => __( 'Theme the Gutenberg canvas in light and dark. Off keeps the canvas matching your live site exactly.', 'adminkit' ) ),
			array( 'key' => 'replace_icons_enabled', 'group' => $appearance, 'label' => __( 'AdminKit icons', 'adminkit' ),
				'desc' => __( 'Swap WordPress\'s menu and toolbar icons for AdminKit\'s set. Icons customised elsewhere (e.g. Admin Menu Editor) are left alone.', 'adminkit' ) ),

			// Users & access
			array( 'key' => 'quick_edit_users_enabled', 'group' => $users,   'label' => __( 'Users quick edit', 'adminkit' ),
				'desc' => __( 'Edit first name, last name, email and role inline from the users list — no need to open the full profile.', 'adminkit' ) ),
			array( 'key' => 'username_changer_enabled', 'group' => $users,   'label' => __( 'Username changer', 'adminkit' ),
				'desc' => __( 'Lets admins rename a user\'s login on Users → Edit (WordPress disables this by default). Sensitive — invalidates the user\'s active sessions; they must sign in again. Single-site only.', 'adminkit' ) ),
			array( 'key' => 'custom_avatars_enabled',   'group' => $users,   'label' => __( 'Custom avatars', 'adminkit' ),
				'desc' => __( 'Adds "AdminKit Portraits (Generated)" to Settings → Discussion → Default Avatar. Pick it there to give every user a unique generated portrait.', 'adminkit' ) ),
		);

		// Bricks builder restyle — always listed, but greyed out via `available`
		// when the Bricks theme isn't the active theme (the feature has nothing
		// to restyle in that case). On a Bricks site the row reads as a normal
		// ON-by-default toggle; elsewhere it's a locked, dimmed "Bricks theme
		// not active" row so users can see the option exists.
		$rows[] = array(
			'key' => 'bricks_builder_enabled', 'group' => $appearance, 'label' => __( 'Bricks builder', 'adminkit' ),
			'desc' => __( 'Restyle the Bricks builder UI with your tokens. Automatically sets Bricks → Settings → Builder mode to "Custom".', 'adminkit' ),
			'available' => self::bricks_detected(),
			'unavailableHint' => __( 'Activate the Bricks theme to use this.', 'adminkit' ),
		);

		return $rows;
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
	 * Known host plugin file(s) per adapter slug, so the Plugins tab can tell which
	 * of the site's installed plugins AdminKit themes natively. Kept central (no
	 * per-adapter edits) and tolerant — free/pro variants are listed together. A
	 * miss degrades gracefully: the plugin simply shows unbadged (generic base
	 * styling) rather than "Native" (never a duplicate row). Theme adapters (e.g.
	 * Bricks) aren't here — they're matched from the active theme set instead.
	 *
	 * @return array<string, string[]>
	 */
	private static function integration_host_files() {
		return array(
			'acf'               => array( 'advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php', 'secure-custom-fields/secure-custom-fields.php' ),
			'admin-menu-editor' => array( 'admin-menu-editor/menu-editor.php', 'admin-menu-editor-pro/menu-editor.php' ),
			'fluent-booking'    => array( 'fluent-booking/fluent-booking.php', 'fluent-booking-pro/fluent-booking-pro.php' ),
			'fluent-smtp'       => array( 'fluent-smtp/fluent-smtp.php' ),
			'fluentform'        => array( 'fluentform/fluentform.php', 'fluentformpro/fluentformpro.php' ),
			'flying-press'      => array( 'flying-press/flying-press.php' ),
			'happyfiles'        => array( 'happyfiles/happyfiles.php', 'happyfiles-pro/happyfiles.php' ),
			'loco-translate'    => array( 'loco-translate/loco.php' ),
			'query-monitor'     => array( 'query-monitor/query-monitor.php' ),
			'slim-seo'          => array( 'slim-seo/slim-seo.php' ),
			'woocommerce'       => array( 'woocommerce/woocommerce.php' ),
			'wp-migrate-db-pro' => array( 'wp-migrate-db-pro/wp-migrate-db-pro.php', 'wp-migrate-db/wp-migrate-db.php' ),
			'wpcode'            => array( 'wpcode/wpcode.php', 'insert-headers-and-footers/ihaf.php' ),
		);
	}

	/**
	 * Build the Plugins-tab list: EVERY plugin installed on the site (plus
	 * AdminKit's active theme adapters). Supported hosts — those AdminKit ships a
	 * tuned adapter for — are flagged native (toggleable, dark-mode included);
	 * every other plugin is left unbadged and simply inherits AdminKit's base
	 * token styling. The tab mirrors the site's plugin list, active or not.
	 *
	 * Row shape: slug (adapter slug when native, '' otherwise), label (the
	 * plugin's own name), type, supported (bool), system (bool — AdminKit's own
	 * locked row), enabled (adapter toggle — native rows only). System first, then
	 * native, then alphabetical.
	 *
	 * @return array<int, array>
	 */
	private static function plugins_list() {
		// basename → adapter slug for EVERY plugin adapter (active or not), so an
		// installed-but-inactive supported plugin still earns its Native badge. The
		// "Native" mark means "AdminKit ships a tuned adapter for this", independent
		// of whether the plugin is currently active.
		$hosts   = self::integration_host_files();
		$by_file = array();
		foreach ( self::integration_specs() as $s ) {
			if ( 'plugin' !== $s['type'] ) {
				continue;
			}
			foreach ( (array) ( isset( $hosts[ $s['slug'] ] ) ? $hosts[ $s['slug'] ] : array() ) as $file ) {
				$by_file[ $file ] = $s['slug'];
			}
		}

		$rows = array();

		// 1) EVERY installed plugin — the tab mirrors the site's plugins. Unknown
		// (no adapter) ones carry no badge; they're themed automatically by
		// AdminKit's generic layer (base component CSS + WP-var remap) when active.
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// Per-plugin opt-out list for generic plugins — file paths the user has
		// flipped OFF in the Plugins tab. Read once so each row reflects it.
		$generic_off = (array) AdminKit_Settings::get( 'generic_theming_off' );

		$self = plugin_basename( ADMINKIT_FILE );
		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $self ) {
				// AdminKit itself — shown as a locked "System" row: always on and
				// not toggleable here (greyed out), so the user sees it but can't
				// disable its own host from this tab.
				$rows[] = array(
					'slug'      => '',
					'file'      => $file,
					'label'     => '' !== (string) $data['Name'] ? $data['Name'] : 'AdminKit',
					'type'      => 'plugin',
					'supported' => false,
					'system'    => true,
					'enabled'   => true,
					'active'    => true,
				);
				continue;
			}
			$slug      = isset( $by_file[ $file ] ) ? $by_file[ $file ] : '';
			$supported = ( '' !== $slug );
			$name      = '' !== (string) $data['Name'] ? $data['Name'] : $file;
			// Native rows read their integration_{slug}_enabled toggle.
			// Generic rows: ON by default, OFF if file appears in generic_theming_off.
			$enabled   = $supported
				? (bool) AdminKit_Settings::get( 'integration_' . $slug . '_enabled' )
				: ! in_array( $file, $generic_off, true );
			$rows[]    = array(
				'slug'      => $slug,
				'file'      => $file,
				'label'     => $name,
				'type'      => 'plugin',
				'supported' => $supported,
				'enabled'   => $enabled,
				// Installed-but-inactive plugins are listed too (mirrors WP's own
				// Plugins screen). The JS renders them muted and without a switch
				// — AdminKit can't act on a plugin that isn't loaded anyway.
				'active'    => is_plugin_active( $file ),
			);
		}

		// 2) AdminKit's ACTIVE theme adapter (e.g. Bricks) — themes aren't in
		// get_plugins(); list the active one so the user sees their theme is native.
		foreach ( self::integration_specs() as $s ) {
			if ( 'theme' !== $s['type'] || ! call_user_func( array( $s['class'], 'is_active' ) ) ) {
				continue;
			}
			$rows[] = array(
				'slug'      => $s['slug'],
				'file'      => '',
				'label'     => $s['label'],
				'type'      => 'theme',
				'supported' => true,
				'enabled'   => (bool) AdminKit_Settings::get( 'integration_' . $s['slug'] . '_enabled' ),
				'active'    => true,
			);
		}

		// AdminKit's own "System" row leads, then native, then alphabetical.
		usort( $rows, static function ( $a, $b ) {
			$as = ! empty( $a['system'] );
			$bs = ! empty( $b['system'] );
			if ( $as !== $bs ) {
				return $as ? -1 : 1;
			}
			if ( $a['supported'] !== $b['supported'] ) {
				return $a['supported'] ? -1 : 1;
			}
			return strcasecmp( $a['label'], $b['label'] );
		} );

		return $rows;
	}

	/**
	 * Bricks theme is the currently-active theme. Doesn't consult the
	 * AdminKit integration toggle — for that, see bricks_connected().
	 *
	 * @return bool
	 */
	private static function bricks_detected() {
		return class_exists( 'AdminKit_Integration_Bricks' )
			&& AdminKit_Integration_Bricks::is_active();
	}

	/**
	 * Bricks tokens are actually flowing into AdminKit — the theme is active
	 * AND the integration toggle (Plugins tab) is on. When false, the Design
	 * tab hides the sync status and the Bricks accent source.
	 *
	 * @return bool
	 */
	private static function bricks_connected() {
		if ( ! self::bricks_detected() ) {
			return false;
		}
		self::register_integration_toggles(); // ensure schema default applies
		return (bool) AdminKit_Settings::get( 'integration_bricks_enabled' );
	}

	/**
	 * Count of CSS custom properties currently exposed by Bricks's generated
	 * style-manager.min.css (the source of every --bricks-* / --primary / etc.
	 * we cascade into --ak-*). A regex over `--name:` declarations — minified
	 * file (~10KB), so the parse is trivial. 0 when the file doesn't exist or
	 * isn't readable.
	 *
	 * @return int
	 */
	private static function bricks_token_count() {
		if ( ! class_exists( 'AdminKit_Integration_Bricks' ) ) {
			return 0;
		}
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . AdminKit_Integration_Bricks::TOKENS_REL;
		if ( ! is_readable( $path ) ) {
			return 0;
		}
		$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $css || '' === $css ) {
			return 0;
		}
		// Count distinct custom-property declarations. `--name:` is the universal
		// signature; duplicates across selectors are rare in a generated palette.
		return preg_match_all( '/--[a-z0-9_-]+\s*:/i', $css );
	}

	/**
	 * Load the Bricks-export JSON templates bundled with the plugin (under
	 * `assets/bricks-export/`) as an ORDERED step-by-step import guide. Each
	 * step is one accordion in the SPA modal: Theme Style → Variables → then
	 * the four palettes in dependency order (Semantic first because the rest
	 * reference its variables, then Brand, then Neutral, finally Notifications).
	 *
	 * Each step's `content` is the raw JSON string so the SPA can pretty-print
	 * it on the client (and so copy/download stays byte-stable). Missing files
	 * are skipped silently — the modal will simply show fewer steps rather
	 * than 500.
	 *
	 * @return array
	 */
	private static function load_bricks_exports() {
		$dir   = ADMINKIT_PATH . 'assets/bricks-export/';
		$theme = __( 'Bricks → Settings → Theme Styles', 'adminkit' );
		$vars  = __( 'Bricks → Settings → Variables', 'adminkit' );
		$pal   = __( 'Bricks → Settings → Color Palettes', 'adminkit' );

		$step = static function ( $key, $title, $hint, $filename, $download ) use ( $dir ) {
			$path = $dir . $filename;
			if ( ! is_readable( $path ) ) {
				return null;
			}
			return array(
				'key'      => $key,
				'title'    => $title,
				'hint'     => $hint,
				'filename' => $download,
				'content'  => file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			);
		};

		$steps = array(
			$step( 'theme-style',           __( 'Theme Style', 'adminkit' ),                       $theme, 'theme-style-waaskit.json', 'waaskit-theme-style.json' ),
			$step( 'variables',             __( 'Variables', 'adminkit' ),                         $vars,  'variables-categories.json', 'waaskit-variables.json' ),
			$step( 'palette-semantic',      __( 'Color palette — Semantic', 'adminkit' ),          $pal,   'palette-semantique.json',   'waaskit-palette-semantic.json' ),
			$step( 'palette-brand',         __( 'Color palette — Brand', 'adminkit' ),             $pal,   'palette-marque.json',       'waaskit-palette-brand.json' ),
			$step( 'palette-neutral',       __( 'Color palette — Neutral', 'adminkit' ),           $pal,   'palette-neutre.json',       'waaskit-palette-neutral.json' ),
			$step( 'palette-notifications', __( 'Color palette — Notifications', 'adminkit' ),     $pal,   'palette-notifications.json','waaskit-palette-notifications.json' ),
		);

		return array_values( array_filter( $steps ) );
	}

	/**
	 * Register an on/off toggle per integration so the UI can disable one in
	 * case of a conflict. Idempotent; called where the schema is needed (UI +
	 * save). Default ON.
	 *
	 * Also registers `generic_theming_off` — the per-plugin opt-out list used
	 * by `gate_generic_theming()`. Stored as an array of plugin file paths
	 * (the user has turned theming OFF for these). Default empty — every
	 * generic plugin is themed unless the user opts it out individually
	 * (one row, one switch in the Plugins tab).
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
		AdminKit_Settings::register( 'generic_theming_off', array(
			'type'     => 'array',
			'group'    => 'integrations',
			'default'  => array(),
			'sanitize' => static function ( $v ) {
				if ( ! is_array( $v ) ) {
					return array();
				}
				// Plugin file paths look like `acf/acf.php` — keep slashes + dots.
				$clean = array();
				foreach ( $v as $file ) {
					$file = sanitize_text_field( (string) $file );
					if ( '' !== $file ) {
						$clean[] = $file;
					}
				}
				return array_values( array_unique( $clean ) );
			},
		) );
	}

	/**
	 * Gate AdminKit's admin restyle depending on which plugin owns the current
	 * screen. Hooked on `adminkit/should_load` — the same filter that gates the
	 * `adminkit` body class in `AdminKit_Assets::add_admin_body_class()`. When
	 * this returns false, no CSS loads and `body.adminkit` is never added.
	 *
	 * Two paths:
	 *
	 *   Native adapter screen (owns_screen() returns true for some integration):
	 *     → integration toggle OFF → return false (nothing loads; WP native UI)
	 *     → integration toggle ON  → return true  (tokens + chrome + generic + adapter
	 *       all load; auto-theme suppressed separately via `adminkit/suppress_auto_theme`)
	 *
	 *   Generic plugin screen (no adapter):
	 *     → plugin in `generic_theming_off` → return false
	 *     → otherwise → return true (tokens + chrome + generic + auto-theme load)
	 *
	 * @param bool   $should_load
	 * @param string $context admin | login | frontend | editor.
	 * @return bool
	 */
	public static function gate_generic_theming( $should_load, $context ) {
		if ( 'admin' !== $context || ! $should_load ) {
			return $should_load;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return $should_load;
		}
		// Check native adapters first: if any adapter owns this screen, the
		// user's integration toggle is the sole gate — ON keeps AdminKit loading
		// (auto-theme suppressed separately via suppress_auto_theme_for_adapters()),
		// OFF removes AdminKit entirely from those pages.
		foreach ( self::integration_specs() as $s ) {
			if ( ! method_exists( $s['class'], 'owns_screen' ) ) {
				continue;
			}
			if ( ! call_user_func( array( $s['class'], 'owns_screen' ), $screen ) ) {
				continue;
			}
			// Screen is owned by this adapter — respect its toggle.
			return (bool) apply_filters( 'adminkit/integration_enabled', true, $s['slug'] );
		}
		// Generic plugin: check the per-plugin opt-out list.
		$off = (array) AdminKit_Settings::get( 'generic_theming_off' );
		if ( ! $off ) {
			return true; // nothing opted out — short-circuit before get_plugins()
		}
		$file = self::plugin_file_for_screen( $screen );
		return ! ( $file && in_array( $file, $off, true ) );
	}

	/**
	 * Suppress AdminKit's auto-theme on screens owned by a native adapter.
	 * Hooked on `adminkit/suppress_auto_theme` (fired in `AdminKit_Auto_Theme::enqueue()`).
	 * The adapter's own CSS handles all theming — auto-theme scanning correctly-themed
	 * elements in dark mode re-tags their already-correct computed colours as "muted",
	 * causing a visible flash when auto-theme.css overrides the adapter's rules.
	 *
	 * `integration_specs()` is already memoised (static $specs), so the loop is
	 * cheap — one array walk per auto-theme enqueue on plugin pages.
	 *
	 * @param bool             $suppress False by default.
	 * @param \WP_Screen|null  $screen
	 * @return bool
	 */
	public static function suppress_auto_theme_for_adapters( $suppress, $screen ) {
		if ( $suppress || ! $screen ) {
			return $suppress;
		}
		foreach ( self::integration_specs() as $s ) {
			if ( ! method_exists( $s['class'], 'owns_screen' ) ) {
				continue;
			}
			if ( ! apply_filters( 'adminkit/integration_enabled', true, $s['slug'] ) ) {
				continue; // disabled integration — auto-theme suppression not needed
			}
			if ( call_user_func( array( $s['class'], 'owns_screen' ), $screen ) ) {
				return true; // adapter active + owns screen → suppress auto-theme
			}
		}
		return false;
	}

	/**
	 * Best-effort screen → plugin file mapping. Pulls the slug out of the
	 * screen ID (`toplevel_page_<slug>` or `<parent>_page_<slug>`) and
	 * matches it against installed plugins' dirname / basename. Returns
	 * null on a WP core screen or when no plugin matches.
	 *
	 * Result is memoised per screen ID: the filter fires at least twice per
	 * request (body class + dispatch) and `get_plugins()` reads all plugin
	 * headers from disk on every call — the cache keeps it at one parse.
	 *
	 * @param \WP_Screen|null $screen
	 * @return string|null Plugin file path (`acf/acf.php`), or null.
	 */
	private static function plugin_file_for_screen( $screen ) {
		static $cache = array();
		if ( ! $screen ) {
			return null;
		}
		$id = (string) $screen->id;
		if ( array_key_exists( $id, $cache ) ) {
			return $cache[ $id ];
		}
		$slug = '';
		if ( 0 === strpos( $id, 'toplevel_page_' ) ) {
			$slug = substr( $id, strlen( 'toplevel_page_' ) );
		} elseif ( false !== strpos( $id, '_page_' ) ) {
			$slug = substr( $id, strpos( $id, '_page_' ) + strlen( '_page_' ) );
		}
		if ( '' === $slug ) {
			return ( $cache[ $id ] = null );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $_data ) {
			$dir  = dirname( $file );
			$base = basename( $file, '.php' );
			if ( '.' === $dir ) {
				$dir = $base;
			}
			if ( $slug === $dir || $slug === $base
				|| 0 === strpos( $slug, $dir . '-' ) || 0 === strpos( $slug, $dir . '_' ) ) {
				return ( $cache[ $id ] = $file );
			}
		}
		return ( $cache[ $id ] = null );
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
