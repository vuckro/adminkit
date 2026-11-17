<?php
/**
 * Advanced Custom Fields (ACF / ACF Pro / SCF) Tier B integration.
 *
 * Scoped to ACF admin screens (`.acf-admin-page`) and screens enqueuing `acf-input`
 * (`fields.css`), gated on host major 6.x.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Acf extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'acf';
	}

	/** @return string */
	public static function label() {
		return 'Advanced Custom Fields (ACF)';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php', 'secure-custom-fields/secure-custom-fields.php' );
	}

	/** @return string */
	protected static function wordmark_menu_slug() {
		return 'edit.php?post_type=acf-field-group';
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'ACF_VERSION' );
	}

	/**
	 * Every ACF CPT screen (Field Groups, Post Types, Taxonomies, Options Pages) or submenu (`*_page_acf*`).
	 *
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		$types = array( 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' );
		if ( ! empty( $screen->post_type ) && in_array( $screen->post_type, $types, true ) ) {
			return true;
		}
		return false !== strpos( $screen->id, '_page_acf' );
	}

	/**
	 * Fields also appear on native posts, users and taxonomy screens.
	 *
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_input_screen( $screen ) {
		return function_exists( 'wp_style_is' ) && wp_style_is( 'acf-input', 'enqueued' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'ACF_VERSION' ) ? ACF_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '6';
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-acf-fields',
			'src'       => 'inc/integrations/plugins/acf/css/fields.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_input_screen' ),
		) );
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-acf-admin',
			'src'       => 'inc/integrations/plugins/acf/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/** @return string */
	protected static function menu_icon() {
		return 'id';
	}

	/** @return string */
	protected static function menu_icon_selector() {
		return '#adminmenu #toplevel_page_edit-post_type-acf-field-group .wp-menu-image';
	}
}
