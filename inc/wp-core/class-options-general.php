<?php
/**
 * AdminKit — options-general.php: split into themed blocks.
 *
 * WP renders every general setting (Site Title, Tagline, Admin Email,
 * Membership, Language, Date/time, …) into ONE long `.form-table`. That's
 * fine to scan top-to-bottom but the reader has no visual cue that
 * "Membership" is conceptually separate from "Site Title". This module
 * enqueues a small footer script that moves the `<tr>`s into three
 * themed sub-cards (Site identity / Account & registration / Locale)
 * for readability. Form submission is unchanged — every input keeps its
 * `name=` so options.php receives the same POST.
 *
 * The behaviour lives in `assets/js/wp-screens/options-general.js`; the
 * card chrome reuses the existing `.form-table` polish from
 * `assets/css/wp-screens/options.css`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Options_General {

	const PENDING_CLASS = 'ak-options-pending';
	const READY_CLASS   = 'ak-options-ready';

	/**
	 * Hook the enqueue. Called once from the orchestrator.
	 *
	 * @return void
	 */
	const LOGO_FIELD_NAME = 'adminkit_general_logo_url';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_branding' ) );
		add_action( 'admin_action_update', array( __CLASS__, 'save_branding' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_prepaint' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Keep identity editing alongside the native site icon, without registering the full settings option in options.php. */
	public static function register_branding() {
		global $pagenow;
		if ( 'options-general.php' !== $pagenow
			|| ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		add_settings_field( 'ak_branding', __( 'Administration logo', 'adminkit' ), array( __CLASS__, 'branding_field' ), 'general' );
	}

	/**
	 * Persist ONLY the `logo_url` key when Settings → General is submitted,
	 * avoiding a full-array `register_setting('general', 'adminkit_settings')`
	 * write that could clobber concurrent AdminKit option keys.
	 *
	 * @return void
	 */
	public static function save_branding() {
		global $pagenow;
		if ( 'options.php' !== $pagenow
			|| ! isset( $_POST['option_page'] )
			|| 'general' !== $_POST['option_page']
			|| ! isset( $_POST[ self::LOGO_FIELD_NAME ] )
			|| ! is_string( $_POST[ self::LOGO_FIELD_NAME ] )
			|| ! apply_filters( 'adminkit/should_load', true, 'admin' )
			|| ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'general-options' );

		$raw    = trim( (string) wp_unslash( $_POST[ self::LOGO_FIELD_NAME ] ) );
		$clean  = '' === $raw ? '' : esc_url_raw( $raw, array( 'http', 'https' ) );
		$stored = get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		if ( '' === $clean ) {
			unset( $stored['logo_url'] );
		} else {
			$stored['logo_url'] = $clean;
		}
		update_option( AdminKit_Settings::OPTION_KEY, $stored, true );
	}

	/** Back-compat sanitizer kept callable in case external code invokes it directly. */
	public static function sanitize_branding( $input ) {
		$stored = (array) get_option( AdminKit_Settings::OPTION_KEY, array() );
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) { return $stored; }
		if ( isset( $input['logo_url'] ) && is_string( $input['logo_url'] ) ) {
			$clean = esc_url_raw( trim( $input['logo_url'] ), array( 'http', 'https' ) );
			if ( '' === $clean ) {
				unset( $stored['logo_url'] );
			} else {
				$stored['logo_url'] = $clean;
			}
		}
		return $stored;
	}

	public static function branding_field() {
		$url   = AdminKit_Settings::brand_logo( 'light' );
		$nonce = ( current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' ) && function_exists( 'wp_create_nonce' ) )
			? wp_create_nonce( AdminKit_Core_Branding::LOGO_UPLOAD_NONCE )
			: '';
		?>
		<div class="ak-general-logo">
			<img id="ak-general-logo-preview" class="<?php echo esc_attr( AdminKit_Settings::dynamic_logo_class() ); ?>" <?php if ( $url ) : ?>src="<?php echo esc_url( $url ); ?>"<?php endif; ?> alt="" <?php echo $url ? '' : 'hidden'; ?> />
			<p><button type="button" class="button" id="ak-general-logo-upload" data-upload-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Upload image', 'adminkit' ); ?></button> <button type="button" class="button" id="ak-general-logo-remove"><?php esc_html_e( 'Remove', 'adminkit' ); ?></button></p>
			<div class="ak-general-logo__url">
			<label for="ak-general-logo-url"><?php esc_html_e( 'Image URL', 'adminkit' ); ?></label>
			<input type="url" id="ak-general-logo-url" name="<?php echo esc_attr( self::LOGO_FIELD_NAME ); ?>" value="<?php echo esc_url( $url ); ?>" />
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the current request should receive the tabbed/carded enhancement.
	 *
	 * Shared by the pre-paint bootstrap and footer script so they never drift.
	 *
	 * @return bool
	 */
	private static function should_enhance() {
		return AdminKit_Screen::is_one_of( array( 'options-general' ) )
			&& (bool) apply_filters( 'adminkit/should_load', true, 'admin' );
	}

	/**
	 * Print the anti-FOUC pre-paint bootstrap.
	 *
	 * The builder runs in the footer, after the first possible paint. Add a
	 * short-lived marker to <html> from the head so CSS can hide the raw one-table
	 * layout until options-general.js has grouped it into cards.
	 *
	 * @return void
	 */
	public static function print_prepaint() {
		if ( ! self::should_enhance() ) {
			return;
		}
		AdminKit_Assets::print_prepaint( 'adminkit-options-general-prepaint', self::PENDING_CLASS, self::READY_CLASS );
	}

	/**
	 * Enqueue the split-into-blocks script on `options-general.php` only,
	 * with the section titles riding along as an inline bootstrap.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_enhance() ) {
			return;
		}

		wp_enqueue_media();
		$strings = array(
			'logo' => __( 'Administration logo', 'adminkit' ),
			'logoDesc' => __( 'Shown with the expanded menu. The site icon takes over when the menu is collapsed.', 'adminkit' ),
			// Top-level navigation and card headings.
			'tabGeneral'   => __( 'Identity', 'adminkit' ),
			'tabAccount'   => __( 'Account', 'adminkit' ),
			'tabLocale'    => __( 'Localization', 'adminkit' ),
			'tabOther'     => __( 'Other settings', 'adminkit' ),
			'languageZone' => __( 'Language & timezone', 'adminkit' ),
			'languageZoneDesc' => __( 'Choose the site language and the timezone used for publishing.', 'adminkit' ),
			'formats' => __( 'Date & time formats', 'adminkit' ),
			'formatsDesc' => __( 'Choose how dates and times appear, and the first day of the week.', 'adminkit' ),
			// Block titles + one-line lede shown above each card.
			'identity'     => __( 'Site identity', 'adminkit' ),
			'identityDesc' => __( 'How your site presents itself — name, tagline and addresses.', 'adminkit' ),
			'siteIcon'     => __( 'Site Icon', 'adminkit' ),
			'siteIconDesc' => __( 'Shown in browser tabs, bookmark lists and the app icon when visitors save your site to a phone home screen.', 'adminkit' ),
			'account'      => __( 'Account & registration', 'adminkit' ),
			'accountDesc'  => __( 'Admin email, who can sign up, and the default role assigned to new users.', 'adminkit' ),
			'locale'       => __( 'Language, date & time', 'adminkit' ),
			'localeDesc'   => __( 'Site language plus the timezone and formats used wherever dates appear.', 'adminkit' ),
		);

		AdminKit_Assets::enqueue_script(
			'adminkit-options-general',
			'assets/js/wp-screens/options-general.js',
			array(),
			'window.AdminKitOptionsGeneral=' . wp_json_encode( $strings ) . ';'
		);
	}
}
