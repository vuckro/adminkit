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
 * Privacy: no cookies, the raw IP is never stored, and there is no lasting
 * per-visitor record. Three daily metrics — page views, visits (a "visit"/session
 * is inferred from the Referer: empty or off-site → new visit) and UNIQUE VISITORS.
 * Uniques are deduped by a salted, DAILY-ROTATING hash of IP + User-Agent
 * (visitor_hash) computed in memory: never reversible to an IP, never linkable
 * across days, and the dedup rows are pruned after a day — the same consent-free
 * model privacy-first analytics (Plausible, Fathom) use. Nothing here needs a
 * cookie banner.
 *
 * Footprint: the beacon loads only on the public front end, never in wp-admin, and
 * never for signed-in staff (they'd skew their own stats). Bots are dropped at the
 * endpoint by User-Agent.
 *
 * Filters:
 *   adminkit/stats/enabled    (bool)    master on/off
 *   adminkit/stats/client_ip  (string)  override the resolved client IP (proxies/CDN)
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
		// OFF by default: this is the ONE feature with an inherent front-end cost —
		// a beacon POST per page view that boots WordPress. Keeping it opt-in means
		// activating AdminKit fires NOTHING on the front end (no beacon, no stats
		// page, no dashboard card) until you turn it on in Settings → Features. The
		// beacon (vs server-side counting) is deliberate: it survives full-page
		// caches and JS-gates out most bots, so the data stays honest once enabled.
		AdminKit_Settings::register( 'stats_enabled', array(
			'type'     => 'toggle',
			'group'    => 'features',
			'default'  => false,
			'sanitize' => 'rest_sanitize_boolean',
		) );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Keep the schema current. Admin requests via admin_init; the REST collector via
		// rest_api_init too, so a front-end beacon never writes to a not-yet-migrated
		// column (e.g. right after a plugin UPDATE — activation hooks don't fire on update
		// — before any admin page loads, which used to silently drop hits). ensure_schema
		// early-returns on a version match, so it's a cheap (autoloaded) option compare on
		// the hot path; dbDelta only runs the once when the version actually moves.
		add_action( 'admin_init', array( 'AdminKit_Stats_Store', 'ensure_schema' ) );
		add_action( 'rest_api_init', array( 'AdminKit_Stats_Store', 'ensure_schema' ) );
		// Prune yesterday's unique-dedup rows once a day, admin-side (off the hot path).
		add_action( 'admin_init', array( __CLASS__, 'maybe_prune' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Master switch — the registered setting (default OFF; see init()) behind a
	 * filter so integrations can force it on/off.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/stats/enabled', AdminKit_Settings::get( 'stats_enabled' ) );
	}

	/**
	 * Prune the ephemeral unique-dedup rows at most once per calendar day. Guarded
	 * by an option so a busy admin area doesn't re-run the DELETE on every request.
	 * Admin-only (hooked on admin_init) → never on the front-end hot path.
	 *
	 * @return void
	 */
	public static function maybe_prune() {
		$today = current_time( 'Y-m-d' );
		if ( get_option( 'adminkit_stats_pruned' ) === $today ) {
			return;
		}
		AdminKit_Stats_Store::prune_old_uniques();
		update_option( 'adminkit_stats_pruned', $today, false );
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
				),
			)
		);
	}

	/** Defensive input caps applied BEFORE any expensive parsing or DB work. */
	const MAX_PATH_BYTES = 2048;
	const MAX_REF_BYTES  = 2048;

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

		$raw_path = (string) $request->get_param( 'path' );
		$raw_ref  = (string) $request->get_param( 'ref' );

		// 2. Hard input caps BEFORE expensive regex/parse. A 1 MB junk payload is
		//    rejected at the door without touching the DB or the URL parser.
		if ( strlen( $raw_path ) > self::MAX_PATH_BYTES || strlen( $raw_ref ) > self::MAX_REF_BYTES ) {
			return self::ack();
		}

		$path = self::normalize_path( $raw_path );
		if ( '' === $path ) {
			return self::ack();
		}

		$source   = self::referrer_source( $raw_ref );
		$is_visit = ( 'internal' !== $source ); // empty/off-site referer → new visit
		$src_name = ( 'direct' === $source ) ? 'direct' : ( 'internal' === $source ? '' : $source );

		// Server-derived identity: a salted, daily-rotating hash of IP + User-Agent,
		// used for BOTH unique-visitor dedup (daily) and live presence (5-min). No
		// cookie, no client token — and the raw IP never leaves visitor_hash().
		$visitor_hash = self::visitor_hash();

		AdminKit_Stats_Store::record( $path, $is_visit, $src_name, $visitor_hash );

		// 3. Presence: mark this visitor active for the realtime count + Live view.
		//    The path + source attached here are what the Live view groups by.
		if ( '' !== $visitor_hash ) {
			$live_source = ( 'internal' === $source ) ? 'direct' : $src_name;
			AdminKit_Stats_Store::mark_active( $visitor_hash, time(), $path, $live_source );
		}

		return self::ack();
	}

	/**
	 * The cookieless visitor identity — a 32-char hex hash of a server secret + the
	 * site-local DAY + client IP + User-Agent. Consent-free by construction:
	 *   • salted with wp_salt() → not reversible to an IP without the secret;
	 *   • the day is part of the input → the SAME visitor hashes DIFFERENTLY each
	 *     day, so it can never link someone across days;
	 *   • computed in memory; only ever persisted as this hash, never the IP.
	 * Returns '' when no IP resolves (uniques + presence then skip this hit).
	 *
	 * @return string
	 */
	private static function visitor_hash() {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return '';
		}
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$day = current_time( 'Y-m-d' ); // site-local — rotates the hash every day.
		return substr( hash( 'sha256', wp_salt( 'auth' ) . '|' . $day . '|' . $ip . '|' . $ua ), 0, 32 );
	}

	/**
	 * Resolve the client IP. REMOTE_ADDR (the beacon's direct peer) by DEFAULT — the
	 * safe choice on a normal host. Forwarded headers (X-Forwarded-For /
	 * CF-Connecting-IP) are CLIENT-SPOOFABLE on a host that isn't actually behind a
	 * trusted proxy, and since uniques are keyed on the IP hash, a spoofed header on
	 * the PUBLIC /hit endpoint could mint unlimited fake "unique visitors" (+ dedup
	 * rows). So they're consulted ONLY when a site opts in via `adminkit/stats/trust_proxy`
	 * (genuinely behind a proxy/CDN). `adminkit/stats/client_ip` still allows a bespoke
	 * override. The value only ever feeds visitor_hash() and is never stored.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		if ( apply_filters( 'adminkit/stats/trust_proxy', false ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as $key ) {
				if ( empty( $_SERVER[ $key ] ) ) {
					continue;
				}
				$candidate = (string) wp_unslash( $_SERVER[ $key ] );
				// X-Forwarded-For can be "client, proxy1, proxy2" — the client is first.
				if ( false !== strpos( $candidate, ',' ) ) {
					$parts     = explode( ',', $candidate );
					$candidate = trim( $parts[0] );
				}
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}
		/** Override the resolved client IP (proxies/CDN, or to force a specific value). */
		$ip    = (string) apply_filters( 'adminkit/stats/client_ip', $ip );
		$valid = filter_var( $ip, FILTER_VALIDATE_IP );
		return $valid ? $valid : '';
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
		// Decoding can re-introduce a query/fragment (e.g. %3F → ?); drop it so the same
		// page can't split into two rows (and to bound junk-encoded path-row bloat).
		$path = preg_replace( '/[?#].*$/', '', $path );
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
		// Compare with a leading www. stripped from BOTH sides, so example.com and
		// www.example.com count as the same (own) host whichever way round it is.
		if ( preg_replace( '/^www\./', '', $host ) === preg_replace( '/^www\./', '', $self ) ) {
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
