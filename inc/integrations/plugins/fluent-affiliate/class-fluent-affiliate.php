<?php
/**
 * FluentAffiliate integration (Vue 3 SPA on Element Plus; `--fla-*` and `--el-*` tokens).
 *
 * Loaded only on `toplevel_page_fluent-affiliate`. Version-gated as Tier B because
 * compiled host CSS bakes Element Plus defaults and focal border literals.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Affiliate extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-affiliate';
	}

	/** @return string */
	public static function label() {
		return 'FluentAffiliate';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'fluent-affiliate/fluent-affiliate.php', 'fluent-affiliate-pro/fluent-affiliate-pro.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENT_AFFILIATE_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENT_AFFILIATE_VERSION' ) ? FLUENT_AFFILIATE_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '1';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-affiliate' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-affiliate-admin',
			'src'       => 'inc/integrations/plugins/fluent-affiliate/css/admin.css',
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
		return 'money';
	}
}
