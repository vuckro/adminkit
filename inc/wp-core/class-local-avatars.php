<?php
/**
 * Local avatars — a per-user profile picture that REPLACES Gravatar.
 *
 * Opt-in via the `local_avatars_enabled` setting (OFF by default). When a user
 * has no local avatar set, behaviour is 100% unchanged — Gravatar everywhere.
 *
 * Designed to be NON-DESTRUCTIVE:
 *   - The avatar swap rides on `pre_get_avatar_data`, WordPress's own filter for
 *     supplying avatar args. We only set a URL when the resolved user actually
 *     has a local avatar; otherwise we return the args UNTOUCHED, so core falls
 *     back to Gravatar exactly as before. `force_default` (the "show the default
 *     mystery person" path) is always honoured — we bail on it.
 *   - srcset / retina works for free: WordPress re-calls the filter at size×2 to
 *     build the 2x source, so resolving the attachment at the requested size each
 *     time yields a correctly-sized image for every density.
 *   - The avatar is a Media Library attachment id stored in user meta
 *     (`adminkit_local_avatar`). If that attachment is later deleted, the meta is
 *     cleared (`delete_attachment`) and, defensively, an id that no longer
 *     resolves to an image is treated as "no avatar" at read time. Deleting the
 *     USER deletes the attachment they own too (`delete_user`/`wpmu_delete_user`),
 *     so the Media Library is never left with an orphan.
 *
 * Generated fallback (automatic whenever local avatars are on — no separate
 * toggle): when a user has no uploaded avatar, AdminKit hands WordPress a
 * friendly auto-generated avatar (DiceBear's hosted, key-less HTTP API) as the
 * Gravatar `d=` *default* — a real Gravatar still wins, only a missing one falls
 * back to the generated image. The seed is the md5 of the login (NON-PII), so
 * each user reliably gets the same unique face. The DiceBear style defaults to
 * `personas` (illustrated human portraits, high variety); the style + final URL
 * are both filterable for self-hosting / swapping the service.
 *
 * The profile UI is intentionally minimal: a single circular bubble that doubles
 * as the upload trigger, plus a "Reset to default" button that clears any upload
 * (reverting to Gravatar / the generated face). No re-roll affordance — the
 * generated face is deterministic from the login, so each user already gets a
 * unique portrait, and uploading replaces it.
 *
 * The profile field renders on show_user_profile / edit_user_profile and saves on
 * personal_options_update / edit_user_profile_update, guarded by a dedicated
 * nonce + capability checks. The media-picker behaviour lives in
 * assets/js/wp-core/local-avatars.js, enqueued only on profile.php / user-edit.php.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Local_Avatars {

	/** User-meta key holding the local avatar's attachment id (single int). */
	const META_KEY = 'adminkit_local_avatar';

	/** Nonce action + field name guarding the profile save. */
	const NONCE_ACTION = 'adminkit_local_avatar_save';
	const NONCE_FIELD  = 'adminkit_local_avatar_nonce';

	/** Form field name carrying the chosen attachment id. */
	const FIELD = 'adminkit_local_avatar';

	/** Form field flag (the Reset button) requesting a revert to the default avatar. */
	const RESET_FIELD = 'adminkit_local_avatar_reset';

	/**
	 * DiceBear hosted API base + version. The generated-avatar URL is built from
	 * this on both PHP (the d= fallback) and JS (the live "generate" preview), so
	 * they agree byte-for-byte for a given seed + style + size.
	 */
	const DICEBEAR_BASE = 'https://api.dicebear.com/9.x/';

	/** Screen ids that carry the user profile / edit form. @var string[] */
	const SCREENS = array( 'profile', 'user-edit' );

	/**
	 * When true, filter_avatar_data() ignores the uploaded local avatar and
	 * resolves the "no-upload" effective avatar instead (Gravatar, or the generated
	 * d= fallback). Used by fallback_preview_url() so the profile field can preview
	 * what the avatar becomes once a custom upload is removed.
	 *
	 * @var bool
	 */
	private static $skip_local = false;

	/**
	 * Wire the hooks — only when the feature is enabled. Called once from the
	 * plugin orchestrator. Returns early (no hooks at all) when the opt-in toggle
	 * is off, so Gravatar behaviour is genuinely 100% unchanged.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! AdminKit_Settings::get( 'local_avatars_enabled' ) ) {
			return;
		}

		// Replace the avatar URL when the user has a local avatar. priority/args
		// match get_avatar_data()'s filter signature ($args, $id_or_email).
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );

		// Profile field render + save (self profile and editing another user).
		add_action( 'show_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_field' ) );

		// When an attachment is deleted, clear it from any user pointing at it so
		// no profile is left referencing a dead id.
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		// When a user is deleted, delete the avatar attachment they own so the
		// Media Library isn't left with an orphan. Fires BEFORE deletion, while the
		// user meta is still readable. wpmu_delete_user mirrors it on multisite.
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'on_delete_user' ) );

		// Media picker on the profile / user-edit screens.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Feed WordPress's avatar pipeline. Two non-destructive behaviours:
	 *
	 *   1. A user with an uploaded local avatar gets its URL set directly (core
	 *      short-circuits to it, skipping Gravatar).
	 *   2. A user with NO local avatar gets a friendly generated avatar passed only
	 *      as the Gravatar `d=` *default* (automatic when local avatars are on) —
	 *      $args['url'] is left unset, so a real Gravatar still wins and only a
	 *      missing one falls back to the generated image.
	 *
	 * Otherwise $args is returned UNCHANGED (nothing set or `force_default` asked) —
	 * core proceeds to Gravatar / its own default exactly as it would without us.
	 *
	 * @param array $args        get_avatar_data() args (size, url, found_avatar, …).
	 * @param mixed $id_or_email User id, email, WP_User, WP_Post or WP_Comment.
	 * @return array
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;

		// Honour the explicit "show the default avatar" request unchanged — AdminKit
		// no longer adds an entry to the Settings → Discussion default-avatar list.
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$user_id = self::resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $args;
		}

		// 1) An uploaded local avatar always wins — set the URL directly so core
		//    short-circuits straight to it (Gravatar is skipped entirely). Skipped
		//    when resolving the "no-upload" fallback for the profile preview.
		if ( ! self::$skip_local ) {
			$url = self::get_local_avatar_url( $user_id, $size );
			if ( '' !== $url ) {
				$args['url']          = $url;
				$args['found_avatar'] = true;
				return $args;
			}
		}

		// 2) No local avatar: hand WordPress a generated avatar as the Gravatar `d=`
		//    *default* — automatic whenever local avatars are on (no separate toggle).
		//    $args['url'] is deliberately left UNSET so core still builds the Gravatar
		//    URL: a real Gravatar wins, and only a missing one redirects to ours.
		$args['default'] = self::get_generated_avatar_url( $user_id, $size );

		return $args;
	}

	/**
	 * Build a friendly auto-generated avatar URL for a user, used as the Gravatar
	 * `d=` fallback when the user has neither a local avatar nor a real Gravatar.
	 *
	 * Privacy: seeded with a NON-PII value (md5 of the user_login, NEVER the raw
	 * email) so the seed leaks nothing about the user. The generator is DiceBear's
	 * hosted HTTP API (https://api.dicebear.com), a free, key-less, URL-based
	 * service.
	 *
	 * Both the style and the whole URL are filterable so a site can swap the
	 * service or self-host:
	 *   - `adminkit/generated_avatar_style` — the DiceBear style slug.
	 *   - `adminkit/generated_avatar_url`   — the final URL (args: url, user_id, size).
	 *
	 * @param int $user_id
	 * @param int $size    Requested square size in px (DiceBear PNG caps at 256).
	 * @return string
	 */
	public static function get_generated_avatar_url( $user_id, $size = 96 ) {
		$user_id = (int) $user_id;
		$size    = max( 1, min( 256, (int) $size ) ); // DiceBear PNG max is 256px.

		$seed  = self::get_generated_seed( $user_id );
		$style = self::generated_avatar_style( $user_id );

		$url = add_query_arg(
			array(
				'seed' => $seed,
				'size' => $size,
			),
			self::DICEBEAR_BASE . $style . '/png'
		);

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
	 * The seed used to generate a user's avatar. The md5 of the login (NON-PII,
	 * never the raw email), so each user reliably gets the same unique face — and
	 * any two users get visibly distinct portraits.
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
	 * Resolve + sanitise the DiceBear style slug used for generated avatars.
	 *
	 * Default: `personas` — illustrated human portraits with high variety, so each
	 * user gets a visibly unique face out of the box. Override via the filter to
	 * pick another DiceBear style (notionists, avataaars, lorelei, micah, open-peeps,
	 * fun-emoji…).
	 *
	 * @param int $user_id The user the avatar is for.
	 * @return string A slug matching [a-z0-9-]+, never empty.
	 */
	public static function generated_avatar_style( $user_id ) {
		/**
		 * Filter the DiceBear style used for generated avatars.
		 *
		 * @param string $style   Default 'personas' (human portraits, varied).
		 * @param int    $user_id The user the avatar is for.
		 */
		$style = apply_filters( 'adminkit/generated_avatar_style', 'personas', (int) $user_id );
		$style = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $style ) );
		return '' === $style ? 'personas' : $style;
	}

	/**
	 * Resolve a WordPress user id from any of the identifiers WP passes to the
	 * avatar filters. Returns 0 when no user can be determined (e.g. a comment
	 * left by a logged-out visitor, or an unknown email) so the caller bails
	 * cleanly to Gravatar.
	 *
	 * @param mixed $id_or_email int id | email string | WP_User | WP_Post | WP_Comment.
	 * @return int
	 */
	private static function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			// absint() to mirror core's own get_avatar_data() handling and keep a
			// stray negative/float id from ever reaching get_user_meta().
			return absint( $id_or_email );
		}
		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof WP_Comment ) {
			// Only comments authored by a registered user map to an avatar we own.
			return empty( $id_or_email->user_id ) ? 0 : (int) $id_or_email->user_id;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}
		return 0;
	}

	/**
	 * The local avatar attachment id for a user, or 0 if none is set or the stored
	 * id no longer resolves to an image (defensive against an orphaned reference).
	 *
	 * @param int $user_id
	 * @return int
	 */
	public static function get_local_avatar_id( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$id = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( $id <= 0 ) {
			return 0;
		}
		// Treat a non-image / missing attachment as "no avatar".
		if ( ! wp_attachment_is_image( $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * Resolve the local avatar URL for a user at a given pixel size, or '' when
	 * there's no usable local avatar.
	 *
	 * @param int $user_id
	 * @param int $size    Requested square size in px.
	 * @return string
	 */
	public static function get_local_avatar_url( $user_id, $size = 96 ) {
		$id = self::get_local_avatar_id( $user_id );
		if ( ! $id ) {
			return '';
		}
		$size = max( 1, (int) $size );
		$url  = wp_get_attachment_image_url( $id, array( $size, $size ) );
		return $url ? (string) $url : '';
	}

	/**
	 * The avatar a user would show with NO uploaded local avatar — i.e. their real
	 * Gravatar, or the generated face via the d= fallback. Used to preview the
	 * bubble so it's never blank, and handed to the JS so "Reset to default"
	 * reverts the preview to it. Returns '' when avatars are globally disabled.
	 * Temporarily flags filter_avatar_data() to ignore the upload.
	 *
	 * @param int $user_id
	 * @param int $size    Requested square size in px.
	 * @return string
	 */
	public static function fallback_preview_url( $user_id, $size = 96 ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_option( 'show_avatars' ) ) {
			return '';
		}
		self::$skip_local = true;
		$url = get_avatar_url( $user_id, array( 'size' => (int) $size ) );
		self::$skip_local = false;
		return $url ? (string) $url : '';
	}

	/**
	 * Render the avatar field on the profile / user-edit form. Shown only to a
	 * user who may edit the target profile; the upload button shows only when the
	 * editor can upload files (others see the current avatar read-only).
	 *
	 * @param WP_User $user The profile being edited.
	 * @return void
	 */
	public static function render_field( $user ) {
		if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$upload_id  = self::get_local_avatar_id( $user->ID );
		$has_upload = $upload_id > 0;
		$can_pick   = current_user_can( 'upload_files' );

		// Preview the EFFECTIVE avatar so the bubble is never blank: the uploaded
		// image if any, otherwise whatever get_avatar() resolves to (a real
		// Gravatar, or the generated face). The same no-upload URL is handed to the
		// JS so "Reset to default" reverts to it.
		$fallback    = self::fallback_preview_url( $user->ID, 96 );
		$preview     = $has_upload ? self::get_local_avatar_url( $user->ID, 96 ) : $fallback;
		$has_preview = '' !== $preview;

		// The button's accessible name (static — clicking always opens the picker).
		$media_aria  = $has_upload
			? __( 'Change the profile picture', 'adminkit' )
			: __( 'Upload a profile picture', 'adminkit' );
		$state_class = $has_upload ? 'is-filled' : 'is-empty';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<h2><?php echo esc_html__( 'Profile picture', 'adminkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr class="user-adminkit-local-avatar-wrap">
				<th><?php echo esc_html__( 'Profile picture', 'adminkit' ); ?></th>
				<td>
					<div class="adminkit-local-avatar <?php echo esc_attr( $state_class ); ?>" id="adminkit-local-avatar">
						<input type="hidden" name="<?php echo esc_attr( self::FIELD ); ?>"
							id="adminkit-local-avatar-input" value="<?php echo esc_attr( (string) $upload_id ); ?>" />
						<?php if ( $can_pick ) : ?>
							<input type="hidden" name="<?php echo esc_attr( self::RESET_FIELD ); ?>"
								id="adminkit-local-avatar-reset-input" value="" />
						<?php endif; ?>
						<?php if ( $can_pick ) : ?>
							<button type="button" class="adminkit-local-avatar__media" id="adminkit-local-avatar-btn"
								aria-label="<?php echo esc_attr( $media_aria ); ?>">
								<img class="adminkit-local-avatar__preview" id="adminkit-local-avatar-preview"
									src="<?php echo esc_url( $preview ); ?>"
									alt=""
									width="96" height="96"
									<?php echo $has_preview ? '' : 'hidden'; ?> />
								<span class="adminkit-local-avatar__placeholder" id="adminkit-local-avatar-placeholder"
									aria-hidden="true"<?php echo $has_preview ? ' hidden' : ''; ?>>
									<?php echo self::icon_user(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-contained SVG markup. ?>
								</span>
								<span class="adminkit-local-avatar__overlay" aria-hidden="true">
									<?php echo self::icon_camera(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-contained SVG markup. ?>
									<span class="adminkit-local-avatar__overlay-text"><?php echo esc_html__( 'Change', 'adminkit' ); ?></span>
								</span>
							</button>
							<?php
							// Reset to default — clears the uploaded image, reverting to the real
							// Gravatar / deterministic generated face. Shown only when there's an
							// upload to clear; the JS toggles it as the user picks / resets.
							?>
							<p class="adminkit-local-avatar__actions">
								<button type="button" class="button adminkit-local-avatar__action adminkit-local-avatar__reset" id="adminkit-local-avatar-reset"<?php echo $has_upload ? '' : ' hidden'; ?>>
									<?php echo self::icon_reset(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-contained SVG markup. ?>
									<span><?php echo esc_html__( 'Reset to default', 'adminkit' ); ?></span>
								</button>
							</p>
						<?php else : ?>
							<?php if ( $has_preview ) : ?>
								<span class="adminkit-local-avatar__media">
									<img class="adminkit-local-avatar__preview"
										src="<?php echo esc_url( $preview ); ?>"
										alt="" width="96" height="96" />
								</span>
							<?php endif; ?>
						<?php endif; ?>
						<p class="description"><?php echo esc_html__( 'Upload an image to use as this user\'s avatar everywhere. Leave empty to fall back to Gravatar — or, when Gravatar has no image, a generated portrait unique to this user.', 'adminkit' ); ?></p>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Inline "user" placeholder glyph for the empty avatar state. Static,
	 * self-contained SVG (currentColor) — no user data, safe to echo as-is.
	 *
	 * @return string
	 */
	private static function icon_user() {
		return '<svg viewBox="0 0 24 24" role="presentation" focusable="false" aria-hidden="true">'
			. '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.69-8 6v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1c0-3.31-3.58-6-8-6Z"/>'
			. '</svg>';
	}

	/**
	 * Inline camera glyph for the hover overlay. Static, self-contained SVG
	 * (currentColor) — no user data, safe to echo as-is.
	 *
	 * @return string
	 */
	private static function icon_camera() {
		return '<svg viewBox="0 0 24 24" role="presentation" focusable="false" aria-hidden="true">'
			. '<path d="M9 3a1 1 0 0 0-.8.4L7 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3l-1.2-1.6A1 1 0 0 0 15 3H9Zm3 5.5A4.5 4.5 0 1 1 7.5 13 4.5 4.5 0 0 1 12 8.5Zm0 2A2.5 2.5 0 1 0 14.5 13 2.5 2.5 0 0 0 12 10.5Z"/>'
			. '</svg>';
	}

	/**
	 * Inline "revert / reset" glyph (counter-clockwise arrow) for the Reset-to-default
	 * button. Static, self-contained SVG (currentColor stroke) — safe to echo as-is.
	 *
	 * @return string
	 */
	private static function icon_reset() {
		return '<svg class="adminkit-local-avatar__action-icon" viewBox="0 0 24 24" role="presentation" focusable="false" aria-hidden="true">'
			. '<path d="M3 3v6h6M3.51 9A9 9 0 1 1 3 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Persist the chosen avatar on profile save. Verifies the dedicated nonce and
	 * the edit_user capability, then stores the absint'd attachment id (or clears
	 * the meta when empty). No-op silently when the nonce/cap fails so it never
	 * interferes with the rest of the profile form.
	 *
	 * @param int $user_id The profile being saved.
	 * @return void
	 */
	public static function save_field( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// Dedicated nonce for this field. check_admin_referer dies on failure,
		// matching WP's own profile-field convention.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// Reset to default (the Reset button): clear the uploaded avatar so the user
		// reverts to their real Gravatar / the deterministic generated face. Highest
		// precedence — the JS clears this flag whenever a new upload is chosen.
		if ( ! empty( $_POST[ self::RESET_FIELD ] ) ) {
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}

		$raw = isset( $_POST[ self::FIELD ] ) ? absint( wp_unslash( $_POST[ self::FIELD ] ) ) : 0;

		// Only store an id that resolves to a real image; anything else clears it.
		if ( $raw > 0 && wp_attachment_is_image( $raw ) ) {
			update_user_meta( $user_id, self::META_KEY, $raw );
		} else {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	/**
	 * When an attachment is deleted, clear it from every user whose local avatar
	 * points at it — so no profile is left referencing a dead attachment id.
	 *
	 * @param int $attachment_id
	 * @return void
	 */
	public static function on_delete_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		$users = get_users( array(
			'meta_key'   => self::META_KEY,
			'meta_value' => $attachment_id,
			'fields'     => 'ID',
		) );
		foreach ( $users as $uid ) {
			delete_user_meta( (int) $uid, self::META_KEY );
		}
	}

	/**
	 * When a user is deleted, delete the avatar attachment they own so it doesn't
	 * linger in the Media Library as an orphan.
	 *
	 * Tight guard: only the attachment id stored in THIS user's meta, and only
	 * when it still resolves to a real image attachment, is force-deleted. Anything
	 * empty / invalid / not theirs is left untouched — we never delete anything but
	 * the single avatar this user pointed at.
	 *
	 * @param int $user_id The user being deleted (meta still readable at this point).
	 * @return void
	 */
	public static function on_delete_user( $user_id ) {
		$id = self::get_local_avatar_id( $user_id ); // 0 unless a valid image they own.
		if ( $id > 0 ) {
			wp_delete_attachment( $id, true );
		}
	}

	/**
	 * Enqueue the media-picker script on the profile / user-edit screens, with the
	 * WordPress media frame and the localized button labels (kept out of the JS).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! AdminKit_Screen::is_one_of( self::SCREENS ) ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return; // No picker without the upload capability.
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}

		// Hover-overlay styles for the clickable preview. Enqueued directly (the
		// Assets registry runs only for the CSS contexts; this rides the same
		// screen gate as the picker), mtime-busted like every other AdminKit asset.
		$css_src  = 'assets/css/wp-core/local-avatars.css';
		$css_path = ADMINKIT_PATH . $css_src;
		wp_enqueue_style(
			'adminkit-local-avatars',
			ADMINKIT_URL . $css_src,
			array( AdminKit_Assets::TOKENS_HANDLE ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ADMINKIT_VERSION
		);

		// The profile being edited: the current user on profile.php, or the
		// ?user_id target on user-edit.php. Used for the "Reset to default → revert
		// to the effective avatar (Gravatar / generated face)" preview handed to the JS.
		$target_user = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : get_current_user_id(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the page's own subject id; no state change.

		$bootstrap = array(
			'title'    => __( 'Select a profile picture', 'adminkit' ),
			'button'   => __( 'Use this image', 'adminkit' ),
			// Button accessible name, swapped per filled / empty state after a
			// pick or a reset (the bubble always opens the media picker).
			'ariaFill'  => __( 'Change the profile picture', 'adminkit' ),
			'ariaEmpty' => __( 'Upload a profile picture', 'adminkit' ),
			// Default avatar (real Gravatar / generated-from-login face): "Reset to
			// default" reverts the preview to this so the bubble is never left blank.
			'defaultUrl' => self::fallback_preview_url( $target_user, 96 ),
			// The page-title avatar (built by profile-account.js) doubles as a
			// second picker trigger; its accessible name lives here with the field's.
			'heroAria' => __( 'Change the profile picture', 'adminkit' ),
		);

		wp_enqueue_media();
		// Deps:
		//  - `media-editor` (defines `wp.media`, pulled in by wp_enqueue_media()) so
		//    this footer script runs AFTER `wp.media` exists — not jquery (the brick
		//    uses none; jQuery still loads transitively via media-editor's deps).
		//  - `adminkit-profile-account` so it runs AFTER the tab builder has lifted
		//    the page-title avatar into the hero: setupHero() can then find + wire it
		//    as a second picker trigger. profile-account is always enqueued on these
		//    screens (its SCREENS superset ours), so the handle is reliably present.
		AdminKit_Assets::enqueue_script(
			'adminkit-local-avatars',
			'assets/js/wp-core/local-avatars.js',
			array( 'media-editor', 'adminkit-profile-account' ),
			'window.AdminKitLocalAvatars=' . wp_json_encode( $bootstrap ) . ';'
		);
	}
}
