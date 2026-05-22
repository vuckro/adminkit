<?php
/**
 * Fluent SMTP integration.
 *
 * Fluent SMTP renders its settings UI as a Vue SPA bundling Element UI
 * 2.x, which hardcodes blue (#409eff) for primary state + canary white
 * surfaces for cards and panels. Route the most visible surfaces and
 * Element UI states through AdminKit tokens. Loaded only on the
 * settings_page_fluent-mail screen.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Smtp extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluent-smtp';
	}

	/**
	 * Fluent SMTP defines `FLUENTMAIL_PLUGIN_FILE` in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENTMAIL_PLUGIN_FILE' );
	}

	/**
	 * Fluent SMTP registers under Settings → Fluent SMTP via
	 * `add_options_page( ..., 'fluent-mail', ... )`, which gives the
	 * screen ID `settings_page_fluent-mail`.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'settings_page_fluent-mail' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-smtp-admin',
			'src'       => 'inc/integrations/fluent-smtp/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}
}
