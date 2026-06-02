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
	 * FluentForm registers its top-level menu under `fluent_forms`.
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return 'fluent_forms';
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
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLUENTFORM_VERSION' ) ? FLUENTFORM_VERSION : null;
	}

	/**
	 * Verified against FluentForm 6.x. admin.css overrides the bundled
	 * Element UI's hardcoded hex by selector, which a new major could
	 * restructure — register_assets() falls back to the native UI until
	 * the skin is re-checked and this is bumped.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '6.2.2';
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
		// Element UI hex is overridden by selector; a new FluentForm major
		// could restructure it. Fall back to the native UI until re-verified.
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-fluentform-admin',
			'src'       => 'inc/integrations/plugins/fluentform/css/admin.css',
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
		// Menu icon swap runs on EVERY admin page (the menu shows everywhere),
		// like AdminKit_Core_Menu_Icons and the other Fluent adapters.
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon' ), 21 );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_ff( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}

	/**
	 * Swap FluentForm's menu icon for a document glyph.
	 *
	 * FluentForm registers its top-level menu with a base64 data-URI icon, so its
	 * `.wp-menu-image` carries no dashicon class and AdminKit_Core_Menu_Icons
	 * (keyed by dashicon) never reaches it. Drop the inline background-image and
	 * mask a document Heroicon into the icon box — same technique menu_css() uses,
	 * so it tracks the menu foreground colour in light/dark/hover/current. Inlined
	 * here so the adapter owns its own mark. Gated exactly like the other Fluent
	 * adapters: only when the icon toggle is on AND the global should_load pause
	 * hasn't disabled AdminKit. The menu shows on every admin page, so no screen gate.
	 *
	 * @return void
	 */
	public static function print_menu_icon() {
		if ( ! class_exists( 'AdminKit_Settings' ) || ! AdminKit_Settings::get( 'replace_icons_enabled' ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		if ( ! class_exists( 'AdminKit_Icons' ) ) {
			return;
		}
		// Heroicons (solid) document-text — a form is a document; fill is irrelevant
		// (painted as a mask; the visible colour is currentColor).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0 0 1-1.875-1.875V5.25A3.75 3.75 0 0 0 9 1.5H5.625ZM7.5 15a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 7.5 15Zm.75 2.25a.75.75 0 0 0 0 1.5H12a.75.75 0 0 0 0-1.5H8.25Z" clip-rule="evenodd"/><path d="M12.971 1.816A5.23 5.23 0 0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 0 1 3.434 1.279 9.768 9.768 0 0 0-6.963-6.963Z"/></svg>';
		$box  = '#adminmenu #toplevel_page_fluent_forms .wp-menu-image';
		$css  = $box . '{background-image:none !important;box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
		$css .= $box . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
			. 'vertical-align:middle;position:relative;top:-2px;' . AdminKit_Icons::mask( $svg ) . '}';
		echo '<style id="adminkit-fluentform-menu-icon">' . $css . "</style>\n"; // SVG is URL-encoded in mask().
	}
}
