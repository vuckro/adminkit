<?php
/**
 * User Quick Edit — inline editing of basic user fields from users.php,
 * mirroring the Quick Edit pattern WordPress ships for posts/pages.
 *
 * On the users list, each row picks up a "Quick Edit" action. Clicking it
 * collapses the row and reveals an inline form (in the same table row, like
 * the post Quick Edit) with first name, last name, email, and role. Saving
 * posts to admin-ajax.php which runs `wp_update_user()` and returns the
 * re-rendered table row HTML so the JS can swap it in without a page reload.
 *
 * Why this is small enough to live here:
 *  - 4 fields only (the basic identity info — anything else stays on user-edit.php).
 *  - The role field only renders when the current user can `promote_users`.
 *  - Each row's current values ship as data-attributes on its action button,
 *    so the JS opens an editor with no extra round-trip.
 *  - WordPress's own table-row renderer (`WP_Users_List_Table::single_row()`)
 *    re-paints the row after save — no client-side HTML rebuilding.
 *
 * Security:
 *  - One nonce per row, bound to the user-id action key.
 *  - `current_user_can( 'edit_user', $target )` gates BOTH the action link
 *    and the AJAX save.
 *  - Role changes additionally require `current_user_can( 'promote_users' )`
 *    AND the requested role must exist in the WP roles registry.
 *  - `wp_update_user()` handles email validation, sanitisation and the
 *    capability checks for editing super-admins, network admins, etc.
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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_adminkit_user_quick_edit', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Inject a "Quick Edit" link into the user row's hover-actions, carrying
	 * the user's current basic fields as data-attributes so the inline form
	 * can open pre-filled without a second request.
	 */
	public static function add_row_action( $actions, $user ) {
		if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return $actions;
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION . $user->ID );
		$roles = (array) $user->roles;
		$role  = $roles ? $roles[0] : '';

		$btn = sprintf(
			'<button type="button" class="button-link adminkit-qe-open"'
			. ' data-user-id="%1$d" data-nonce="%2$s"'
			. ' data-first-name="%3$s" data-last-name="%4$s"'
			. ' data-email="%5$s" data-role="%6$s"'
			. ' aria-expanded="false">%7$s</button>',
			(int) $user->ID,
			esc_attr( $nonce ),
			esc_attr( (string) $user->first_name ),
			esc_attr( (string) $user->last_name ),
			esc_attr( (string) $user->user_email ),
			esc_attr( $role ),
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

	public static function enqueue( $hook ) {
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
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'genericErr' => __( 'Save failed.', 'adminkit' ),
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
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}
		// Use the canonical screen-column getter when available — it returns
		// an array of `column_id => label`. `WP_Screen::get_columns()` exists
		// but can return non-array values depending on context, which is what
		// hit us in production (count(int) fatal). The `get_column_headers()`
		// helper always returns an array, with the same data, so it's safer.
		$colspan = 6; // 6 native users.php columns (cb, username, name, email, role, posts).
		if ( function_exists( 'get_column_headers' ) ) {
			$cols = get_column_headers( get_current_screen() );
			if ( is_array( $cols ) && $cols ) {
				$colspan = count( $cols );
			}
		}

		$can_promote = current_user_can( 'promote_users' );
		$roles       = wp_roles()->get_names();
		?>
		<template id="adminkit-quick-edit-template">
			<tr class="inline-edit-row inline-edit-row-user adminkit-quick-edit-row">
				<td colspan="<?php echo (int) $colspan; ?>" class="colspanchange">
					<fieldset class="inline-edit-col-left">
						<legend class="inline-edit-legend"><?php esc_html_e( 'Quick Edit', 'adminkit' ); ?></legend>
						<div class="inline-edit-col">
							<label>
								<span class="title"><?php esc_html_e( 'First name', 'adminkit' ); ?></span>
								<span class="input-text-wrap"><input type="text" name="first_name" class="ptitle adminkit-qe-first-name"></span>
							</label>
							<label>
								<span class="title"><?php esc_html_e( 'Last name', 'adminkit' ); ?></span>
								<span class="input-text-wrap"><input type="text" name="last_name" class="ptitle adminkit-qe-last-name"></span>
							</label>
							<label>
								<span class="title"><?php esc_html_e( 'Email', 'adminkit' ); ?></span>
								<span class="input-text-wrap"><input type="email" name="user_email" class="ptitle adminkit-qe-email"></span>
							</label>
							<?php if ( $can_promote ) : ?>
								<label>
									<span class="title"><?php esc_html_e( 'Role', 'adminkit' ); ?></span>
									<select name="role" class="adminkit-qe-role">
										<?php foreach ( $roles as $key => $name ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( translate_user_role( $name ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							<?php endif; ?>
						</div>
					</fieldset>
					<div class="submit inline-edit-save">
						<button type="button" class="button cancel adminkit-qe-cancel"><?php esc_html_e( 'Cancel', 'adminkit' ); ?></button>
						<button type="button" class="button button-primary save adminkit-qe-save"><?php esc_html_e( 'Update User', 'adminkit' ); ?></button>
						<span class="spinner"></span>
						<span class="adminkit-qe-error error" role="alert" aria-live="polite"></span>
					</div>
				</td>
			</tr>
		</template>
		<?php
	}

	/**
	 * AJAX save handler. Returns JSON with the re-rendered row HTML on success
	 * so the JS can swap it in without a page reload. Capability checks are
	 * defence-in-depth — the row action filter already hides the entry point
	 * for users who can't edit, but the AJAX endpoint can be hit directly.
	 */
	public static function handle_save() {
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing user id.', 'adminkit' ) ), 400 );
		}
		check_ajax_referer( self::NONCE_ACTION . $user_id );

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'adminkit' ) ), 403 );
		}

		$update = array( 'ID' => $user_id );
		if ( isset( $_POST['first_name'] ) ) {
			$update['first_name'] = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
		}
		if ( isset( $_POST['last_name'] ) ) {
			$update['last_name'] = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );
		}
		if ( isset( $_POST['user_email'] ) ) {
			$update['user_email'] = sanitize_email( wp_unslash( $_POST['user_email'] ) );
		}

		// Role: only honoured when the editor can promote, and only when the
		// posted role actually exists in WP's roles registry.
		if ( isset( $_POST['role'] ) && current_user_can( 'promote_users' ) ) {
			$role = sanitize_key( wp_unslash( $_POST['role'] ) );
			if ( $role && array_key_exists( $role, wp_roles()->get_names() ) ) {
				// Don't let an admin demote themselves accidentally — same
				// guard wp-admin's own user-edit applies.
				if ( $user_id !== get_current_user_id() ) {
					$update['role'] = $role;
				}
			}
		}

		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		// Return the new field values so the JS can repaint the cells it owns
		// (name / email / role) without us having to load the WP_List_Table
		// machinery on an AJAX request — keeps the server side dependency-free.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_success( array() );
		}

		$role_key = isset( $user->roles[0] ) ? (string) $user->roles[0] : '';
		$role_names = wp_roles()->get_names();
		$role_display = isset( $role_names[ $role_key ] )
			? translate_user_role( $role_names[ $role_key ] )
			: '';

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
