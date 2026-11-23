<?php
/**
 * FluentBooking integration (`--fcal-*` semantic/legacy layers, Element Plus, TinyMCE bridge).
 *
 * Loaded only on `toplevel_page_fluent-booking`. Public booking pages remain untouched.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Booking extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-booking';
	}

	/** @return string */
	public static function label() {
		return 'FluentBooking';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'fluent-booking/fluent-booking.php', 'fluent-booking-pro/fluent-booking-pro.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENT_BOOKING_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENT_BOOKING_VERSION' ) ? FLUENT_BOOKING_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '2';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-booking' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-booking-admin',
			'src'       => 'inc/integrations/plugins/fluent-booking/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Assets::ELEMENT_PLUS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/** @return bool */
	protected static function bails_core_forms() {
		return true;
	}

	/** @return string */
	protected static function menu_icon() {
		// Heroicons solid calendar — painted as a CSS mask so foreground follows menu states.
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M6.75 2.25A.75.75 0 0 1 7.5 3v1.5h9V3a.75.75 0 0 1 1.5 0v1.5h.75a3 3 0 0 1 3 3v11.25a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3V7.5a3 3 0 0 1 3-3H6V3a.75.75 0 0 1 .75-.75Zm13.5 9a1.5 1.5 0 0 0-1.5-1.5H5.25a1.5 1.5 0 0 0-1.5 1.5v7.5a1.5 1.5 0 0 0 1.5 1.5h13.5a1.5 1.5 0 0 0 1.5-1.5v-7.5Z" clip-rule="evenodd"/></svg>';
	}

	/** @return void */
	protected static function boot() {
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ) );
	}

	/** @return void */
	public static function sync_theme() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::owns_screen( $screen ) ) {
			return;
		}
		AdminKit_Fluent_Theme::print_spa_sync( array(
			'id'              => 'adminkit-fluent-booking-theme-sync',
			'storage_key'     => 'fcal_color_mode',
			'dark_classes'    => array( 'dark', 'fluent_theme_dark' ),
			'include_wpbody'  => true,
			'editor_css'      => 'inc/integrations/plugins/fluent-booking/css/editor.css',
			'editor_style_id' => 'adminkit-booking-editor',
		) );
	}
}
