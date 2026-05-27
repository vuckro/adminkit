<?php
/**
 * Settings page — the admin UI for AdminKit.
 *
 * AdminKit's SPA tabs (Dashboard / Settings / Plugins) are hosted INSIDE
 * Settings → General — same `options-general.php` URL that WP's own General
 * tabs use, just three extra tabs in the merged tab strip. No separate
 * AdminKit menu entry. The PHP side here only:
 *   - enqueues the SPA assets on the options-general screen + hands the app
 *     its data via `window.AdminKitData`,
 *   - exposes one REST route (`adminkit/v1/settings`) the SPA POSTs to,
 *   - redirects the legacy `?page=adminkit` URL to the merged page for
 *     back-compat with any bookmark / pre-merge upgrader.
 *
 * The data is built from the settings registry: the semantic token taxonomy
 * (rendered read-only on the Design tab), the feature toggles, and the detected
 * integrations. Saving runs each known field through its registered `sanitize`
 * callback and persists only registered keys.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Settings_Page {

	/** Legacy ?page= slug kept ONLY for the back-compat redirect. The SPA now
	 *  lives on the bare `options-general.php` URL — there's no `Settings →
	 *  AdminKit` submenu, no `settings_page_adminkit` screen hook. The
	 *  constant value matches what older bookmarks / external links may carry
	 *  (e.g. `options-general.php?page=adminkit`); see legacy_redirect(). */
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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Back-compat: `?page=adminkit` was the previous home of the SPA before
		// the merge. Redirect any visit there to the merged page so old
		// bookmarks + upgrade paths land on the right tab.
		add_action( 'admin_init', array( __CLASS__, 'legacy_redirect' ) );
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
	 * Redirect the legacy `options-general.php?page=adminkit` URL to the
	 * merged page. Pre-merge, the SPA lived under that `page` param; any old
	 * bookmark / dashboard quick-link / upgrade-path call lands there and now
	 * needs to be sent to the Dashboard tab of the unified page. The hash
	 * triggers `options-general.js`'s URL-fragment routing so the user lands
	 * directly on the right tab instead of the default Site identity.
	 *
	 * Capability gate matches the SPA's REST permission (manage_options) so
	 * an unauthorised visitor doesn't reveal the page even via redirect.
	 *
	 * @return void
	 */
	public static function legacy_redirect() {
		if ( empty( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'options-general.php#dashboard' ) );
		exit;
	}

	/**
	 * Prepend a "Settings" link to the plugin row actions on plugins.php so the
	 * admin can jump straight to AdminKit's Dashboard tab from the Plugins
	 * screen — no detour via the Settings menu.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public static function plugin_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php#dashboard' ) ),
			esc_html__( 'Settings', 'adminkit' )
		);
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Enqueue the SPA assets on the merged `options-general` screen and hand
	 * the app its data. The style depends on `adminkit-tokens` so
	 * `var(--ak-*)` resolves; the script depends on `wp-api-fetch` (which
	 * also wires the REST nonce). `wp_enqueue_media()` powers the
	 * brand-logo picker on the Dashboard tab — loaded on every General
	 * visit (simpler than lazy-loading; cost paid once per page view).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'options-general.php' !== $hook ) {
			return;
		}

		$css = 'assets/css/settings.css';
		$js  = 'assets/js/settings.js';

		wp_enqueue_media(); // WordPress media frame, for the Branding logo pickers.
		wp_enqueue_style( self::HANDLE, ADMINKIT_URL . $css, array( AdminKit_Assets::TOKENS_HANDLE ), self::ver( $css ) );
		// `adminkit-options-general` is the handle the merged tab strip script
		// registers under (see AdminKit_Core_Options_General::enqueue). Declaring it
		// as a dep forces WordPress to print + execute it BEFORE settings.js, so the
		// `<section data-adminkit-panel="…">` placeholders the SPA mounts into already
		// exist in the DOM when this script runs. Without it the SPA bails (no panels
		// to render into) and the AdminKit tabs come up empty.
		wp_enqueue_script( self::HANDLE, ADMINKIT_URL . $js, array( 'wp-api-fetch', 'adminkit-options-general' ), self::ver( $js ), true );
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

		// Note: the LIGHT favicon (WP's native `site_icon`) is no longer
		// posted through this route. WordPress's own Site Icon row on the
		// Site identity tab handles it via the standard options.php POST;
		// the AdminKit Brand card only owns the dark-mode companion.

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

		$colors = array();
		foreach ( AdminKit_Settings::color_map() as $group ) {
			$tokens = array();
			foreach ( $group['tokens'] as $t ) {
				$tokens[] = array(
					'token'         => $t['token'],
					'label'         => $t['label'],
					'bricks'        => isset( $t['bricks'] ) ? $t['bricks'] : '',
					'source'        => isset( $t['source'] ) ? $t['source'] : '',
					'own'           => ! empty( $t['own'] ),
					// Accent-family tokens (--ak-primary*, --ak-on-accent, --ak-focus)
					// follow `accent_source` rather than the static `bricks` field.
					// Read by sourcePill() in the SPA to colour the Source pill.
					'accent_family' => ! empty( $t['accent_family'] ),
				);
			}
			$colors[] = array(
				'group'  => $group['group'],
				'label'  => $group['label'],
				'desc'   => isset( $group['desc'] ) ? $group['desc'] : '',
				'tokens' => $tokens,
			);
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
			'dashboard'    => self::dashboard(),
			'colors'       => $colors,
			'providers'    => self::providers(),
			'features'     => $features,
			'integrations' => $integrations,
			'logos'        => array(
				'light' => (string) AdminKit_Settings::get( 'logo_light' ),
				'dark'  => (string) AdminKit_Settings::get( 'logo_dark' ),
			),
			// Dark-mode favicon — light one comes from WP's native `site_icon`
			// (see `siteIcon` below), this is AdminKit's own paired storage.
			'faviconDark'  => (string) AdminKit_Settings::get( 'favicon_dark' ),
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
				'dashboard'         => __( 'Dashboard', 'adminkit' ),
				'design'            => __( 'Design', 'adminkit' ),
				// "Preferences" rather than "Settings" / "Features" so the AdminKit
				// tab label reads distinctly from WordPress's own Settings (Réglages)
				// menu — same string is used by both the embedded and standalone modes.
				'features'          => __( 'Preferences', 'adminkit' ),
				'soon'              => __( 'Coming soon', 'adminkit' ),
				'own'               => __( 'AdminKit', 'adminkit' ),
				'ownHint'           => __( 'AdminKit-defined role (no provider equivalent).', 'adminkit' ),
				'featuresIntro'     => __( 'Turn AdminKit modules on or off.', 'adminkit' ),
				'enableAll'         => __( 'Enable all', 'adminkit' ),
				'disableAll'        => __( 'Disable all', 'adminkit' ),
				'branding'          => __( 'Branding', 'adminkit' ),
				'logoHint'          => __( 'Your brand logo, light and dark. Ideally a horizontal SVG (crisp at any size), or a PNG at least ~320×80px (≈ 4:1), on a transparent background. Used for the site-title mark, the login screen and the Bricks builder.', 'adminkit' ),
				'logoDisplay'       => __( 'Logo display', 'adminkit' ),
				'logoDisplayHint'   => __( 'Choose how the logo shows in each location.', 'adminkit' ),
				'logoLight'         => __( 'Logo — light mode', 'adminkit' ),
				'logoDark'          => __( 'Logo — dark mode', 'adminkit' ),
				'logoLightMode'     => __( 'Light Mode', 'adminkit' ),
				'logoDarkMode'      => __( 'Dark Mode', 'adminkit' ),
				'logoPlaceholder'   => __( 'https://…/logo.svg', 'adminkit' ),
				'logoPick'          => __( 'Choose a logo', 'adminkit' ),
				'logoChange'        => __( 'Change logo', 'adminkit' ),
				'logoRemove'        => __( 'Remove logo', 'adminkit' ),
				'mediaTitle'        => __( 'Select a logo', 'adminkit' ),
				'mediaButton'       => __( 'Use this image', 'adminkit' ),
				// Cropper modal — used only by the dark favicon slot (light favicon
				// is WP's native Site Icon, edited outside the SPA). Labels stay
				// generic so they read naturally in the cropping flow.
				'mediaSiteIconTitle'  => __( 'Choose a favicon', 'adminkit' ),
				'mediaSiteIconButton' => __( 'Use this image', 'adminkit' ),
				'plugins'           => __( 'Plugins', 'adminkit' ),
				'pluginsIntro'      => __( 'Every plugin installed on your site, plus AdminKit\'s active theme adapters. Native ones have a tuned adapter you can switch per host; the rest carry a Generic badge and inherit AdminKit\'s base styling automatically.', 'adminkit' ),
				'native'            => __( 'Native', 'adminkit' ),
				'nativeHint'        => __( 'AdminKit ships a tuned adapter for this plugin — light and dark.', 'adminkit' ),
				'system'            => __( 'System', 'adminkit' ),
				'systemHint'        => __( 'AdminKit itself — always on and not removable here.', 'adminkit' ),
				'generic'           => __( 'Generic', 'adminkit' ),
				'genericHint'       => __( 'No dedicated adapter — themed automatically by AdminKit\'s base layer.', 'adminkit' ),
				'themesLabel'       => __( 'Themes', 'adminkit' ),
				// Display row — short location labels so the segmented controls feel
				// like "where does the brand mark show up?" rather than reciting WP
				// internals. "WordPress" covers the admin bar (top toolbar in wp-admin
				// AND the front-end logged-in toolbar — same DOM); "Login" is the
				// wp-login.php screen.
				'wpLogoLabel'       => __( 'WordPress', 'adminkit' ),
				'loginLogoLabel'    => __( 'Login', 'adminkit' ),
				'wpLogoBrand'       => __( 'Logo', 'adminkit' ),
				'wpLogoFavicon'     => __( 'Favicon', 'adminkit' ),
				'wpLogoInherit'     => __( 'Inherit', 'adminkit' ),
				'save'              => __( 'Save changes', 'adminkit' ),
				'saving'            => __( 'Saving…', 'adminkit' ),
				'saved'             => __( 'Saved', 'adminkit' ),
				'error'             => __( 'Could not save', 'adminkit' ),
				'light'             => __( 'Light', 'adminkit' ),
				'dark'              => __( 'Dark', 'adminkit' ),
				'mode'              => __( 'Mode', 'adminkit' ),
				'unsaved'           => __( 'Unsaved changes', 'adminkit' ),
				'close'             => __( 'Close', 'adminkit' ),
				'details'           => __( 'Details', 'adminkit' ),
				'roadmapHint'       => __( 'Click a card for details.', 'adminkit' ),
				'roadmapVerifyLabel' => __( 'To verify', 'adminkit' ),
				'roadmapVerifyHint'  => __( 'Done — confirm so it can be removed from the roadmap.', 'adminkit' ),
				'roadmapStarHint'    => __( 'Game-changer', 'adminkit' ),
				'designLegendTitle' => __( 'Live colour reference', 'adminkit' ),
				'designLegend'      => __( 'Each row shows a live colour preview, the role, then its AdminKit token ← the WaasKit semantic it reads · the primitive it resolves from. Read-only — the palette is driven by your tokens.', 'adminkit' ),
				'typography'        => __( 'Typography', 'adminkit' ),
				'typographyDesc'    => __( 'Body font follows Bricks (--font-base) when set, otherwise Inter.', 'adminkit' ),
				'typeTitle'         => __( 'Font & sizes', 'adminkit' ),
				'typeBody'          => __( 'Body', 'adminkit' ),
				'typeSmall'         => __( 'Small', 'adminkit' ),
				'typeCaption'       => __( 'Caption', 'adminkit' ),
				/* translators: pangram used as a font preview sample — translate to a sentence that exercises your language's letters. */
				'pangram'           => __( 'The quick brown fox jumps over the lazy dog', 'adminkit' ),

				// --- Brand card (Dashboard secondary card on Site identity) ------
				'brandEyebrow'        => __( 'Brand', 'adminkit' ),
				'brandTitle'          => __( 'Logo, favicon & accent', 'adminkit' ),
				'brandSyncStatus'     => __( 'Tokens synced with Bricks Builder', 'adminkit' ),
				/* translators: %d: number of CSS custom properties exposed by Bricks. */
				'brandSyncStatusCount' => __( '%d tokens', 'adminkit' ),
				// Slot titles read as a coherent set — "<Kind> <Mode>" — with the
				// mode word following the kind so the eye scans the kind first.
				// The LIGHT favicon is intentionally absent: it's WP's native
				// `site_icon`, edited via the Site Icon row sitting alongside on
				// the same tab. Duplicating it here just stacked the same picker.
				'slotLight'           => __( 'Logo Light Mode', 'adminkit' ),
				'slotLightSub'        => __( 'SVG · PNG ≥ 400×100', 'adminkit' ),
				'slotDark'            => __( 'Logo Dark Mode', 'adminkit' ),
				'slotDarkSub'         => __( 'SVG · PNG ≥ 400×100', 'adminkit' ),
				'slotFaviconDark'     => __( 'Favicon Dark Mode', 'adminkit' ),
				'slotFaviconDarkSub'  => __( 'Auto-swap via prefers-color-scheme', 'adminkit' ),
				'slotUpload'          => __( 'Upload', 'adminkit' ),
				'slotRemove'          => __( 'Remove', 'adminkit' ),
				'slotMediaLib'        => __( 'Media library', 'adminkit' ),
				'accentLabel'         => __( 'Color', 'adminkit' ),
				'accentInherit'       => __( 'Inheriting from provider / baseline', 'adminkit' ),
				'accentClear'         => __( 'Clear accent', 'adminkit' ),
				// Accent picker — first option is labelled "WordPress" rather than
				// "AdminKit" because it represents the standard WordPress accent
				// (WP block-editor blue) when no provider supplies one. The Bricks
				// option is rendered ONLY when the integration is connected (see
				// `bricksConnected` in boot data).
				'accentSrcAdminKit'   => __( 'WordPress', 'adminkit' ),
				'accentSrcBricks'     => __( 'Bricks', 'adminkit' ),
				'accentSrcCustom'     => __( 'Custom', 'adminkit' ),
				'accentSrcBricksHint' => __( 'Bricks not detected', 'adminkit' ),
				'sourceCustom'        => __( 'Custom', 'adminkit' ),
				'accentShowDerived'   => __( 'Show derived colours', 'adminkit' ),
				'accentHideDerived'   => __( 'Hide derived colours', 'adminkit' ),
				'derivedHover'        => __( 'Hover', 'adminkit' ),
				'derivedSubtle'       => __( 'Subtle', 'adminkit' ),
				'derivedOnAccent'     => __( 'On accent', 'adminkit' ),
				'derivedOnAccentSub'  => __( 'readable', 'adminkit' ),
				'derivedFocus'        => __( 'Focus', 'adminkit' ),
				'derivedFocusSub'     => __( '@ 50%', 'adminkit' ),
				'displayLabel'        => __( 'Display', 'adminkit' ),
				// Brand-card Action — the only one left after the Phase A cleanup.
				// Opens the Bricks-export modal (4 sections of JSON templates).
				'actionExport'        => __( 'Export to Bricks', 'adminkit' ),
				// Export modal (Design tab).
				'exportTitle'         => __( 'Export to Bricks', 'adminkit' ),
				'exportIntro'         => __( 'Follow the steps in order — open each one, copy or download the file, then import it where indicated.', 'adminkit' ),
				'exportCopy'          => __( 'Copy', 'adminkit' ),
				'exportCopied'        => __( 'Copied', 'adminkit' ),
				'exportDownload'      => __( 'Download .json', 'adminkit' ),
				'exportClose'         => __( 'Close', 'adminkit' ),
				// Bulk action shared by the Features tab and the Plugins tab —
				// reverts every row to its registered schema default.
				'resetDefaults'       => __( 'Reset to defaults', 'adminkit' ),
				'tokensCtaTitle'      => __( 'Want to dig in?', 'adminkit' ),
				'tokensCtaSub'        => __( 'Browse every token AdminKit exposes — read-only reference.', 'adminkit' ),
				/* translators: %d is the total number of design tokens (resolved at render time). */
				'tokensCtaBtnFmt'     => __( 'View all %d tokens', 'adminkit' ),
				'tokensRefEyebrow'    => __( 'Reference', 'adminkit' ),
				'tokensRefTitle'      => __( 'Token map', 'adminkit' ),
				'tokensRefSub'        => __( 'Read-only. AdminKit derives every token from the provider/baseline cascade.', 'adminkit' ),
				'tokensRefHide'       => __( 'Hide', 'adminkit' ),
				'colToken'            => __( 'Token', 'adminkit' ),
				'colCascade'          => __( 'Cascade', 'adminkit' ),
				'colValue'            => __( 'Value', 'adminkit' ),
				'colSource'           => __( 'Source', 'adminkit' ),
				'sourceBricks'        => __( 'Bricks', 'adminkit' ),
				'sourceAuto'          => __( 'Auto', 'adminkit' ),
				'sourceAdminKit'      => __( 'AdminKit', 'adminkit' ),
			),
		);
	}

	/**
	 * Dashboard tab meta — version chip, last-updated badge, roadmap columns.
	 * The Brand card + tokens reference take the main slot (rendered client-side
	 * by buildDesign()), so this only ships the data the roadmap section needs.
	 *
	 * @return array
	 */
	private static function dashboard() {
		/**
		 * Filter the AdminKit dashboard data. Add roadmap items via
		 * `$data['roadmap'][N]['items']`. The legacy `cards[]` + `overviewLabel`
		 * keys are gone — the SPA no longer renders an Overview hero strip
		 * (the Brand card from the former Design tab took its place).
		 *
		 * @param array $data { version, updated, updatedLabel, roadmapLabel, roadmap[] }
		 */
		// "Last updated" badge — the main plugin file's mtime (changes on every
		// release / deploy), localised to the site's date format. Dynamic, so it stays
		// truthful without manual upkeep.
		$updated = @filemtime( ADMINKIT_FILE );
		return apply_filters( 'adminkit/dashboard', array(
			'version'       => 'v' . ADMINKIT_VERSION,
			'updated'       => $updated ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated ) : '',
			/* translators: %s = a date. */
			'updatedLabel'  => __( 'Updated', 'adminkit' ),
			'roadmapLabel'  => __( 'Roadmap', 'adminkit' ),
			// AdminKit's roadmap — THE single source. Keep it coherent with what's
			// actually being built, and mirror it in README.md's Roadmap section when
			// it changes (see CLAUDE.md → "Keep the docs alive").
			// Columns render left→right in this order: In progress · Next · Planned.
			// Each item: label (card title), desc (one-line on the card), detail
			// (paragraph shown in the click-through modal) and optional bullets[].
			'roadmap'       => array(
				array(
					'title' => __( 'In progress', 'adminkit' ),
					'items' => array(
						array(
							'label'  => __( 'Universal plugin compatibility', 'adminkit' ),
							'desc'   => __( 'Any plugin looks right, even without an adapter.', 'adminkit' ),
							'detail' => __( 'Strengthen the shared base layer — notices, meta-boxes, tabs, modals, common form controls — so a plugin with no dedicated adapter still looks coherent in light and dark out of the box. The stronger this layer, the less per-plugin work is ever needed.', 'adminkit' ),
							'star'   => true,
						),
						array(
							'label'  => __( 'Custom dashboard page', 'adminkit' ),
							'desc'   => __( 'A redesigned WordPress home screen that\'s actually useful.', 'adminkit' ),
							'detail' => __( 'Replace the default WordPress dashboard with a polished home screen built on native data — site health, content snapshots, recent activity, quick actions — without new database tables or heavy machinery. Useful first, decorative second.', 'adminkit' ),
							'star'   => true,
						),
					),
				),
				array(
					'title' => __( 'Next', 'adminkit' ),
					'items' => array(
						array(
							'label'  => __( 'More native screens styled', 'adminkit' ),
							'desc'   => __( 'Taxonomies, custom post types and the rest.', 'adminkit' ),
							'detail' => __( 'Extend AdminKit\'s per-screen polish to the pages still wearing default WordPress styling: the category and tag editors, custom-post-type lists, and the few remaining core screens — so the whole admin feels like one product.', 'adminkit' ),
						),
						array(
							'label'  => __( 'In-app palette editor', 'adminkit' ),
							'desc'   => __( 'Pick accent, surface and text colours directly.', 'adminkit' ),
							'detail' => __( 'Turn today\'s read-only token map into a real editor: choose your accent, surfaces and text, preview the change live across wp-admin, and export the palette to a provider like Bricks.', 'adminkit' ),
							'star'   => true,
						),
						array(
							'label'  => __( 'Colour sync', 'adminkit' ),
							'desc'   => __( 'Pull colours from your provider or theme.', 'adminkit' ),
							'detail' => __( 'Read the active provider or theme palette and keep AdminKit in sync automatically, so the admin always matches the brand without manual edits.', 'adminkit' ),
						),
						array(
							'label'  => __( 'More provider adapters', 'adminkit' ),
							'desc'   => __( 'Beyond Bricks — ACSS, Core Framework, Oxygen, Elementor…', 'adminkit' ),
							'detail' => __( 'Inherit brand colours from more page builders and frameworks (Automatic.css, Core Framework, Oxygen, Elementor, GeneratePress), the same way the Bricks adapter works today.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Accessibility checks', 'adminkit' ),
							'desc'   => __( 'Warn when a colour fails contrast.', 'adminkit' ),
							'detail' => __( 'Flag colour choices that fall below contrast and legibility thresholds, right where you pick them.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Import / export settings', 'adminkit' ),
							'desc'   => __( 'Clone an AdminKit setup across sites.', 'adminkit' ),
							'detail' => __( 'Save a whole AdminKit configuration to a file and import it on another site in one step — ideal for agencies.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Per-role visibility', 'adminkit' ),
							'desc'   => __( 'Choose which roles get the skin and who may edit it.', 'adminkit' ),
							'detail' => __( 'Decide which user roles see the AdminKit skin and which may change its settings — handy for client sites.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Admin-bar polish', 'adminkit' ),
							'desc'   => __( 'Front and back admin bar, refined.', 'adminkit' ),
							'detail' => __( 'Finish the admin bar end to end — the same on the front end and back end, with tidy submenus, clear keyboard focus, and a clean responsive layout on mobile.', 'adminkit' ),
						),
					),
				),
				array(
					'title' => __( 'Planned', 'adminkit' ),
					'items' => array(
						array(
							'label'  => __( 'Command palette', 'adminkit' ),
							'desc'   => __( 'Jump anywhere with a keystroke.', 'adminkit' ),
							'detail' => __( 'A ⌘K / Ctrl-K launcher to jump straight to any admin page, setting or post — no more hunting through menus.', 'adminkit' ),
							'star'   => true,
						),
						array(
							'label'  => __( 'Theme variants', 'adminkit' ),
							'desc'   => __( 'Beyond light and dark — sepia, high-contrast.', 'adminkit' ),
							'detail' => __( 'Ship additional admin themes on top of light and dark, including an accessibility-minded high-contrast variant.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Per-user theme preference', 'adminkit' ),
							'desc'   => __( 'Each user picks their own mode.', 'adminkit' ),
							'detail' => __( 'Let every user choose their own light/dark mode and accent, saved per account, so the admin feels personal without changing it for anyone else.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Admin notices manager', 'adminkit' ),
							'desc'   => __( 'Tame the notice clutter.', 'adminkit' ),
							'detail' => __( 'Gather WordPress\'s scattered admin notices into one tidy, collapsible area so update nags and plugin messages stop shoving your content down the page.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Native menu editor', 'adminkit' ),
							'desc'   => __( 'Reorder, rename and hide menu items.', 'adminkit' ),
							'detail' => __( 'A lightweight, native way to reorder, rename or hide admin-menu items per role — the essentials, without a separate plugin.', 'adminkit' ),
						),
						array(
							'label'  => __( 'White-label & admin footer', 'adminkit' ),
							'desc'   => __( 'Hide WordPress branding, add an agency credit.', 'adminkit' ),
							'detail' => __( 'Remove WordPress branding across the admin and add your own footer credit and version line.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Custom admin CSS', 'adminkit' ),
							'desc'   => __( 'Drop in your own admin tweaks.', 'adminkit' ),
							'detail' => __( 'A small sanitised field for your own admin CSS on top of AdminKit\'s tokens — for the one-off tweak without a child plugin.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Density / compact mode', 'adminkit' ),
							'desc'   => __( 'Comfortable or compact spacing.', 'adminkit' ),
							'detail' => __( 'An optional denser layout that tightens spacing across wp-admin for power users, while keeping the comfortable default.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Typography controls', 'adminkit' ),
							'desc'   => __( 'Choose the admin font and scale.', 'adminkit' ),
							'detail' => __( 'Pick the admin font family and base size from sensible presets so the whole admin matches your brand\'s type.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Bricks dynamic logo tag', 'adminkit' ),
							'desc'   => __( 'Use your AdminKit logo anywhere in Bricks.', 'adminkit' ),
							'detail' => __( 'Expose the configured brand logo as a Bricks dynamic-data tag, so it can be dropped into any Bricks design and stays in sync.', 'adminkit' ),
						),
						array(
							'label'  => __( 'WordPress Playground demo', 'adminkit' ),
							'desc'   => __( 'Try AdminKit live in the browser.', 'adminkit' ),
							'detail' => __( 'A one-click WordPress Playground link so anyone can try AdminKit in the browser with no install — great for the README and the .org listing.', 'adminkit' ),
						),
					),
				),
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
