<?php
/**
 * Stats page — the full drill-in, on its own AdminKit submenu page
 * (admin.php?page=adminkit-stats). The dashboard card is the at-a-glance;
 * THIS is "see everything" with longer top-N lists and a free date range picker.
 *
 * Reads through AdminKit_Stats_Store::summary_range() — the single data seam.
 * Admin-only; never touches the front end.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Stats_Page {

	const REST_NS    = 'adminkit/v1';
	const REST_ROUTE = '/stats';

	/** Top-N list length for the page (the card asks 10; the page asks more). The
	 *  SPA paginates + searches this set client-side, so it's the searchable depth. */
	const LIST_SIZE = 100;

	/** Submenu page hook suffix (set when the page registers); gates enqueue. */
	private static $hook = '';

	/**
	 * Wire the REST route. Gated on the same toggle as collection — no tab/data
	 * when tracking is off (the SPA hides the tab via boot_data()).
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! AdminKit_Stats_Tracker::is_enabled() ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Self-register the focused "Statistics" submenu page (priority 11 so the
		// AdminKit parent menu, added at the default priority, exists first).
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Capability required to view stats (shared with the dashboard card). */
	private static function capability() {
		return apply_filters( 'adminkit/stats/dashboard_capability', 'edit_posts' );
	}

	/**
	 * Register the Statistics page as an AdminKit submenu. Capability is
	 * `manage_options` to match the parent menu's reach (the at-a-glance dashboard
	 * card already serves lower-capability roles). The page mounts the shared SPA
	 * engine on the 'stats' screen.
	 *
	 * @return void
	 */
	public static function add_page() {
		self::$hook = (string) add_submenu_page(
			AdminKit_Settings_Page::SLUG,
			__( 'Statistics', 'adminkit' ),
			__( 'Statistics', 'adminkit' ),
			'manage_options',
			AdminKit_Settings_Page::SLUG_STATS,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the page host on the 'stats' screen — the shared engine builds the UI.
	 *
	 * @return void
	 */
	public static function render() {
		AdminKit_Settings_Page::render_host( 'stats' );
	}

	/**
	 * Enqueue the shared admin app with the stats-screen boot payload, on this page
	 * only (gated on the hook suffix captured at registration).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		AdminKit_Settings_Page::enqueue_app( AdminKit_Settings_Page::boot_data( 'stats' ) );
	}

	/**
	 * Boot payload merged into window.AdminKitData by the settings page. The SPA
	 * uses `enabled` to decide whether to render the Statistics tab, and `default`
	 * to seed the date picker on first load (when no preference is saved).
	 *
	 * @return array
	 */
	public static function boot_data() {
		$enabled = AdminKit_Stats_Tracker::is_enabled() && current_user_can( self::capability() );
		$state   = AdminKit_Stats_Dashboard::get_user_state();

		// The Statistics PAGE is an analysis view, not a real-time glance: never LAND on
		// Live (that's the dashboard widget's default). If the saved preset is 'live',
		// open on the default range instead — without rewriting the saved preset, so the
		// dashboard widget keeps its own Live default. The user can still pick Live here.
		if ( 'live' === $state['preset'] ) {
			list( $cs, $ce ) = AdminKit_Stats_Dashboard::preset_range( AdminKit_Stats_Dashboard::DEFAULT_PRESET );
			$state = array(
				'preset' => AdminKit_Stats_Dashboard::DEFAULT_PRESET,
				'start'  => $cs,
				'end'    => $ce,
			);
		}

		// Server-localised preset list so the SPA just paints labels.
		$labels  = AdminKit_Stats_Dashboard::preset_labels();
		$presets = array();
		foreach ( AdminKit_Stats_Dashboard::PRESETS as $id ) {
			$presets[] = array(
				'id'    => $id,
				'label' => isset( $labels[ $id ] ) ? (string) $labels[ $id ] : $id,
			);
		}

		return array(
			'enabled'     => (bool) $enabled,
			'route'       => self::REST_NS . self::REST_ROUTE,
			'today'       => current_time( 'Y-m-d' ),
			'presets'     => $presets,
			'liveRefresh' => (int) AdminKit_Stats_Dashboard::LIVE_REFRESH_MS,
			// Open on the user's saved/default preset (no forced Live landing).
			'state'       => array(
				'preset' => $state['preset'],
				'start'  => $state['start'],
				'end'    => $state['end'],
			),
		);
	}

	/**
	 * i18n strings for the Statistics tab (merged into the SPA's i18n map).
	 *
	 * @return array<string,string>
	 */
	public static function i18n() {
		return array(
			'statsTab'             => __( 'Statistics', 'adminkit' ),
			'statsIntro'           => __( 'Traffic from the built-in, cookieless tracker. Pick any date range to see unique visitors, page views, your most-viewed pages and where visitors come from.', 'adminkit' ),
			'statsUniques'         => __( 'Unique visitors', 'adminkit' ),
			'statsPageviews'       => __( 'Page views', 'adminkit' ),
			'statsVsPrev'          => __( 'vs previous period', 'adminkit' ),
			'statsTrendNew'        => __( 'New', 'adminkit' ),
			'statsActive'          => __( 'active now', 'adminkit' ),
			'statsTopPages'        => __( 'Top pages', 'adminkit' ),
			'statsTopSources'      => __( 'Top sources', 'adminkit' ),
			'statsDirect'          => __( 'Direct', 'adminkit' ),
			'statsNoData'          => __( 'No traffic data yet — views will appear here as visitors arrive.', 'adminkit' ),
			'statsNone'            => __( 'No data', 'adminkit' ),
			'statsNoMatch'         => __( 'No match', 'adminkit' ),
			'statsSearch'          => __( 'Search…', 'adminkit' ),
			'statsPagerPrev'       => __( 'Previous', 'adminkit' ),
			'statsPagerNext'       => __( 'Next', 'adminkit' ),
			'statsLoading'         => __( 'Loading…', 'adminkit' ),
			'statsRangeFrom'       => __( 'From', 'adminkit' ),
			'statsRangeTo'         => __( 'To', 'adminkit' ),
			'statsRangeLabel'      => __( 'Date range', 'adminkit' ),
			'statsColPage'         => __( 'Page', 'adminkit' ),
			'statsColSource'       => __( 'Source', 'adminkit' ),
			'statsColViews'        => __( 'Views', 'adminkit' ),
			'statsColVisits'       => __( 'Visits', 'adminkit' ),
			'statsLiveHeadOne'     => __( 'visitor active right now', 'adminkit' ),
			'statsLiveHeadMany'    => __( 'visitors active right now', 'adminkit' ),
			'statsLiveViewing'     => __( 'Currently viewing', 'adminkit' ),
			'statsLiveFrom'        => __( 'Coming from', 'adminkit' ),
			'statsLiveEmpty'       => __( 'Nobody is on the site right now — this view refreshes automatically.', 'adminkit' ),
			'statsLiveColViewers'  => __( 'Viewers', 'adminkit' ),
		);
	}

	/**
	 * Register the read endpoint: GET adminkit/v1/stats?start=Y-m-d&end=Y-m-d.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => static function () {
					return current_user_can( self::capability() );
				},
				'args'                => array(
					'preset' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'start'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'end'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Return the full payload for a date range: resolved start/end, window totals,
	 * the day series (for a chart), top pages with titles, top sources, plus the
	 * live active count. Invalid dates degrade to the user's default range — no
	 * fatal possible.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_get( $request ) {
		$preset    = (string) $request->get_param( 'preset' );
		$raw_start = (string) $request->get_param( 'start' );
		$raw_end   = (string) $request->get_param( 'end' );

		// A blank/unknown preset with explicit dates → custom; otherwise a rolling
		// preset id. set_user_state() persists + returns the resolved state.
		if ( '' === $preset && ( '' !== $raw_start || '' !== $raw_end ) ) {
			$preset = 'custom';
		}
		if ( '' === $preset && '' === $raw_start && '' === $raw_end ) {
			$state = AdminKit_Stats_Dashboard::get_user_state();
		} else {
			$state = AdminKit_Stats_Dashboard::set_user_state( $preset, $raw_start, $raw_end );
		}
		$start = $state['start'];
		$end   = $state['end'];

		// Live mode is a different shape: no series / no totals, just the active
		// count grouped by page + source from the recent_activity buckets. The
		// SPA branches on `preset === 'live'` to render the live view.
		if ( 'live' === $state['preset'] ) {
			return rest_ensure_response( self::live_payload( $state ) );
		}

		$sum = AdminKit_Stats_Store::summary_range( $start, $end, self::LIST_SIZE );

		$series   = array();
		$tpv      = 0;
		$tv       = 0;
		$tu       = 0;
		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );
		for ( $ts = $start_ts; $ts <= $end_ts; $ts += DAY_IN_SECONDS ) {
			$d  = gmdate( 'Y-m-d', $ts );
			$pv = isset( $sum['days'][ $d ]['pageviews'] ) ? (int) $sum['days'][ $d ]['pageviews'] : 0;
			$v  = isset( $sum['days'][ $d ]['visits'] ) ? (int) $sum['days'][ $d ]['visits'] : 0;
			$u  = isset( $sum['days'][ $d ]['uniques'] ) ? (int) $sum['days'][ $d ]['uniques'] : 0;
			$series[] = array( 'date' => $d, 'pageviews' => $pv, 'visits' => $v, 'uniques' => $u );
			$tpv += $pv;
			$tv  += $v;
			$tu  += $u;
		}

		$pages = array();
		foreach ( (array) ( isset( $sum['top_pages'] ) ? $sum['top_pages'] : array() ) as $row ) {
			$path    = isset( $row['name'] ) ? (string) $row['name'] : '';
			$pages[] = array(
				'path'      => $path,
				'title'     => self::page_title( $path ),
				'url'       => esc_url_raw( home_url( $path ) ),
				'pageviews' => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
			);
		}

		$sources = array();
		foreach ( (array) ( isset( $sum['top_sources'] ) ? $sum['top_sources'] : array() ) as $row ) {
			$name      = isset( $row['name'] ) ? (string) $row['name'] : '';
			$sources[] = array(
				'name'   => '' === $name ? 'direct' : $name,
				'visits' => isset( $row['visits'] ) ? (int) $row['visits'] : 0,
			);
		}

		// Trend baseline: the immediately-preceding window of the SAME length, read
		// through the SAME cached primitive (limit 1 → no top-N rows pulled). Uniform
		// for every preset and custom range; the SPA derives the ▲/▼ % from it.
		list( $pstart, $pend ) = AdminKit_Stats_Dashboard::previous_range( $start, $end );
		$previous = AdminKit_Stats_Dashboard::range_totals( $pstart, $pend );

		return rest_ensure_response( array(
			'preset'   => $state['preset'],
			'start'    => $start,
			'end'      => $end,
			'totals'   => array( 'visits' => $tv, 'pageviews' => $tpv, 'uniques' => $tu ),
			'previous' => array( 'visits' => (int) $previous['visits'], 'pageviews' => (int) $previous['pageviews'], 'uniques' => (int) $previous['uniques'] ),
			'active'   => (int) AdminKit_Stats_Store::active_visitors( time() ),
			'series'   => $series,
			'pages'    => $pages,
			'sources'  => $sources,
			// Extension seam: extra metric tiles contributed by other plugins
			// (WooCommerce revenue, FluentCart conversions…). Empty natively.
			'cards'    => self::extra_cards( $start, $end, $state['preset'] ),
		) );
	}

	/**
	 * Collect + normalise extra stat tiles contributed by integrations. The filter
	 * lets a plugin (e.g. WooCommerce) return its own metric cards for the period;
	 * each is sanitised to a fixed display shape the SPA paints alongside the
	 * native Visits / Page-views tiles. Natively returns an empty list.
	 *
	 * Card shape (each entry): array(
	 *   'id'    => string,                 // stable key (sanitised)
	 *   'label' => string,                 // tile caption, already translated
	 *   'value' => string,                 // display value, already formatted ("1 240 €")
	 *   'sub'   => string (optional),      // small secondary line
	 *   'trend' => array(                  // optional ▲/▼ badge
	 *       'dir'  => 'up' | 'down' | 'flat',
	 *       'text' => string,              // e.g. "+12%"
	 *   ),
	 * )
	 *
	 * @param string $start  Y-m-d
	 * @param string $end    Y-m-d
	 * @param string $preset Resolved preset id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extra_cards( $start, $end, $preset ) {
		/**
		 * Contribute extra metric tiles to the Statistics period view.
		 *
		 * @param array  $cards  Accumulator (start empty).
		 * @param string $start  Y-m-d window start.
		 * @param string $end    Y-m-d window end.
		 * @param string $preset Resolved preset id.
		 */
		$cards = apply_filters( 'adminkit/stats/cards', array(), $start, $end, $preset );
		if ( ! is_array( $cards ) ) {
			return array();
		}
		$out = array();
		foreach ( $cards as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['label'] ) ) {
				continue;
			}
			$card = array(
				'id'    => isset( $c['id'] ) ? sanitize_key( $c['id'] ) : '',
				'label' => (string) $c['label'],
				'value' => isset( $c['value'] ) ? (string) $c['value'] : '',
			);
			if ( isset( $c['sub'] ) ) {
				$card['sub'] = (string) $c['sub'];
			}
			if ( isset( $c['trend'] ) && is_array( $c['trend'] ) ) {
				$dir           = isset( $c['trend']['dir'] ) ? (string) $c['trend']['dir'] : 'flat';
				$card['trend'] = array(
					'dir'  => in_array( $dir, array( 'up', 'down', 'flat' ), true ) ? $dir : 'flat',
					'text' => isset( $c['trend']['text'] ) ? (string) $c['trend']['text'] : '',
				);
			}
			$out[] = $card;
		}
		return $out;
	}

	/**
	 * Live-mode payload: ignore start/end (Live is "right now"). Reads the active
	 * buckets, counts distinct tokens, and groups by their LATEST page + source.
	 *
	 * Returned shape diverges intentionally from the period payload — `viewers`
	 * instead of `pageviews/visits`, no `series`/`totals` — so the SPA renders a
	 * different layout without misreading per-day fields.
	 *
	 * @param array $state Current resolved user state.
	 * @return array
	 */
	private static function live_payload( array $state ) {
		$now    = time();
		$active = AdminKit_Stats_Store::recent_activity( $now );

		$pages_h   = array();
		$sources_h = array();
		foreach ( $active as $entry ) {
			$p = isset( $entry['p'] ) ? (string) $entry['p'] : '';
			$s = isset( $entry['s'] ) ? (string) $entry['s'] : '';
			if ( '' !== $p ) {
				$pages_h[ $p ] = isset( $pages_h[ $p ] ) ? $pages_h[ $p ] + 1 : 1;
			}
			if ( '' !== $s ) {
				$sources_h[ $s ] = isset( $sources_h[ $s ] ) ? $sources_h[ $s ] + 1 : 1;
			}
		}
		arsort( $pages_h );
		arsort( $sources_h );

		$pages = array();
		foreach ( array_slice( $pages_h, 0, self::LIST_SIZE, true ) as $path => $n ) {
			$pages[] = array(
				'path'    => $path,
				'title'   => self::page_title( $path ),
				'url'     => esc_url_raw( home_url( $path ) ),
				'viewers' => (int) $n,
			);
		}
		$sources = array();
		foreach ( array_slice( $sources_h, 0, self::LIST_SIZE, true ) as $name => $n ) {
			$sources[] = array(
				'name'    => '' === $name ? 'direct' : $name,
				'viewers' => (int) $n,
			);
		}

		return array(
			'preset'  => $state['preset'],
			'start'   => $state['start'],
			'end'     => $state['end'],
			'active'  => count( $active ),
			'pages'   => $pages,
			'sources' => $sources,
		);
	}

	/**
	 * Resolve a stored path to a human page title. url_to_postid (cheap rewrite
	 * match) → get_the_title fallback → the path itself. Memoised per request.
	 *
	 * @param string $path
	 * @return string
	 */
	private static function page_title( $path ) {
		static $cache = array();
		if ( isset( $cache[ $path ] ) ) {
			return $cache[ $path ];
		}
		$title = '';
		if ( '/' === $path ) {
			$title = get_bloginfo( 'name' );
		} else {
			$id = url_to_postid( home_url( $path ) );
			if ( $id ) {
				$t = get_the_title( $id );
				if ( '' !== $t ) {
					$title = $t;
				}
			}
		}
		if ( '' === $title ) {
			$title = '' !== trim( $path, '/' ) ? trim( $path, '/' ) : $path;
		}
		$cache[ $path ] = $title;
		return $title;
	}
}
