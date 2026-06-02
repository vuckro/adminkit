<?php
/**
 * Toolbar manager — reorder + hide the TOP-LEVEL admin-bar nodes (a focused
 * cousin of the Menu manager). Top level only; no rename, no custom items.
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
 * reference the parent id, which is re-added with the same id). Reordering is within
 * a node's own group (WP's left default group vs the right `top-secondary`), so the
 * account cluster stays on the right.
 *
 * Storage: a single autoload-off option (the layout is small — a list of ids + a
 * hidden set), not a table.
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

	/** Single option holding { order: [id…], hidden: [id…] }. */
	const OPTION = 'adminkit_toolbar';

	const REST_NS    = 'adminkit/v1';
	const REST_ROUTE = '/toolbar';

	/** Submenu page hook suffix (set when the page registers); gates enqueue. */
	private static $page_hook = '';

	/**
	 * Register the setting + wire hooks. The REST route is always registered (the
	 * editor needs it); application is gated on the toggle.
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

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Self-register the focused "Toolbar" submenu page (priority 12 so it lands
		// after Menu/Statistics under the AdminKit parent).
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 12 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// Apply on BOTH bars (wp-admin + front-end for logged-in users), very late so
		// every node — core + plugins — already exists.
		add_action( 'admin_bar_menu', array( __CLASS__, 'apply' ), 99999 );
	}

	/**
	 * Master switch — gates APPLICATION only (the editor page is always available so a
	 * layout can be prepared while off).
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
	 * Register the Toolbar editor as an AdminKit submenu. Mounts the shared SPA engine
	 * on the 'toolbar' screen.
	 *
	 * @return void
	 */
	public static function add_page() {
		self::$page_hook = (string) add_submenu_page(
			AdminKit_Settings_Page::SLUG,
			__( 'Toolbar', 'adminkit' ),
			__( 'Toolbar', 'adminkit' ),
			'manage_options',
			AdminKit_Settings_Page::SLUG_TOOLBAR,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page host on the 'toolbar' screen — the shared engine builds the UI.
	 *
	 * @return void
	 */
	public static function render_page() {
		AdminKit_Settings_Page::render_host( 'toolbar' );
	}

	/**
	 * Enqueue the shared admin app with the toolbar-screen boot payload, on this page
	 * only (gated on the hook suffix captured at registration).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$page_hook || $hook !== self::$page_hook ) {
			return;
		}
		AdminKit_Settings_Page::enqueue_app( AdminKit_Settings_Page::boot_data( 'toolbar' ) );
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
	 * The saved layout: a desired top-level order + a hidden set. Sanitised on read.
	 *
	 * @return array{order:string[],hidden:string[]}
	 */
	public static function get_config() {
		$cfg = get_option( self::OPTION, array() );
		$order  = ( isset( $cfg['order'] ) && is_array( $cfg['order'] ) ) ? array_values( array_map( 'strval', $cfg['order'] ) ) : array();
		$hidden = ( isset( $cfg['hidden'] ) && is_array( $cfg['hidden'] ) ) ? array_values( array_map( 'strval', $cfg['hidden'] ) ) : array();
		return array( 'order' => $order, 'hidden' => $hidden );
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	/**
	 * GET  adminkit/v1/toolbar — the pristine top-level nodes + the saved layout.
	 * POST adminkit/v1/toolbar — persist { order, hidden }.
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
	 * Build the admin bar in THIS request (without our own apply()) and return the
	 * pristine top-level nodes, in native order, each with a plain-text label and its
	 * group (so the SPA can keep the left/right clusters apart). Plus the saved layout.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_get() {
		return rest_ensure_response( array(
			'nodes'  => self::snapshot_nodes(),
			'config' => self::get_config(),
		) );
	}

	/**
	 * Snapshot the live top-level admin-bar nodes by constructing a throwaway bar and
	 * firing the same `admin_bar_menu` everyone adds to — but with OUR apply() detached,
	 * so the editor sees every node in native order regardless of the saved layout.
	 *
	 * @return array<int,array{id:string,label:string,group:string}>
	 */
	private static function snapshot_nodes() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		// Detach our own apply so the throwaway render is pristine, then build the bar
		// exactly like core's _wp_admin_bar_init (initialize → add_menus → the action).
		remove_action( 'admin_bar_menu', array( __CLASS__, 'apply' ), 99999 );
		$bar = new WP_Admin_Bar();
		$bar->initialize();
		$bar->add_menus();
		do_action( 'admin_bar_menu', $bar );
		add_action( 'admin_bar_menu', array( __CLASS__, 'apply' ), 99999 );

		$nodes = $bar->get_nodes();
		if ( ! is_array( $nodes ) ) {
			return array();
		}

		// Classify each node against WP's verified admin-bar structure (see
		// wp-includes/admin-bar.php): the ONLY top-level group is `top-secondary`
		// (the right cluster — account, search…), added with no parent; every other
		// group is a dropdown sub-group (parent = an item). get_nodes() returns the
		// raw, UNBOUND nodes, so a top-level item's parent is still false/'' (it is
		// rebound to 'root' only later in _bind, during render):
		//   • parent false / '' / 'root' → left cluster
		//   • parent 'top-secondary'     → right cluster
		//   • anything else              → a dropdown child (skip)
		$out = array();
		foreach ( $nodes as $id => $node ) {
			$id = (string) $id;
			if ( 'root' === $id || ! empty( $node->group ) ) {
				continue; // the root container + group wrappers aren't items
			}
			$parent = isset( $node->parent ) ? $node->parent : false;
			if ( false === $parent || '' === $parent || 'root' === $parent ) {
				$side = 'root';
			} elseif ( 'top-secondary' === $parent ) {
				$side = 'secondary';
			} else {
				continue; // a dropdown child — not a top-level bar item
			}

			$out[] = array(
				'id'    => $id,
				'label' => self::node_label( $node ),
				'group' => $side,
			);
		}
		return $out;
	}

	/**
	 * A readable, plain-text label for a node: its title with tags stripped + whitespace
	 * collapsed, falling back to a tidied id. Some nodes carry only an icon (no text) —
	 * those fall back to the id so the editor still shows something selectable.
	 *
	 * @param object $node
	 * @return string
	 */
	private static function node_label( $node ) {
		$title = isset( $node->title ) ? (string) $node->title : '';
		$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $title ) ) );
		if ( '' !== $title ) {
			return $title;
		}
		// No text (icon-only node) → a tidied id: drop the wp-admin-bar- prefix.
		$id = isset( $node->id ) ? (string) $node->id : '';
		return preg_replace( '/^wp-admin-bar-/', '', $id );
	}

	/**
	 * POST handler — sanitise + persist the layout. `order` is a list of node ids in
	 * the desired order; `hidden` a list of ids to remove. Protected ids can't be
	 * hidden. Both are stored verbatim (ids are validated on apply against the live
	 * bar, so a stale id is simply ignored there).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_save( $request ) {
		$order_in  = $request->get_param( 'order' );
		$hidden_in = $request->get_param( 'hidden' );

		$order     = array();
		$protected = self::protected_nodes();
		if ( is_array( $order_in ) ) {
			foreach ( $order_in as $id ) {
				$id = self::sanitize_node_id( $id );
				if ( '' !== $id ) {
					$order[] = $id;
				}
			}
		}
		$hidden = array();
		if ( is_array( $hidden_in ) ) {
			foreach ( $hidden_in as $id ) {
				$id = self::sanitize_node_id( $id );
				if ( '' !== $id && ! isset( $protected[ $id ] ) ) {
					$hidden[] = $id;
				}
			}
		}

		update_option( self::OPTION, array(
			'order'  => array_values( array_unique( $order ) ),
			'hidden' => array_values( array_unique( $hidden ) ),
		), false );

		return rest_ensure_response( array( 'saved' => true, 'config' => self::get_config() ) );
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

	/* ─────────────────────────── Apply ─────────────────────────── */

	/**
	 * Apply the saved layout to the live bar: hide (remove) then reorder. Runs on
	 * `admin_bar_menu` very late so every node already exists.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function apply( $bar ) {
		if ( ! is_object( $bar ) ) {
			return;
		}
		$cfg = self::get_config();
		if ( empty( $cfg['order'] ) && empty( $cfg['hidden'] ) ) {
			return;
		}
		$protected = self::protected_nodes();

		// Hide: drop the node (children go with it). Never a protected id.
		$hidden = array();
		foreach ( $cfg['hidden'] as $id ) {
			if ( isset( $protected[ $id ] ) ) {
				continue;
			}
			$hidden[ $id ] = true;
			$bar->remove_node( $id );
		}

		// Reorder: re-add the kept nodes in the saved order. Re-adding moves a node to
		// the end of its group's run, so walking the saved order rebuilds the sequence;
		// any node NOT in the saved order keeps its native slot (a newly-appeared item
		// just sits where WP added it). A node's children ride along (they reference the
		// re-added parent id).
		foreach ( $cfg['order'] as $id ) {
			if ( isset( $hidden[ $id ] ) ) {
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
}
