<?php
/**
 * Username Changer — opt-in editing of `user_login` from profile.php /
 * user-edit.php. WordPress disables the Username field by design (the column
 * is treated as immutable), but a number of sites legitimately need to fix a
 * typo or follow a rename request. This module exposes that capability as a
 * single feature toggle.
 *
 * Off by default. Enable from AdminKit → Features → Username changer. When
 * disabled, `init()` returns early and no hooks bind — WordPress's native
 * "disabled" username field remains untouched.
 *
 * Flow — entirely native, no AJAX endpoint:
 *   1. On profile.php / user-edit.php for a target the viewer can `edit_user`,
 *      a small JS brick replaces WP's "Usernames cannot be changed."
 *      description with a "Click to enable" hint and switches the input from
 *      `disabled` to `readonly` + a `locked` class (so it can be clicked).
 *   2. Clicking the field shows a `window.confirm()` dialog that spells out
 *      the consequence (sessions invalidated). On confirm we remove the
 *      readonly + locked class — the input becomes normally editable.
 *   3. The user types and clicks WordPress's native "Update User" button.
 *      `$_POST['user_login']` is submitted with the other profile fields.
 *   4. We hook `user_profile_update_errors` to VALIDATE the new login
 *      (`sanitize_user( $raw, true )` round-trip + `username_exists()`); any
 *      problem becomes a normal `WP_Error` that WordPress surfaces with its
 *      built-in error UI. WordPress aborts the update if validation fails.
 *   5. We hook `profile_update` (fires after `wp_update_user()` on success)
 *      to APPLY the rename:
 *        - direct `$wpdb->update( $wpdb->users, [...] )` — wp_update_user
 *          ignores `user_login` by design,
 *        - `clean_user_cache()` to bust the user-object + login caches,
 *        - session housekeeping (next bullet).
 *   6. Session housekeeping. The WP auth cookie hashes `user_login` into its
 *      signature, so a rename invalidates EVERY existing auth cookie for
 *      that user. We make the consequences explicit instead of letting them
 *      surface as random "you've been logged out":
 *        - Self-edit: destroy our other sessions via
 *          `WP_Session_Tokens::destroy_others( wp_get_session_token() )` (so
 *          the rename signs out every other device we were on), then re-issue
 *          our current auth cookie with `wp_set_auth_cookie()` so the
 *          redirect that follows the form save lands authenticated.
 *        - Other user: `WP_Session_Tokens::destroy_all()` — they must sign in
 *          again on every device with the new name. This *is* the security
 *          property of changing the login.
 *   7. Fires `adminkit/username_changed` ($user_id, $old, $new) for audit
 *      logs and external systems mirroring users by `user_login`.
 *
 * Security model:
 *   - Capability: `current_user_can( 'edit_user', $target )` — maps through
 *     WordPress's super-admin / network-admin / promote_users rules. The
 *     native user-edit.php form already gates submission with this; our
 *     `profile_update` handler re-checks defensively.
 *   - Nonce: WordPress's own `update-user_{ID}` nonce already protects the
 *     form. We don't add another — the change rides the native submission.
 *   - Input: `sanitize_user( $raw, true )` strict mode + raw-equality check;
 *     we never silently transform the user's input. `username_exists()`
 *     deduplicates against the rest of the user table.
 *
 * Known limitations:
 *   - Multisite: skipped entirely. `user_login` is unique per network and
 *     used by cross-site mappings; renaming safely needs network-admin level
 *     orchestration this module doesn't implement. The toggle's UI still
 *     shows but `init()` returns early on multisite — the field stays
 *     read-only as native WP.
 *   - `user_nicename` is *not* renamed when `user_login` changes. That's
 *     intentional: it keeps `/author/<old-slug>` URLs stable (no SEO loss),
 *     and a separate admin action can rename `user_nicename` later if needed.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Username_Changer {

	public static function init() {
		if ( ! AdminKit_Settings::get( 'username_changer_enabled' ) ) {
			return;
		}
		// `user_login` on multisite is a network-wide unique key with cross-site
		// mappings. Renaming safely there is out of scope for this module.
		if ( is_multisite() ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'apply' ), 10, 2 );
	}

	/**
	 * Resolve which user the current screen is editing. `user-edit.php` carries
	 * the target as `?user_id=N`; `profile.php` always edits the current user.
	 * Returns 0 when the screen isn't an editable profile context, so the
	 * caller can bail without enqueueing.
	 */
	private static function target_user_id( $hook ) {
		if ( 'user-edit.php' === $hook ) {
			return isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		}
		if ( 'profile.php' === $hook ) {
			return get_current_user_id();
		}
		return 0;
	}

	public static function enqueue( $hook ) {
		$user_id = self::target_user_id( $hook );
		if ( ! $user_id ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$css_src  = 'assets/css/wp-core/username-changer.css';
		$css_path = ADMINKIT_PATH . $css_src;
		wp_enqueue_style(
			'adminkit-username-changer',
			ADMINKIT_URL . $css_src,
			array( AdminKit_Assets::TOKENS_HANDLE ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ADMINKIT_VERSION
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-username-changer',
			'assets/js/wp-core/username-changer.js',
			array(),
			'window.AdminKitUsernameChanger=' . wp_json_encode(
				array(
					'unlockedHint'  => __( 'Type the new username, then click "Update User" below to save.', 'adminkit' ),
					'unlockConfirm' => __( 'Renaming a user invalidates every active sign-in they have. They must use the new username from now on. Continue?', 'adminkit' ),
				)
			) . ';'
		);
	}

	/**
	 * Validate the submitted `user_login` ahead of `wp_update_user()`. Adds
	 * errors to `$errors` (a `WP_Error` instance) — WordPress aborts the
	 * update if anything was added, and surfaces our messages with its
	 * built-in error UI. We never silently transform what the admin typed.
	 *
	 * No-ops when the field wasn't submitted (the lock state in our JS skips
	 * it, and on plain WP without our toggle the disabled attribute strips it
	 * from the form), when it matches the current value, or when the viewer
	 * lacks `edit_user` on this target (defence in depth).
	 *
	 * @param WP_Error $errors  Accumulator passed by reference.
	 * @param bool     $update  Whether this is an update (true) or insert (false).
	 * @param stdClass $user    User object the form is targeting (raw form data;
	 *                          `$user->ID` is the only reliable property here).
	 */
	public static function validate( $errors, $update, $user ) {
		if ( ! $update ) {
			return; // Creation flow, not our concern.
		}
		if ( ! isset( $_POST['user_login'] ) ) {
			return;
		}
		if ( empty( $user->ID ) || ! current_user_can( 'edit_user', (int) $user->ID ) ) {
			return;
		}
		$current = get_userdata( (int) $user->ID );
		if ( ! $current ) {
			return;
		}

		$raw = trim( (string) wp_unslash( $_POST['user_login'] ) );
		if ( '' === $raw || $raw === $current->user_login ) {
			return; // No change attempted.
		}

		// `sanitize_user( $v, true )` in strict mode keeps only allowed chars.
		// We then require an exact round-trip: if sanitisation stripped or
		// changed anything, reject — never silently save a different name.
		$sanitized = sanitize_user( $raw, true );
		if ( '' === $sanitized || $sanitized !== $raw ) {
			$errors->add(
				'adminkit_username_invalid',
				__( '<strong>Error:</strong> Invalid username. Use letters, numbers, spaces, or any of . - _ @ — and nothing else.', 'adminkit' )
			);
			return;
		}

		if ( username_exists( $sanitized ) ) {
			$errors->add(
				'adminkit_username_taken',
				__( '<strong>Error:</strong> That username is already taken.', 'adminkit' )
			);
		}
	}

	/**
	 * Apply the rename after WordPress's own `wp_update_user()` has run all
	 * the fields it owns. Only fires when validation passed (the
	 * `user_profile_update_errors` hook above aborts the request otherwise).
	 *
	 * @param int     $user_id       Affected user ID.
	 * @param WP_User $old_user_data Pre-update user object — what `user_login` was before this request.
	 */
	public static function apply( $user_id, $old_user_data ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return; // Defence in depth — the form gate already requires this.
		}
		if ( ! isset( $_POST['user_login'] ) ) {
			return;
		}

		$raw = trim( (string) wp_unslash( $_POST['user_login'] ) );
		$old_login = is_object( $old_user_data ) ? (string) $old_user_data->user_login : '';
		if ( '' === $raw || $raw === $old_login ) {
			return;
		}

		// Validation already ran in user_profile_update_errors; re-sanitise as
		// the last defensive layer before the SQL write.
		$sanitized = sanitize_user( $raw, true );
		if ( '' === $sanitized || $sanitized !== $raw ) {
			return;
		}
		// Re-check duplicate against the live state right before the write —
		// a concurrent rename in another tab is the only realistic race here,
		// but it's cheap to cover.
		$existing = username_exists( $sanitized );
		if ( $existing && (int) $existing !== (int) $user_id ) {
			return;
		}

		global $wpdb;

		// `wp_update_user()` deliberately ignores user_login (it's documented
		// as immutable). A direct $wpdb->update is the canonical path; format
		// strings (%s, %d) provide one more layer of escaping on top of the
		// validation above.
		$updated = $wpdb->update(
			$wpdb->users,
			array( 'user_login' => $sanitized ),
			array( 'ID' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return;
		}

		// Bust the user-object + login caches so subsequent reads see the new
		// value within this request and the next.
		clean_user_cache( $user_id );

		// The WP auth cookie hashes `user_login`. After this rename every
		// existing cookie for $user_id will validate to false. Make that
		// explicit:
		//   - Self-edit: actively destroy our OTHER sessions (so the rename
		//     signs us out of every other device — the intended security
		//     property of a rename). Then re-issue our current request's
		//     cookie under the new login so the post-save redirect lands
		//     authenticated instead of bouncing to wp-login.php.
		//   - Other user: destroy ALL of the target's sessions. Whatever
		//     device they're on, they'll be signed out and must sign in again
		//     with the new name.
		if ( $user_id === get_current_user_id() ) {
			$token = wp_get_session_token();
			if ( $token ) {
				WP_Session_Tokens::get_instance( $user_id )->destroy_others( $token );
			}
			wp_clear_auth_cookie();
			wp_set_auth_cookie( $user_id, false );
		} else {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}

		/**
		 * Fires after a user's `user_login` has been renamed via this module.
		 *
		 * Use this hook to record the rename in an audit log or to update any
		 * external system that mirrors WordPress users by their login.
		 *
		 * @param int    $user_id   Affected user ID.
		 * @param string $old_login Previous user_login.
		 * @param string $new_login New user_login.
		 */
		do_action( 'adminkit/username_changed', $user_id, $old_login, $sanitized );
	}
}
