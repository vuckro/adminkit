<?php
/**
 * FluentForm integration.
 *
 * FluentForm's admin is a Vue SPA built on a bundled (older) Element
 * UI that hardcodes every color as hex — there are no CSS variables
 * to remap, so admin.css overrides each surface by selector and
 * routes the brand blue (#1a7efb) to AdminKit's primary.
 *
 * The CSS loads only on FluentForm screens (owns_screen). AdminKit's
 * own form primitives (inputs / buttons / tables) are bailed on these
 * screens via `adminkit/enqueue_forms` so they don't fight Element
 * UI's input/button styling.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Fluentform extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'fluentform';
	}

	/**
	 * FluentForm defines FLUENTFORM_VERSION in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLUENTFORM_VERSION' );
	}

	/**
	 * Match every FluentForm admin screen. The top-level menu slug is
	 * `fluent_forms` (screen `toplevel_page_fluent_forms`); all
	 * sub-pages keep the `fluent_forms` slug fragment in their id
	 * (e.g. `fluent-forms_page_fluent_forms_settings`), so a single
	 * substring check covers Forms, Entries, Settings, Payments,
	 * Add-ons, Transfer, SMTP and Docs.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && false !== strpos( $screen->id, 'fluent_forms' );
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluentform-admin',
			'src'       => 'inc/integrations/fluentform/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on FluentForm screens —
	 * Element UI ships its own input/button/table styling and our
	 * components/*.css would double-style its widgets. Our admin.css
	 * handles the Element UI surfaces directly instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_ff' ) );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_ff( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}
}
