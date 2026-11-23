<?php
/**
 * AdminKit integration adapter for FluentBoards and its embedded FluentCRM widget.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Boards extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-boards';
	}

	/** @return string */
	public static function label() {
		return 'FluentBoards';
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENT_BOARDS_PLUGIN_VERSION' );
	}

	/** @return string[] */
	public static function host_files() {
		return array(
			'fluent-boards/fluent-boards.php',
			'fluent-boards-pro/fluent-boards-pro.php',
		);
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENT_BOARDS_PLUGIN_VERSION' ) ? FLUENT_BOARDS_PLUGIN_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '2';
	}

	/**
	 * @param mixed $screen Current WordPress screen.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		if ( ! $screen instanceof WP_Screen ) {
			return false;
		}
		$screen_id = (string) $screen->id;
		return 'toplevel_page_fluent-boards' === $screen_id
			|| '_page_fluent-boards' === substr( $screen_id, -19 );
	}

	/**
	 * Load CSS on standalone FluentBoards screens and the CRM contact task widget.
	 *
	 * @return bool
	 */
	public static function should_load_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::owns_screen( $screen ) ) {
			return true;
		}
		return class_exists( 'AdminKit_Integration_Fluent_Crm' )
			&& AdminKit_Integration_Fluent_Crm::owns_screen( $screen );
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-boards-admin',
			'src'       => 'inc/integrations/plugins/fluent-boards/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Assets::ELEMENT_PLUS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'should_load_styles' ),
		) );
	}

	/** @return bool */
	protected static function bails_core_forms() {
		return true;
	}

	/** @return string */
	protected static function menu_icon() {
		return 'square-kanban';
	}

	/** @return void */
	protected static function boot() {
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_head', array( __CLASS__, 'sync_theme' ) );
	}

	/**
	 * Append integration marker class to admin body.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::owns_screen( $screen ) ) {
			$classes .= ' ak-fluent-boards';
		}
		return $classes;
	}

	/** @return void */
	public static function sync_theme() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::owns_screen( $screen ) ) {
			return;
		}
		AdminKit_Fluent_Theme::print_spa_sync( array(
			'id'             => 'adminkit-fluent-boards-theme-sync',
			'storage_key'    => 'fluent_theme_mode',
			'dark_classes'   => array( 'fluent_theme_dark', 'fluent_boards_theme_dark', 'fbs-dark' ),
			'light_classes'  => array( 'fbs-light' ),
			'include_wpbody' => true,
			'custom_event'   => 'onFbsThemeChange',
			'editor_css'     => 'inc/integrations/plugins/fluent-boards/css/editor.css',
			'editor_style_id' => 'adminkit-fluent-boards-editor',
		) );
	}
}
