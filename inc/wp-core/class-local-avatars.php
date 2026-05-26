<?php
/**
 * AdminKit portraits — registers a native WordPress default-avatar option.
 *
 * What it does, end to end:
 *   1. Adds **AdminKit Portraits (Generated)** to *Settings → Discussion →
 *      Default Avatar* via the core `avatar_defaults` filter. Lives next to
 *      Wavatar / Identicon / Retro / MonsterID — the dropdown every WordPress
 *      user knows.
 *   2. When the avatar pipeline is asked for our option (and ONLY ours — we
 *      never override other choices), serves a unique DiceBear portrait keyed
 *      by `md5( user_login )`. Pastel-gradient backdrop so every user reads as
 *      a visibly distinct card.
 *
 * Critical detail: we set `$args['url']` directly to short-circuit Gravatar.
 * Setting `$args['default']` (the `d=` fallback URL) does NOT work — Gravatar
 * proxies the redirect through `i2.wp.com` (Photon), which **strips every
 * query parameter**, including our per-user `seed=`. The result is that every
 * user lands on DiceBear's default image. Bypassing Gravatar by populating
 * `url` ourselves preserves the seed and gives each user a unique portrait.
 *
 * Semantic: picking AdminKit Portraits means "give every user a generated
 * portrait, including users who have a real Gravatar." If you want real
 * Gravatars + a generated fallback, pick Wavatar / Identicon / etc. — those
 * are Gravatar-side generators that honour real Gravatars natively.
 *
 * Gated by `custom_avatars_enabled` (default ON). Off = AdminKit is invisible
 * to the avatar pipeline.
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

	/** DiceBear style. Notion-style portraits — modern, varied, professional. */
	const STYLE = 'notionists';

	/** Pastel palette DiceBear draws gradient backdrops from (deterministic per seed). */
	const BACKGROUND = 'b6e3f4,c0aede,d1d4f9,ffd5dc,ffdfbf,cbd5e8,f4cae4,e6f5c9,fff2ae,fdcdac';

	public static function init() {
		if ( ! AdminKit_Settings::get( 'custom_avatars_enabled' ) ) {
			return;
		}
		add_filter( 'avatar_defaults', array( __CLASS__, 'register_default' ) );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );
	}

	public static function register_default( $defaults ) {
		$defaults[ self::AVATAR_KEY ] = __( 'AdminKit Portraits (Generated)', 'adminkit' );
		return $defaults;
	}

	/**
	 * Replace the avatar URL with our DiceBear portrait when THIS call's default
	 * is our option. Setting `$args['url']` directly tells WP "use this URL,
	 * don't go through Gravatar" — necessary because Gravatar proxies the d=
	 * fallback through Photon, which strips query strings (and so our seed).
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
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
