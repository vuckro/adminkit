<?php
/**
 * Stats dashboard card — ONE focused card for the cookieless tracker:
 * active-visitors pulse, headline metrics (unique visitors + page views), a
 * sparkline, and the top pages / top sources lists for a user-chosen date range.
 *
 * The user picks the range via two native date inputs (start + end). Their
 * choice is persisted in `user_meta['adminkit_stats_range']` and consumed on
 * every render — across logins, devices, and dashboard re-renders. On change,
 * one AJAX call re-renders just the body of THIS card.
 *
 * Reads through AdminKit_Stats_Store::summary_range() — the single data seam,
 * two-tier cached.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Stats_Dashboard {

	/** AJAX action to re-render the card body for a chosen range. */
	const RENDER_ACTION = 'adminkit_stats_render';

	/** Per-user meta key: stores `{preset: '30d'}` for rolling presets, or
	 *  `{preset: 'custom', start: Y-m-d, end: Y-m-d}` for free ranges. */
	const RANGE_META = 'adminkit_stats_range';

	/** Default preset on first render. Users who have saved a preset keep it;
	 *  only those with no stored choice land here. */
	const DEFAULT_PRESET = '30d';

	/** Available presets, in selector order. 'live' is a special UI state — the
	 *  body renders real-time data and auto-refreshes client-side. 'custom'
	 *  reveals the two date inputs and stores dates verbatim. Everything else is
	 *  a rolling range computed from "today" at render time. */
	const PRESETS = array( 'live', 'today', '7d', '30d', '90d', 'ytd', 'custom' );

	/** Old preset ids that map to a current one, for in-place migration of saved
	 *  user_meta — so removing/renaming a preset never costs anyone their saved
	 *  choice. Pre-Live shipped '1y'; it's a near-equivalent of 'ytd' (year-to-date). */
	const LEGACY_PRESETS = array( '1y' => 'ytd' );

	/** Hard cap on the custom range span, server-side — protects against huge spans. */
	const MAX_DAYS = 730;

	/** Live view auto-refresh cadence (ms) — read by the dashboard JS. Filterable
	 *  via the boot-data layer if a site wants slower/faster polling. */
	const LIVE_REFRESH_MS = 10000;

	/**
	 * Wire the card + the body-render AJAX. Gated on the same toggle as collection.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! AdminKit_Stats_Tracker::is_enabled() ) {
			return;
		}
		add_filter( 'adminkit/dashboard/cards', array( __CLASS__, 'register_card' ) );
		add_action( 'wp_ajax_' . self::RENDER_ACTION, array( __CLASS__, 'ajax_render' ) );
	}

	/**
	 * Register the single Statistics card. Defaults to the main column for width.
	 *
	 * @param array $cards
	 * @return array
	 */
	public static function register_card( $cards ) {
		if ( ! is_array( $cards ) ) {
			return $cards;
		}
		$cards['stats'] = array(
			'cb'    => array( __CLASS__, 'render' ),
			'col'   => 'main',
			'label' => __( 'Statistics', 'adminkit' ),
		);
		return $cards;
	}

	/** Capability required to view stats (filterable). */
	private static function capability() {
		return apply_filters( 'adminkit/stats/dashboard_capability', 'edit_posts' );
	}

	/* ─────────────────────────── Range persistence ───────────────────────── */

	/**
	 * Compute [start, end] for a rolling preset. "Today minus N + today" so the
	 * span is inclusive on both ends. Unknown preset → default's range.
	 *
	 * @param string $preset
	 * @return array{0:string,1:string}
	 */
	public static function preset_range( $preset ) {
		$today = current_time( 'Y-m-d' );
		switch ( $preset ) {
			case 'live':
				// Live view is "right now" — but we still return a valid Y-m-d pair
				// (today/today) so any code consuming a range gets a sane fallback.
				return array( $today, $today );
			case 'today':
				return array( $today, $today );
			case '7d':
				return array( gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ), $today );
			case '90d':
				return array( gmdate( 'Y-m-d', strtotime( $today . ' -89 days' ) ), $today );
			case 'ytd':
				// Year-to-date: January 1st of the CURRENT year (in site timezone)
				// through today. A variable-length window — meaningful as a
				// year-in-progress metric.
				return array( substr( $today, 0, 4 ) . '-01-01', $today );
			case '30d':
			default:
				return array( gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) ), $today );
		}
	}

	/**
	 * Default date range (for callers like the SPA boot data). Equals the default
	 * preset's current range. Filterable.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function default_range() {
		$out = (array) apply_filters( 'adminkit/stats/default_range', self::preset_range( self::DEFAULT_PRESET ) );
		return array(
			self::clamp_date( isset( $out[0] ) ? $out[0] : '' ),
			self::clamp_date( isset( $out[1] ) ? $out[1] : '' ),
		);
	}

	/* ──────────────── Trend / comparison (shared: page + card) ──────────────── */

	/**
	 * The window immediately before [start, end], of the SAME number of days —
	 * uniform across presets AND custom ranges, so the trend baseline is always
	 * "the previous equal-length period".
	 *
	 * @param string $start Y-m-d
	 * @param string $end   Y-m-d
	 * @return array{0:string,1:string} [prev_start, prev_end]
	 */
	public static function previous_range( $start, $end ) {
		$s = strtotime( $start );
		$e = strtotime( $end );
		if ( false === $s || false === $e ) {
			return array( $start, $end );
		}
		$span_days  = (int) floor( ( $e - $s ) / DAY_IN_SECONDS ) + 1;
		$prev_end   = $s - DAY_IN_SECONDS;
		$prev_start = $prev_end - ( $span_days - 1 ) * DAY_IN_SECONDS;
		return array( gmdate( 'Y-m-d', $prev_start ), gmdate( 'Y-m-d', $prev_end ) );
	}

	/**
	 * Sum site unique visitors + visits + page views over a window via the cached
	 * summary primitive. Asks limit 1 so no top-N rows are pulled (day totals are
	 * independent of the list limit) — a cheap, cached read.
	 *
	 * @param string $start Y-m-d
	 * @param string $end   Y-m-d
	 * @return array{visits:int,pageviews:int,uniques:int}
	 */
	public static function range_totals( $start, $end ) {
		$sum = AdminKit_Stats_Store::summary_range( $start, $end, 1 );
		$v   = 0;
		$pv  = 0;
		$u   = 0;
		foreach ( (array) ( isset( $sum['days'] ) ? $sum['days'] : array() ) as $d ) {
			$pv += isset( $d['pageviews'] ) ? (int) $d['pageviews'] : 0;
			$v  += isset( $d['visits'] ) ? (int) $d['visits'] : 0;
			$u  += isset( $d['uniques'] ) ? (int) $d['uniques'] : 0;
		}
		return array( 'visits' => $v, 'pageviews' => $pv, 'uniques' => $u );
	}

	/**
	 * Trend descriptor for current vs previous totals — the single source of truth
	 * that the SPA's trendFrom() mirrors byte-for-byte. With a baseline it's a
	 * signed percentage; with NO baseline, new traffic is a plain ▲ (up vs nothing)
	 * and a flat 0 is null (nothing to show).
	 *
	 * @param int $cur
	 * @param int $prev
	 * @return array{dir:string,text:string}|null
	 */
	public static function trend( $cur, $prev ) {
		$cur  = (int) $cur;
		$prev = (int) $prev;
		if ( $prev <= 0 ) {
			return $cur > 0 ? array( 'dir' => 'up', 'text' => '' ) : null;
		}
		$pct = (int) round( ( ( $cur - $prev ) / $prev ) * 100 );
		return array(
			'dir'  => $pct > 0 ? 'up' : ( $pct < 0 ? 'down' : 'flat' ),
			'text' => ( $pct > 0 ? '+' : '' ) . $pct . '%',
		);
	}

	/**
	 * Render the ▲/▼ trend badge for the dashboard card (PHP twin of the SPA's
	 * trendBadge()). '' when there's no trend to show.
	 *
	 * @param int $cur
	 * @param int $prev
	 * @return string Safe HTML.
	 */
	public static function trend_badge( $cur, $prev ) {
		$t = self::trend( $cur, $prev );
		if ( null === $t ) {
			return '';
		}
		$arrow = 'up' === $t['dir'] ? '▲' : ( 'down' === $t['dir'] ? '▼' : '→' );
		// No baseline (empty text) → "New" instead of a bare arrow (matches the SPA).
		$label = $arrow . ' ' . ( '' !== $t['text'] ? $t['text'] : __( 'New', 'adminkit' ) );
		return '<span class="ak-dash__stats-trend ak-dash__stats-trend--' . esc_attr( $t['dir'] )
			. '" title="' . esc_attr__( 'vs previous period', 'adminkit' ) . '">'
			. esc_html( $label ) . '</span>';
	}

	/**
	 * Read the current user's state. ROLLING presets (today/30d/90d/1y) are
	 * stored as just `{preset: id}` and re-resolved on every render — so "30 days"
	 * still means the last 30 days tomorrow, not the dates you picked yesterday.
	 * `custom` stores the explicit dates. The previous storage shape
	 * (numeric `[start, end]`) is read as a custom range so nobody loses their
	 * saved window across this upgrade.
	 *
	 * @return array{start:string,end:string,preset:string}
	 */
	public static function get_user_state() {
		$saved = get_user_meta( get_current_user_id(), self::RANGE_META, true );

		if ( is_array( $saved ) ) {
			// Legacy shape: numeric [start, end] without a 'preset' key.
			if ( ! isset( $saved['preset'] ) && isset( $saved[0], $saved[1] ) ) {
				list( $s, $e ) = self::sanitise_range( (string) $saved[0], (string) $saved[1] );
				return array( 'start' => $s, 'end' => $e, 'preset' => 'custom' );
			}
			// Current shape.
			$preset = isset( $saved['preset'] ) ? (string) $saved['preset'] : '';
			// Migrate legacy preset ids in place (e.g. the old '1y' → 'ytd').
			if ( isset( self::LEGACY_PRESETS[ $preset ] ) ) {
				$preset = self::LEGACY_PRESETS[ $preset ];
			}
			if ( 'custom' === $preset && isset( $saved['start'], $saved['end'] ) ) {
				list( $s, $e ) = self::sanitise_range( (string) $saved['start'], (string) $saved['end'] );
				return array( 'start' => $s, 'end' => $e, 'preset' => 'custom' );
			}
			if ( in_array( $preset, self::PRESETS, true ) && 'custom' !== $preset ) {
				list( $s, $e ) = self::preset_range( $preset );
				return array( 'start' => $s, 'end' => $e, 'preset' => $preset );
			}
		}

		// Nothing valid stored → default.
		list( $s, $e ) = self::preset_range( self::DEFAULT_PRESET );
		return array( 'start' => $s, 'end' => $e, 'preset' => self::DEFAULT_PRESET );
	}

	/**
	 * Persist a new state. For a rolling preset we save only its ID (so the range
	 * keeps rolling). For `custom` we save the sanitised dates verbatim.
	 *
	 * @param string $preset
	 * @param string $start Only used when preset === 'custom'.
	 * @param string $end   Only used when preset === 'custom'.
	 * @return array{start:string,end:string,preset:string} The state actually stored.
	 */
	public static function set_user_state( $preset, $start = '', $end = '' ) {
		$preset = (string) $preset;
		if ( 'custom' === $preset ) {
			list( $s, $e ) = self::sanitise_range( $start, $end );
			update_user_meta( get_current_user_id(), self::RANGE_META, array(
				'preset' => 'custom',
				'start'  => $s,
				'end'    => $e,
			) );
			return array( 'start' => $s, 'end' => $e, 'preset' => 'custom' );
		}
		if ( ! in_array( $preset, self::PRESETS, true ) ) {
			$preset = self::DEFAULT_PRESET;
		}
		update_user_meta( get_current_user_id(), self::RANGE_META, array( 'preset' => $preset ) );
		list( $s, $e ) = self::preset_range( $preset );
		return array( 'start' => $s, 'end' => $e, 'preset' => $preset );
	}

	/**
	 * Sanitise a (start, end) pair: each clamped to today; start ≤ end; the span
	 * capped to MAX_DAYS so a malicious / typo'd input can't sweep ten years.
	 *
	 * @param string $start
	 * @param string $end
	 * @return array{0:string,1:string}
	 */
	private static function sanitise_range( $start, $end ) {
		$start = self::clamp_date( $start );
		$end   = self::clamp_date( $end );
		if ( strcmp( $start, $end ) > 0 ) {
			$tmp   = $start;
			$start = $end;
			$end   = $tmp;
		}
		$span = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS );
		if ( $span > self::MAX_DAYS ) {
			$start = gmdate( 'Y-m-d', strtotime( $end . ' -' . ( self::MAX_DAYS - 1 ) . ' days' ) );
		}
		return array( $start, $end );
	}

	/**
	 * Validate one date: must be Y-m-d, never in the future. Junk / future → today.
	 *
	 * @param string $date
	 * @return string Y-m-d
	 */
	private static function clamp_date( $date ) {
		$today = current_time( 'Y-m-d' );
		$date  = is_string( $date ) ? trim( $date ) : '';
		if ( '' === $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $today;
		}
		return ( strcmp( $date, $today ) > 0 ) ? $today : $date;
	}

	/* ─────────────────────────── Render ─────────────────────────── */

	/**
	 * Render the whole card (outer section + body). Empty when the viewer lacks
	 * the capability.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		// Open on the user's saved/default preset (no forced Live landing).
		$state = self::get_user_state();
		echo '<section class="ak-card ak-dash__card ak-dash__stats" data-ak-stats>';
		echo self::body( $state['start'], $state['end'], $state['preset'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
		echo '</section>';
	}

	/**
	 * AJAX: persist the new state (preset OR custom dates) for the current user,
	 * then return the rendered body.
	 *
	 * @return void
	 */
	public static function ajax_render() {
		check_ajax_referer( self::RENDER_ACTION );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		// Lightweight "ping": return ONLY the active-now pill — for the live counter
		// on the dashboard period views. No state write, no summary read; cheap to
		// poll every few seconds.
		if ( ! empty( $_POST['ping'] ) ) {
			wp_send_json_success( array( 'active_html' => self::active_pill() ) );
		}

		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		$start  = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end    = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';

		// When the client sends explicit dates without a preset (or with 'custom'),
		// it's a custom range; otherwise it's a rolling preset id.
		if ( '' === $preset && ( '' !== $start || '' !== $end ) ) {
			$preset = 'custom';
		}
		$state = self::set_user_state( $preset, $start, $end );

		wp_send_json_success( array(
			'start'  => $state['start'],
			'end'    => $state['end'],
			'preset' => $state['preset'],
			'html'   => self::body( $state['start'], $state['end'], $state['preset'] ),
		) );
	}

	/**
	 * Build the card body HTML for an explicit state.
	 *
	 * @param string $start  Y-m-d
	 * @param string $end    Y-m-d
	 * @param string $preset Currently-active preset id (or 'custom').
	 * @return string Safe HTML.
	 */
	private static function body( $start, $end, $preset = '30d' ) {
		// Live is its own thing — no date-range query, no sparkline; it groups
		// the active-bucket entries by page and source.
		if ( 'live' === $preset ) {
			return self::body_live();
		}

		$sum = AdminKit_Stats_Store::summary_range( $start, $end );

		// Continuous oldest→newest day series (no gaps) + window totals.
		$series_pv = array();
		$total_pv  = 0;
		$total_u   = 0;
		$start_ts  = strtotime( $start );
		$end_ts    = strtotime( $end );
		for ( $ts = $start_ts; $ts <= $end_ts; $ts += DAY_IN_SECONDS ) {
			$d  = gmdate( 'Y-m-d', $ts );
			$pv = isset( $sum['days'][ $d ]['pageviews'] ) ? (int) $sum['days'][ $d ]['pageviews'] : 0;
			$u  = isset( $sum['days'][ $d ]['uniques'] ) ? (int) $sum['days'][ $d ]['uniques'] : 0;
			$series_pv[] = $pv;
			$total_pv   += $pv;
			$total_u    += $u;
		}

		// Trend baseline — the previous equal-length window (shared helper, cached).
		list( $ps, $pe ) = self::previous_range( $start, $end );
		$prev = self::range_totals( $ps, $pe );

		// Head: title + the "active now" pill (shown on period views as live
		// context — hidden on Live, where the big count already is it) + the picker.
		// Synced with the SPA stats page, which does the same.
		$out  = '<div class="ak-card__head ak-dash__stats-head">';
		$out .= '<h2 class="ak-card__title">' . esc_html__( 'Statistics', 'adminkit' ) . '</h2>';
		// Wrap the active-now pill so the dashboard JS can keep it LIVE on period
		// views too — polling JUST this count (cheap: no full-card re-render, no
		// saved-state write). Kept present even when empty (0 active) so the poller
		// has a stable target to fill / clear.
		$out .= '<span class="ak-dash__stats-active-wrap" data-ak-stats-active data-ak-stats-active-interval="' . esc_attr( (string) self::LIVE_REFRESH_MS ) . '">' . self::active_pill() . '</span>';
		$out .= self::range_picker( $start, $end, $preset );
		$out .= '</div>';

		// Headline metrics — ALWAYS shown (a clear "0 / 0" beats a blank panel when a
		// short window like Today has no views yet), each with a ▲/▼ trend vs the
		// previous period (same logic + look as the SPA stats page).
		$out .= '<div class="ak-dash__stats-top">';
		$out .= '<div class="ak-dash__stats-metric"><span class="ak-dash__stats-num-row">'
			. '<span class="ak-dash__stats-num">' . esc_html( number_format_i18n( $total_u ) ) . '</span>'
			. self::trend_badge( $total_u, $prev['uniques'] )
			. '</span><span class="ak-dash__stats-lbl">' . esc_html__( 'Unique visitors', 'adminkit' ) . '</span></div>';
		$out .= '<div class="ak-dash__stats-metric"><span class="ak-dash__stats-num-row">'
			. '<span class="ak-dash__stats-num">' . esc_html( number_format_i18n( $total_pv ) ) . '</span>'
			. self::trend_badge( $total_pv, $prev['pageviews'] )
			. '</span><span class="ak-dash__stats-lbl">' . esc_html__( 'Page views', 'adminkit' ) . '</span></div>';
		$out .= '</div>';

		if ( $total_pv <= 0 ) {
			$out .= '<p class="ak-dash__empty">' . esc_html__( 'No views in this period yet.', 'adminkit' ) . '</p>';
			return $out . self::more_link();
		}

		// Sparkline (page views/day) — needs ≥2 days; a single day (Today) shows just
		// the metrics + lists, no flat one-point line.
		if ( count( $series_pv ) > 1 ) {
			$out .= '<div class="ak-dash__stats-spark">' . self::sparkline( $series_pv ) . '</div>';
		}

		// Two compact lists side by side.
		$out .= '<div class="ak-dash__stats-cols">';
		$out .= self::render_list( __( 'Top pages', 'adminkit' ), isset( $sum['top_pages'] ) ? (array) $sum['top_pages'] : array(), 'pageviews', true );
		$out .= self::render_list( __( 'Top sources', 'adminkit' ), isset( $sum['top_sources'] ) ? (array) $sum['top_sources'] : array(), 'visits', false );
		$out .= '</div>';

		return $out . self::more_link();
	}

	/**
	 * Footer link from the dashboard widget to the full Statistics page. Appended INSIDE
	 * each body so it survives the AJAX period-swap (which replaces the card's innerHTML
	 * with the body HTML). Empty when the Stats page isn't registered.
	 *
	 * @return string Safe HTML.
	 */
	private static function more_link() {
		if ( ! class_exists( 'AdminKit_Settings_Page' ) ) {
			return '';
		}
		return sprintf(
			'<a class="ak-dash__more ak-dash__stats-more" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . AdminKit_Settings_Page::SLUG_STATS ) ),
			esc_html__( 'View all statistics', 'adminkit' )
		);
	}

	/**
	 * The Live view body — a real-time snapshot. Reads the active-bucket entries
	 * (last ACTIVE_WINDOW_BUCKETS minutes), counts distinct visitors, then groups
	 * by their LATEST page and source. Uses the same render_list() layout as the
	 * period view, so the card's visual shape stays steady when you toggle to
	 * Live. The client picks up `data-ak-stats-live` and starts auto-refreshing.
	 *
	 * Empty-state policy: when 0 visitors are present, we still show the big "0"
	 * and a tagline — silence here is the truth ("nobody on the site right now"),
	 * not "no data ever". That keeps the Live mode useful at all hours.
	 *
	 * @return string Safe HTML.
	 */
	private static function body_live() {
		$today  = current_time( 'Y-m-d' );
		$now    = time();
		$active = AdminKit_Stats_Store::recent_activity( $now );
		$count  = count( $active );

		// No "active now" pill here — on Live the big count IS the active number
		// (matches the SPA stats page).
		$out  = '<div class="ak-card__head ak-dash__stats-head">';
		$out .= '<h2 class="ak-card__title">' . esc_html__( 'Statistics', 'adminkit' ) . '</h2>';
		$out .= self::range_picker( $today, $today, 'live' );
		$out .= '</div>';

		// `is-quiet` when nobody's on the site — the live dot stops pulsing and the
		// count goes muted, so the "nobody here" message reads clean (no animated dot
		// sitting over it).
		$out .= '<div class="ak-dash__stats-live' . ( 0 === $count ? ' is-quiet' : '' ) . '" data-ak-stats-live data-ak-stats-live-interval="' . esc_attr( (string) self::LIVE_REFRESH_MS ) . '">';

		// Headline: huge active-now count + tagline.
		$out .= '<div class="ak-dash__stats-live-headline">';
		$out .= '<span class="ak-dash__stats-live-num">' . esc_html( number_format_i18n( $count ) ) . '</span>';
		/* translators: tagline under the big realtime visitor count. */
		$out .= '<span class="ak-dash__stats-live-lbl">' . esc_html( _n( 'visitor active right now', 'visitors active right now', $count, 'adminkit' ) ) . '</span>';
		$out .= '</div>';

		if ( 0 === $count ) {
			$out .= '<p class="ak-dash__stats-live-empty">' . esc_html__( 'Nobody is on the site right now — this view refreshes automatically.', 'adminkit' ) . '</p>';
			$out .= '</div>';
			return $out . self::more_link();
		}

		// Aggregate the per-token latest seen into page + source histograms.
		$pages   = array();
		$sources = array();
		foreach ( $active as $entry ) {
			$p = isset( $entry['p'] ) ? (string) $entry['p'] : '';
			$s = isset( $entry['s'] ) ? (string) $entry['s'] : '';
			if ( '' !== $p ) {
				$pages[ $p ] = isset( $pages[ $p ] ) ? $pages[ $p ] + 1 : 1;
			}
			if ( '' !== $s ) {
				$sources[ $s ] = isset( $sources[ $s ] ) ? $sources[ $s ] + 1 : 1;
			}
		}
		arsort( $pages );
		arsort( $sources );

		$page_rows = array();
		foreach ( array_slice( $pages, 0, 5, true ) as $p => $n ) {
			$page_rows[] = array( 'name' => $p, 'pageviews' => $n );
		}
		$src_rows = array();
		foreach ( array_slice( $sources, 0, 5, true ) as $s => $n ) {
			$src_rows[] = array( 'name' => $s, 'visits' => $n );
		}

		$out .= '<div class="ak-dash__stats-cols">';
		$out .= self::render_list( __( 'Currently viewing', 'adminkit' ), $page_rows, 'pageviews', true );
		$out .= self::render_list( __( 'Coming from', 'adminkit' ), $src_rows, 'visits', false );
		$out .= '</div>';

		$out .= '</div>';
		return $out . self::more_link();
	}

	/**
	 * The "active visitors" pill. Hidden when zero so it never reads as "0 online"
	 * noise. Anonymous, cookieless; NOT logged-in accounts.
	 *
	 * @return string Safe HTML.
	 */
	private static function active_pill() {
		$n = (int) AdminKit_Stats_Store::active_visitors( time() );
		if ( $n < 1 ) {
			return '';
		}
		return sprintf(
			'<span class="ak-dash__stats-active" title="%1$s"><span class="ak-dash__stats-active-dot"></span>%2$s</span>',
			esc_attr__( 'Visitors active on the site in the last few minutes', 'adminkit' ),
			/* translators: %s: number of active visitors. */
			esc_html( sprintf( _n( '%s active now', '%s active now', $n, 'adminkit' ), number_format_i18n( $n ) ) )
		);
	}

	/**
	 * Labels for the preset buttons (translated). Centralised so the same labels
	 * are used by the dashboard card AND (via i18n) the SPA stats tab.
	 *
	 * @return array<string,string> preset id => translated label.
	 */
	public static function preset_labels() {
		return array(
			'live'   => __( 'Live', 'adminkit' ),
			'today'  => __( 'Today', 'adminkit' ),
			'7d'     => __( '7 days', 'adminkit' ),
			'30d'    => __( '30 days', 'adminkit' ),
			'90d'    => __( '90 days', 'adminkit' ),
			'ytd'    => __( 'Year to date', 'adminkit' ),
			'custom' => __( 'Custom', 'adminkit' ),
		);
	}

	/**
	 * The shared range picker — a row of preset chips and (when 'custom' is the
	 * active preset) two native date inputs. The active preset carries
	 * `is-active` + `aria-pressed=true`; the date inputs are hidden via the
	 * `hidden` attribute when a rolling preset is active, so screen readers
	 * skip them automatically.
	 *
	 * @param string $start  Y-m-d
	 * @param string $end    Y-m-d
	 * @param string $active Active preset id (or 'custom').
	 * @return string Safe HTML.
	 */
	private static function range_picker( $start, $end, $active ) {
		$today  = current_time( 'Y-m-d' );
		$labels = self::preset_labels();
		$is_custom = ( 'custom' === $active );
		$is_live   = ( 'live' === $active );
		$mode = $is_live ? 'live' : ( $is_custom ? 'custom' : 'preset' );

		$out  = '<div class="ak-dash__stats-range" role="group" aria-label="' . esc_attr__( 'Date range', 'adminkit' ) . '" data-ak-range-mode="' . esc_attr( $mode ) . '">';

		// Preset row. The 'live' chip carries a pulse dot — visible always, but
		// CSS only animates it when the chip is active so an inactive Live chip
		// stays calm.
		$out .= '<div class="ak-dash__stats-presets" role="group">';
		foreach ( self::PRESETS as $id ) {
			$on    = ( $id === $active );
			$label = esc_html( isset( $labels[ $id ] ) ? $labels[ $id ] : $id );
			$inner = ( 'live' === $id )
				? '<span class="ak-dash__stats-pulse" aria-hidden="true"></span>' . $label
				: $label;
			$out  .= sprintf(
				'<button type="button" class="ak-dash__stats-preset%1$s%2$s" data-ak-stats-preset="%3$s" aria-pressed="%4$s">%5$s</button>',
				$on ? ' is-active' : '',
				( 'live' === $id ) ? ' ak-dash__stats-preset--live' : '',
				esc_attr( $id ),
				$on ? 'true' : 'false',
				$inner
			);
		}
		$out .= '</div>';

		// Custom inputs (hidden unless 'custom' is the active preset).
		$out .= '<div class="ak-dash__stats-custom"' . ( $is_custom ? '' : ' hidden' ) . '>';
		$out .= '<input type="date" class="ak-dash__stats-date" data-ak-stats-date="start" value="' . esc_attr( $start ) . '" max="' . esc_attr( $today ) . '" aria-label="' . esc_attr__( 'Start date', 'adminkit' ) . '" />';
		$out .= '<span class="ak-dash__stats-range-sep" aria-hidden="true">→</span>';
		$out .= '<input type="date" class="ak-dash__stats-date" data-ak-stats-date="end" value="' . esc_attr( $end ) . '" max="' . esc_attr( $today ) . '" aria-label="' . esc_attr__( 'End date', 'adminkit' ) . '" />';
		$out .= '</div>';

		$out .= '</div>';
		return $out;
	}

	/**
	 * Render one labelled list column (top pages or top sources). Top pages show
	 * the page TITLE (resolved via url_to_postid) with the path as a secondary
	 * line; the row links to the live page. Sources show the referrer host (or
	 * the translated "Direct").
	 *
	 * @param string $heading
	 * @param array  $rows
	 * @param string $value_key 'pageviews' | 'visits'
	 * @param bool   $is_pages
	 * @return string Safe HTML.
	 */
	private static function render_list( $heading, $rows, $value_key, $is_pages ) {
		$out = '<div class="ak-dash__stats-col">';
		$out .= '<h3 class="ak-dash__stats-h">' . esc_html( $heading ) . '</h3>';

		$rows = array_slice( $rows, 0, 5 );
		if ( ! $rows ) {
			$out .= '<p class="ak-dash__stats-none">' . esc_html__( 'No data', 'adminkit' ) . '</p>';
			$out .= '</div>';
			return $out;
		}

		$out .= '<ul class="ak-dash__stats-list">';
		foreach ( $rows as $row ) {
			$name  = isset( $row['name'] ) ? (string) $row['name'] : '';
			$val   = isset( $row[ $value_key ] ) ? (int) $row[ $value_key ] : 0;
			$count = '<span class="ak-dash__stats-row-val">' . esc_html( number_format_i18n( $val ) ) . '</span>';

			if ( $is_pages ) {
				$title = self::page_title( $name );
				$out  .= '<li class="ak-dash__stats-row">'
					. '<a class="ak-dash__stats-row-name ak-dash__stats-row-name--page" href="' . esc_url( home_url( $name ) ) . '" title="' . esc_attr( $name ) . '">'
					. '<span class="ak-dash__stats-row-title">' . esc_html( $title ) . '</span>'
					. '<span class="ak-dash__stats-row-sub">' . esc_html( $name ) . '</span>'
					. '</a>' . $count . '</li>';
			} else {
				$label = ( 'direct' === $name ) ? __( 'Direct', 'adminkit' ) : $name;
				$out  .= '<li class="ak-dash__stats-row">'
					. '<span class="ak-dash__stats-row-name" title="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</span>'
					. $count . '</li>';
			}
		}
		$out .= '</ul>';
		$out .= '</div>';
		return $out;
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
			$title = trim( $path, '/' );
			$title = '' === $title ? $path : $title;
		}
		$cache[ $path ] = $title;
		return $title;
	}

	/**
	 * Build a minimal inline-SVG sparkline (line + soft area) from a series of
	 * integers. PHP floats → '.'-decimal everywhere, locale-independent.
	 *
	 * @param int[] $values Oldest → newest.
	 * @return string SVG markup (numeric content only — safe to echo).
	 */
	private static function sparkline( array $values ) {
		$values = array_values( $values );
		$n      = count( $values );
		if ( $n < 1 ) {
			return '';
		}

		$w  = 300.0;
		$h  = 56.0;
		$px = 2.0;
		$py = 6.0;

		$max = 1;
		foreach ( $values as $v ) {
			$max = max( $max, (int) $v );
		}

		$inner_w = $w - 2 * $px;
		$inner_h = $h - 2 * $py;
		$base    = round( $h - $py, 1 );

		$pts = array();
		foreach ( $values as $i => $v ) {
			$x     = ( 1 === $n ) ? ( $px + $inner_w / 2 ) : ( $px + $i * $inner_w / ( $n - 1 ) );
			$y     = $py + $inner_h * ( 1 - ( (int) $v ) / $max );
			$pts[] = array( round( $x, 1 ), round( $y, 1 ) );
		}

		$line = '';
		foreach ( $pts as $i => $p ) {
			$line .= ( 0 === $i ? 'M' : 'L' ) . $p[0] . ' ' . $p[1] . ' ';
		}

		$last = $pts[ $n - 1 ];
		$area = 'M' . $pts[0][0] . ' ' . $base . ' ';
		foreach ( $pts as $p ) {
			$area .= 'L' . $p[0] . ' ' . $p[1] . ' ';
		}
		$area .= 'L' . $last[0] . ' ' . $base . ' Z';

		return '<svg class="ak-spark" viewBox="0 0 300 56" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
			. '<path class="ak-spark__area" d="' . esc_attr( trim( $area ) ) . '"/>'
			. '<path class="ak-spark__line" d="' . esc_attr( trim( $line ) ) . '"/>'
			. '</svg>';
	}
}
