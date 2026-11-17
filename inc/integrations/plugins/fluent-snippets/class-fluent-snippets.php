<?php
/**
 * FluentSnippets (`easy-code-manager`) integration — Vue 3 SPA on Element Plus + CodeMirror 6 container.
 *
 * Loaded only on `toplevel_page_fluent-snippets`; CodeMirror syntax tokens remain theme-owned.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluent_Snippets extends AdminKit_Integration_Base {

	/** @return string */
	public static function slug() {
		return 'fluent-snippets';
	}

	/** @return string */
	public static function label() {
		return 'FluentSnippets';
	}

	/** @return string[] */
	public static function host_files() {
		return array( 'easy-code-manager/easy-code-manager.php' );
	}

	/** @return bool */
	public static function is_active() {
		return defined( 'FLUENT_SNIPPETS_PLUGIN_VERSION' );
	}

	/** @return string|null */
	protected static function host_version() {
		return defined( 'FLUENT_SNIPPETS_PLUGIN_VERSION' ) ? FLUENT_SNIPPETS_PLUGIN_VERSION : null;
	}

	/** @return string|null */
	protected static function max_tested_host_version() {
		return '11.0';
	}

	/**
	 * @param \WP_Screen|null $screen Screen object.
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_fluent-snippets' === $screen->id;
	}

	/** @return void */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluent-snippets-admin',
			'src'       => 'inc/integrations/plugins/fluent-snippets/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Assets::ELEMENT_PLUS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/** @return bool */
	protected static function bails_core_forms() {
		return true;
	}
}
