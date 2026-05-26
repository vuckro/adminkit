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
	 * look that still feels diverse across a users list: positive-or-neutral
	 * expression, the FULL natural skin-tone range (pale through to dark), natural
	 * hair colours, and only realistic accessories (no fantasy variants like
	 * eyepatch / heart eyes / pink hair).
	 *
	 * MOUTH, EYES and ACCESSORIES take DiceBear's named variants. SKIN_COLOR and
	 * HAIR_COLOR take raw hex values — DiceBear's API rejects the preset names
	 * for these (regex `^(transparent|[a-fA-F0-9]{6})$`). The hexes match the
	 * original avataaars project's named presets:
	 *   - skin: `ffdbb4` (pale), `edb98a` (light), `d08b5b` (brown),
	 *           `ae5d29` (dark brown), `614335` (black) — full diversity.
	 *   - hair: `2c1b18` (black), `b58143` (blonde), `d6b370` (blonde golden),
	 *           `724133` (brown), `4a312c` (dark brown).
	 *
	 * ACCESSORIES_PROBABILITY: percentage chance a user gets glasses on this seed.
	 * DiceBear default is 10; we bump to 30 so the accessory variety actually
	 * shows up in a list of a handful of users without being on every face.
	 */
	const MOUTH                   = 'smile,default,twinkle,serious';
	const EYES                    = 'default,happy';
	const SKIN_COLOR              = 'ffdbb4,edb98a,d08b5b,ae5d29,614335';
	const HAIR_COLOR              = '2c1b18,b58143,d6b370,724133,4a312c';
	const ACCESSORIES             = 'prescription01,prescription02,round,sunglasses,wayfarers';
	const ACCESSORIES_PROBABILITY = 30;

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
		// Wire the safety net ALWAYS (independent of the toggle), symmetrically:
		//   - disable / deactivate → reset `avatar_default` to Mystery Person
		//     if it was AdminKit's key (otherwise WP keeps passing
		//     `?d=adminkit_portraits` to Gravatar and breaks the rendering).
		//   - enable / activate     → upgrade `avatar_default` from Mystery
		//     Person to AdminKit Portraits if Custom avatars is on, so the
		//     feature is actually visible without a manual trip to Discussion.
		// Together these make the off / on cycle round-trip cleanly.
		add_action( 'update_option_' . AdminKit_Settings::OPTION_KEY, array( __CLASS__, 'on_settings_update' ), 10, 2 );
		register_deactivation_hook( ADMINKIT_FILE, array( __CLASS__, 'cleanup_avatar_default' ) );
		register_activation_hook( ADMINKIT_FILE, array( __CLASS__, 'restore_avatar_default' ) );

		if ( ! AdminKit_Settings::get( 'custom_avatars_enabled' ) ) {
			return;
		}
		add_filter( 'avatar_defaults', array( __CLASS__, 'register_default' ) );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );
		// Email or login change → drop the cached Gravatar check so it re-runs next render.
		add_action( 'profile_update', array( __CLASS__, 'invalidate_cache' ) );

		// "Refresh" affordances — a per-user button on profile.php /
		// user-edit.php, and a bulk action in the users-list dropdown for
		// refreshing many users at once. The users.php per-row action was
		// dropped to keep the hover menu uncluttered; bulk + profile cover
		// the same use case without the visual noise.
		add_action( 'admin_init', array( __CLASS__, 'maybe_shuffle' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_filter( 'bulk_actions-users', array( __CLASS__, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_bulk_notice' ) );
	}

	/**
	 * When the AdminKit settings option is updated, mirror the Custom avatars
	 * toggle into the WordPress `avatar_default` option:
	 *   - on → off  : reset `adminkit_portraits` to `mystery` (otherwise WP
	 *                 keeps passing `?d=adminkit_portraits` to Gravatar after
	 *                 we stopped handling it).
	 *   - off → on  : upgrade `mystery` to `adminkit_portraits` so the feature
	 *                 visibly comes back on without a manual trip to
	 *                 Settings → Discussion.
	 * No transition = no action; the WP default isn't touched.
	 */
	public static function on_settings_update( $old, $new ) {
		$was_on = ! empty( $old['custom_avatars_enabled'] );
		$now_on = ! empty( $new['custom_avatars_enabled'] );
		if ( $was_on && ! $now_on ) {
			self::cleanup_avatar_default();
		} elseif ( ! $was_on && $now_on ) {
			self::restore_avatar_default();
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

	/**
	 * Upgrade the WordPress `avatar_default` option to AdminKit Portraits when
	 * Custom avatars is on AND the current default is Mystery Person (or its
	 * `mm` / `mp` aliases — the factory defaults). Any other explicit choice
	 * (Wavatar / Identicon / Retro / MonsterID / Blank) is left untouched.
	 *
	 * Called on plugin activation and on the off → on settings transition.
	 * Idempotent; safe to call repeatedly.
	 */
	public static function restore_avatar_default() {
		if ( ! AdminKit_Settings::get( 'custom_avatars_enabled' ) ) {
			return;
		}
		$current = get_option( 'avatar_default', 'mystery' );
		if ( in_array( $current, array( 'mystery', 'mm', 'mp' ), true ) ) {
			update_option( 'avatar_default', self::AVATAR_KEY );
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
			. '&accessories=' . self::ACCESSORIES
			. '&accessoriesProbability=' . self::ACCESSORIES_PROBABILITY
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
		// When the click came from profile.php / user-edit.php, anchor the
		// redirect to our section so the page lands on the Profile picture row
		// instead of scrolling back to the top. The anchor is harmless elsewhere
		// (e.g. users.php where no matching id exists — browser just doesn't scroll).
		$basename = basename( (string) wp_parse_url( $referer, PHP_URL_PATH ) );
		if ( in_array( $basename, array( 'profile.php', 'user-edit.php' ), true ) ) {
			$referer .= '#adminkit-profile-picture';
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
		<h2 id="adminkit-profile-picture"><?php esc_html_e( 'Profile picture', 'adminkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Avatar', 'adminkit' ); ?></th>
				<td>
					<div style="border-radius:50%;overflow:hidden;width:96px;height:96px;line-height:0;margin-bottom:1em">
						<?php echo get_avatar( $user->ID, 96 ); ?>
					</div>
					<a href="<?php echo esc_url( $shuffle_url ); ?>" class="button"><?php esc_html_e( 'Refresh', 'adminkit' ); ?></a>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Add a "Refresh avatar" entry to the users-list Bulk actions dropdown so
	 * admins can re-roll seeds for many users at once. Only registered when
	 * AdminKit Portraits is the active default (the action wouldn't change
	 * anything visible otherwise).
	 */
	public static function add_bulk_action( $actions ) {
		if ( self::AVATAR_KEY !== get_option( 'avatar_default' ) ) {
			return $actions;
		}
		$actions['adminkit_refresh'] = __( 'Refresh avatar', 'adminkit' );
		return $actions;
	}

	/**
	 * Handle the bulk Refresh action — roll a fresh seed for each selected user
	 * the current admin may edit, then redirect with a count param so the
	 * admin notice can confirm how many were refreshed.
	 */
	public static function handle_bulk_action( $sendback, $action, $user_ids ) {
		if ( 'adminkit_refresh' !== $action ) {
			return $sendback;
		}
		$count = 0;
		foreach ( (array) $user_ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid > 0 && current_user_can( 'edit_user', $uid ) ) {
				update_user_meta( $uid, self::SEED_META, md5( wp_generate_uuid4() ) );
				++$count;
			}
		}
		return add_query_arg( 'adminkit_refreshed', $count, $sendback );
	}

	/**
	 * After a bulk Refresh, render a success notice on the next users.php load.
	 */
	public static function maybe_show_bulk_notice() {
		if ( ! isset( $_GET['adminkit_refreshed'] ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'users' !== $screen->id ) {
			return;
		}
		$count = (int) $_GET['adminkit_refreshed'];
		if ( $count <= 0 ) {
			return;
		}
		$msg = sprintf(
			/* translators: %d: number of users whose avatars were refreshed. */
			_n( '%d avatar refreshed.', '%d avatars refreshed.', $count, 'adminkit' ),
			$count
		);
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $msg )
		);
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
