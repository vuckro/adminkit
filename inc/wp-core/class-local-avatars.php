<?php
/**
 * AdminKit portraits — registers a native WordPress default-avatar option.
 *
 * Flow:
 *   1. Adds **AdminKit Portraits (Generated)** to *Settings → Discussion →
 *      Default Avatar* via the core `avatar_defaults` filter. Sits next to
 *      Wavatar / Identicon / Retro / MonsterID, the dropdown every WordPress
 *      user knows.
 *   2. When the avatar pipeline asks for our option AND the user has no real
 *      photo from any source, serves a unique DiceBear portrait keyed by
 *      `md5( user_login )`, on a solid-pastel backdrop picked deterministically
 *      from a 10-colour palette so every user reads as a distinct card.
 *
 * "No real photo from any source" is checked in this order:
 *   a. `$args['url']` already populated → another filter handled it (Simple
 *      Local Avatars, WP User Avatar, an OAuth login plugin saving a remote
 *      URL, …). We bail.
 *   b. The user has a real Gravatar (HEAD `gravatar.com/avatar/HASH?d=404`
 *      returns 200). Result cached in `adminkit_has_gravatar` user meta —
 *      invalidated on `profile_update` so an email change re-checks. We bail.
 *   c. Neither → we set `$args['url']` directly to our DiceBear URL.
 *
 * Setting `$args['url']` (not `$args['default']`) is deliberate: Gravatar
 * proxies the `d=` fallback through Photon (`i2.wp.com`), which strips every
 * query string — including our per-user `seed=`. Bypassing Gravatar by
 * populating `url` ourselves preserves the seed.
 *
 * Gated by `custom_avatars_enabled` (default ON). Off = AdminKit invisible to
 * the avatar pipeline.
 *
 * External service: DiceBear (`api.dicebear.com`). Disclosed in `readme.txt`.
 * Seed is NON-PII (md5 of the login, never the raw email).
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Local_Avatars {

	/** Slug stored by WP in the `avatar_default` option to pick our option. */
	const AVATAR_KEY = 'adminkit_portraits';

	/** DiceBear style. avataaars — cartoon Memoji-style portraits, very varied per seed. */
	const STYLE = 'avataaars';

	/**
	 * Pastel palette DiceBear picks ONE colour from (deterministic per seed) for the
	 * solid backdrop. Hex without `#` — DiceBear's URL format.
	 */
	const BACKGROUND = 'b6e3f4,c0aede,d1d4f9,ffd5dc,ffdfbf,cbd5e8,f4cae4,e6f5c9,fff2ae,fdcdac';

	/** User-meta key caching whether the user has a real Gravatar (`1` | `0`). */
	const GRAVATAR_META = 'adminkit_has_gravatar';

	public static function init() {
		if ( ! AdminKit_Settings::get( 'custom_avatars_enabled' ) ) {
			return;
		}
		add_filter( 'avatar_defaults', array( __CLASS__, 'register_default' ) );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );
		// Email or login change → drop the cached Gravatar check so it re-runs next render.
		add_action( 'profile_update', array( __CLASS__, 'invalidate_cache' ) );
	}

	public static function register_default( $defaults ) {
		$defaults[ self::AVATAR_KEY ] = __( 'AdminKit Portraits (Generated)', 'adminkit' );
		return $defaults;
	}

	public static function filter_avatar_data( $args, $id_or_email ) {
		// (a) Another filter already supplied a real URL — never override.
		if ( ! empty( $args['url'] ) ) {
			return $args;
		}

		$requested = isset( $args['default'] ) ? (string) $args['default'] : '';
		if ( self::AVATAR_KEY !== $requested ) {
			return $args;
		}

		$user_id = self::resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $args;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $args;
		}

		// (b) Real Gravatar wins UNLESS this is a forced-default render. The
		//     Discussion settings page sets `force_default = true` to show what
		//     each option looks like, even for users with a real Gravatar — we
		//     need to honour that so the AdminKit Portraits preview renders.
		if ( empty( $args['force_default'] ) && self::has_real_gravatar( $user ) ) {
			return $args;
		}

		// (c) Generated portrait. Setting $args['url'] bypasses Gravatar entirely so
		//     the seed survives (Gravatar's Photon proxy would otherwise strip it).
		//     `backgroundType=gradientLinear` + the pastel palette gives a soft
		//     two-tone backdrop (DiceBear picks two colours deterministically per
		//     seed) — subtle enough to read in both light and dark wp-admin themes.
		$seed = md5( strtolower( $user->user_login ) );
		$size = isset( $args['size'] ) ? max( 1, min( 256, (int) $args['size'] ) ) : 96;

		$args['url'] = esc_url_raw(
			'https://api.dicebear.com/9.x/' . self::STYLE . '/png'
			. '?seed=' . rawurlencode( $seed )
			. '&size=' . $size
			. '&backgroundColor=' . self::BACKGROUND
			. '&backgroundType=gradientLinear'
		);
		$args['found_avatar'] = true;
		return $args;
	}

	/**
	 * Does this user have a real Gravatar? Cached forever in user meta (`1` or `0`);
	 * `profile_update` invalidates it so an email change re-checks on the next render.
	 *
	 * Cache miss → one HEAD to `gravatar.com/avatar/HASH?d=404` with a 2-second
	 * timeout. `d=404` makes Gravatar return 404 when no real avatar exists, 200
	 * when one does. A timeout / network error is treated as "no" (we serve our
	 * portrait) so a flaky Gravatar can't block the page.
	 */
	private static function has_real_gravatar( $user ) {
		$cached = (string) get_user_meta( $user->ID, self::GRAVATAR_META, true );
		if ( '1' === $cached ) {
			return true;
		}
		if ( '0' === $cached ) {
			return false;
		}

		$email = trim( $user->user_email );
		if ( '' === $email || ! is_email( $email ) ) {
			update_user_meta( $user->ID, self::GRAVATAR_META, '0' );
			return false;
		}

		$hash     = hash( 'sha256', strtolower( $email ) );
		$response = wp_remote_head(
			"https://www.gravatar.com/avatar/{$hash}?d=404",
			array(
				'timeout'     => 2,
				'redirection' => 0,
			)
		);

		$has = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
		update_user_meta( $user->ID, self::GRAVATAR_META, $has ? '1' : '0' );
		return $has;
	}

	public static function invalidate_cache( $user_id ) {
		delete_user_meta( (int) $user_id, self::GRAVATAR_META );
	}

	private static function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return absint( $id_or_email );
		}
		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof WP_Comment ) {
			return empty( $id_or_email->user_id ) ? 0 : (int) $id_or_email->user_id;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}
		return 0;
	}
}
