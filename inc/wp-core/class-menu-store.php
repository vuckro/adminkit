<?php
/**
 * Menu-manager storage — the custom table that persists the admin-menu layout
 * (order, icon overrides, hidden flags) edited in Settings → AdminKit → Menu.
 *
 * One network-shared table keyed by `blog_id`, so each site in a multisite network
 * keeps its own layout while single-site installs just use blog_id 1. The config is
 * a small per-site document (one row per menu / submenu entry), so reads are cached
 * (per-request static + a transient busted on save).
 *
 * Schema (version-gated via the network option `adminkit_menu_db_version`):
 *   {base_prefix}adminkit_menu_items
 *     id · blog_id · parent ('' = top-level) · slug · position · icon · hidden
 *     · roles (reserved, v2) · updated_at
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Menu_Store {

	/** Bump when the schema changes so ensure_schema() re-runs dbDelta. */
	const DB_VERSION     = '1.2';
	const DB_VERSION_KEY = 'adminkit_menu_db_version';
	const CACHE_PREFIX   = 'adminkit_menu_cfg_';

	/** @var array<int,array> Per-request config cache, keyed by blog_id. */
	private static $cache = array();

	/**
	 * Fully-qualified table name. Uses base_prefix so the table is network-shared
	 * (one table for the whole install), with a blog_id column for per-site scoping.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->base_prefix . 'adminkit_menu_items';
	}

	/**
	 * Create / migrate the table when the stored DB version differs. Cheap to call
	 * on every admin load — it's a single option compare until the version bumps.
	 * The version lives in a NETWORK option because the table is network-shared.
	 *
	 * @return void
	 */
	public static function ensure_schema() {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_KEY ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, lowercase
		// types, KEY (not INDEX), one field per line.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL auto_increment,
  blog_id bigint(20) unsigned NOT NULL default 0,
  parent varchar(190) NOT NULL default '',
  slug varchar(255) NOT NULL default '',
  position int(11) NOT NULL default 0,
  icon text,
  hidden tinyint(1) NOT NULL default 0,
  type varchar(20) NOT NULL default 'item',
  title varchar(190) NOT NULL default '',
  roles text,
  updated_at datetime default NULL,
  PRIMARY KEY  (id),
  KEY blog_id (blog_id)
) {$collate};";

		dbDelta( $sql );
		update_site_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * The saved layout for a site as
	 *   array( 'top' => array( {slug, position, icon, hidden}, … ),
	 *          'sub' => array( parent_slug => array( {slug, position, hidden}, … ) ) )
	 * Empty arrays when nothing is saved. Cached per-request + via a transient.
	 *
	 * @param int|null $blog_id Defaults to the current site.
	 * @return array{top:array,sub:array}
	 */
	public static function get_config( $blog_id = null ) {
		$blog_id = ( null === $blog_id ) ? get_current_blog_id() : (int) $blog_id;

		if ( isset( self::$cache[ $blog_id ] ) ) {
			return self::$cache[ $blog_id ];
		}

		$cached = get_transient( self::CACHE_PREFIX . $blog_id );
		if ( is_array( $cached ) && isset( $cached['top'], $cached['sub'] ) ) {
			self::$cache[ $blog_id ] = $cached;
			return $cached;
		}

		global $wpdb;
		$table = self::table();
		// $table is built from $wpdb->base_prefix (trusted), not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT parent, slug, position, icon, hidden, type, title FROM {$table} WHERE blog_id = %d ORDER BY parent ASC, position ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$blog_id
			),
			ARRAY_A
		);

		$config = self::assemble( is_array( $rows ) ? $rows : array() );
		self::$cache[ $blog_id ] = $config;
		set_transient( self::CACHE_PREFIX . $blog_id, $config, DAY_IN_SECONDS );
		return $config;
	}

	/**
	 * Replace a site's saved layout. Items are already-sanitised rows:
	 *   { parent:string, slug:string, position:int, icon:string, hidden:bool }
	 * (top-level rows carry parent ''). Delete-then-insert keeps it a clean snapshot.
	 *
	 * @param array         $items
	 * @param int|null      $blog_id Defaults to the current site.
	 * @return void
	 */
	public static function save_config( array $items, $blog_id = null ) {
		$blog_id = ( null === $blog_id ) ? get_current_blog_id() : (int) $blog_id;

		global $wpdb;
		$table = self::table();
		$wpdb->delete( $table, array( 'blog_id' => $blog_id ), array( '%d' ) );

		$now = current_time( 'mysql' );
		foreach ( $items as $it ) {
			if ( empty( $it['slug'] ) ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'blog_id'    => $blog_id,
					'parent'     => isset( $it['parent'] ) ? (string) $it['parent'] : '',
					'slug'       => (string) $it['slug'],
					'position'   => isset( $it['position'] ) ? (int) $it['position'] : 0,
					'icon'       => isset( $it['icon'] ) ? (string) $it['icon'] : '',
					'hidden'     => ! empty( $it['hidden'] ) ? 1 : 0,
					'type'       => isset( $it['type'] ) ? (string) $it['type'] : 'item',
					'title'      => isset( $it['title'] ) ? (string) $it['title'] : '',
					'updated_at' => $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		self::flush_cache( $blog_id );
	}

	/**
	 * Whether the current site has any saved layout (so the manager can skip its
	 * apply hooks entirely when there's nothing to do).
	 *
	 * @param int|null $blog_id
	 * @return bool
	 */
	public static function has_config( $blog_id = null ) {
		$config = self::get_config( $blog_id );
		return ! empty( $config['top'] ) || ! empty( $config['sub'] );
	}

	/**
	 * Drop the per-request + transient cache for a site.
	 *
	 * @param int $blog_id
	 * @return void
	 */
	private static function flush_cache( $blog_id ) {
		unset( self::$cache[ $blog_id ] );
		delete_transient( self::CACHE_PREFIX . $blog_id );
	}

	/**
	 * Shape flat rows into the { top, sub } config structure.
	 *
	 * @param array $rows
	 * @return array{top:array,sub:array}
	 */
	private static function assemble( $rows ) {
		$top = array();
		$sub = array();
		foreach ( $rows as $r ) {
			if ( ! isset( $r['parent'], $r['slug'] ) ) {
				continue;
			}
			$parent = (string) $r['parent'];
			$item   = array(
				'slug'     => (string) $r['slug'],
				'position' => (int) $r['position'],
				'hidden'   => ! empty( $r['hidden'] ),
			);
			if ( '' === $parent ) {
				$item['icon']  = (string) $r['icon'];
				$item['type']  = isset( $r['type'] ) ? (string) $r['type'] : 'item';
				$item['title'] = isset( $r['title'] ) ? (string) $r['title'] : '';
				$top[]         = $item;
			} else {
				$item['title']    = isset( $r['title'] ) ? (string) $r['title'] : '';
				$sub[ $parent ][] = $item;
			}
		}
		return array(
			'top' => $top,
			'sub' => $sub,
		);
	}
}
