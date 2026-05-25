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
 *     resolves to an image is treated as "no avatar" at read time.
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

	/** Screen ids that carry the user profile / edit form. @var string[] */
	const SCREENS = array( 'profile', 'user-edit' );

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

		// Media picker on the profile / user-edit screens.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Supply a local avatar's URL to WordPress's avatar pipeline, when one is set.
	 *
	 * Non-destructive contract: returns $args UNCHANGED unless the resolved user
	 * has a usable local avatar — so with nothing set (or `force_default` asked),
	 * core proceeds to Gravatar / the default exactly as it would without us.
	 *
	 * @param array $args        get_avatar_data() args (size, url, found_avatar, …).
	 * @param mixed $id_or_email User id, email, WP_User, WP_Post or WP_Comment.
	 * @return array
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
		// Honour the explicit "show the default avatar" request.
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$user_id = self::resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $args;
		}

		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$url  = self::get_local_avatar_url( $user_id, $size );
		if ( '' === $url ) {
			return $args;
		}

		$args['url']          = $url;
		$args['found_avatar'] = true;
		return $args;
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
			return (int) $id_or_email;
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

		$id        = self::get_local_avatar_id( $user->ID );
		$preview   = $id ? self::get_local_avatar_url( $user->ID, 96 ) : '';
		$can_pick  = current_user_can( 'upload_files' );
		$has_image = '' !== $preview;

		// Visible label inside the hover overlay, and the button's accessible name.
		$overlay_label = $has_image ? __( 'Change photo', 'adminkit' ) : __( 'Add a photo', 'adminkit' );
		$media_aria    = $has_image
			? __( 'Change the profile picture', 'adminkit' )
			: __( 'Upload a profile picture', 'adminkit' );
		$state_class   = $has_image ? 'is-filled' : 'is-empty';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<h2><?php echo esc_html__( 'Profile picture', 'adminkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr class="user-adminkit-local-avatar-wrap">
				<th><?php echo esc_html__( 'Profile picture', 'adminkit' ); ?></th>
				<td>
					<div class="adminkit-local-avatar <?php echo esc_attr( $state_class ); ?>" id="adminkit-local-avatar">
						<input type="hidden" name="<?php echo esc_attr( self::FIELD ); ?>"
							id="adminkit-local-avatar-input" value="<?php echo esc_attr( (string) $id ); ?>" />
						<?php if ( $can_pick ) : ?>
							<button type="button" class="adminkit-local-avatar__media" id="adminkit-local-avatar-btn"
								aria-label="<?php echo esc_attr( $media_aria ); ?>">
								<img class="adminkit-local-avatar__preview" id="adminkit-local-avatar-preview"
									src="<?php echo esc_url( $preview ); ?>"
									alt=""
									width="96" height="96"
									<?php echo $has_image ? '' : 'hidden'; ?> />
								<span class="adminkit-local-avatar__placeholder" id="adminkit-local-avatar-placeholder"
									aria-hidden="true"<?php echo $has_image ? ' hidden' : ''; ?>>
									<?php echo self::icon_user(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-contained SVG markup. ?>
								</span>
								<span class="adminkit-local-avatar__overlay" aria-hidden="true">
									<?php echo self::icon_camera(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, self-contained SVG markup. ?>
									<span class="adminkit-local-avatar__overlay-label" id="adminkit-local-avatar-overlay-label"><?php echo esc_html( $overlay_label ); ?></span>
								</span>
							</button>
							<p class="adminkit-local-avatar__actions">
								<button type="button" class="button-link adminkit-local-avatar__remove" id="adminkit-local-avatar-remove"
									<?php echo $has_image ? '' : 'hidden'; ?>>
									<?php echo esc_html__( 'Remove', 'adminkit' ); ?>
								</button>
							</p>
						<?php else : ?>
							<?php if ( $has_image ) : ?>
								<span class="adminkit-local-avatar__media">
									<img class="adminkit-local-avatar__preview"
										src="<?php echo esc_url( $preview ); ?>"
										alt="" width="96" height="96" />
								</span>
							<?php endif; ?>
						<?php endif; ?>
						<p class="description"><?php echo esc_html__( 'This image replaces the Gravatar everywhere this user appears. Leave empty to use Gravatar.', 'adminkit' ); ?></p>
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
	 * Inline "camera" glyph for the hover overlay. Static, self-contained SVG
	 * (currentColor) — no user data, safe to echo as-is.
	 *
	 * @return string
	 */
	private static function icon_camera() {
		return '<svg viewBox="0 0 24 24" role="presentation" focusable="false" aria-hidden="true">'
			. '<path d="M9 3a1 1 0 0 0-.8.4L7 5H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3l-1.2-1.6A1 1 0 0 0 15 3H9Zm3 5a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>'
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

		wp_enqueue_media();
		AdminKit_Assets::enqueue_script(
			'adminkit-local-avatars',
			'assets/js/wp-core/local-avatars.js',
			array( 'jquery' ),
			'window.AdminKitLocalAvatars=' . wp_json_encode( array(
				'title'        => __( 'Select a profile picture', 'adminkit' ),
				'button'       => __( 'Use this image', 'adminkit' ),
				// Overlay label + button accessible name, per filled / empty state.
				'overlayFill'  => __( 'Change photo', 'adminkit' ),
				'overlayEmpty' => __( 'Add a photo', 'adminkit' ),
				'ariaFill'     => __( 'Change the profile picture', 'adminkit' ),
				'ariaEmpty'    => __( 'Upload a profile picture', 'adminkit' ),
			) ) . ';'
		);
	}
}
