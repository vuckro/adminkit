<?php
/**
 * Cookieless traffic tracker — the collection layer.
 *
 * A tiny front-end beacon (assets/js/track.js, ~1 KB) posts one fire-and-forget
 * hit per page view to a public REST endpoint, which folds it into the daily
 * aggregates (AdminKit_Stats_Store). Client-side on purpose: page caches like
 * FlyingPress serve cached HTML without running PHP, so a server-side counter
 * would under-count — the beacon still fires from the browser. Nothing blocks the
 * page: the script is deferred and uses navigator.sendBeacon.
 *
 * Privacy: no cookies, no IP storage, no per-visitor rows. A "visit" (session) is
 * inferred from the Referer at write time (empty or off-site → new visit), the
 * same heuristic privacy-first analytics use. So it counts visits/sessions, not
 * unique visitors — honest by construction and consent-free.
 *
 * Footprint: the beacon loads only on the public front end, never in wp-admin, and
 * never for signed-in staff (they'd skew their own stats). Bots are dropped at the
 * endpoint by User-Agent.
 *
 * Filter:
 *   adminkit/stats/enabled  (bool)  master on/off
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Stats_Tracker {

	const REST_NS    = 'adminkit/v1';
	const REST_ROUTE = '/hit';
	const HANDLE     = 'adminkit-track';

	/**
	 * Register the setting + wire hooks. The REST route is always registered (it's
	 * the collector); the beacon is enqueued only where it should run.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'stats_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		) );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Keep the schema current without a front-end DB hit (admin-only).
		add_action( 'admin_init', array( 'AdminKit_Stats_Store', 'ensure_schema' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Master switch — registered setting (default ON) through a filter.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/stats/enabled', AdminKit_Settings::get( 'stats_enabled' ) );
	}

	/**
	 * Whether this request should be counted at all. Excludes signed-in staff
	 * (they'd skew their own stats) and non-page contexts (feeds, previews,
	 * favicon, robots, REST/AJAX). Front-end page views only.
	 *
	 * @return bool
	 */
	private static function should_count() {
		if ( is_admin() || is_feed() || is_preview() || is_embed() ) {
			return false;
		}
		if ( is_robots() || is_trackback() || is_favicon() ) {
			return false;
		}
		// Anyone who can edit content is "staff" — don't count their browsing.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Enqueue the beacon on qualifying front-end page views. Passes the endpoint
	 * URL + a referer flag to the script via a small inline `before` blob.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_count() ) {
			return;
		}

		$data = 'window.AdminKitTrack=' . wp_json_encode( array(
			'url' => esc_url_raw( rest_url( self::REST_NS . self::REST_ROUTE ) ),
		) ) . ';';

		AdminKit_Assets::enqueue_script( self::HANDLE, 'assets/js/track.js', array(), $data );
	}

	/**
	 * Register the public collector endpoint. Public by design (anonymous visitors
	 * must reach it); abuse surface is minimal — it only ever increments counters
	 * and stores no caller-controlled identity.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_hit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'path' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => static function ( $v ) {
							return is_string( $v ) ? $v : '';
						},
					),
					'ref'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => static function ( $v ) {
							return is_string( $v ) ? $v : '';
						},
					),
					't'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => static function ( $v ) {
							return is_string( $v ) ? $v : '';
						},
					),
				),
			)
		);
	}

	/** Defensive input caps applied BEFORE any expensive parsing or DB work. */
	const MAX_PATH_BYTES  = 2048;
	const MAX_REF_BYTES   = 2048;
	const MAX_TOKEN_BYTES = 64;

	/**
	 * Build the always-204 response with explicit no-cache headers — the beacon
	 * must never be served from a proxy/CDN cache, otherwise downstream Cloudflare
	 * etc. could swallow the request entirely. Single place so every early-exit
	 * branch in rest_hit() returns the same response.
	 *
	 * @return WP_REST_Response
	 */
	private static function ack() {
		$res = new WP_REST_Response( null, 204 );
		$res->header( 'Cache-Control', 'no-store, max-age=0' );
		return $res;
	}

	/**
	 * Collector callback — validate, derive visit + source, fold into aggregates.
	 * Always returns 204 with no-cache headers so the beacon stays cheap AND can't
	 * be cached upstream.
	 *
	 * Defensive ordering: cheap checks (toggle, bot UA, input length caps) run
	 * BEFORE any DB work, so a flood of garbage payloads never reaches the writes.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_hit( $request ) {
		// 1. Cheap gates: feature flag + UA-based bot drop. Filters can flip
		//    `is_enabled` after init, so re-check here.
		if ( ! self::is_enabled() || self::is_bot() ) {
			return self::ack();
		}

		$raw_path  = (string) $request->get_param( 'path' );
		$raw_ref   = (string) $request->get_param( 'ref' );
		$raw_token = (string) $request->get_param( 't' );

		// 2. Hard input caps BEFORE expensive regex/parse. A 1 MB junk payload is
		//    rejected at the door without touching the DB or the URL parser.
		if (
			strlen( $raw_path )  > self::MAX_PATH_BYTES  ||
			strlen( $raw_ref )   > self::MAX_REF_BYTES   ||
			strlen( $raw_token ) > self::MAX_TOKEN_BYTES
		) {
			return self::ack();
		}

		$path = self::normalize_path( $raw_path );
		if ( '' === $path ) {
			return self::ack();
		}

		$source   = self::referrer_source( $raw_ref );
		$is_visit = ( 'internal' !== $source ); // empty/off-site referer → new visit
		$src_name = ( 'direct' === $source ) ? 'direct' : ( 'internal' === $source ? '' : $source );
		AdminKit_Stats_Store::record( $path, $is_visit, $src_name );

		// 3. Presence: mark this anonymous tab active for the realtime count + Live
		//    view. The client token is opaque; we hash it with a server salt so the
		//    stored value reveals nothing even if the option table leaked. The path
		//    and source attached here are what the Live view groups by — same data
		//    already accepted above, no new caller input.
		if ( '' !== $raw_token ) {
			$token       = substr( hash( 'md5', wp_salt() . preg_replace( '/[^A-Za-z0-9]/', '', $raw_token ) ), 0, 32 );
			$live_source = ( 'internal' === $source ) ? 'direct' : $src_name;
			AdminKit_Stats_Store::mark_active( $token, time(), $path, $live_source );
		}

		return self::ack();
	}

	/**
	 * Normalise a client-sent path: strip scheme/host/query/fragment, force a
	 * leading slash, cap length to the column, collapse the home path to '/'.
	 *
	 * @param string $raw
	 * @return string '' when unusable.
	 */
	private static function normalize_path( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		// Accept a full URL or a bare path; keep only the path component.
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = $raw;
		}
		$path = '/' . ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );
		$path = rawurldecode( $path );
		// Never store control chars; cap to the 190-char column.
		$path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		if ( strlen( $path ) > 190 ) {
			$path = substr( $path, 0, 190 );
		}
		return $path;
	}

	/**
	 * Classify the Referer into a source token:
	 *   'internal' → same host (not a new visit)
	 *   'direct'   → no referer (a new visit, source unknown)
	 *   <host>     → off-site host, lowercased, leading www. stripped (a new visit)
	 *
	 * @param string $ref
	 * @return string
	 */
	private static function referrer_source( $ref ) {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return 'direct';
		}
		$host = wp_parse_url( $ref, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return 'direct';
		}
		$host = strtolower( $host );
		$self = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $self || 'www.' . $self === $host || $host === 'www.' . $self ) {
			return 'internal';
		}
		$host = preg_replace( '/^www\./', '', $host );
		if ( strlen( $host ) > 190 ) {
			$host = substr( $host, 0, 190 );
		}
		return $host;
	}

	/** Hostile / automated UA needles. Filterable so sites can add their own. */
	private static function bot_needles() {
		return (array) apply_filters( 'adminkit/stats/bot_needles', array(
			'bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit',
			'embedly', 'pingdom', 'lighthouse', 'headless', 'curl', 'wget',
			'python-requests', 'go-http-client', 'java/', 'okhttp', 'ahrefs',
			'semrush', 'mj12', 'monitor', 'uptime', 'sitelock', 'screaming',
		) );
	}

	/**
	 * Cheap bot filter by User-Agent. False-positive bias: when in doubt we DROP
	 * the hit. A real visitor mistakenly excluded costs us 1 count; a bot wrongly
	 * counted pollutes the stats permanently. Filter `adminkit/stats/bot_needles`
	 * to extend, `adminkit/stats/is_bot` to short-circuit completely.
	 *
	 * @return bool
	 */
	private static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$ua = is_string( $ua ) ? trim( sanitize_text_field( $ua ) ) : '';

		// Allow integrations to flip the decision either way before we scan.
		$override = apply_filters( 'adminkit/stats/is_bot', null, $ua );
		if ( null !== $override ) {
			return (bool) $override;
		}

		// No UA, or so short it can't be a real browser → automated.
		if ( '' === $ua || strlen( $ua ) < 10 ) {
			return true;
		}

		$ua_lc = strtolower( $ua );
		foreach ( self::bot_needles() as $needle ) {
			if ( '' !== $needle && false !== strpos( $ua_lc, (string) $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
