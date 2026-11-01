<?php
/**
 * User Quick Edit — inline editing of basic user fields from users.php,
 * mirroring the Quick Edit pattern WordPress ships for posts/pages.
 *
 * On the users list, each row picks up a "Quick Edit" action. Clicking it
 * hides the row and reveals an inline form (in the same table position, like
 * the post Quick Edit) with first name, last name, email, and role. Saving
 * POSTs to admin-ajax.php which runs `wp_update_user()` and returns the new
 * field values; the JS repaints the row's visible cells (name / email / role)
 * and refreshes the data-attributes on the trigger button so the next Quick
 * Edit opens with the saved values. Display name is intentionally NOT here —
 * it's a per-user preference that belongs on user-edit.php where the full
 * dropdown of name combinations lives.
 *
 * Why this is small enough to live here:
 *  - Only the basic identity fields — anything richer stays on user-edit.php.
 *  - The role field only renders when the current user can `promote_users`.
 *  - Each row's current values ship as data-attributes on its trigger button,
 *    so the JS opens an editor with no extra round-trip.
 *  - The editor markup does NOT carry `.inline-edit-row` — that class would
 *    drag in post-Quick-Edit CSS rules (floated fieldset, narrow `.title`
 *    span) that fight our layout. We ship our own styles instead, scoped
 *    to `.adminkit-qe-*` classes.
 *
 * Security:
 *  - One nonce per row, bound to the user-id action key.
 *  - `current_user_can( 'edit_user', $target )` gates BOTH the action link
 *    and the AJAX save.
 *  - Role changes additionally require `current_user_can( 'promote_users' )`
 *    AND the requested role must exist in the WP roles registry.
 *  - Admins can't demote themselves via the role field — same guard wp-admin's
 *    own user-edit applies.
 *  - `wp_update_user()` handles email validation, sanitisation and the
 *    capability checks for editing super-admins, network admins, etc.
 *
 * Disable the whole feature via AdminKit → Features → Users quick edit; the
 * PHP `init()` then returns early and no hooks bind.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_User_Quick_Edit {

	/** Action key passed to `check_ajax_referer()` and `wp_create_nonce()`. */
	const NONCE_ACTION = 'adminkit_user_quick_edit_';

	public static function init() {
		if ( ! AdminKit_Settings::get( 'quick_edit_users_enabled' ) ) {
			return;
		}
		add_filter( 'user_row_actions', array( __CLASS__, 'add_row_action' ), 20, 2 );
		add_action( 'admin_footer-users.php', array( __CLASS__, 'print_form_template' ) );
		AdminKit_Assets::register( array(
			'handle' => 'adminkit-user-quick-edit',
			'src' => 'assets/css/wp-core/user-quick-edit.css',
			'deps' => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'admin',
			'condition' => static function ( $screen ) { return $screen && 'users' === $screen->base && current_user_can( 'list_users' ); },
		) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_adminkit_user_quick_edit', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Inject a "Quick Edit" link into the user row's hover-actions, carrying
	 * the user's current basic fields as data-attributes so the inline form
	 * can open pre-filled without a second request.
	 */
	public static function add_row_action( $actions, $user ) {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) { return $actions; }
		if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return $actions;
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION . $user->ID );
		$roles = array_values( array_filter( (array) $user->roles, 'is_string' ) );
		$role  = isset( $roles[0] ) ? $roles[0] : '';
		$multi = count( $roles ) > 1 ? '1' : '0';
		$self  = ( (int) $user->ID === (int) get_current_user_id() ) ? '1' : '0';

		// Avatar + display name ride along so the inline editor's header can
		// show "who am I editing" without a second request. Size 80 covers the
		// 40×40 render at 2x for retina screens.
		$avatar_url   = get_avatar_url( $user->ID, array( 'size' => 80 ) );
		$display_name = $user->display_name ? $user->display_name : $user->user_login;

		$btn = sprintf(
			'<button type="button" class="button-link adminkit-qe-open"'
			. ' data-user-id="%1$d" data-nonce="%2$s"'
			. ' data-first-name="%3$s" data-last-name="%4$s"'
			. ' data-email="%5$s" data-role="%6$s"'
			. ' data-multi-role="%7$s" data-self="%8$s"'
			. ' data-avatar-url="%9$s" data-display-name="%10$s"'
			. ' aria-expanded="false">%11$s</button>',
			(int) $user->ID,
			esc_attr( $nonce ),
			esc_attr( (string) $user->first_name ),
			esc_attr( (string) $user->last_name ),
			esc_attr( (string) $user->user_email ),
			esc_attr( $role ),
			esc_attr( $multi ),
			esc_attr( $self ),
			esc_attr( (string) $avatar_url ),
			esc_attr( (string) $display_name ),
			esc_html__( 'Quick Edit', 'adminkit' )
		);

		// Put Quick Edit right after Edit, before Delete — matches the order
		// post quick-edit ships with.
		$new = array();
		foreach ( $actions as $key => $value ) {
			$new[ $key ] = $value;
			if ( 'edit' === $key ) {
				$new['adminkit_quick_edit'] = $btn;
			}
		}
		if ( ! isset( $new['adminkit_quick_edit'] ) ) {
			$new['adminkit_quick_edit'] = $btn;
		}
		return $new;
	}

	/**
	 * Enqueue the Quick Edit CSS + JS on users.php only, gated by `list_users`
	 * so we never paint the script for users who can't see the list anyway.
	 * The CSS depends on the AdminKit tokens stylesheet because every colour
	 * in [user-quick-edit.css](../../assets/css/wp-core/user-quick-edit.css)
	 * references a `--ak-*` token.
	 */
	public static function enqueue( $hook ) {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) { return; }
		if ( 'users.php' !== $hook ) {
			return;
		}
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}


		AdminKit_Assets::enqueue_script(
			'adminkit-user-quick-edit',
			'assets/js/wp-core/user-quick-edit.js',
			array(),
			'window.AdminKitUserQuickEdit=' . wp_json_encode( array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'genericErr'    => __( 'Save failed.', 'adminkit' ),
				'multiRoleHint' => __( 'This user has multiple roles; edit roles on their full profile.', 'adminkit' ),
				'selfEmailHint' => __( 'Change your own email from Profile so it can be confirmed.', 'adminkit' ),
			) ) . ';'
		);
	}

	/**
	 * Render the hidden template that the JS clones in below the edited row.
	 * Lives in a `<template>` so it never paints unless JS asks for it.
	 *
	 * Output columns + colspan are computed from the actual list-table so the
	 * inline row spans the right number of cells even if a plugin added or
	 * removed columns from users.php.
	 */
	public static function print_form_template() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) { return; }
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}
		// `get_column_headers()` always returns an array of `column_id => label`.
		// `WP_Screen::get_columns()` exists too but can return non-array values
		// depending on context — that's what hit us in production (count(int)
		// fatal). The is_array() guard below is the fix's load-bearing part.
		$colspan = 6; // 6 native users.php columns (cb, username, name, email, role, posts).
		$cols    = get_column_headers( get_current_screen() );
		if ( is_array( $cols ) && $cols ) {
			$colspan = count( $cols );
		}

		$can_promote = current_user_can( 'promote_users' );
		$roles       = wp_list_pluck( get_editable_roles(), 'name' );
		?>
		<template id="adminkit-quick-edit-template">
			<tr class="adminkit-quick-edit-row">
				<td colspan="<?php echo (int) $colspan; ?>" class="colspanchange">
					<div class="adminkit-qe-wrap">

						<header class="adminkit-qe-header">
							<img class="adminkit-qe-avatar" alt="" width="40" height="40">
							<div class="adminkit-qe-identity">
								<strong class="adminkit-qe-display-name"></strong>
								<span class="adminkit-qe-email-display"></span>
							</div>
						</header>

						<fieldset class="adminkit-qe-fields">
							<legend class="screen-reader-text"><?php esc_html_e( 'Quick Edit', 'adminkit' ); ?></legend>
							<label>
								<span class="title"><?php esc_html_e( 'First name', 'adminkit' ); ?></span>
								<input type="text" class="adminkit-qe-first-name">
							</label>
							<label>
								<span class="title"><?php esc_html_e( 'Last name', 'adminkit' ); ?></span>
								<input type="text" class="adminkit-qe-last-name">
							</label>
							<label>
								<span class="title"><?php esc_html_e( 'Email', 'adminkit' ); ?></span>
								<input type="email" class="adminkit-qe-email">
							</label>
							<?php if ( $can_promote ) : ?>
								<label>
									<span class="title"><?php esc_html_e( 'Role', 'adminkit' ); ?></span>
									<select class="adminkit-qe-role">
										<?php foreach ( $roles as $key => $name ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( translate_user_role( $name ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							<?php endif; ?>
						</fieldset>

						<footer class="adminkit-qe-actions">
							<button type="button" class="button adminkit-qe-cancel"><?php esc_html_e( 'Cancel', 'adminkit' ); ?></button>
							<span class="adminkit-qe-error" role="alert" aria-live="polite"></span>
							<button type="button" class="button button-primary adminkit-qe-save"><?php esc_html_e( 'Update User', 'adminkit' ); ?></button>
						</footer>

					</div>
				</td>
			</tr>
		</template>
		<?php
	}

	/**
	 * AJAX save handler. Sanitises the posted fields, runs `wp_update_user()`,
	 * then returns the post-save field values as JSON so the JS can repaint the
	 * row's visible cells (no full re-render of the WP_Users_List_Table row).
	 *
	 * Capability checks are defence-in-depth: the row action filter already
	 * hides the entry point for users who can't edit, but the AJAX endpoint can
	 * be hit directly so we re-check here.
	 */
	public static function handle_save() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) { wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'adminkit' ) ), 403 ); }
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing user id.', 'adminkit' ) ), 400 );
		}
		check_ajax_referer( self::NONCE_ACTION . $user_id );

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'adminkit' ) ), 403 );
		}

		$existing_user = get_userdata( $user_id );
		if ( ! $existing_user ) {
			wp_send_json_error( array( 'message' => __( 'Missing user id.', 'adminkit' ) ), 404 );
		}

		$update = array( 'ID' => $user_id );
		if ( isset( $_POST['first_name'] ) ) {
			$update['first_name'] = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
		}
		if ( isset( $_POST['last_name'] ) ) {
			$update['last_name'] = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );
		}
		if ( isset( $_POST['user_email'] ) ) {
			$raw_email   = trim( sanitize_text_field( wp_unslash( $_POST['user_email'] ) ) );
			$clean_email = function_exists( 'sanitize_email' ) ? sanitize_email( $raw_email ) : $raw_email;
			if ( '' === $clean_email || ( function_exists( 'is_email' ) && ! is_email( $clean_email ) ) ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'adminkit' ) ), 400 );
			}
			$existing_email = isset( $existing_user->user_email ) ? (string) $existing_user->user_email : '';
			if ( 0 !== strcasecmp( $clean_email, $existing_email ) ) {
				if ( $user_id === get_current_user_id() ) {
					wp_send_json_error( array( 'message' => __( 'Change your own email from Profile so it can be confirmed.', 'adminkit' ) ), 400 );
				}
				if ( function_exists( 'email_exists' ) ) {
					$email_owner = email_exists( $clean_email );
					if ( $email_owner && (int) $email_owner !== $user_id ) {
						wp_send_json_error( array( 'message' => __( 'This email address is already registered.', 'adminkit' ) ), 409 );
					}
				}
				$update['user_email'] = $clean_email;
			}
		}

		// Role: only honoured when the editor can promote both globally and on the target,
		// and only when the posted role belongs to WordPress's filtered assignable set.
		if ( isset( $_POST['role'] ) && current_user_can( 'promote_users' ) ) {
			if ( ! current_user_can( 'promote_user', $user_id ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'adminkit' ) ), 403 );
			}
			$role = sanitize_key( wp_unslash( $_POST['role'] ) );
			if ( ! $role || ! array_key_exists( $role, get_editable_roles() ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'adminkit' ) ), 403 );
			}
			$existing_roles = ! empty( $existing_user->roles ) ? array_values( array_filter( (array) $existing_user->roles, 'is_string' ) ) : array();
			if ( count( $existing_roles ) > 1 ) {
				if ( $role !== $existing_roles[0] ) {
					wp_send_json_error( array( 'message' => __( 'This user has multiple roles; edit roles on their full profile.', 'adminkit' ) ), 400 );
				}
				// Unchanged primary role on a multi-role user: omit $update['role'] so set_role() never wipes secondary roles.
			} elseif ( $user_id !== get_current_user_id() ) {
				$update['role'] = $role;
			}
		}

		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		// Read the user back so the response carries authoritative values
		// (wp_update_user() may have rewritten what we sent — e.g. an email
		// normalisation) — these feed both the visible-cell repaint and the
		// data-attrs refresh on the trigger button.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_success( array() );
		}

		$post_roles  = ! empty( $user->roles ) ? array_values( array_filter( (array) $user->roles, 'is_string' ) ) : array();
		$role_key    = isset( $post_roles[0] ) ? (string) $post_roles[0] : '';
		$role_names  = wp_roles()->get_names();
		$role_labels = array();
		foreach ( $post_roles as $r_key ) {
			if ( isset( $role_names[ $r_key ] ) ) {
				$role_labels[] = translate_user_role( $role_names[ $r_key ] );
			}
		}
		$role_display = implode( ', ', $role_labels );

		$name_display = trim( $user->first_name . ' ' . $user->last_name );
		if ( '' === $name_display ) {
			$name_display = '—'; // matches the WP users list "no name" placeholder.
		}

		wp_send_json_success(
			array(
				'first_name'   => (string) $user->first_name,
				'last_name'    => (string) $user->last_name,
				'user_email'   => (string) $user->user_email,
				'role'         => $role_key,
				'role_display' => $role_display,
				'name_display' => $name_display,
			)
		);
	}
}
