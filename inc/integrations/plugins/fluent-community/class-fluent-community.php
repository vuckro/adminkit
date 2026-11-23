<?php
/**
 * FluentCommunity integration — ADMIN onboarding wizard only (`toplevel_page_fluent-community`).
 *
 * Frontend portal routes (`/portal`) remain out of scope and untouched.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Community extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-community';
	}

	/** @return string */
	public static function label() {
		return 'FluentCommunity';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'fluent-community/fluent-community.php', 'fluent-community-pro/fluent-community-pro.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' ) ? FLUENT_COMMUNITY_PLUGIN_VERSION : null;
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
		return $screen && 'toplevel_page_fluent-community' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-community-admin',
			'src'       => 'inc/integrations/plugins/fluent-community/css/admin.css',
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
		return 'book';
	}
}
