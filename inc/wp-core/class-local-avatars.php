<?php
/**
 * AdminKit portraits — a unique generated avatar per user, woven into WordPress's
 * native avatar system the same way Wavatar / Identicon / Retro / MonsterID are.
 *
 * What it does, in one paragraph: AdminKit registers "AdminKit Portraits
 * (Generated)" as an option in the WordPress **Settings → Discussion → Default
 * Avatar** dropdown (via the core `avatar_defaults` filter), and intercepts
 * `pre_get_avatar_data` to substitute a unique DiceBear portrait URL for any
 * user who has no real Gravatar. The portrait is built from `md5(user_login)`
 * (NON-PII — never the raw email) plus a pastel gradient drawn from a fixed
 * palette, so every user reads as a visibly distinct card in the users list.
 *
 * Default behaviour without any clicks: when `custom_avatars_enabled` is on
 * (default) AND WordPress's stored default is `mystery` / `mm` / `mp` (the
 * factory default that nobody changes), AdminKit silently substitutes its
 * portraits. Pick any other option in Settings → Discussion (Wavatar, Retro,
 * Identicon…) and AdminKit steps aside — the user's explicit choice wins.
 *
 * Why this shape:
 *   - No upload UI, no profile field, no Media Library plumbing. AdminKit owns
 *     the *generated fallback* role only; user-supplied pictures stay where
 *     WordPress puts them — Gravatar (and any plugin that already provides
 *     "local avatar upload"). Single-responsibility.
 *   - Native integration through `avatar_defaults` means there is no second
 *     settings UI to learn — the option lives where every WordPress user knows
 *     to look. The AdminKit toggle is just the master switch for the whole
 *     integration.
 *   - The collision the WP "Mystery Person" default would otherwise produce
 *     (all users showing the same icon) is the exact case we silently fix; any
 *     explicit choice the user made is left alone.
 *
 * Filterable extension points:
 *   - `adminkit/generated_avatar_style` — DiceBear style slug (default `avataaars`).
 *   - `adminkit/generated_avatar_url`   — final URL (self-host or swap service).
 *
 * External service: DiceBear's hosted, key-less HTTP API
 * (`https://api.dicebear.com`). Disclosed in `readme.txt`. Stops being called as
 * soon as `custom_avatars_enabled` is off, or the WP default is anything
 * other than Mystery Person / AdminKit Portraits.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Local_Avatars {

	/**
	 * The slug WordPress stores in `avatar_default` to pick AdminKit's portraits.
	 * Appears in the Settings → Discussion dropdown next to `wavatar`, `identicon`, …
	 */
	const AVATAR_KEY = 'adminkit_portraits';

	/** DiceBear hosted API base + version. */
	const DICEBEAR_BASE = 'https://api.dicebear.com/9.x/';

	/**
	 * The WP `avatar_default` values that mean "I haven't picked a specific
	 * generator" — Mystery Person (in its old + new spellings) and the AdminKit
	 * key itself. Any other value (wavatar, identicon, retro, monsterid, blank,
	 * gravatar_default) is an explicit choice we never override.
	 *
	 * @var string[]
	 */
	const INTERCEPT_DEFAULTS = array( self::AVATAR_KEY, 'mystery', 'mm', 'mp' );

	/**
	 * Pastel palette DiceBear picks from (deterministically, by seed) to back
	 * each portrait with a gradient. The backdrop is what makes a fresh users.php
	 * list scan as "obviously different people" even before the face reads.
	 * Hex values without `#` — DiceBear's URL format.
	 *
	 * @var string[]
	 */
	const BACKGROUND_PALETTE = array(
		'b6e3f4', // sky
		'c0aede', // lavender
		'd1d4f9', // periwinkle
		'ffd5dc', // pink
		'ffdfbf', // peach
		'cbd5e8', // dusty blue
		'f4cae4', // rose
		'e6f5c9', // mint
		'fff2ae', // butter
		'fdcdac', // apricot
	);

	/**
	 * Wire the two filters — only when `custom_avatars_enabled` is on. With the
	 * toggle off, AdminKit adds neither a dropdown entry nor a portrait
	 * substitution; WordPress avatars behave 100% as if AdminKit weren't here.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! AdminKit_Settings::get( 'custom_avatars_enabled' ) ) {
			return;
		}

		// Add "AdminKit Portraits (Generated)" to Settings → Discussion → Default Avatar.
		add_filter( 'avatar_defaults', array( __CLASS__, 'register_avatar_default' ) );

		// Substitute our URL into $args['default'] so WP passes it to Gravatar as
		// the `d=` fallback. Gravatar serves the real avatar when one exists, and
		// redirects to our URL otherwise.
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );
	}

	/**
	 * Register the AdminKit option in WordPress's `Settings → Discussion → Default
	 * Avatar` dropdown. Keyed by AVATAR_KEY (what WordPress will store), label is
	 * the human-readable name shown next to "Wavatar (Generated)" etc.
	 *
	 * @param array<string, string> $defaults Map of stored slug → display name.
	 * @return array<string, string>
	 */
	public static function register_avatar_default( $defaults ) {
		$defaults[ self::AVATAR_KEY ] = __( 'AdminKit Portraits (Generated)', 'adminkit' );
		return $defaults;
	}

	/**
	 * Feed WordPress's avatar pipeline. Sets `$args['default']` to the AdminKit
	 * portrait URL whenever the WP-stored default is one we own (`adminkit_portraits`,
	 * or the boring `mystery` / `mm` / `mp`). Otherwise returns $args UNCHANGED
	 * so an explicit Wavatar / Identicon / Retro / MonsterID choice is honoured.
	 *
	 * `force_default` (the "show the chosen default, even for users with a real
	 * Gravatar" path) is honoured by passing through — our portrait still rides
	 * on `$args['default']`, so a forced default still becomes our portrait when
	 * the WP option is one we intercept.
	 *
	 * @param array $args        get_avatar_data() args (size, default, …).
	 * @param mixed $id_or_email int id | email | WP_User | WP_Post | WP_Comment.
	 * @return array
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
		$opt = (string) get_option( 'avatar_default', 'mystery' );
		if ( ! in_array( $opt, self::INTERCEPT_DEFAULTS, true ) ) {
			return $args;
		}

		$user_id = self::resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $args;
		}

		$size = isset( $args['size'] ) ? max( 1, (int) $args['size'] ) : 96;
		$args['default'] = self::get_generated_avatar_url( $user_id, $size );
		return $args;
	}

	/**
	 * Build a DiceBear portrait URL for a user. Each URL carries the seed
	 * (deterministic per user — see get_generated_seed()) plus a pastel
	 * `backgroundColor` palette and `backgroundType=gradientLinear` so every
	 * user gets a distinct gradient backdrop.
	 *
	 * Privacy: seeded with a NON-PII value (md5 of the user_login, NEVER the
	 * raw email).
	 *
	 * Filter `adminkit/generated_avatar_url` to self-host or swap the service.
	 *
	 * @param int $user_id
	 * @param int $size    Requested square size in px (DiceBear PNG caps at 256).
	 * @return string
	 */
	public static function get_generated_avatar_url( $user_id, $size = 96 ) {
		$user_id = (int) $user_id;
		$size    = max( 1, min( 256, (int) $size ) );

		$seed  = self::get_generated_seed( $user_id );
		$style = self::generated_avatar_style( $user_id );

		// Manual concatenation — `add_query_arg` URL-encodes commas, and DiceBear's
		// `backgroundColor` expects them raw to read a multi-value palette.
		$url = self::DICEBEAR_BASE . $style . '/png'
			. '?seed=' . rawurlencode( $seed )
			. '&size=' . (int) $size
			. '&backgroundColor=' . implode( ',', self::BACKGROUND_PALETTE )
			. '&backgroundType=gradientLinear';

		/**
		 * Filter the final generated-avatar URL (e.g. to self-host or swap service).
		 *
		 * @param string $url     The DiceBear PNG URL.
		 * @param int    $user_id The user the avatar is for.
		 * @param int    $size    Requested square size in px.
		 */
		$url = apply_filters( 'adminkit/generated_avatar_url', $url, $user_id, $size );

		return esc_url_raw( (string) $url );
	}

	/**
	 * The seed used to generate a user's portrait. md5 of the user_login (NON-PII,
	 * never the raw email) so each user reliably gets the same unique portrait
	 * and any two users get visibly distinct ones.
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function get_generated_seed( $user_id ) {
		$user_id = (int) $user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		return $user ? md5( strtolower( $user->user_login ) ) : (string) $user_id;
	}

	/**
	 * Resolve + sanitise the DiceBear style slug.
	 *
	 * Default: `avataaars` — varied cartoon human portraits with explicit skin
	 * tones, hair styles, accessories. The most visually-distinct DiceBear style
	 * out of the box. Override via the filter to pick another (personas,
	 * notionists, lorelei, micah, open-peeps, fun-emoji…).
	 *
	 * @param int $user_id The user the avatar is for.
	 * @return string A slug matching [a-z0-9-]+, never empty.
	 */
	public static function generated_avatar_style( $user_id ) {
		/**
		 * Filter the DiceBear style used for AdminKit portraits.
		 *
		 * @param string $style   Default 'avataaars'.
		 * @param int    $user_id The user the avatar is for.
		 */
		$style = apply_filters( 'adminkit/generated_avatar_style', 'avataaars', (int) $user_id );
		$style = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $style ) );
		return '' === $style ? 'avataaars' : $style;
	}

	/**
	 * Resolve a WordPress user id from any of the identifiers WP passes to
	 * `pre_get_avatar_data`. Returns 0 when no user can be determined (e.g. a
	 * comment left by a logged-out visitor, or an unknown email) so the caller
	 * bails cleanly to Gravatar.
	 *
	 * @param mixed $id_or_email int id | email string | WP_User | WP_Post | WP_Comment.
	 * @return int
	 */
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
