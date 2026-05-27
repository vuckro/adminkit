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

	/** Nonce action shared by every AdminKit avatar-refresh affordance:
	 *  the page-title button on profile.php / user-edit.php, the inline
	 *  Quick Edit refresh on users.php, and the users-list bulk action. All
	 *  three POST to `wp_ajax_adminkit_shuffle_avatar` with this nonce. */
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

		// "Refresh" affordances — a single AJAX endpoint used by BOTH the
		// page-title button on profile.php / user-edit.php (data passed through
		// the profile script's localized object so the click handler does the
		// POST) AND the users.php Quick Edit inline button. Plus a bulk action
		// in the users-list dropdown for re-rolling many users at once.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_add_refresh_data' ) );
		add_filter( 'bulk_actions-users', array( __CLASS__, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_bulk_notice' ) );
		add_action( 'wp_ajax_adminkit_shuffle_avatar', array( __CLASS__, 'handle_shuffle_ajax' ) );
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
	 * Roll a new seed for the target user and return the fresh avatar URL as
	 * JSON. The page-title "Refresh avatar" button on profile.php /
	 * user-edit.php AND the users.php Quick Edit refresh button both call
	 * this endpoint — one server-side path, two UI surfaces.
	 *
	 * Capability gate + nonce: an admin (or the user themselves) editing
	 * their own profile.
	 */
	public static function handle_shuffle_ajax() {
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing user id.', 'adminkit' ) ), 400 );
		}
		check_ajax_referer( self::SHUFFLE_NONCE );
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'adminkit' ) ), 403 );
		}

		update_user_meta( $user_id, self::SEED_META, md5( wp_generate_uuid4() ) );

		// Return the fresh URL at size 80 — covers the editor header's 40×40
		// render at 2x retina, and the JS reuses it for the list-table cell
		// (which renders at 32 nominal; the browser scales the larger source
		// cleanly enough for a click-and-see flow). One URL keeps the response
		// + the JS swap path simple.
		wp_send_json_success(
			array(
				'avatar_url' => get_avatar_url( $user_id, array( 'size' => 80 ) ),
			)
		);
	}

	/**
	 * Whether a per-user "Refresh avatar" affordance is meaningful for this
	 * user *right now*: feature on, AdminKit Portraits is the active default,
	 * and the user isn't being served a real Gravatar (a re-roll wouldn't
	 * visibly change anything if Gravatar overrides our portrait).
	 *
	 * `$allow_probe` controls the Gravatar check:
	 *   - false (default): cache-only — reads the `GRAVATAR_META` value and
	 *     never triggers a fresh HEAD. Right for row-rendering contexts (the
	 *     users.php Quick Edit button) where N callers per page would each
	 *     pay a network probe.
	 *   - true: full `has_real_gravatar()` check — single-user contexts
	 *     (profile.php / user-edit.php) where one HEAD probe is acceptable
	 *     and gives the right answer on the *first* visit too.
	 */
	public static function can_regenerate( $user, $allow_probe = false ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}
		if ( ! AdminKit_Settings::get( 'custom_avatars_enabled' ) ) {
			return false;
		}
		if ( self::AVATAR_KEY !== get_option( 'avatar_default' ) ) {
			return false;
		}
		if ( $allow_probe ) {
			return ! self::has_real_gravatar( $user );
		}
		// Cached '1' = real Gravatar → regenerating wouldn't visibly change the
		// rendered avatar. Cached '0' or uncached → assume the generated
		// portrait is in use; the next render will populate the cache anyway.
		$cached = (string) get_user_meta( $user->ID, self::GRAVATAR_META, true );
		return '1' !== $cached;
	}

	/**
	 * Inject the AJAX endpoint + nonce + target user id into the profile
	 * script's localized data object so `profile-account.js` can wire the
	 * "Refresh avatar" page-title button to `handle_shuffle_ajax()` directly
	 * — no GET round-trip, no `wp_safe_redirect` dance, no URL params.
	 *
	 * Fires on `admin_enqueue_scripts`. If the profile script isn't registered
	 * (wrong screen, feature off, …) or the avatar cannot be regenerated for
	 * this user, returns silently and the button JS never sees the keys it
	 * needs to render itself.
	 */
	public static function maybe_add_refresh_data() {
		if ( ! wp_script_is( 'adminkit-profile-account', 'registered' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$user_id = 0;
		if ( 'user-edit' === $screen->id ) {
			$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( 'profile' === $screen->id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! self::can_regenerate( $user, true ) ) {
			return;
		}
		wp_add_inline_script(
			'adminkit-profile-account',
			'window.AdminKitProfileAccount=Object.assign(window.AdminKitProfileAccount||{},'
				. wp_json_encode(
					array(
						'refreshAvatarLabel'   => __( 'Refresh avatar', 'adminkit' ),
						'refreshAvatarError'   => __( 'Could not refresh the avatar — please try again.', 'adminkit' ),
						'refreshAvatarAjaxUrl' => admin_url( 'admin-ajax.php' ),
						'refreshAvatarNonce'   => wp_create_nonce( self::SHUFFLE_NONCE ),
						'refreshAvatarUserId'  => $user_id,
					)
				) . ');',
			'before'
		);
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
		$screen = get_current_screen();
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
