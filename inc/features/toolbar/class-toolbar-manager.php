<?php
/**
 * Toolbar manager — reorder, rename, swap icons and hide the TOP-LEVEL admin-bar nodes
 * (a focused cousin of the Menu manager). Top level only; no custom items.
 *
 * Snapshot: the admin bar renders too late in the page to capture before the
 * SPA's boot data, so the editor pulls the live top-level nodes over REST. The
 * REST reader builds the bar in-request (mirroring _wp_admin_bar_init) WITHOUT our
 * own apply() hook, so the editor always sees the PRISTINE bar (every node, native
 * order) regardless of the saved layout.
 *
 * Apply: on `admin_bar_menu` very late (after every plugin has added its nodes),
 * on BOTH wp-admin and the front-end bar (logged-in). Hide = remove_node(); reorder
 * = re-add the kept nodes in the saved order (each node's children ride along — they
 * reference the parent id, which is re-added with the same id), applying any custom
 * title or icon override along the way. Reordering is within a node's own group
 * (WP's left default group vs the right `top-secondary`), so the account cluster
 * stays on the right.
 *
 * Storage: a single autoload-off option (the layout is small — a list of ids, a
 * hidden set, and per-node title/icon maps), not a table.
 *
 * Filters:
 *   adminkit/toolbar_manager/enabled  (bool)    master on/off (mirrors the toggle)
 *   adminkit/toolbar/capability       (string)  cap to view/save (default manage_options)
 *   adminkit/toolbar/protected_nodes  (string[])ids that can never be hidden/removed
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Toolbar_Manager {

	/**
	 * Option holding per-context toolbar layouts (`admin` and `frontend`), each shaped as
	 * `{ order: [id…], hidden: [id…], titles: {id:title}, icons: {id:icon} }`. Legacy flat
	 * `{ order, hidden, titles, icons }` options migrate transparently on read.
	 */
	const OPTION = 'adminkit_toolbar';

	const REST_NS    = 'adminkit/v1';
	const REST_ROUTE = '/toolbar';

	/**
	 * Transient holding the PRISTINE admin-context top-level nodes, captured during a real
	 * admin-bar render (admin context). The REST reader prefers it over an in-request
	 * rebuild: some nodes are `is_admin()`-gated (the command palette, for one) and never
	 * appear in the REST context, so a REST-only snapshot would silently miss them.
	 */
	const SNAPSHOT_KEY          = 'adminkit_toolbar_nodes';
	const FRONTEND_SNAPSHOT_KEY = 'adminkit_toolbar_nodes_frontend';

	/** Submenu page hook suffix (set when the page registers); gates enqueue. */
	private static $page_hook = '';

	/** @var array<string,string> Per-request map of `#wp-admin-bar-<id>` => custom icon override. */
	private static $icon_overrides = array();

	/** @var array<string,bool> Per-request set of `#wp-admin-bar-<id>` nodes that paint on `> .ab-item::before`. */
	private static $ab_item_overrides = array();

	/**
	 * Core WP admin-bar node id => native dashicons codepoint + whether it paints on
	 * `.ab-icon` or `> .ab-item::before`, for restoring the original WP dashicon when a
	 * user explicitly pins a toolbar node back to `wp-default`.
	 *
	 * @var array<string,array{glyph:string,target:string}>
	 */
	private static $toolbar_dashicon_glyphs = array(
		'wp-logo'         => array( 'glyph' => '\\f120', 'target' => 'ab-icon' ),
		'site-name'       => array( 'glyph' => '\\f226', 'target' => 'ab-item' ),
		'site-editor'     => array( 'glyph' => '\\f100', 'target' => 'ab-item' ),
		'updates'         => array( 'glyph' => '\\f463', 'target' => 'ab-icon' ),
		'comments'        => array( 'glyph' => '\\f101', 'target' => 'ab-icon' ),
		'new-content'     => array( 'glyph' => '\\f132', 'target' => 'ab-icon' ),
		'edit'            => array( 'glyph' => '\\f464', 'target' => 'ab-item' ),
		'customize'       => array( 'glyph' => '\\f540', 'target' => 'ab-item' ),
		'command-palette' => array( 'glyph' => '\\f179', 'target' => 'ab-icon' ),
		'search'          => array( 'glyph' => '\\f179', 'target' => 'search' ),
		'menu-toggle'     => array( 'glyph' => '\\f333', 'target' => 'ab-icon' ),
	);

	/**
	 * Register the setting; bind the editor route and application only when enabled.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'toolbar_manager_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );


		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// Capture the live front-end bar when an administrator views the site so the
		// Toolbar editor's "Front end" mode reflects any theme/plugin front-end items.
		add_action( 'admin_bar_menu', array( __CLASS__, 'capture_frontend' ), 99998 );

		// Apply on BOTH bars (wp-admin + front-end for logged-in users), very late so
		// every node — core + plugins — already exists.
		add_action( 'admin_bar_menu', array( __CLASS__, 'apply' ), 99999 );
		add_action( 'admin_head', array( __CLASS__, 'print_css' ), 22 );
		add_action( 'wp_head', array( __CLASS__, 'print_css' ), 22 );
		add_action( 'update_option_' . AdminKit_Settings::OPTION_KEY, array( __CLASS__, 'flush_snapshots' ) );
		add_action( 'update_option_site_icon', array( __CLASS__, 'flush_snapshots' ) );
	}

	/**
	 * Clear cached node snapshots when branding or site-icon settings change so the
	 * editor immediately reflects the updated `site-name` / `wp-logo` state.
	 *
	 * @return void
	 */
	public static function flush_snapshots() {
		delete_transient( self::SNAPSHOT_KEY );
		delete_transient( self::FRONTEND_SNAPSHOT_KEY );
	}

	/**
	 * Master switch for the editor and toolbar application.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/toolbar_manager/enabled', AdminKit_Settings::get( 'toolbar_manager_enabled' ) );
	}

	/** Capability required to view + save the toolbar layout (filterable). */
	private static function capability() {
		return apply_filters( 'adminkit/toolbar/capability', 'manage_options' );
	}

	/**
	 * Node ids that must never be hidden/removed — keeps the user from locking
	 * themselves out of their account menu / the AdminKit-facing bits. Filterable.
	 *
	 * @return array<string,bool> id => true
	 */
	private static function protected_nodes() {
		$ids = (array) apply_filters( 'adminkit/toolbar/protected_nodes', array(
			'wp-admin-bar-my-account', // the account menu (sign-out lives under it)
			'wp-admin-bar-menu-toggle', // the responsive bar toggle
		) );
		return array_fill_keys( array_map( 'strval', $ids ), true );
	}

	/* ─────────────────────────── Admin page ─────────────────────────── */

	/**
	 * Capture the PRISTINE bar on the AdminKit settings screen (admin context → the full
	 * node set, incl. is_admin()-gated nodes the REST context can't see), just before
	 * apply() @ 99999. Written server-side now; read by the SPA's REST GET a moment
	 * later in the same page load.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . AdminKit_Settings_Page::SLUG !== $hook ) {
			return;
		}
		add_action( 'admin_bar_menu', array( __CLASS__, 'capture' ), 99998 );
	}

	/**
	 * Boot payload merged into window.AdminKitData. The SPA uses `enabled` to decide
	 * whether to render, and `route` to fetch the live nodes + saved layout.
	 *
	 * @return array
	 */
	public static function boot_data() {
		return array(
			'enabled' => self::is_enabled() && current_user_can( self::capability() ),
			'route'   => self::REST_NS . self::REST_ROUTE,
		);
	}

	/* ─────────────────────────── Config ─────────────────────────── */

	/**
	 * Sanitise a single context slice (`admin` or `frontend`) from raw option or REST data.
	 *
	 * @param mixed              $raw
	 * @param array<string,bool> $protected
	 * @return array{order:string[],hidden:string[],titles:array<string,string>,icons:array<string,string>}
	 */
	private static function sanitize_context_slice( $raw, $protected = array() ) {
		if ( ! is_array( $raw ) ) {
			return array(
				'order'  => array(),
				'hidden' => array(),
				'titles' => array(),
				'icons'  => array(),
			);
		}
		$order = array();
		if ( isset( $raw['order'] ) && is_array( $raw['order'] ) ) {
			foreach ( $raw['order'] as $id ) {
				$id = self::sanitize_node_id( $id );
				if ( '' !== $id ) {
					$order[] = $id;
				}
			}
		}
		$hidden = array();
		if ( isset( $raw['hidden'] ) && is_array( $raw['hidden'] ) ) {
			foreach ( $raw['hidden'] as $id ) {
				$id = self::sanitize_node_id( $id );
				if ( '' !== $id && ! isset( $protected[ $id ] ) ) {
					$hidden[] = $id;
				}
			}
		}
		$titles = array();
		if ( isset( $raw['titles'] ) && is_array( $raw['titles'] ) ) {
			foreach ( $raw['titles'] as $id => $t ) {
				$id = self::sanitize_node_id( $id );
				$t  = is_string( $t ) ? sanitize_text_field( $t ) : '';
				if ( '' !== $id && '' !== $t ) {
					$titles[ $id ] = $t;
				}
			}
		}
		$icons = array();
		if ( isset( $raw['icons'] ) && is_array( $raw['icons'] ) ) {
			foreach ( $raw['icons'] as $id => $ic ) {
				$id = self::sanitize_node_id( $id );
				$ic = self::sanitize_icon( $ic );
				if ( '' !== $id && '' !== $ic ) {
					$icons[ $id ] = $ic;
				}
			}
		}
		return array(
			'order'  => array_values( array_unique( $order ) ),
			'hidden' => array_values( array_unique( $hidden ) ),
			'titles' => $titles,
			'icons'  => $icons,
		);
	}

	/**
	 * The saved layout: either for a specific context (`'admin'` | `'frontend'`) or the
	 * full per-context map when `$context` is omitted. Legacy flat `{ order, hidden,
	 * titles, icons }` options migrate transparently to the `admin` context on read.
	 *
	 * @param string|null $context Optional `'admin'` or `'frontend'`.
	 * @return array
	 */
	public static function get_config( $context = null ) {
		$raw       = get_option( self::OPTION, array() );
		$protected = self::protected_nodes();
		if ( is_array( $raw ) && ( isset( $raw['admin'] ) || isset( $raw['frontend'] ) ) ) {
			$all = array(
				'admin'    => self::sanitize_context_slice( isset( $raw['admin'] ) ? $raw['admin'] : array(), $protected ),
				'frontend' => self::sanitize_context_slice( isset( $raw['frontend'] ) ? $raw['frontend'] : array(), $protected ),
			);
		} else {
			$all = array(
				'admin'    => self::sanitize_context_slice( $raw, $protected ),
				'frontend' => self::sanitize_context_slice( array(), $protected ),
			);
		}
		if ( 'admin' === $context || 'frontend' === $context ) {
			return $all[ $context ];
		}
		return $all;
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	/**
	 * GET  adminkit/v1/toolbar — the pristine top-level nodes + the saved layout.
	 * POST adminkit/v1/toolbar — persist { admin: {...}, frontend: {...} }.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => static function () {
						return current_user_can( self::capability() );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => static function () {
						return current_user_can( self::capability() );
					},
				),
			)
		);
	}

	/**
	 * GET payload — the pristine top-level nodes for both `admin` and `frontend`
	 * contexts + the saved per-context layouts.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get() {
		$admin_nodes = get_transient( self::SNAPSHOT_KEY );
		if ( ! is_array( $admin_nodes ) || ! $admin_nodes ) {
			$admin_nodes = self::snapshot_nodes();
		}
		$admin_nodes = self::filter_context_nodes( $admin_nodes, 'admin' );

		$frontend_nodes = get_transient( self::FRONTEND_SNAPSHOT_KEY );
		if ( ! is_array( $frontend_nodes ) || ! $frontend_nodes ) {
			$frontend_nodes = self::snapshot_frontend_nodes( $admin_nodes );
		} else {
			$frontend_nodes = self::ensure_frontend_nodes( self::filter_context_nodes( $frontend_nodes, 'frontend' ) );
		}

		$configs = self::get_config();
		return rest_ensure_response( array(
			'nodes'           => $admin_nodes,
			'nodesByContext'  => array(
				'admin'    => $admin_nodes,
				'frontend' => $frontend_nodes,
			),
			'config'          => $configs['admin'],
			'configByContext' => $configs,
		) );
	}

	/**
	 * Capture the live top-level nodes from a REAL admin-bar render (admin context) into a
	 * transient, BEFORE apply() reorders/hides. Hooked only on the Toolbar page (via
	 * enqueue()), so it's free everywhere else.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function capture( $bar ) {
		if ( is_object( $bar ) ) {
			set_transient( self::SNAPSHOT_KEY, self::extract_nodes( $bar, 'admin' ), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Capture the live front-end admin-bar nodes when an administrator visits the public
	 * site, BEFORE apply() reorders/hides them.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function capture_frontend( $bar ) {
		if ( is_admin() || ! is_object( $bar ) || ! is_admin_bar_showing() || ! current_user_can( self::capability() ) ) {
			return;
		}
		$nodes = self::ensure_frontend_nodes( self::extract_nodes( $bar, 'frontend' ) );
		set_transient( self::FRONTEND_SNAPSHOT_KEY, $nodes, HOUR_IN_SECONDS );
	}

	/**
	 * In-request FALLBACK snapshot: build a throwaway bar (our apply() detached) and
	 * extract its top-level nodes. Runs in the CALLER's context (REST = not is_admin()),
	 * so it can miss `is_admin()`-gated nodes — exactly why capture() is preferred.
	 *
	 * @return array<int,array{id:string,label:string,group:string,defaultIcon:string,dashicon:string}>
	 */
	private static function snapshot_nodes() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		remove_action( 'admin_bar_menu', array( __CLASS__, 'capture_frontend' ), 99998 );
		remove_action( 'admin_bar_menu', array( __CLASS__, 'apply' ), 99999 );
		$bar = new WP_Admin_Bar();
		$bar->initialize();
		$bar->add_menus();
		do_action( 'admin_bar_menu', $bar );
		add_action( 'admin_bar_menu', array( __CLASS__, 'capture_frontend' ), 99998 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'apply' ), 99999 );

		return self::extract_nodes( $bar, 'admin' );
	}

	/**
	 * Synthesize the front-end toolbar node list when no front-end page has been visited
	 * yet since the transient cleared. Adapts the admin snapshot to the front-end context
	 * (dropping wp-admin-only nodes and adding front-end core nodes like `site-editor` /
	 * `customize`, `edit`, and `search`).
	 *
	 * @param array<int,array> $admin_nodes
	 * @return array<int,array>
	 */
	private static function snapshot_frontend_nodes( $admin_nodes ) {
		$admin_only = array(
			'menu-toggle'                      => true,
			'command-palette'                  => true,
			'ak-notifications'                 => true,
			'woocommerce-site-visibility-badge' => true,
			'archive'                          => true,
		);
		$out = array();
		foreach ( $admin_nodes as $node ) {
			$id = isset( $node['id'] ) ? (string) $node['id'] : '';
			if ( '' === $id || isset( $admin_only[ $id ] ) ) {
				continue;
			}
			if ( 'ak-view-site' === $id ) {
				$defaults            = self::node_default_icons( 'ak-view-site', 'frontend' );
				$node['label']       = __( 'Dashboard', 'adminkit' );
				$node['defaultIcon'] = $defaults['defaultIcon'];
				$node['dashicon']    = $defaults['dashicon'];
			}
			$out[] = $node;
		}
		return self::ensure_frontend_nodes( $out );
	}

	/**
	 * Ensure context-dependent front-end core nodes (`site-editor` or `customize`,
	 * `edit`, and `search`) are always present in the front-end editor list even if the
	 * snapshot was captured on an archive/home view where `edit` wasn't rendered.
	 *
	 * @param array<int,array> $nodes
	 * @return array<int,array>
	 */
	private static function ensure_frontend_nodes( $nodes ) {
		$by_id = array();
		foreach ( $nodes as $n ) {
			if ( isset( $n['id'] ) ) {
				$by_id[ $n['id'] ] = $n;
			}
		}

		$is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$extra_after    = array();

		if ( $is_block_theme && ! isset( $by_id['site-editor'] ) ) {
			$defaults                    = self::node_default_icons( 'site-editor', 'frontend' );
			$extra_after['site-name'][] = array(
				'id'          => 'site-editor',
				'label'       => __( 'Edit Site' ),
				'group'       => 'root',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		} elseif ( ! $is_block_theme && ! isset( $by_id['customize'] ) ) {
			$defaults                    = self::node_default_icons( 'customize', 'frontend' );
			$extra_after['site-name'][] = array(
				'id'          => 'customize',
				'label'       => __( 'Customize' ),
				'group'       => 'root',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}

		if ( ! isset( $by_id['edit'] ) ) {
			$defaults    = self::node_default_icons( 'edit', 'frontend' );
			$pt_obj      = function_exists( 'get_post_type_object' ) ? get_post_type_object( 'page' ) : null;
			$edit_label  = ( $pt_obj && isset( $pt_obj->labels->edit_item ) ) ? $pt_obj->labels->edit_item : __( 'Edit Page' );
			$anchor      = isset( $by_id['new-content'] ) ? 'new-content' : 'site-name';
			$extra_after[ $anchor ][] = array(
				'id'          => 'edit',
				'label'       => $edit_label,
				'group'       => 'root',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}

		$out = array();
		foreach ( $nodes as $n ) {
			$out[] = $n;
			$id    = isset( $n['id'] ) ? $n['id'] : '';
			if ( isset( $extra_after[ $id ] ) ) {
				foreach ( $extra_after[ $id ] as $ins ) {
					$out[] = $ins;
				}
				unset( $extra_after[ $id ] );
			}
		}
		foreach ( $extra_after as $ins_list ) {
			foreach ( $ins_list as $ins ) {
				$out[] = $ins;
			}
		}

		if ( ! isset( $by_id['search'] ) ) {
			$defaults = self::node_default_icons( 'search', 'frontend' );
			$out[]    = array(
				'id'          => 'search',
				'label'       => __( 'Search' ),
				'group'       => 'secondary',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}

		return $out;
	}

	/**
	 * Refresh context-dependent metadata (such as hiding `wp-logo` and updating
	 * `site-name`'s default icon when `branding_enabled` is active) on a cached snapshot.
	 *
	 * @param array<int,array> $nodes
	 * @param string           $context `'admin'` | `'frontend'`
	 * @return array<int,array>
	 */
	private static function filter_context_nodes( $nodes, $context ) {
		$branding = (bool) AdminKit_Settings::get( 'branding_enabled' );
		$out      = array();
		foreach ( $nodes as $n ) {
			if ( ! is_array( $n ) || empty( $n['id'] ) ) {
				continue;
			}
			$id = (string) $n['id'];
			if ( $branding && 'wp-logo' === $id ) {
				continue;
			}
			$defaults         = self::node_default_icons( $id, $context );
			$n['defaultIcon'] = $defaults['defaultIcon'];
			$n['dashicon']    = $defaults['dashicon'];
			$out[]            = $n;
		}
		return $out;
	}

	/**
	 * Extract + classify the TOP-LEVEL items from a built admin bar into the editor's
	 * shape. Shared by capture() (real bar) and snapshot_nodes() (throwaway bar).
	 *
	 * @param WP_Admin_Bar $bar
	 * @param string       $context `'admin'` | `'frontend'`
	 * @return array<int,array{id:string,label:string,group:string,defaultIcon:string,dashicon:string}>
	 */
	private static function extract_nodes( $bar, $context = 'admin' ) {
		$nodes = $bar->get_nodes();
		if ( ! is_array( $nodes ) ) {
			return array();
		}

		$branding = (bool) AdminKit_Settings::get( 'branding_enabled' );
		$out      = array();
		foreach ( $nodes as $id => $node ) {
			$id = (string) $id;
			if ( 'root' === $id || ! empty( $node->group ) ) {
				continue; // the root container + group wrappers aren't items
			}
			// When AdminKit's automatic toolbar identity is active, `#wp-admin-bar-wp-logo`
			// is hidden by CSS (`AdminKit_Core_Branding`) and its mark lives on `site-name`.
			if ( $branding && 'wp-logo' === $id ) {
				continue;
			}
			$parent = isset( $node->parent ) ? $node->parent : false;
			if ( false === $parent || '' === $parent || 'root' === $parent ) {
				$side = 'root';
			} elseif ( 'top-secondary' === $parent ) {
				$side = 'secondary';
			} else {
				continue; // a dropdown child — not a top-level bar item
			}

			$defaults = self::node_default_icons( $id, $context );
			$out[]    = array(
				'id'          => $id,
				'label'       => self::node_label( $node ),
				'group'       => $side,
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}
		return $out;
	}

	/**
	 * Default icon metadata for a toolbar node so the editor's icon button previews
	 * the same glyph the bar displays by default in the given context (`admin` vs
	 * `frontend`, and accounting for `branding_enabled`).
	 *
	 * @param string $id
	 * @param string $context `'admin'` | `'frontend'`
	 * @return array{defaultIcon:string,dashicon:string}
	 */
	private static function node_default_icons( $id, $context = 'admin' ) {
		$branding = (bool) AdminKit_Settings::get( 'branding_enabled' );
		if ( 'site-name' === $id ) {
			// When automatic branding is on, `site-name` displays the brand mark (falling
			// back to the WordPress `W` logomark `\f120`), not the generic house glyph.
			if ( $branding ) {
				return array(
					'defaultIcon' => '',
					'dashicon'    => 'dashicons-wordpress',
				);
			}
			return array(
				'defaultIcon' => 'home',
				'dashicon'    => 'dashicons-admin-home',
			);
		}
		if ( 'ak-view-site' === $id ) {
			return array(
				'defaultIcon' => 'frontend' === $context ? 'home' : 'external-link',
				'dashicon'    => '',
			);
		}
		$lucide_map = array(
			'menu-toggle'         => 'menu',
			'comments'            => 'chat',
			'new-content'         => 'plus',
			'updates'             => 'update',
			'command-palette'     => 'search',
			'search'              => 'search',
			'edit'                => 'document-pencil',
			'customize'           => 'sliders',
			'site-editor'         => 'app',
			'archive'             => 'eye',
			'ak-notifications'    => 'bell',
			'ak-theme-toggle'     => 'sun',
			'query-monitor'       => 'clock',
			'my-account'          => 'user',
			'ame-quick-search-tb' => 'search',
			'edit_with_bricks'    => 'brush',
			'editor_mode'         => 'app',
		);
		$dashicon_map = array(
			'wp-logo'         => 'dashicons-wordpress',
			'menu-toggle'     => 'dashicons-menu',
			'comments'        => 'dashicons-admin-comments',
			'new-content'     => 'dashicons-plus',
			'updates'         => 'dashicons-update',
			'command-palette' => 'dashicons-search',
			'search'          => 'dashicons-search',
			'edit'            => 'dashicons-edit',
			'customize'       => 'dashicons-admin-customizer',
			'site-editor'     => 'dashicons-layout',
			'my-account'      => 'dashicons-admin-users',
		);
		return array(
			'defaultIcon' => isset( $lucide_map[ $id ] ) ? $lucide_map[ $id ] : '',
			'dashicon'    => isset( $dashicon_map[ $id ] ) ? $dashicon_map[ $id ] : '',
		);
	}

	/**
	 * A readable, plain-text label for a node, in three falls:
	 *   1. the visible title — but with `aria-hidden="true"` spans dropped first, so a
	 *      node like Comments reads "0 awaiting moderation", not "00 …" (the decorative
	 *      count bubble duplicates the screen-reader count);
	 *   2. for icon-only nodes (no visible text — `ak-view-site`, `ak-theme-toggle`, …)
	 *      the accessible tooltip `meta['title']` ("View site"), never the raw id;
	 *   3. a humanized id as the last resort (`ak-foo-bar` → "Foo bar").
	 *
	 * @param object $node
	 * @return string
	 */
	private static function node_label( $node ) {
		$raw = isset( $node->title ) ? (string) $node->title : '';
		// Drop decorative, aria-hidden spans (count bubbles, icon glyphs) before stripping.
		$raw   = preg_replace( '/<span[^>]*aria-hidden=["\']true["\'][^>]*>.*?<\/span>/is', ' ', $raw );
		$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
		if ( '' !== $title ) {
			return $title;
		}
		// Icon-only node → the accessible tooltip (meta['title']) if any.
		if ( isset( $node->meta['title'] ) && '' !== trim( (string) $node->meta['title'] ) ) {
			return trim( wp_strip_all_tags( (string) $node->meta['title'] ) );
		}
		// Last resort: humanize the id (drop the wp-admin-bar- / ak- prefix, dashes → spaces).
		$id = isset( $node->id ) ? (string) $node->id : '';
		$id = preg_replace( '/^(wp-admin-bar-|ak-)/', '', $id );
		return ucfirst( trim( str_replace( array( '-', '_' ), ' ', $id ) ) );
	}

	/**
	 * POST handler — sanitise + persist the layout. Accepts either a per-context
	 * `{ admin: {...}, frontend: {...} }` payload or a legacy flat `{ order, hidden,
	 * titles, icons }` payload (which updates `admin` while keeping `frontend`).
	 * Protected ids can't be hidden.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save( $request ) {
		$protected   = self::protected_nodes();
		$current     = self::get_config();
		$admin_in    = $request->get_param( 'admin' );
		$frontend_in = $request->get_param( 'frontend' );

		$params = $request->get_params();
		foreach ( array( 'admin', 'frontend', 'order', 'hidden', 'titles', 'icons' ) as $key ) {
			if ( array_key_exists( $key, $params ) && ! is_array( $params[ $key ] ) ) {
				return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
			}
		}
		if ( ! array_intersect_key( $params, array_flip( array( 'admin', 'frontend', 'order', 'hidden', 'titles', 'icons' ) ) ) ) {
			return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
		}

		foreach ( array( $admin_in, $frontend_in, $params ) as $slice ) {
			if ( ! is_array( $slice ) ) { continue; }
			foreach ( array( 'order', 'hidden', 'titles', 'icons' ) as $field ) {
				if ( ! array_key_exists( $field, $slice ) ) { continue; }
				if ( ! is_array( $slice[ $field ] ) ) {
					return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
				}
				foreach ( $slice[ $field ] as $value ) {
					if ( ! is_string( $value ) ) {
						return AdminKit_Storage::error( 'adminkit_invalid_toolbar', 400 );
					}
				}
			}
		}

		if ( is_array( $admin_in ) || is_array( $frontend_in ) ) {
			$saved = array(
				'admin'    => is_array( $admin_in ) ? self::sanitize_context_slice( $admin_in, $protected ) : $current['admin'],
				'frontend' => is_array( $frontend_in ) ? self::sanitize_context_slice( $frontend_in, $protected ) : $current['frontend'],
			);
		} else {
			$legacy = array(
				'order'  => $request->get_param( 'order' ),
				'hidden' => $request->get_param( 'hidden' ),
				'titles' => $request->get_param( 'titles' ),
				'icons'  => $request->get_param( 'icons' ),
			);
			$saved  = array(
				'admin'    => self::sanitize_context_slice( $legacy, $protected ),
				'frontend' => $current['frontend'],
			);
		}

		$result = AdminKit_Storage::save_option( self::OPTION, $saved, false );
		if ( is_wp_error( $result ) ) { return $result; }

		$configs = self::get_config();
		return rest_ensure_response( array(
			'saved'           => true,
			'config'          => $configs['admin'],
			'configByContext' => $configs,
		) );
	}

	/**
	 * A node id is a slug-ish string (`wp-admin-bar-…`); keep only safe chars.
	 *
	 * @param mixed $id
	 * @return string
	 */
	private static function sanitize_node_id( $id ) {
		$id = is_string( $id ) ? trim( $id ) : '';
		return preg_replace( '/[^a-zA-Z0-9_\-]/', '', $id );
	}

	/**
	 * Sanitise a toolbar icon value: `wp-default`, a curated WordPress dashicon class,
	 * a known AdminKit icon name, or a custom SVG data-URI. Mirrors
	 * AdminKit_Menu_Manager::sanitize_icon().
	 *
	 * @param mixed $icon
	 * @return string
	 */
	private static function sanitize_icon( $icon ) {
		if ( ! is_string( $icon ) || '' === $icon ) {
			return '';
		}
		if ( 'wp-default' === $icon ) {
			return 'wp-default';
		}
		if ( 0 === strpos( $icon, 'dashicons-' ) ) {
			return ( class_exists( 'AdminKit_Menu_Manager' ) && in_array( $icon, AdminKit_Menu_Manager::WP_DASHICONS, true ) ) ? $icon : '';
		}
		if ( class_exists( 'AdminKit_Icons' ) && AdminKit_Icons::has( $icon ) ) {
			return $icon;
		}
		$icon = trim( $icon );
		if ( 0 === stripos( $icon, '<svg' ) ) {
			$icon = 'data:image/svg+xml,' . rawurlencode( $icon );
		}
		if ( 0 !== stripos( $icon, 'data:image/svg+xml' ) || strlen( $icon ) > 16384 ) {
			return '';
		}
		if ( false !== strpos( $icon, '<' ) ) {
			return '';
		}
		return str_replace( '"', '', $icon );
	}

	/* ─────────────────────────── Apply ─────────────────────────── */

	/**
	 * Apply the saved layout for the active context (`admin` vs `frontend`) to the live
	 * bar: hide (remove), apply custom titles + icons, then reorder. Runs on
	 * `admin_bar_menu` very late so every node already exists.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function apply( $bar ) {
		if ( ! is_object( $bar ) ) {
			return;
		}
		$context = is_admin() ? 'admin' : 'frontend';
		$cfg     = self::get_config( $context );
		if ( empty( $cfg['order'] ) && empty( $cfg['hidden'] ) && empty( $cfg['titles'] ) && empty( $cfg['icons'] ) ) {
			return;
		}
		$protected = self::protected_nodes();
		$branding  = (bool) AdminKit_Settings::get( 'branding_enabled' );

		// Hide: drop the node (children go with it). Never a protected id.
		$hidden = array();
		foreach ( $cfg['hidden'] as $id ) {
			if ( isset( $protected[ $id ] ) ) {
				continue;
			}
			$hidden[ $id ] = true;
			$bar->remove_node( $id );
		}

		// Apply title + icon overrides to any live node (whether reordered or not).
		$touched = array_unique( array_merge( array_keys( $cfg['titles'] ), array_keys( $cfg['icons'] ) ) );
		foreach ( $touched as $id ) {
			if ( isset( $hidden[ $id ] ) || ( $branding && 'wp-logo' === $id ) ) {
				continue;
			}
			$node = $bar->get_node( $id );
			if ( ! $node ) {
				continue;
			}
			$props = (array) $node;
			if ( isset( $cfg['titles'][ $id ] ) && '' !== $cfg['titles'][ $id ] ) {
				$props = self::apply_node_title( $props, $cfg['titles'][ $id ] );
			}
			if ( isset( $cfg['icons'][ $id ] ) && '' !== $cfg['icons'][ $id ] ) {
				$props = self::apply_node_icon( $id, $props, $cfg['icons'][ $id ] );
			}
			$bar->add_node( $props );
		}

		// Reorder: re-add the kept nodes in the saved order. Re-adding moves a node to
		// the end of its group's run, so walking the saved order rebuilds the sequence;
		// any node NOT in the saved order keeps its native slot (a newly-appeared item
		// just sits where WP added it). A node's children ride along (they reference the
		// re-added parent id).
		foreach ( $cfg['order'] as $id ) {
			if ( isset( $hidden[ $id ] ) || ( $branding && 'wp-logo' === $id ) ) {
				continue;
			}
			$node = $bar->get_node( $id );
			if ( ! $node ) {
				continue; // stale / not present this request
			}
			$bar->remove_node( $id );
			$bar->add_node( (array) $node );
		}
	}

	/**
	 * Replace the visible label of a toolbar node while keeping its icon, count
	 * bubbles, avatar, search form or brand logo intact.
	 *
	 * @param array  $props
	 * @param string $custom_title
	 * @return array
	 */
	private static function apply_node_title( $props, $custom_title ) {
		$safe    = esc_html( $custom_title );
		$safe_at = esc_attr( $custom_title );
		$raw     = isset( $props['title'] ) ? (string) $props['title'] : '';
		$meta    = isset( $props['meta'] ) && is_array( $props['meta'] ) ? $props['meta'] : array();

		if ( isset( $meta['menu_title'] ) ) {
			$meta['menu_title'] = $custom_title;
		}

		if ( false !== strpos( $raw, 'adminbarsearch' ) || false !== strpos( $raw, '<form' ) ) {
			$raw = preg_replace( '/(<label[^>]*>).*?(<\/label>)/is', '$1' . $safe . '$2', $raw, 1 );
			$raw = preg_replace( '/(<input[^>]*type=["\']submit["\'][^>]*value=["\'])[^"\']*(["\'])/is', '$1' . $safe_at . '$2', $raw, 1 );
		} elseif ( false !== strpos( $raw, 'ak-site-name-text' ) ) {
			$raw = preg_replace( '/(<span[^>]*class=["\'][^"\']*ak-site-name-text[^"\']*["\'][^>]*>).*?(<\/span>)/is', '$1' . $safe . '$2', $raw, 1 );
		} elseif ( false !== strpos( $raw, 'class="ab-label"' ) || false !== strpos( $raw, "class='ab-label'" ) ) {
			$raw = preg_replace( '/(<span[^>]*class=["\']ab-label["\'][^>]*>).*?(<\/span>)/is', '$1' . $safe . '$2', $raw, 1 );
		} elseif ( false !== strpos( $raw, 'display-name' ) ) {
			$raw = preg_replace( '/^.*?(<img\b.*)?$/is', $safe . '$1', $raw, 1 );
		} elseif ( false !== strpos( $raw, 'ak-theme-icon' ) ) {
			$meta['title'] = $custom_title;
		} elseif ( false !== strpos( $raw, 'ab-icon' ) ) {
			if ( false !== strpos( $raw, 'screen-reader-text' ) ) {
				$raw = preg_replace( '/<span[^>]*class=["\'][^"\']*screen-reader-text[^"\']*["\'][^>]*>.*?<\/span>/is', '<span class="ab-label">' . $safe . '</span>', $raw, 1 );
			} else {
				$raw .= '<span class="ab-label">' . $safe . '</span>';
			}
		} elseif ( preg_match( '/^(<span[^>]*>.*?<\/span>)\s*[^<]+$/is', $raw, $m ) ) {
			$raw = $m[1] . ' ' . $safe;
		} else {
			$raw = $safe;
		}

		$props['title'] = $raw;
		$props['meta']  = $meta;
		return $props;
	}

	/**
	 * Apply a custom icon override to a toolbar node:
	 *   - for AdminKit SVG nodes (`ak-notifications`, `ak-view-site`, `ak-theme-toggle`),
	 *     replace the inline `<svg class="ak-theme-icon" ...>` directly when a Lucide icon
	 *     is chosen;
	 *   - for `search` (`#wp-admin-bar-search` on the front end), leave the `<form>` intact
	 *     and target `#adminbarsearch::before` in `print_css()`;
	 *   - for other nodes, ensure `.ab-icon` is present (or record an `.ab-item::before`
	 *     override) and queue the CSS rule for `print_css()`.
	 *
	 * @param string $id
	 * @param array  $props
	 * @param string $icon
	 * @return array
	 */
	private static function apply_node_icon( $id, $props, $icon ) {
		$raw = isset( $props['title'] ) ? (string) $props['title'] : '';

		// Inline-SVG AdminKit toolbar buttons: swap the inline SVG directly when a
		// Lucide icon name is picked.
		if ( false !== strpos( $raw, 'ak-theme-icon' ) && class_exists( 'AdminKit_Icons' ) && AdminKit_Icons::has( $icon ) ) {
			$svg = str_replace( '<svg ', '<svg class="ak-theme-icon" ', AdminKit_Icons::display_svg( $icon ) );
			$props['title'] = preg_replace( '/<svg[^>]*class=["\'][^"\']*ak-theme-icon[^"\']*["\'][^>]*>.*?<\/svg>/is', $svg, $raw );
			return $props;
		}

		$dom_id = ( 0 === strpos( $id, 'wp-admin-bar-' ) ) ? $id : 'wp-admin-bar-' . $id;

		if ( 'search' === $id || false !== strpos( $raw, 'adminbarsearch' ) ) {
			self::$icon_overrides[ $dom_id ] = ( 'wp-default' === $icon ) ? 'wp-default:search' : $icon;
			return $props;
		}

		// When a user explicitly overrides `site-name`'s icon, strip any injected brand
		// logo class so the custom icon + site name text render cleanly.
		if ( 'site-name' === $id ) {
			if ( isset( $props['meta']['class'] ) && is_string( $props['meta']['class'] ) ) {
				$props['meta']['class'] = trim( str_replace( 'ak-has-brand-logo', '', $props['meta']['class'] ) );
			}
			if ( false !== strpos( $raw, 'ak-site-name-text' ) && preg_match( '/<span[^>]*class=["\'][^"\']*ak-site-name-text[^"\']*["\'][^>]*>(.*?)<\/span>/is', $raw, $m ) ) {
				$raw            = $m[1];
				$props['title'] = $raw;
			}
		}

		if ( 0 === strpos( $icon, 'dashicons-' ) ) {
			if ( false !== strpos( $raw, 'ab-icon' ) ) {
				$props['title'] = preg_replace(
					'/class=(["\'])([^"\']*\bab-icon\b[^"\']*)\1/i',
					'class=$1$2 dashicons-before ' . esc_attr( $icon ) . '$1',
					$raw,
					1
				);
			} else {
				$props['title'] = '<span class="ab-icon dashicons-before ' . esc_attr( $icon ) . '" aria-hidden="true"></span>' . $raw;
			}
			self::$icon_overrides[ $dom_id ] = $icon;
			return $props;
		}

		if ( 'wp-default' === $icon ) {
			self::$icon_overrides[ $dom_id ] = 'wp-default:' . $id;
			return $props;
		}

		// Lucide icon name or custom SVG data-URI: ensure the node has an `.ab-icon`
		// span (unless it's a known `> .ab-item::before` node like site-name/edit/customize/site-editor/query-monitor).
		$ab_item_ids = array(
			'site-name'        => true,
			'edit'             => true,
			'customize'        => true,
			'site-editor'      => true,
			'archive'          => true,
			'query-monitor'    => true,
			'edit_with_bricks' => true,
			'editor_mode'      => true,
		);
		if ( isset( $ab_item_ids[ $id ] ) ) {
			self::$ab_item_overrides[ $dom_id ] = true;
		} elseif ( false === strpos( $raw, 'ab-icon' ) ) {
			$props['title'] = '<span class="ab-icon" aria-hidden="true"></span>' . $raw;
		}
		self::$icon_overrides[ $dom_id ] = $icon;
		return $props;
	}

	/**
	 * Print per-node toolbar icon CSS overrides in `admin_head` and `wp_head` (at
	 * priority 22, right after `AdminKit_Core_Menu_Icons` at 21 and `AdminKit_Core_Branding`
	 * at 20).
	 *
	 * @return void
	 */
	public static function print_css() {
		if ( empty( self::$icon_overrides ) || ! is_admin_bar_showing() ) {
			return;
		}
		$desktop = '';
		$mobile  = '';

		foreach ( self::$icon_overrides as $dom_id => $icon ) {
			// When `site-name` has an explicit custom icon override, neutralize
			// `AdminKit_Core_Branding`'s `::before` mark so only the chosen icon shows.
			if ( 'wp-admin-bar-site-name' === $dom_id && 0 === strpos( $icon, 'dashicons-' ) ) {
				$desktop .= '.wp-admin #wpadminbar #wp-toolbar #wp-admin-bar-site-name > .ab-item.ab-item.ab-item::before,#wpadminbar #wp-toolbar #wp-admin-bar-site-name > .ab-item.ab-item.ab-item::before{content:none !important;background-image:none !important}';
			}

			if ( 'wp-admin-bar-search' === $dom_id ) {
				$sel = '#wpadminbar #wp-toolbar #wp-admin-bar-search #adminbarsearch::before';
				if ( 0 === strncmp( $icon, 'wp-default:', 11 ) ) {
					$desktop .= $sel . '{content:"\\f179";-webkit-mask:none;mask:none;background:transparent;font:normal 20px/1 dashicons}';
				} else {
					$mask = self::icon_mask( $icon );
					if ( '' !== $mask ) {
						$desktop .= $sel . '{content:"";width:20px;height:20px;top:6px;' . $mask . '}';
					}
				}
				continue;
			}

			if ( 0 === strpos( $icon, 'dashicons-' ) ) {
				// Cancel any AdminKit mask on this node's `.ab-icon` so WP's native
				// dashicons-before class paints its font glyph.
				$sel      = '#wpadminbar #wp-toolbar #' . $dom_id . ' .ab-icon.ab-icon.ab-icon';
				$desktop .= $sel . '{box-sizing:border-box;height:32px;width:20px;padding:6px 0;margin-right:6px}';
				$desktop .= $sel . '::before{-webkit-mask:none;mask:none;background:transparent;font:normal 20px/1 dashicons;display:block;width:20px;height:20px}';
				continue;
			}

			if ( 0 === strncmp( $icon, 'wp-default:', 11 ) ) {
				$raw_id = substr( $icon, 11 );
				if ( isset( self::$toolbar_dashicon_glyphs[ $raw_id ] ) ) {
					$info  = self::$toolbar_dashicon_glyphs[ $raw_id ];
					$glyph = $info['glyph'];
					if ( 'ab-item' === $info['target'] ) {
						$sel      = '.wp-admin #wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before,#wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before';
						$desktop .= $sel . '{content:"' . $glyph . '" !important;-webkit-mask:none;mask:none;background:transparent !important;font:normal 20px/1 dashicons;float:left;width:20px;height:32px;padding:6px 0;margin-right:6px}';
					} else {
						$sel      = '#wpadminbar #wp-toolbar #' . $dom_id . ' .ab-icon.ab-icon.ab-icon::before';
						$desktop .= $sel . '{content:"' . $glyph . '";-webkit-mask:none;mask:none;background:transparent;font:normal 20px/1 dashicons}';
					}
				}
				continue;
			}

			$mask = self::icon_mask( $icon );
			if ( '' === $mask ) {
				continue;
			}

			if ( ! empty( self::$ab_item_overrides[ $dom_id ] ) ) {
				$sel      = '.wp-admin #wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before,#wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before';
				$desktop .= $sel . '{content:"" !important;background-image:none !important;box-sizing:border-box;float:left;height:32px;width:20px;padding:6px 0;margin-right:6px;position:static;top:auto;' . $mask . '}';
				$mobile  .= $sel . '{content:"" !important;background-image:none !important;box-sizing:border-box;display:block;float:none;width:52px;height:46px;margin:0;text-indent:0;position:static;top:auto;' . $mask . '-webkit-mask-size:22px 22px;mask-size:22px 22px}';
			} else {
				$sel      = '#wpadminbar #wp-toolbar #' . $dom_id . ' .ab-icon.ab-icon.ab-icon';
				$desktop .= $sel . '{box-sizing:border-box;height:32px;width:20px;padding:6px 0;margin-right:6px}';
				$desktop .= $sel . '::before{content:"";display:block;width:20px;height:20px;margin:0;position:static;top:auto;' . $mask . '}';
				$mobile  .= $sel . '{box-sizing:border-box;width:52px;height:46px;margin:0;padding:0}';
				$mobile  .= $sel . '::before{content:"";display:block;width:52px;height:46px;margin:0;position:static;top:auto;' . $mask . '-webkit-mask-size:22px 22px;mask-size:22px 22px}';
			}
		}

		$css = '';
		if ( '' !== $desktop ) {
			$css .= '@media screen and (min-width:783px){' . $desktop . '}';
		}
		if ( '' !== $mobile ) {
			$css .= '@media screen and (max-width:782px){' . $mobile . '}';
		}
		if ( '' !== $css ) {
			echo '<style id="adminkit-toolbar-manager">' . $css . "</style>\n";
		}
	}

	/**
	 * Build the CSS mask declarations for a Lucide icon name or SVG data-URI.
	 *
	 * @param string $icon
	 * @return string
	 */
	private static function icon_mask( $icon ) {
		if ( 0 === strpos( $icon, 'data:image/svg+xml' ) ) {
			$uri = 'url("' . $icon . '")';
			return 'background-color:currentColor;-webkit-mask:' . $uri . ' center/20px 20px no-repeat;mask:' . $uri . ' center/20px 20px no-repeat;';
		}
		if ( ! class_exists( 'AdminKit_Icons' ) ) {
			return '';
		}
		$svg = AdminKit_Icons::svg( $icon );
		return '' === $svg ? '' : AdminKit_Icons::mask( $svg );
	}
}
