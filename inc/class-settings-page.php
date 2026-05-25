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
 * (rendered read-only on the Design tab), the feature toggles, and the detected
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

		wp_enqueue_media(); // WordPress media frame, for the Branding logo pickers.
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
				'group'  => isset( $f['group'] ) ? $f['group'] : '',
				'label'  => $f['label'],
				'desc'   => $f['desc'],
				'parent' => isset( $f['parent'] ) ? $f['parent'] : '',
				'value'  => (bool) AdminKit_Settings::get( $f['key'] ),
				// `bulk => false` keeps a row out of the Enable all / Disable all sweep
				// (for an override that shouldn't be toggled in bulk).
				'bulk'   => ! isset( $f['bulk'] ) || (bool) $f['bulk'],
			);
		}

		// Plugins tab — the site's actually-active plugins/themes, each tagged as
		// natively themed (a tuned AdminKit adapter) or generic (base styles only).
		self::register_integration_toggles();
		$integrations = self::plugins_list();

		return array(
			'route'        => self::REST_NS . self::REST_ROUTE,
			'dashboard'    => self::dashboard( $features, $stored ),
			'colors'       => $colors,
			'providers'    => self::providers(),
			'features'     => $features,
			'integrations' => $integrations,
			'logos'        => array(
				'light' => (string) AdminKit_Settings::get( 'logo_light' ),
				'dark'  => (string) AdminKit_Settings::get( 'logo_dark' ),
			),
			'wpLogo'       => (string) AdminKit_Settings::get( 'wp_logo' ),
			'loginLogo'    => (string) AdminKit_Settings::get( 'login_logo' ),
			'hasSiteIcon'  => '' !== (string) get_site_icon_url(),
			'i18n'         => array(
				'dashboard'         => __( 'Dashboard', 'adminkit' ),
				'design'            => __( 'Design', 'adminkit' ),
				'features'          => __( 'Settings', 'adminkit' ),
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
				'plugins'           => __( 'Plugins', 'adminkit' ),
				'pluginsIntro'      => __( 'Your active plugins and themes. Native ones have a tuned AdminKit adapter you can switch per host; the rest inherit AdminKit\'s base styling automatically.', 'adminkit' ),
				'native'            => __( 'Native', 'adminkit' ),
				'generic'           => __( 'Generic', 'adminkit' ),
				'nativeHint'        => __( 'AdminKit ships a tuned adapter for this plugin — light and dark.', 'adminkit' ),
				'genericHint'       => __( 'No dedicated adapter — themed automatically by AdminKit\'s base styles.', 'adminkit' ),
				'themesLabel'       => __( 'Themes', 'adminkit' ),
				'wpLogoLabel'       => __( 'Admin bar', 'adminkit' ),
				'loginLogoLabel'    => __( 'Login screen', 'adminkit' ),
				'wpLogoBrand'       => __( 'Logo', 'adminkit' ),
				'wpLogoFavicon'     => __( 'Favicon', 'adminkit' ),
				'wpLogoHide'        => __( 'Hide', 'adminkit' ),
				'wpLogoInherit'     => __( 'Inherit', 'adminkit' ),
				'wpLogoNoIcon'      => __( 'No Site Icon set — the mark stays empty until you add one (Settings → General).', 'adminkit' ),
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
				'designLegendTitle' => __( 'Live colour reference', 'adminkit' ),
				'designLegend'      => __( 'Each row shows a live colour preview, the role, then its AdminKit token ← the WaasKit semantic it reads · the primitive it resolves from. Read-only — the palette is driven by your tokens.', 'adminkit' ),
				'typography'        => __( 'Typography', 'adminkit' ),
				'typographyDesc'    => __( 'Body font follows Bricks (--font-base) when set, otherwise Inter.', 'adminkit' ),
				'typeBody'          => __( 'Body', 'adminkit' ),
				'typeSmall'         => __( 'Small', 'adminkit' ),
				'typeCaption'       => __( 'Caption', 'adminkit' ),
				/* translators: pangram used as a font preview sample — translate to a sentence that exercises your language's letters. */
				'pangram'           => __( 'The quick brown fox jumps over the lazy dog', 'adminkit' ),
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
		$total   = count( $features );

		$cards = array(
			array(
				'label'  => __( 'Tokens', 'adminkit' ),
				'value'  => self::active_provider_label(),
				/* translators: %s: the selected accent colour value (e.g. #2563eb). */
				'hint'   => $primary ? sprintf( __( 'accent %s', 'adminkit' ), $primary ) : __( 'accent inherited', 'adminkit' ),
				'swatch' => '--ak-primary',
				'tab'    => 'design',
			),
			array(
				'label' => __( 'Features', 'adminkit' ),
				'value' => (string) $features_on,
				/* translators: %d: total number of feature modules. */
				'hint'  => sprintf( _n( 'of %d module on', 'of %d modules on', $total, 'adminkit' ), $total ),
				'icon'  => 'features',
				'tab'   => 'settings',
			),
		);

		/**
		 * Filter the AdminKit dashboard overview. Add overview cards via
		 * `$data['cards']`, or roadmap items via `$data['roadmap'][N]['items']`.
		 *
		 * @param array $data { intro, version, cards[], roadmap[] }
		 */
		// "Last updated" badge — the main plugin file's mtime (changes on every
		// release / deploy), localised to the site's date format. Dynamic, so it stays
		// truthful without manual upkeep.
		$updated = @filemtime( ADMINKIT_FILE );
		return apply_filters( 'adminkit/dashboard', array(
			'intro'         => __( 'A quick overview of your AdminKit setup.', 'adminkit' ),
			'version'       => 'v' . ADMINKIT_VERSION,
			'updated'       => $updated ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated ) : '',
			/* translators: %s = a date. */
			'updatedLabel'  => __( 'Updated', 'adminkit' ),
			'overviewLabel' => __( 'Overview', 'adminkit' ),
			'cards'         => $cards,
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
							'label'   => __( 'Generated avatars', 'adminkit' ),
							'desc'    => __( 'Auto-create a friendly avatar for users without a photo.', 'adminkit' ),
							'detail'  => __( 'When an account has no uploaded photo and no Gravatar, AdminKit fills the gap with a friendly, auto-generated avatar — so every user looks intentional instead of a blank silhouette. Avatars come from a hosted generator (nothing is stored on your site), stay the same for each user, and a real Gravatar always wins.', 'adminkit' ),
							'bullets' => array(
								__( 'Opt-in — pairs with Local avatars.', 'adminkit' ),
								__( 'Nothing stored: generated on demand.', 'adminkit' ),
								__( 'A real Gravatar still takes priority.', 'adminkit' ),
								__( 'Avatar style is overridable via a filter.', 'adminkit' ),
							),
						),
						array(
							'label'   => __( 'Roadmap detail view', 'adminkit' ),
							'desc'    => __( 'Hover a card, click for a clean detail panel.', 'adminkit' ),
							'detail'  => __( 'Every card on this roadmap is interactive: hover to highlight it, click to open a tidy panel describing the feature and where it stands — so this page stays a plan you can actually follow.', 'adminkit' ),
							'bullets' => array(
								__( 'Click any card for details.', 'adminkit' ),
								__( 'Keyboard and screen-reader friendly.', 'adminkit' ),
								__( 'Looks right in light and dark.', 'adminkit' ),
							),
						),
					),
				),
				array(
					'title' => __( 'Next', 'adminkit' ),
					'items' => array(
						array(
							'label'  => __( 'Login screen branding', 'adminkit' ),
							'desc'   => __( 'Your logo on the wp-login screen.', 'adminkit' ),
							'detail' => __( 'Carry the brand logo (with its light and dark variants) you already set for the admin bar onto the wp-login.php screen, replacing the WordPress mark — a small change with a big first-impression payoff.', 'adminkit' ),
						),
						array(
							'label'  => __( 'More native screens styled', 'adminkit' ),
							'desc'   => __( 'Taxonomies, custom post types and the rest.', 'adminkit' ),
							'detail' => __( 'Extend AdminKit\'s per-screen polish to the pages still wearing default WordPress styling: the category and tag editors, custom-post-type lists, and the few remaining core screens — so the whole admin feels like one product.', 'adminkit' ),
						),
						array(
							'label'  => __( 'In-app palette editor', 'adminkit' ),
							'desc'   => __( 'Pick accent, surface and text colours directly.', 'adminkit' ),
							'detail' => __( 'Turn today\'s read-only token map into a real editor: choose your accent, surfaces and text, preview the change live across wp-admin, and export the palette to a provider like Bricks.', 'adminkit' ),
						),
					),
				),
				array(
					'title' => __( 'Planned', 'adminkit' ),
					'items' => array(
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
							'label'  => __( 'Theme variants', 'adminkit' ),
							'desc'   => __( 'Beyond light and dark — sepia, high-contrast.', 'adminkit' ),
							'detail' => __( 'Ship additional admin themes on top of light and dark, including an accessibility-minded high-contrast variant.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Per-role visibility', 'adminkit' ),
							'desc'   => __( 'Choose which roles get the skin and who may edit it.', 'adminkit' ),
							'detail' => __( 'Decide which user roles see the AdminKit skin and which may change its settings — handy for client sites.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Accessibility checks', 'adminkit' ),
							'desc'   => __( 'Warn when a colour fails contrast.', 'adminkit' ),
							'detail' => __( 'Flag colour choices that fall below contrast and legibility thresholds, right where you pick them.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Custom dashboard widgets', 'adminkit' ),
							'desc'   => __( 'Replace the WP home screen.', 'adminkit' ),
							'detail' => __( 'Swap the default dashboard for quick actions, site status and recent activity that are actually useful.', 'adminkit' ),
						),
						array(
							'label'  => __( 'Import / export settings', 'adminkit' ),
							'desc'   => __( 'Clone an AdminKit setup across sites.', 'adminkit' ),
							'detail' => __( 'Save a whole AdminKit configuration to a file and import it on another site in one step — ideal for agencies.', 'adminkit' ),
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
						array(
							'label'  => __( 'White-label & admin footer', 'adminkit' ),
							'desc'   => __( 'Hide WordPress branding, add an agency credit.', 'adminkit' ),
							'detail' => __( 'Remove WordPress branding across the admin and add your own footer credit and version line.', 'adminkit' ),
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
	 * Feature toggles shown on the Settings tab, in display order. Keys match
	 * settings registered in AdminKit_Settings / AdminKit_Post_Previews.
	 *
	 * @return array
	 */
	private static function feature_descriptors() {
		// `group` is the section heading the Settings tab buckets a row under
		// (identical strings = same group; order = first-seen). A child inherits
		// its parent's group by carrying the same label.
		$content    = __( 'Content & lists', 'adminkit' );
		$appearance = __( 'Appearance & access', 'adminkit' );
		$rows = array(
			array( 'key' => 'post_previews_enabled', 'group' => $content,    'label' => __( 'Post previews', 'adminkit' ),             'desc' => __( 'Screenshot thumbnail column in post-type list tables.', 'adminkit' ) ),
			array( 'key' => 'post_previews_mshots',  'group' => $content,    'label' => __( 'Live screenshots (mShots)', 'adminkit' ), 'desc' => __( 'Use WordPress.com mShots. Off = featured image only (no external calls).', 'adminkit' ), 'parent' => 'post_previews_enabled' ),
			array( 'key' => 'theme_toggle_enabled',  'group' => $appearance, 'label' => __( 'Dark mode', 'adminkit' ),       'desc' => __( 'Enable dark mode with a toggle in the admin bar. Off forces light mode everywhere.', 'adminkit' ) ),
			array( 'key' => 'module_login_enabled',  'group' => $appearance, 'label' => __( 'Login screen', 'adminkit' ),              'desc' => __( 'Style the wp-login.php screen.', 'adminkit' ) ),
			array( 'key' => 'editor_content_theme',   'group' => $appearance, 'label' => __( 'Gutenberg', 'adminkit' ),     'desc' => __( 'Theme the Gutenberg block-editor canvas (content + native blocks) in light and dark. Turn off to keep the canvas matching your live site exactly.', 'adminkit' ) ),
			array( 'key' => 'replace_icons_enabled',  'group' => $appearance, 'label' => __( 'AdminKit icons', 'adminkit' ), 'desc' => __( 'Replace WordPress\'s native menu and toolbar icons with AdminKit\'s set. Non-destructive: icons already customised (e.g. via Admin Menu Editor) are left untouched.', 'adminkit' ) ),
			array( 'key' => 'local_avatars_enabled',  'group' => $appearance, 'label' => __( 'Local avatars', 'adminkit' ), 'desc' => __( 'Let users upload a profile picture that replaces Gravatar; anyone with no photo gets a friendly auto-generated face (via a hosted generator, disclosed in the readme). Off = Gravatar everywhere.', 'adminkit' ) ),
		);

		// Bricks builder restyle — only meaningful when the Bricks theme is active.
		if ( class_exists( 'AdminKit_Integration_Bricks' ) && AdminKit_Integration_Bricks::is_active() ) {
			$rows[] = array( 'key' => 'bricks_builder_enabled', 'group' => $appearance, 'label' => __( 'Bricks builder', 'adminkit' ), 'desc' => __( 'Restyle the Bricks builder UI with your tokens. Needs Bricks builder mode set to "Custom".', 'adminkit' ) );
		}

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
	 * of the site's active plugins AdminKit themes natively. Kept central (no
	 * per-adapter edits) and tolerant — free/pro variants are listed together. A
	 * miss degrades gracefully: the plugin simply shows as "generic" rather than
	 * "native" (never a duplicate row). Theme adapters (e.g. Bricks) aren't here —
	 * they're matched from the active theme set instead.
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
	 * Build the Plugins-tab list: every ACTIVE plugin on the site (plus AdminKit's
	 * active theme adapters), each tagged as natively themed (a tuned AdminKit
	 * adapter — toggleable, dark-mode included) or generic (no dedicated adapter,
	 * so it inherits AdminKit's base token styling). Nothing dormant is listed —
	 * the tab mirrors what's actually running, so there's no "inactive" state.
	 *
	 * Row shape: slug (adapter slug when native, '' when generic), label (adapter
	 * label when native, the plugin's own name when generic), type, supported
	 * (bool), enabled (adapter toggle — native rows only). Native first, then
	 * alphabetical.
	 *
	 * @return array<int, array>
	 */
	private static function plugins_list() {
		// Active adapters, keyed by slug.
		$active = array();
		foreach ( self::integration_specs() as $s ) {
			if ( call_user_func( array( $s['class'], 'is_active' ) ) ) {
				$active[ $s['slug'] ] = $s;
			}
		}

		// Map active PLUGIN adapters' host files → slug (themes matched below).
		$hosts   = self::integration_host_files();
		$by_file = array();
		foreach ( $active as $slug => $s ) {
			if ( 'plugin' !== $s['type'] ) {
				continue;
			}
			foreach ( (array) ( isset( $hosts[ $slug ] ) ? $hosts[ $slug ] : array() ) as $file ) {
				$by_file[ $file ] = $slug;
			}
		}

		$rows = array();

		// 1) Active plugins on the site.
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$self = plugin_basename( ADMINKIT_FILE ); // don't list AdminKit in its own tab
		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_plugin_active( $file ) || $file === $self ) {
				continue;
			}
			$slug      = isset( $by_file[ $file ] ) ? $by_file[ $file ] : '';
			$supported = ( '' !== $slug );
			$name      = '' !== (string) $data['Name'] ? $data['Name'] : $file;
			$rows[]    = array(
				'slug'      => $slug,
				'label'     => $supported ? $active[ $slug ]['label'] : $name,
				'type'      => 'plugin',
				'supported' => $supported,
				'enabled'   => $supported ? (bool) AdminKit_Settings::get( 'integration_' . $slug . '_enabled' ) : false,
			);
		}

		// 2) Active THEME adapters (e.g. Bricks) — themes aren't in get_plugins(),
		// so add them straight from the active adapter set. Always native.
		foreach ( $active as $slug => $s ) {
			if ( 'theme' !== $s['type'] ) {
				continue;
			}
			$rows[] = array(
				'slug'      => $slug,
				'label'     => $s['label'],
				'type'      => 'theme',
				'supported' => true,
				'enabled'   => (bool) AdminKit_Settings::get( 'integration_' . $slug . '_enabled' ),
			);
		}

		// Native first, then alphabetical — supported integrations lead each group.
		usort( $rows, static function ( $a, $b ) {
			if ( $a['supported'] !== $b['supported'] ) {
				return $a['supported'] ? -1 : 1;
			}
			return strcasecmp( $a['label'], $b['label'] );
		} );

		return $rows;
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
