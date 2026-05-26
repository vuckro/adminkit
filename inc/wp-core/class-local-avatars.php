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

	/**
	 * Avataaars feature filters — narrow the per-seed roll to a clean, standardised
	 * European look: smiling or neutral expression, natural-but-not-fantasy hair
	 * colour, light/pale skin. Keeps things consistent across a users list while
	 * preserving the per-seed variation that comes from hair style, facial hair,
	 * accessories and clothing. Defaults exclude weird/fantasy variants entirely
	 * (no hearts eyes, no big "surprised" eyes, no red/pink hair, no eyepatch …).
	 *
	 * MOUTH and EYES take DiceBear's named variants. SKIN_COLOR and HAIR_COLOR
	 * take raw hex values — DiceBear's API rejects the preset names for these
	 * (the regex is `^(transparent|[a-fA-F0-9]{6})$`). The hexes below match the
	 * original avataaars project's named presets:
	 *   - skin: `edb98a` (light caucasian), `ffdbb4` (pale)
	 *   - hair: `2c1b18` (black), `b58143` (blonde), `d6b370` (blonde golden),
	 *           `724133` (brown), `4a312c` (dark brown)
	 */
	const MOUTH      = 'smile,default,twinkle';
	const EYES       = 'default,happy';
	const SKIN_COLOR = 'edb98a,ffdbb4';
	const HAIR_COLOR = '2c1b18,b58143,d6b370,724133,4a312c';

	/** User-meta key caching whether the user has a real Gravatar (`1` | `0`). */
	const GRAVATAR_META = 'adminkit_has_gravatar';

	/**
	 * User-meta key holding a rolled-by-the-user seed. When present it wins over
	 * the deterministic md5(login). Cleared by an admin-side delete_user_meta if
	 * we ever want to restore the default — kept lean for now (forward shuffle only).
	 */
	const SEED_META = 'adminkit_avatar_seed';

	/** Nonce action used by the shuffle link (profile button + users-list row action). */
	const SHUFFLE_NONCE = 'adminkit_shuffle_avatar';

	public static function init() {
		// Wire the safety net ALWAYS (independent of the toggle): if AdminKit
		// Portraits is the stored WP default and the feature gets disabled
		// (toggle off, or plugin deactivated), reset to Mystery Person so users
		// don't end up with a broken `?d=adminkit_portraits` flying to Gravatar.
		add_action( 'update_option_' . AdminKit_Settings::OPTION_KEY, array( __CLASS__, 'on_settings_update' ), 10, 2 );
		register_deactivation_hook( ADMINKIT_FILE, array( __CLASS__, 'cleanup_avatar_default' ) );

		if ( ! AdminKit_Settings::get( 'custom_avatars_enabled' ) ) {
			return;
		}
		add_filter( 'avatar_defaults', array( __CLASS__, 'register_default' ) );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );
		// Email or login change → drop the cached Gravatar check so it re-runs next render.
		add_action( 'profile_update', array( __CLASS__, 'invalidate_cache' ) );

		// "Don't like my portrait?" — Shuffle affordance on the profile page + as
		// a user-row action on users.php. A plain GET link with a nonce; the
		// `admin_init` handler rolls a new seed and redirects to the clean URL
		// so a browser refresh doesn't keep shuffling.
		add_action( 'admin_init', array( __CLASS__, 'maybe_shuffle' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
	}

	/**
	 * When the AdminKit settings option is updated, detect a custom_avatars
	 * on → off transition and clean up the stored WordPress avatar_default if
	 * it was pointing at our key. Otherwise WP would keep passing
	 * `?d=adminkit_portraits` to Gravatar after we stopped handling it.
	 */
	public static function on_settings_update( $old, $new ) {
		$was_on = ! empty( $old['custom_avatars_enabled'] );
		$now_on = ! empty( $new['custom_avatars_enabled'] );
		if ( $was_on && ! $now_on ) {
			self::cleanup_avatar_default();
		}
	}

	/**
	 * Reset the WordPress `avatar_default` option to Mystery Person if (and only
	 * if) it currently holds our key. Idempotent — safe to call from any "AdminKit
	 * is going inactive" path (toggle off, plugin deactivation).
	 */
	public static function cleanup_avatar_default() {
		if ( self::AVATAR_KEY === get_option( 'avatar_default' ) ) {
			update_option( 'avatar_default', 'mystery' );
		}
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
		//     Solid (not gradient) background — DiceBear picks ONE colour per seed
		//     from the pastel palette. Solid gives a stronger silhouette against
		//     the cartoon outlines than a two-tone gradient ever could.
		$seed = self::get_seed( $user );
		$size = isset( $args['size'] ) ? max( 1, min( 256, (int) $args['size'] ) ) : 96;

		$args['url'] = esc_url_raw(
			'https://api.dicebear.com/9.x/' . self::STYLE . '/png'
			. '?seed=' . rawurlencode( $seed )
			. '&size=' . $size
			. '&backgroundColor=' . self::BACKGROUND
			. '&mouth=' . self::MOUTH
			. '&eyes=' . self::EYES
			. '&skinColor=' . self::SKIN_COLOR
			. '&hairColor=' . self::HAIR_COLOR
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

	/**
	 * Resolve the DiceBear seed for a user. A user-rolled seed (stored by the
	 * Shuffle action) wins over the deterministic `md5( user_login )` default,
	 * so an admin or the user themselves can cycle to a different portrait when
	 * the assigned one doesn't fit.
	 */
	private static function get_seed( $user ) {
		$rolled = (string) get_user_meta( $user->ID, self::SEED_META, true );
		if ( '' !== $rolled ) {
			return $rolled;
		}
		return md5( strtolower( $user->user_login ) );
	}

	/**
	 * Handle a Shuffle GET request — roll a new seed for the target user, then
	 * redirect back to the page the click came from.
	 *
	 * We redirect to `wp_get_referer()` (NOT the current URL minus our params).
	 * Why: when the click was on user-edit.php?user_id=X, the current URL also
	 * carries that `user_id=X`, and stripping it (it overlaps with our own
	 * `user_id` shuffle param) would land WP on user-edit.php without an id →
	 * "Invalid user ID". The referer is whichever page held the link
	 * (user-edit.php?user_id=X, users.php, profile.php) and is the right place
	 * to come back to.
	 *
	 * Hooked on `admin_init`. No-op for any request that doesn't carry our
	 * `?adminkit_shuffle=1` flag.
	 */
	public static function maybe_shuffle() {
		if ( empty( $_GET['adminkit_shuffle'] ) ) {
			return;
		}
		$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'adminkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SHUFFLE_NONCE );

		update_user_meta( $user_id, self::SEED_META, md5( wp_generate_uuid4() ) );

		$referer = wp_get_referer();
		if ( $referer ) {
			// Defensively strip our params in case they got into the referer too.
			$referer = remove_query_arg( array( 'adminkit_shuffle', '_wpnonce' ), $referer );
		} else {
			$referer = admin_url( 'profile.php' );
		}
		wp_safe_redirect( $referer );
		exit;
	}

	/**
	 * Show a "Profile picture" section on profile.php / user-edit.php — current
	 * portrait + Shuffle link. Hidden when:
	 *   - The viewer lacks edit_user capability for this target.
	 *   - AdminKit Portraits isn't the active WP default (our DiceBear isn't
	 *     even rendering for this user).
	 *   - The user has a real Gravatar (Shuffle wouldn't visibly change anything).
	 */
	public static function render_profile_section( $user ) {
		if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		if ( self::AVATAR_KEY !== get_option( 'avatar_default' ) ) {
			return;
		}
		if ( self::has_real_gravatar( $user ) ) {
			return;
		}

		$shuffle_url = wp_nonce_url(
			add_query_arg(
				array(
					'adminkit_shuffle' => '1',
					'user_id'          => $user->ID,
				)
			),
			self::SHUFFLE_NONCE
		);
		?>
		<h2><?php esc_html_e( 'Profile picture', 'adminkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Generated portrait', 'adminkit' ); ?></th>
				<td>
					<span style="display:inline-block;border-radius:50%;overflow:hidden;line-height:0;vertical-align:middle">
						<?php echo get_avatar( $user->ID, 96 ); ?>
					</span>
					<p style="margin-top:1em">
						<a href="<?php echo esc_url( $shuffle_url ); ?>" class="button"><?php esc_html_e( 'Shuffle', 'adminkit' ); ?></a>
						<span class="description" style="margin-left:8px"><?php esc_html_e( "Don't like this portrait? Click to roll a new one.", 'adminkit' ); ?></span>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Add a "Shuffle avatar" row action on users.php so admins can re-roll any
	 * user's portrait without opening their profile. Same visibility rules as
	 * the profile section.
	 */
	public static function add_row_action( $actions, $user ) {
		if ( self::AVATAR_KEY !== get_option( 'avatar_default' ) ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return $actions;
		}
		if ( self::has_real_gravatar( $user ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'adminkit_shuffle' => '1',
					'user_id'          => $user->ID,
				)
			),
			self::SHUFFLE_NONCE
		);
		$actions['adminkit_shuffle'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Shuffle avatar', 'adminkit' ) . '</a>';
		return $actions;
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
