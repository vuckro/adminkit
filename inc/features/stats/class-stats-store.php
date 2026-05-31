<?php
/**
 * Stats storage — the custom table behind the cookieless traffic tracker.
 *
 * DESIGN: aggregate-on-write, never a raw event log. Every page view folds into
 * a tiny set of pre-summed daily rows via ONE `INSERT … ON DUPLICATE KEY UPDATE`,
 * so the table grows by at most (1 site row + 1 row per distinct page + 1 row per
 * distinct referrer) PER DAY. There are no per-visitor rows, no IPs, no cookies:
 * a "visit" (session) is inferred from the Referer at write time.
 *
 * The schema is version-gated so it survives plugin updates without data loss;
 * the table is the persistent source of truth. Reads go through summary_range()
 * which is two-tier cached (object cache + transient).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Stats_Store {

	/** Bump when the schema changes so ensure_schema() re-runs dbDelta. */
	const DB_VERSION     = '1.0';
	const DB_VERSION_KEY = 'adminkit_stats_db_version';

	/** Short-TTL cache key prefix for summary_range() reads. */
	const SUMMARY_PREFIX = 'adminkit_stats_sum_';

	/** Cache group for object-cache lookups (free hit when Redis/Memcache is on). */
	const CACHE_GROUP = 'adminkit_stats';

	/**
	 * Fully-qualified, per-site table name (each site in a multisite keeps its own
	 * traffic) — uses `$wpdb->prefix`, not base_prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'adminkit_stats';
	}

	/**
	 * Create / migrate the table when the stored DB version differs. Cheap to call
	 * (one option compare until the version bumps). Wired to activation + admin_init,
	 * never to a front-end request.
	 *
	 * @return void
	 */
	public static function ensure_schema() {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_KEY ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, lowercase
		// types, KEY (not INDEX), one field per line.
		$sql = "CREATE TABLE {$table} (
  stat_date date NOT NULL,
  kind varchar(12) NOT NULL default '',
  name varchar(190) NOT NULL default '',
  pageviews bigint(20) unsigned NOT NULL default 0,
  visits bigint(20) unsigned NOT NULL default 0,
  PRIMARY KEY  (stat_date,kind,name),
  KEY kind_date (kind,stat_date)
) {$collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
	}

	/**
	 * Fold one page view into the daily aggregates — the tracker's only write.
	 *
	 * Always bumps the site total + the per-page row by one page view. On a new
	 * VISIT it also bumps their `visits` and (when a source is known) the per-source
	 * row. All in a single multi-row upsert → one indexed query per hit.
	 *
	 * @param string $path     Normalised request path (no query/fragment), e.g. `/blog/`.
	 * @param bool   $is_visit Whether this hit starts a new (cookieless) session.
	 * @param string $source   Referrer host, or 'direct' — recorded only on a visit.
	 * @return void
	 */
	public static function record( $path, $is_visit, $source ) {
		global $wpdb;

		$table = self::table();
		$date  = current_time( 'Y-m-d' ); // Site-local "today".
		$visit = $is_visit ? 1 : 0;

		$rows   = array();
		$values = array();

		// Site total (name = '') and the per-page row always get a page view.
		$rows[]   = '(%s,%s,%s,%d,%d)';
		$values[] = $date; $values[] = 'site'; $values[] = ''; $values[] = 1; $values[] = $visit;

		$rows[]   = '(%s,%s,%s,%d,%d)';
		$values[] = $date; $values[] = 'page'; $values[] = $path; $values[] = 1; $values[] = $visit;

		// Per-source row only on a new visit with a known source (pageviews n/a → 0).
		if ( $is_visit && '' !== $source ) {
			$rows[]   = '(%s,%s,%s,%d,%d)';
			$values[] = $date; $values[] = 'ref'; $values[] = $source; $values[] = 0; $values[] = 1;
		}

		// VALUES() in ON DUPLICATE KEY UPDATE is the cross-version (MySQL + MariaDB)
		// way to reference the would-be-inserted value per row. $wpdb->prepare fills
		// the %s/%d placeholders from the flat $values array.
		$sql = "INSERT INTO {$table} (stat_date, kind, name, pageviews, visits) VALUES "
			. implode( ',', $rows )
			. ' ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visits = visits + VALUES(visits)';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/* ─────────────────────────── Active visitors ─────────────────────────── */

	/** Per-minute bucket prefix for active-presence tracking. */
	const ACTIVE_BUCKET_PREFIX = 'adminkit_stats_act_';

	/** How many 60-second buckets count toward "active" (5 minutes). */
	const ACTIVE_WINDOW_BUCKETS = 5;

	/** Bucket transient TTL: window + buffer so the oldest bucket survives a read. */
	const ACTIVE_BUCKET_TTL = 420;

	/** Hard cap on distinct tokens per minute — flood protection without IP storage. */
	const ACTIVE_BUCKET_CAP = 2000;

	/**
	 * Mark an anonymous visitor active "now". MINUTE-BUCKETED design: each minute
	 * is a separate transient holding only that minute's tokens; "active" = union
	 * of the last ACTIVE_WINDOW_BUCKETS minute-buckets. Bounded writes, race-safe
	 * (a worst-case collision loses one token, not the whole map).
	 *
	 * Each bucket entry is `{p: path, s: source, t: ts}` — that gives us
	 * `active_visitors()` (distinct token count, unchanged behaviour) AND
	 * `recent_activity()` (the Live view's grouping by page/source). On revisits
	 * within the same minute, the latest `{path, source, ts}` overwrites the
	 * previous (so the live view sees where each tab is RIGHT NOW).
	 *
	 * @param string $token  Opaque visitor token (already hashed by caller).
	 * @param int    $now    Unix timestamp.
	 * @param string $path   Request path (e.g. '/blog/') — optional, enriches Live view.
	 * @param string $source Referrer host or 'direct' — optional, enriches Live view.
	 * @return void
	 */
	public static function mark_active( $token, $now, $path = '', $source = '' ) {
		$token = substr( preg_replace( '/[^a-f0-9]/', '', (string) $token ), 0, 32 );
		if ( '' === $token ) {
			return;
		}
		$bucket = (int) floor( (int) $now / 60 );
		$key    = self::ACTIVE_BUCKET_PREFIX . $bucket;

		$tokens = self::cache_get( $key );
		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}
		// Cap: only refuse NEW tokens past the cap; existing-token updates are free.
		if ( ! isset( $tokens[ $token ] ) && count( $tokens ) >= self::ACTIVE_BUCKET_CAP ) {
			return; // flood guard
		}
		// Always write the latest data for this token (cheap because the value is small).
		$tokens[ $token ] = array(
			'p' => (string) $path,
			's' => (string) $source,
			't' => (int) $now,
		);
		self::cache_set( $key, $tokens, self::ACTIVE_BUCKET_TTL );
	}

	/**
	 * Count anonymous visitors active within the last ACTIVE_WINDOW_BUCKETS
	 * minutes — the union of distinct tokens across those minute buckets.
	 *
	 * @param int $now Unix timestamp.
	 * @return int
	 */
	public static function active_visitors( $now ) {
		$current = (int) floor( (int) $now / 60 );
		$tokens  = array();
		for ( $i = 0; $i < self::ACTIVE_WINDOW_BUCKETS; $i++ ) {
			$bucket = self::cache_get( self::ACTIVE_BUCKET_PREFIX . ( $current - $i ) );
			if ( is_array( $bucket ) && $bucket ) {
				$tokens += $bucket; // union of keys → distinct count is correct
			}
		}
		return count( $tokens );
	}

	/**
	 * The Live view's data primitive: per-token LATEST seen info within the active
	 * window. When the same token appears in multiple buckets, the entry with the
	 * highest `t` wins — that's "where this tab is right now". Backward-compatible:
	 * pre-existing scalar-`1` bucket entries are silently skipped.
	 *
	 * Returned shape: `[ token => { p: path, s: source, t: ts } ]` — keep the keys
	 * one-letter so a 2000-entry bucket stays compact in the options table.
	 *
	 * @param int $now Unix timestamp.
	 * @return array<string,array{p:string,s:string,t:int}>
	 */
	public static function recent_activity( $now ) {
		$current = (int) floor( (int) $now / 60 );
		$latest  = array();
		for ( $i = 0; $i < self::ACTIVE_WINDOW_BUCKETS; $i++ ) {
			$bucket = self::cache_get( self::ACTIVE_BUCKET_PREFIX . ( $current - $i ) );
			if ( ! is_array( $bucket ) ) {
				continue;
			}
			foreach ( $bucket as $token => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue; // legacy scalar-1 entry from a pre-Live bucket
				}
				$ts = isset( $entry['t'] ) ? (int) $entry['t'] : 0;
				if ( ! isset( $latest[ $token ] ) || (int) $latest[ $token ]['t'] < $ts ) {
					$latest[ $token ] = array(
						'p' => isset( $entry['p'] ) ? (string) $entry['p'] : '',
						's' => isset( $entry['s'] ) ? (string) $entry['s'] : '',
						't' => $ts,
					);
				}
			}
		}
		return $latest;
	}

	/* ─────────────────────────── Summary reads ─────────────────────────── */

	/**
	 * Read a compact summary for an explicit date window — THE primitive every
	 * stats surface reads through. Returns per-day site totals plus top pages and
	 * sources within [start, end] (inclusive, Y-m-d, site-timezone).
	 *
	 * Two-tier cached: in-memory object cache (free hit with Redis) + transient
	 * (persistent fallback). Cache miss runs a few indexed GROUP BYs and warms
	 * both tiers.
	 *
	 * @param string $start_date Inclusive start (Y-m-d).
	 * @param string $end_date   Inclusive end   (Y-m-d).
	 * @param int    $limit      Top-N list length.
	 * @return array{start:string,end:string,days:array<string,array{pageviews:int,visits:int}>,top_pages:array,top_sources:array}
	 */
	public static function summary_range( $start_date, $end_date, $limit = 10 ) {
		$start_date = self::normalise_date( $start_date );
		$end_date   = self::normalise_date( $end_date );
		if ( strcmp( $start_date, $end_date ) > 0 ) {
			$tmp        = $start_date;
			$start_date = $end_date;
			$end_date   = $tmp;
		}
		$limit = max( 1, min( 200, (int) $limit ) );

		$key    = self::SUMMARY_PREFIX . $start_date . '_' . $end_date . '_' . $limit;
		$cached = self::cache_get( $key );
		if ( ! is_array( $cached ) ) {
			$cached = self::query_summary_range( $start_date, $end_date, $limit );
			$ttl    = (int) apply_filters( 'adminkit/stats/summary_ttl', 5 * MINUTE_IN_SECONDS );
			self::cache_set( $key, $cached, $ttl );
		}

		/**
		 * Single read seam for traffic stats. A future GA4 / Plausible source
		 * can short-circuit this without the calling surface knowing.
		 *
		 * @param array  $cached     { start, end, days, top_pages, top_sources }.
		 * @param string $start_date
		 * @param string $end_date
		 * @param int    $limit
		 */
		return apply_filters( 'adminkit/stats/summary_range', $cached, $start_date, $end_date, $limit );
	}

	/**
	 * Validate / normalise a date string to Y-m-d. Falls back to "today" if the
	 * input is empty or unparseable, so a typo never produces a fatal.
	 *
	 * @param mixed $date
	 * @return string Y-m-d
	 */
	private static function normalise_date( $date ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( '' !== $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$ts = strtotime( $date );
			if ( false !== $ts ) {
				return gmdate( 'Y-m-d', $ts );
			}
		}
		return current_time( 'Y-m-d' );
	}

	/**
	 * Run the aggregate reads (uncached) for an explicit date range.
	 *
	 * @param string $start_date
	 * @param string $end_date
	 * @param int    $limit
	 * @return array
	 */
	private static function query_summary_range( $start_date, $end_date, $limit ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 200, (int) $limit ) );

		$out = array(
			'start'       => $start_date,
			'end'         => $end_date,
			'days'        => array(),
			'top_pages'   => array(),
			'top_sources' => array(),
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$day_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, pageviews, visits FROM {$table} WHERE kind = 'site' AND stat_date BETWEEN %s AND %s ORDER BY stat_date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		foreach ( (array) $day_rows as $r ) {
			$out['days'][ $r['stat_date'] ] = array(
				'pageviews' => (int) $r['pageviews'],
				'visits'    => (int) $r['visits'],
			);
		}

		// ORDER BY the aggregate expression itself, not the `AS pageviews` / `AS visits`
		// alias: the alias shadows the same-named raw column and MySQL resolves that
		// collision unpredictably (observed: rows came back unsorted on MySQL 9).
		$out['top_pages'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT name, SUM(pageviews) AS pageviews FROM {$table} WHERE kind = 'page' AND stat_date BETWEEN %s AND %s GROUP BY name ORDER BY SUM(pageviews) DESC LIMIT %d",
				$start_date,
				$end_date,
				$limit
			),
			ARRAY_A
		);

		$out['top_sources'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT name, SUM(visits) AS visits FROM {$table} WHERE kind = 'ref' AND stat_date BETWEEN %s AND %s GROUP BY name ORDER BY SUM(visits) DESC LIMIT %d",
				$start_date,
				$end_date,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return $out;
	}

	/* ─────────────────────────── Two-tier cache helpers ──────────────────── */

	/**
	 * Object cache (Redis/Memcache when configured) first, transient fallback. On
	 * transient hit, primes the object cache so the next reader short-circuits.
	 *
	 * @param string $key
	 * @return mixed false when missing.
	 */
	private static function cache_get( $key ) {
		$found = false;
		$val   = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			return $val;
		}
		$val = get_transient( $key );
		if ( false !== $val ) {
			wp_cache_set( $key, $val, self::CACHE_GROUP, MINUTE_IN_SECONDS );
			return $val;
		}
		return false;
	}

	/**
	 * Persistent transient (so a cold PHP worker isn't blind) + object cache (so
	 * the same worker / Redis tier serves the next read in RAM). Transient is
	 * authoritative.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 * @return void
	 */
	private static function cache_set( $key, $value, $ttl ) {
		set_transient( $key, $value, (int) $ttl );
		wp_cache_set( $key, $value, self::CACHE_GROUP, (int) $ttl );
	}
}
