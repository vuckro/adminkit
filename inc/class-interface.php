<?php
/**
 * Personal administration appearance, independent of enabled tools.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Interface {

	/** Register the site default and native personal profile field. */
	public static function init() {
		AdminKit_Settings::register( 'interface_default', array(
			'type' => 'select', 'group' => 'appearance', 'default' => 'adminkit',
			'sanitize' => array( __CLASS__, 'sanitize_default' ),
		) );
		add_filter( 'adminkit/customization_available', array( __CLASS__, 'customization_available' ) );
		add_filter( 'adminkit/appearance_enabled', array( __CLASS__, 'gate' ), 5, 2 );
		add_action( 'personal_options', array( __CLASS__, 'profile_field' ) );
		add_action( 'profile_update', array( __CLASS__, 'save_profile' ) );
	}

	/** @param mixed $value Saved site default. @return string */
	public static function sanitize_default( $value ) {
		return 'wordpress' === $value ? 'wordpress' : 'adminkit';
	}

	/** @return string Site-scoped metadata key (user accounts can span sites). */
	public static function preference_key() {
		return 'adminkit_interface_' . get_current_blog_id();
	}

	/** @return string Explicit personal choice, or inheritance. */
	public static function preference() {
		$id = get_current_user_id();
		$value = $id ? get_user_meta( $id, self::preference_key(), true ) : '';
		return in_array( $value, array( 'adminkit', 'wordpress' ), true ) ? $value : 'inherit';
	}

	/** @return string Resolved personal interface; never cached across accounts/sites. */
	public static function mode() {
		if ( ! self::theme_available() ) { return 'wordpress'; }
		$value = self::preference();
		return 'inherit' === $value ? self::sanitize_default( AdminKit_Settings::get( 'interface_default' ) ) : $value;
	}

	/** @return bool Whether the site makes the administration theme available. */
	public static function theme_available() {
		$value = AdminKit_Settings::get( 'admin_theme_enabled' );
		return null === $value || (bool) $value;
	}

	/** @param bool $available Existing veto. @return bool */
	public static function customization_available( $available = true ) {
		return $available && ( self::theme_available() || (bool) AdminKit_Settings::get( 'module_login_enabled' ) );
	}

	/**
	 * Login is site-wide; public pages are never opted in by a personal choice.
	 * @param bool   $enabled Existing presentation veto.
	 * @param string $context Asset context.
	 * @return bool
	 */
	public static function gate( $enabled, $context ) {
		if ( ! $enabled || ! apply_filters( 'adminkit/should_load', true, $context ) ) {
			return false;
		}
		if ( 'login' === $context ) {
			return (bool) AdminKit_Settings::get( 'module_login_enabled' );
		}
		if ( 'frontend' === $context && ! is_admin_bar_showing() ) {
			return false;
		}
		return 'adminkit' === self::mode();
	}

	/** @param WP_User $user Profile being rendered. */
	public static function profile_field( $user ) {
		if ( (int) $user->ID !== get_current_user_id() ) {
			return;
		}
		$value = self::preference();
		$default = self::sanitize_default( AdminKit_Settings::get( 'interface_default' ) );
		?>
		<tr class="user-adminkit-interface-wrap">
			<th><label for="adminkit-interface"><?php esc_html_e( 'Administration interface', 'adminkit' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'adminkit_interface_' . $user->ID, 'adminkit_interface_nonce' ); ?>
				<select name="adminkit_interface" id="adminkit-interface" <?php disabled( ! self::theme_available() ); ?>>
					<option value="inherit" <?php selected( $value, 'inherit' ); ?>><?php esc_html_e( 'Follow site default', 'adminkit' ); ?></option>
					<option value="adminkit" <?php selected( $value, 'adminkit' ); ?>>AdminKit</option>
					<option value="wordpress" <?php selected( $value, 'wordpress' ); ?>><?php esc_html_e( 'WordPress classic', 'adminkit' ); ?></option>
				</select>
				<?php if ( ! self::theme_available() ) : ?><p class="description"><?php esc_html_e( 'The administration theme is disabled for this site. Your personal preference is kept for reactivation.', 'adminkit' ); ?></p><?php endif; ?>
				<p class="description"><?php
					/* translators: %s: current default administration interface. */
					printf( esc_html__( 'Site default: %s. Your choice only changes appearance; enabled tools and saved settings are kept.', 'adminkit' ), esc_html( 'adminkit' === $default ? 'AdminKit' : __( 'WordPress classic', 'adminkit' ) ) );
				?></p>
			</td>
		</tr>
		<?php
	}

	/** @param int $user_id Account saved by native WordPress profile submission. */
	public static function save_profile( $user_id ) {
		if ( (int) $user_id !== get_current_user_id() || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['adminkit_interface_nonce'], $_POST['adminkit_interface'] )
			|| ! is_string( $_POST['adminkit_interface_nonce'] ) || ! is_string( $_POST['adminkit_interface'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['adminkit_interface_nonce'] ) ), 'adminkit_interface_' . $user_id ) ) {
			return;
		}
		$value = sanitize_key( wp_unslash( $_POST['adminkit_interface'] ) );
		if ( 'inherit' === $value ) {
			delete_user_meta( $user_id, self::preference_key() );
		} elseif ( in_array( $value, array( 'adminkit', 'wordpress' ), true ) ) {
			update_user_meta( $user_id, self::preference_key(), $value );
		}
	}
}
