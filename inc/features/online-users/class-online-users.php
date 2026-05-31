<?php
/**
 * Online users — surfaces who is currently signed in, with the lightest possible
 * database footprint.
 *
 * PRESENCE IS READ, NOT TRACKED. WordPress already records every active session in
 * the `session_tokens` user meta (added on login, removed on logout/expiry). A user
 * is "online" when they hold at least one non-expired session — derived by READING
 * that existing data, so the feature adds ZERO writes for presence and is as stable
 * as core's own session handling. It means "signed in / present" (a session lasts up
 * to ~2 days, 14 with "remember me"), not "active to the second" — a stricter
 * last-activity heartbeat would mean per-page-load writes and is deliberately out of
 * scope.
 *
 * The ONLY write this feature adds is a single `adminkit_last_login` user meta on the
 * `wp_login` action — one write per login — used for the "recent logins" dashboard
 * fallback and the "Last login" column, both of which must work for users whose
 * session is already gone.
 *
 * Surfaces (all reversible — toggle off and each disappears, native screens
 * untouched): a dashboard card (consumed by AdminKit_Custom_Dashboard), plus an
 * "Online" view tab + "Last login" column on the Users list table.
 *
 * Filter:
 *   adminkit/online_users/enabled  (bool)  master on/off
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Online_Users {

	/** Per-login timestamp meta — the feature's only write. */
	const LAST_LOGIN_META = 'adminkit_last_login';

	/** Memoized online map for this request. @var array<int,int>|null */
	private static $map = null;

	/**
	 * Register the setting + wire hooks. The setting is registered unconditionally so
	 * the Settings page can discover it while off. `wp_login` is wired unconditionally
	 * (gated INSIDE the callback) so last-login history keeps accruing even before the
	 * feature is switched on — otherwise the column + fallback would start empty.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'online_users_enabled', array( 'default' => true ) );

		add_action( 'wp_login', array( __CLASS__, 'record_login' ), 10, 2 );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Users list table — a "Last login" column with an online dot. No filter tab:
		// next to Administrator / Subscriber it would read like a role and confuse.
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_last_login_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_last_login_column' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_user_query', array( __CLASS__, 'sort_query' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Master switch — registered setting (default ON) through a filter.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/online_users/enabled', AdminKit_Settings::get( 'online_users_enabled' ) );
	}

	/**
	 * Record the login time — the feature's ONLY write (one per login). Gated here
	 * (not behind init()'s early return) so history accrues regardless of the toggle.
	 *
	 * @param string   $user_login
	 * @param \WP_User $user
	 * @return void
	 */
	public static function record_login( $user_login, $user ) {
		if ( self::is_enabled() && $user instanceof WP_User ) {
			update_user_meta( $user->ID, self::LAST_LOGIN_META, time() );
		}
	}

	/* ───────────────────────── presence (read-only) ───────────────────────── */

	/**
	 * Map of online users → their most recent session-login timestamp, newest first.
	 * Built from ONE read of every `session_tokens` row (the live expiration lives
	 * inside a serialized blob SQL can't filter), then filtered to non-expired
	 * sessions in PHP. Memoized per request — the feature's only DB read.
	 *
	 * @return array<int,int> [ user_id => login_ts ]
	 */
	public static function online_map() {
		if ( null !== self::$map ) {
			return self::$map;
		}

		global $wpdb;
		self::$map = array();
		$now       = time();

		// usermeta.meta_key is indexed and only signed-in users carry this key, so the
		// row set is small. $wpdb->usermeta keeps the right prefix on multisite.
		$rows = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$sessions = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $sessions ) ) {
				continue;
			}
			$login = 0;
			foreach ( $sessions as $s ) {
				// An entry is ['expiration'=>ts,'login'=>ts,…] or, for older tokens, a
				// bare int expiration.
				$exp = is_array( $s ) ? ( isset( $s['expiration'] ) ? $s['expiration'] : 0 ) : $s;
				if ( (int) $exp > $now ) {
					$at    = ( is_array( $s ) && isset( $s['login'] ) ) ? (int) $s['login'] : (int) $exp;
					$login = max( $login, $at );
				}
			}
			if ( $login > 0 ) {
				self::$map[ (int) $row['user_id'] ] = $login;
			}
		}

		arsort( self::$map );
		return self::$map;
	}

	/**
	 * @return int Number of users with at least one live session.
	 */
	public static function online_count() {
		return count( self::online_map() );
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_online( $user_id ) {
		$map = self::online_map();
		return isset( $map[ (int) $user_id ] );
	}

	/**
	 * @param int $user_id
	 * @return int Last-login timestamp, or 0.
	 */
	public static function last_login( $user_id ) {
		return (int) get_user_meta( (int) $user_id, self::LAST_LOGIN_META, true );
	}

	/**
	 * Online users for display, newest session-login first.
	 *
	 * @param int $limit
	 * @return array<int,array{user:\WP_User,since:int}>
	 */
	public static function online_users( $limit = 5 ) {
		$map = self::online_map();
		$ids = array_slice( array_keys( $map ), 0, max( 1, (int) $limit ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( get_users( array( 'include' => $ids, 'orderby' => 'include' ) ) as $user ) {
			$out[] = array( 'user' => $user, 'since' => isset( $map[ $user->ID ] ) ? (int) $map[ $user->ID ] : 0 );
		}
		return $out;
	}

	/**
	 * Most recent logins — the fallback shown when nobody is online. Newest first.
	 *
	 * @param int $limit
	 * @return array<int,array{user:\WP_User,since:int}>
	 */
	public static function recent_logins( $limit = 5 ) {
		$users = get_users( array(
			'number'   => max( 1, (int) $limit ),
			'meta_key' => self::LAST_LOGIN_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'  => 'meta_value_num',
			'order'    => 'DESC',
		) );
		$out = array();
		foreach ( $users as $user ) {
			$out[] = array( 'user' => $user, 'since' => self::last_login( $user->ID ) );
		}
		return $out;
	}

	/* ───────────────────────── users list table ───────────────────────── */

	/**
	 * Append a "Last login" column to the users table.
	 *
	 * @param array $columns
	 * @return array
	 */
	public static function add_last_login_column( $columns ) {
		$columns['adminkit_last_login'] = esc_html__( 'Last login', 'adminkit' );
		return $columns;
	}

	/**
	 * Render the "Last login" cell — a green dot when online, then a relative time.
	 *
	 * @param string $output
	 * @param string $column_name
	 * @param int    $user_id
	 * @return string
	 */
	public static function render_last_login_column( $output, $column_name, $user_id ) {
		if ( 'adminkit_last_login' !== $column_name ) {
			return $output;
		}
		// Online users read "Online" (green dot + word) — they're signed in now, so a
		// "last login" time would be misleading. Only offline users show a last time.
		if ( self::is_online( $user_id ) ) {
			return '<span class="adminkit-online-dot" title="' . esc_attr__( 'Online', 'adminkit' ) . '"></span> ' . esc_html__( 'Online', 'adminkit' );
		}
		$ts = self::last_login( $user_id );
		return $ts
			/* translators: %s: human time difference, e.g. "2 hours". */
			? esc_html( sprintf( __( '%s ago', 'adminkit' ), human_time_diff( $ts, time() ) ) )
			: esc_html__( 'Never', 'adminkit' );
	}

	/**
	 * Make the "Last login" header clickable to sort (like Username / Email). The
	 * second array element (true) makes the first click sort DESC — online users and
	 * the most recent sign-ins at the top.
	 *
	 * @param array $columns
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['adminkit_last_login'] = array( 'adminkit_last_login', true );
		return $columns;
	}

	/**
	 * Order the "Last login" sort the way the column reads: online users first, then
	 * by most-recent login. "Online" isn't a real column, so we set the ORDER BY by
	 * hand — `(ID in the live-session set)` first, then the last-login timestamp from
	 * usermeta (a correlated subquery, so people who never logged in still list, last).
	 * Fires only for our own orderby; every other query is left untouched.
	 *
	 * @param \WP_User_Query $q
	 * @return void
	 */
	public static function sort_query( $q ) {
		$orderby = isset( $q->query_vars['orderby'] ) ? $q->query_vars['orderby'] : '';
		if ( ! is_admin() || 'adminkit_last_login' !== $orderby ) {
			return;
		}
		global $wpdb;
		$order = isset( $q->query_vars['order'] ) ? strtoupper( (string) $q->query_vars['order'] ) : 'DESC';
		$dir   = ( 'ASC' === $order ) ? 'ASC' : 'DESC';

		$ids   = array_keys( self::online_map() );
		$first = $ids
			? '( ' . $wpdb->users . '.ID IN (' . implode( ',', array_map( 'absint', $ids ) ) . ') ) ' . $dir . ', '
			: '';
		$key = esc_sql( self::LAST_LOGIN_META );

		// `+ 0` sorts the stored timestamp numerically; NULL (never logged in) lands last on DESC.
		$q->query_orderby = 'ORDER BY ' . $first
			. '( SELECT mll.meta_value + 0 FROM ' . $wpdb->usermeta . ' mll'
			. " WHERE mll.user_id = {$wpdb->users}.ID AND mll.meta_key = '{$key}' LIMIT 1 ) " . $dir;
	}

	/**
	 * Load the column/dot stylesheet — only on the users list table, like Quick Edit.
	 *
	 * @param string $hook
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'users.php' !== $hook || ! current_user_can( 'list_users' ) ) {
			return;
		}
		$rel  = 'assets/css/wp-core/online-users.css';
		$path = ADMINKIT_PATH . $rel;
		wp_enqueue_style(
			'adminkit-online-users',
			ADMINKIT_URL . $rel,
			array( AdminKit_Assets::TOKENS_HANDLE ),
			file_exists( $path ) ? (string) filemtime( $path ) : ADMINKIT_VERSION
		);
	}
}
