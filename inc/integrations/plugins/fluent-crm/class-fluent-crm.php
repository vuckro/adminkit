<?php
/**
 * FluentCRM integration — variable-driven Vue 3 SPA (`--fc-*` + Element Plus).
 *
 * Loaded only on `toplevel_page_fluentcrm-admin` (plus adminbar search styles).
 * Covers `fluentcampaign-pro` screens hosted on the same mount.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Crm extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-crm';
	}

	/** @return string */
	public static function label() {
		return 'FluentCRM';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'fluent-crm/fluent-crm.php', 'fluentcampaign-pro/fluentcampaign-pro.php' );
	}

	/** @return string */
	protected static function wordmark_menu_slug() {
		return 'fluentcrm-admin';
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENTCRM_PLUGIN_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENTCRM_PLUGIN_VERSION' ) ? FLUENTCRM_PLUGIN_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '3.3.1';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluentcrm-admin' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'  => 'adminkit-fluent-crm-search',
			'src'     => 'inc/integrations/plugins/fluent-crm/css/adminbar-search.css',
			'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context' => 'admin',
		) );
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-crm-admin',
			'src'       => 'inc/integrations/plugins/fluent-crm/css/admin.css',
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
		return 'megaphone';
	}
}
