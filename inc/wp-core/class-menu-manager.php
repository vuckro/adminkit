<?php
/**
 * Menu manager — applies the saved admin-menu layout (top + submenu order, icon
 * overrides, hidden entries) edited in Settings → AdminKit → Menu, and feeds the
 * editor its data. Storage lives in AdminKit_Menu_Store; the glyph set in
 * AdminKit_Icons.
 *
 * Runtime model:
 *   - admin_menu @ 9998  capture a PRISTINE snapshot of $menu/$submenu (settings
 *                        page only) for the editor — taken before our own apply so
 *                        the editor always sees every item + its original icon, even
 *                        when the feature is toggled off.
 *   - admin_menu @ 9999  APPLY: reorder $submenu, swap icons (class + [6]='none'),
 *                        remove hidden entries.
 *   - menu_order filter  reorder the top-level menus.
 *   - admin_head @ 22    emit the per-item icon CSS (currentColor mask → dark-mode
 *                        aware), after the icon feature (21).
 *   - REST POST /adminkit/v1/menu  persist a layout (manage_options).
 *
 * Hiding here is menu-level only (declutter), not access control — direct URLs stay
 * reachable. Per-role access is the deferred v2 layer (the store reserves a column).
 *
 * Filter:
 *   adminkit/menu_manager/enabled  (bool)  master on/off for APPLICATION (the editor
 *                                          stays available regardless).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Menu_Manager {

	/** @var array Pristine menu snapshot for the editor (built on the settings page). */
	private static $snapshot = array();

	/** @var array<string,string> Per-request map of injected class => icon (AdminKit name or svg data-URI). */
	private static $icon_overrides = array();

	/** @var bool Whether any item was tagged hidden this request (so the hide rule is emitted). */
	private static $has_hidden = false;

	/** @var array<string,string> Submenu slug => new parent, for items moved across sections (highlight remap). */
	private static $moved = array();

	/**
	 * Register the setting + wire hooks. The snapshot capture is always wired (the
	 * editor needs it even when off); application is gated on the toggle.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'menu_manager_enabled', array( 'default' => true ) );

		// Ensure the table exists before admin_menu (apply) reads it — `init` fires
		// earlier than admin_menu; gate to admin so the front end never touches it.
		if ( is_admin() ) {
			add_action( 'init', array( 'AdminKit_Menu_Store', 'ensure_schema' ) );
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'capture_snapshot' ), 9998 );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'apply' ), 9999 );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( __CLASS__, 'order_top_level' ) );
		add_filter( 'parent_file', array( __CLASS__, 'remap_parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'remap_submenu_file' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_css' ), 22 );
	}

	/**
	 * Master switch — gates APPLICATION only (the editor tab is always available so
	 * a layout can be prepared while off).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/menu_manager/enabled', AdminKit_Settings::get( 'menu_manager_enabled' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Capture (editor data)                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Snapshot the pristine menu on the AdminKit settings screen, before apply()
	 * mutates it. Cheap no-op on every other screen.
	 *
	 * @return void
	 */
	public static function capture_snapshot() {
		if ( ! self::is_settings_screen() ) {
			return;
		}
		global $menu, $submenu;
		self::$snapshot = self::build_snapshot( (array) $menu, (array) $submenu );
	}

	/**
	 * Whether the current request is the AdminKit settings page.
	 *
	 * @return bool
	 */
	private static function is_settings_screen() {
		// Read-only screen check (no state change), so no nonce needed.
		if ( ! is_admin() || empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		return AdminKit_Settings_Page::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Turn the live $menu/$submenu globals into a clean tree for the editor:
	 *   [ { slug, title, dashicon, children:[ { slug, title } ] }, … ]
	 * Separators and capability-less placeholders are dropped.
	 *
	 * @param array $menu
	 * @param array $submenu
	 * @return array
	 */
	private static function build_snapshot( $menu, $submenu ) {
		$out = array();
		foreach ( $menu as $item ) {
			if ( empty( $item[2] ) ) {
				continue;
			}
			$classes = ( isset( $item[4] ) && is_string( $item[4] ) ) ? $item[4] : '';
			if ( false !== strpos( $classes, 'wp-menu-separator' ) ) {
				// Capture native separators as positionable separator rows (so a reorder
				// keeps them where they belong instead of clustering them at the bottom).
				$out[] = array(
					'slug'     => (string) $item[2],
					'type'     => 'separator',
					'title'    => '',
					'children' => array(),
				);
				continue;
			}
			$slug = (string) $item[2];
			$node = array(
				'slug'     => $slug,
				'title'    => self::clean_title( isset( $item[0] ) ? $item[0] : '' ),
				'dashicon' => self::dashicon_class( isset( $item[6] ) ? $item[6] : '' ),
				'children' => array(),
			);
			if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					if ( empty( $sub[2] ) ) {
						continue;
					}
					$node['children'][] = array(
						'slug'  => (string) $sub[2],
						'title' => self::clean_title( isset( $sub[0] ) ? $sub[0] : '' ),
					);
				}
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * Strip count bubbles / update badges (and any other markup) from a menu title.
	 *
	 * @param string $title
	 * @return string
	 */
	private static function clean_title( $title ) {
		$title = preg_replace( '/<span[^>]*>.*?<\/span>/is', '', (string) $title );
		$title = wp_strip_all_tags( (string) $title );
		return trim( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * The dashicon class an item currently uses (so the editor can preview it), or ''
	 * for image / SVG / data-URI icons.
	 *
	 * @param mixed $icon
	 * @return string
	 */
	private static function dashicon_class( $icon ) {
		return ( is_string( $icon ) && 0 === strpos( $icon, 'dashicons-' ) ) ? $icon : '';
	}

	/**
	 * Editor payload for boot_data(): the pristine tree, the saved layout, the icon
	 * picker set, and the save route.
	 *
	 * @return array
	 */
	public static function editor_data() {
		return array(
			'menu'   => self::$snapshot,
			'config' => AdminKit_Menu_Store::get_config(),
			'icons'  => AdminKit_Icons::set(),
			'route'  => '/adminkit/v1/menu',
			'self'   => AdminKit_Settings_Page::SLUG,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Apply                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Apply icon overrides + hide + submenu reorder. Top-level order is handled by
	 * order_top_level() via the menu_order filter.
	 *
	 * @return void
	 */
	public static function apply() {
		$config = AdminKit_Menu_Store::get_config();
		if ( empty( $config['top'] ) && empty( $config['sub'] ) ) {
			return;
		}
		global $menu, $submenu;

		$top_by_slug = array();
		foreach ( $config['top'] as $t ) {
			$top_by_slug[ $t['slug'] ] = $t;
		}

		// Top-level: hide or swap icon.
		$n = 0;
		foreach ( (array) $menu as $pos => $item ) {
			if ( empty( $item[2] ) || ! isset( $top_by_slug[ $item[2] ] ) ) {
				continue;
			}
			$cfg = $top_by_slug[ $item[2] ];
			if ( isset( $cfg['title'] ) && '' !== $cfg['title'] ) {
				$menu[ $pos ][0] = $cfg['title'];
			}
			if ( ! empty( $cfg['hidden'] ) ) {
				// Hide via CSS (keep the entry so the page title still resolves and the
				// user can't lock themselves out). Never hide AdminKit's own menu.
				if ( AdminKit_Settings_Page::SLUG !== $item[2] ) {
					$menu[ $pos ][4] = trim( ( isset( $menu[ $pos ][4] ) ? $menu[ $pos ][4] : '' ) . ' ak-mh' );
					self::$has_hidden = true;
				}
				continue;
			}
			if ( ! empty( $cfg['icon'] ) && self::is_icon( $cfg['icon'] ) ) {
				$cls = 'ak-mi-' . ( ++$n );
				$menu[ $pos ][4] = trim( ( isset( $menu[ $pos ][4] ) ? $menu[ $pos ][4] : '' ) . ' ' . $cls );
				$menu[ $pos ][6] = 'none';
				self::$icon_overrides[ $cls ] = (string) $cfg['icon'];
			}
		}

		// Submenus: reshuffle. Build the desired state for every configured child, then
		// walk the live submenu — rename + hide in place, and MOVE items whose configured
		// parent differs (cross-section), recording each move for the parent_file /
		// submenu_file highlight remap. Then reorder every configured parent.
		$desired = array();
		foreach ( $config['sub'] as $parent => $subs ) {
			foreach ( $subs as $s ) {
				if ( empty( $s['slug'] ) ) {
					continue;
				}
				$desired[ $s['slug'] ] = array(
					'parent' => $parent,
					'hidden' => ! empty( $s['hidden'] ),
					'title'  => isset( $s['title'] ) ? (string) $s['title'] : '',
				);
			}
		}
		foreach ( $submenu as $cur_parent => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $i => $item ) {
				if ( empty( $item[2] ) || ! isset( $desired[ $item[2] ] ) ) {
					continue;
				}
				$d = $desired[ $item[2] ];
				if ( '' !== $d['title'] ) {
					$item[0] = $d['title'];
				}
				if ( $d['hidden'] ) {
					$item[4]          = trim( ( isset( $item[4] ) ? $item[4] : '' ) . ' ak-mh' );
					self::$has_hidden = true;
				}
				if ( $cur_parent !== $d['parent'] ) {
					unset( $submenu[ $cur_parent ][ $i ] );
					$submenu[ $d['parent'] ][] = $item;
					self::$moved[ $item[2] ]   = $d['parent'];
				} else {
					$submenu[ $cur_parent ][ $i ] = $item;
				}
			}
		}
		foreach ( $config['sub'] as $parent => $subs ) {
			self::reorder_submenu( $parent, $subs );
		}

		// Native WP separators: keep only the ones the layout still lists, so a reorder
		// doesn't leave the rest clustered at the bottom. Then collect every slug already
		// in $menu so a custom item is never injected as a duplicate.
		$config_top = array();
		foreach ( $config['top'] as $t ) {
			$config_top[ $t['slug'] ] = true;
		}
		foreach ( (array) $menu as $pos => $item ) {
			if ( empty( $item[2] ) || isset( $config_top[ $item[2] ] ) ) {
				continue;
			}
			if ( isset( $item[4] ) && is_string( $item[4] ) && false !== strpos( $item[4], 'wp-menu-separator' ) ) {
				unset( $menu[ $pos ] );
			}
		}
		$existing = array();
		foreach ( (array) $menu as $item ) {
			if ( ! empty( $item[2] ) ) {
				$existing[ $item[2] ] = true;
			}
		}

		// Inject custom links + separators that aren't already in the WP menu (the
		// custom ak-sep / link entries). They land before the menu_order filter so they
		// position with the rest; real menus + native separators are skipped (already there).
		foreach ( $config['top'] as $t ) {
			$type = isset( $t['type'] ) ? $t['type'] : 'item';
			if ( 'item' === $type || isset( $existing[ $t['slug'] ] ) ) {
				continue;
			}
			if ( 'separator' === $type ) {
				$classes = 'wp-menu-separator';
				if ( ! empty( $t['hidden'] ) ) {
					$classes         .= ' ak-mh';
					self::$has_hidden = true;
				}
				$menu[] = array( '', 'read', $t['slug'], '', $classes, '', '' );
			} elseif ( 'link' === $type ) {
				$icon                         = ( ! empty( $t['icon'] ) && self::is_icon( $t['icon'] ) ) ? (string) $t['icon'] : 'app';
				$cls                          = 'ak-mi-' . ( ++$n );
				$classes                      = 'menu-top ' . $cls;
				self::$icon_overrides[ $cls ] = $icon;
				if ( ! empty( $t['hidden'] ) ) {
					$classes         .= ' ak-mh';
					self::$has_hidden = true;
				}
				$title  = ( isset( $t['title'] ) && '' !== $t['title'] ) ? $t['title'] : $t['slug'];
				$menu[] = array( $title, 'read', $t['slug'], $title, $classes, '', 'none' );
			}
		}
	}

	/**
	 * Reorder $submenu[$parent] by the configured positions; unconfigured items keep
	 * their relative order at the end. Reassigned as a 0-indexed array so the order
	 * holds however WordPress iterates it.
	 *
	 * @param string $parent
	 * @param array  $config_subs
	 * @return void
	 */
	private static function reorder_submenu( $parent, $config_subs ) {
		global $submenu;

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$order = array();
		foreach ( $config_subs as $s ) {
			$order[ $s['slug'] ] = (int) $s['position'];
		}

		// Decorate each item with its rank (configured position, else a stable
		// after-the-end value), sort, then strip back to the bare items.
		$decorated = array();
		foreach ( array_values( $submenu[ $parent ] ) as $i => $item ) {
			$slug        = isset( $item[2] ) ? (string) $item[2] : '';
			$rank        = isset( $order[ $slug ] ) ? $order[ $slug ] : ( 100000 + $i );
			$decorated[] = array(
				'rank' => $rank,
				'i'    => $i,
				'item' => $item,
			);
		}
		usort(
			$decorated,
			static function ( $a, $b ) {
				return ( $a['rank'] <=> $b['rank'] ) ?: ( $a['i'] <=> $b['i'] );
			}
		);

		$new = array();
		foreach ( $decorated as $row ) {
			$new[] = $row['item'];
		}
		$submenu[ $parent ] = $new;
	}

	/**
	 * menu_order filter: configured top-level slugs first (by position), then any
	 * unlisted items (new menus, separators) in their original order. Hidden items
	 * stay in $menu (CSS-hidden, not removed), so they keep their slot here —
	 * display:none collapses them visually, so it's harmless.
	 *
	 * @param array $menu_order
	 * @return array
	 */
	public static function order_top_level( $menu_order ) {
		$config = AdminKit_Menu_Store::get_config();
		if ( empty( $config['top'] ) || ! is_array( $menu_order ) ) {
			return $menu_order;
		}

		$ranks = array();
		foreach ( $config['top'] as $t ) {
			$ranks[ $t['slug'] ] = (int) $t['position'];
		}
		asort( $ranks );

		$ordered = array();
		foreach ( array_keys( $ranks ) as $slug ) {
			if ( in_array( $slug, $menu_order, true ) ) {
				$ordered[] = $slug;
			}
		}
		foreach ( $menu_order as $slug ) {
			if ( ! in_array( $slug, $ordered, true ) ) {
				$ordered[] = $slug;
			}
		}
		return $ordered;
	}

	/**
	 * `parent_file` remap: when the current page is a submenu we moved to a new
	 * parent, report that new parent so its section opens + highlights.
	 *
	 * @param string $parent_file
	 * @return string
	 */
	public static function remap_parent_file( $parent_file ) {
		$slug = self::current_menu_slug();
		return ( '' !== $slug && isset( self::$moved[ $slug ] ) ) ? self::$moved[ $slug ] : $parent_file;
	}

	/**
	 * `submenu_file` remap: mark a moved submenu as the current item.
	 *
	 * @param string|null $submenu_file
	 * @return string|null
	 */
	public static function remap_submenu_file( $submenu_file ) {
		$slug = self::current_menu_slug();
		return ( '' !== $slug && isset( self::$moved[ $slug ] ) ) ? $slug : $submenu_file;
	}

	/**
	 * The current screen's menu slug, shaped like a $submenu item's [2] (a plugin
	 * page, a post-type list, or the plain pagenow file).
	 *
	 * @return string
	 */
	private static function current_menu_slug() {
		global $pagenow, $typenow, $plugin_page;
		if ( ! empty( $plugin_page ) ) {
			return (string) $plugin_page;
		}
		if ( ! empty( $typenow ) && ! empty( $pagenow ) ) {
			return $pagenow . '?post_type=' . $typenow;
		}
		return isset( $pagenow ) ? (string) $pagenow : '';
	}

	/**
	 * Emit the per-item icon CSS collected during apply(). Same mask technique as the
	 * icon feature (currentColor → tracks light/dark); higher specificity than the
	 * stock-dashicon rule, and [6]='none' already dropped the original glyph.
	 *
	 * @return void
	 */
	public static function print_css() {
		$css = self::$has_hidden ? '#adminmenu .ak-mh{display:none}' : '';
		foreach ( self::$icon_overrides as $cls => $icon ) {
			$mask = self::icon_mask( $icon );
			if ( '' === $mask ) {
				continue;
			}
			$sel  = '#adminmenu .' . $cls . ' .wp-menu-image';
			$css .= $sel . '{box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
			$css .= $sel . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
				. 'vertical-align:middle;position:relative;top:-2px;' . $mask . '}';
		}
		if ( '' !== $css ) {
			echo '<style id="adminkit-menu-manager">' . $css . "</style>\n"; // SVGs are URL-encoded / data-URI masks.
		}
	}

	/**
	 * Whether a stored icon value is usable: a known AdminKit icon name, or a custom
	 * SVG supplied as a data:image/svg+xml URI.
	 *
	 * @param mixed $icon
	 * @return bool
	 */
	private static function is_icon( $icon ) {
		return is_string( $icon ) && ( AdminKit_Icons::has( $icon ) || 0 === strpos( $icon, 'data:image/svg+xml' ) );
	}

	/**
	 * currentColor mask declarations for a stored icon value (name or data-URI), or ''
	 * if unusable. data-URIs are masked directly (quotes are stripped at save time so
	 * the url() can't break out); a name resolves through the icon registry.
	 *
	 * @param string $icon
	 * @return string
	 */
	private static function icon_mask( $icon ) {
		if ( is_string( $icon ) && 0 === strpos( $icon, 'data:image/svg+xml' ) ) {
			$uri = 'url("' . $icon . '")';
			return 'background-color:currentColor;-webkit-mask:' . $uri . ' center/20px 20px no-repeat;mask:' . $uri . ' center/20px 20px no-repeat;';
		}
		$svg = AdminKit_Icons::svg( $icon );
		return '' === $svg ? '' : AdminKit_Icons::mask( $svg );
	}

	/* --------------------------------------------------------------------- */
	/* REST                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Register the save route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'adminkit/v1',
			'/menu',
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
	 * Persist a submitted layout.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_save( $request ) {
		AdminKit_Menu_Store::ensure_schema();
		$items_in = $request->get_param( 'items' );
		$items    = self::sanitize_items( is_array( $items_in ) ? $items_in : array() );
		AdminKit_Menu_Store::save_config( $items );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Sanitise submitted rows. Icons are validated against the registry; slugs are
	 * trimmed/length-capped but otherwise preserved (they must match live menu slugs
	 * like `edit.php?post_type=page`). Unknown slugs simply never match at apply
	 * time, and $wpdb->insert parameterises the write — so this is light by design.
	 *
	 * @param array $items
	 * @return array
	 */
	private static function sanitize_items( $items ) {
		$clean = array();
		foreach ( $items as $it ) {
			if ( empty( $it['slug'] ) || ! is_string( $it['slug'] ) ) {
				continue;
			}
			$parent = ( isset( $it['parent'] ) && is_string( $it['parent'] ) ) ? $it['parent'] : '';
			$type   = ( '' === $parent && isset( $it['type'] ) && in_array( $it['type'], array( 'link', 'separator' ), true ) ) ? $it['type'] : 'item';
			$icon   = ( '' === $parent && isset( $it['icon'] ) ) ? self::sanitize_icon( $it['icon'] ) : '';
			$title  = ( isset( $it['title'] ) && is_string( $it['title'] ) ) ? sanitize_text_field( $it['title'] ) : '';
			// A custom link stores its destination URL as the slug; everything else is a plain slug.
			$slug   = ( 'link' === $type ) ? esc_url_raw( $it['slug'] ) : self::sanitize_slug( $it['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			$clean[] = array(
				'parent'   => self::sanitize_slug( $parent ),
				'slug'     => $slug,
				'position' => isset( $it['position'] ) ? (int) $it['position'] : 0,
				'icon'     => $icon,
				'hidden'   => ! empty( $it['hidden'] ),
				'type'     => $type,
				'title'    => $title,
			);
		}
		return $clean;
	}

	/**
	 * Trim + length-cap a menu slug without mangling its query string / extension.
	 *
	 * @param string $slug
	 * @return string
	 */
	private static function sanitize_slug( $slug ) {
		$slug = wp_strip_all_tags( (string) $slug );
		return substr( trim( $slug ), 0, 190 );
	}

	/**
	 * Sanitise an icon value: a known AdminKit icon NAME, or a custom SVG given as a
	 * `data:image/svg+xml` URI (raw `<svg>` markup is wrapped into one). Anything else
	 * → '' (default). Capped at 16 KB and stripped of `"` so it can't break out of the
	 * CSS url() it is masked through (it is never inserted as HTML).
	 *
	 * @param mixed $icon
	 * @return string
	 */
	private static function sanitize_icon( $icon ) {
		if ( ! is_string( $icon ) || '' === $icon ) {
			return '';
		}
		if ( AdminKit_Icons::has( $icon ) ) {
			return $icon;
		}
		$icon = trim( $icon );
		if ( 0 === stripos( $icon, '<svg' ) ) {
			$icon = 'data:image/svg+xml,' . rawurlencode( $icon );
		}
		if ( 0 !== stripos( $icon, 'data:image/svg+xml' ) || strlen( $icon ) > 16384 ) {
			return '';
		}
		return str_replace( '"', '', $icon );
	}
}
