<?php
/**
 * FlyingPress integration.
 *
 * FlyingPress renders its whole admin into `<div id="app">` as a React
 * SPA styled with Tailwind. The brand intent is the Tailwind `indigo`
 * palette (indigo-600 = #4F46E5) and every surface is a hardcoded
 * Tailwind utility (`bg-white`, `text-gray-900`, `border-gray-200`, …)
 * that never flips in dark mode. admin.css routes the indigo intent to
 * AdminKit's primary and the gray surface/text/border scale to the
 * tokens, so the page inherits the design system and the dark-mode flip.
 *
 * Loaded only on the FlyingPress screen (`toplevel_page_flying-press`).
 * AdminKit's own form primitives (inputs / buttons / tables) are bailed
 * there via `adminkit/enqueue_forms` — FlyingPress ships its own
 * Tailwind input/button styling and our bare-element selectors would
 * fight its ring-based fields.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Flying_Press extends AdminKit_Integration_Base {

	/**
	 * @return string
	 */
	public static function slug() {
		return 'flying-press';
	}

	/**
	 * FlyingPress defines FLYING_PRESS_VERSION in its bootstrap.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'FLYING_PRESS_VERSION' );
	}

	/**
	 * @return string|null
	 */
	protected static function host_version() {
		return defined( 'FLYING_PRESS_VERSION' ) ? FLYING_PRESS_VERSION : null;
	}

	/**
	 * Verified against FlyingPress 5.x. Bump after re-checking the skin on a
	 * new major — register_assets() falls back to native UI until you do.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return '5.4.5';
	}

	/**
	 * FlyingPress registers a top-level menu with slug `flying-press`
	 * via `add_menu_page()`, which gives the screen ID
	 * `toplevel_page_flying-press`. The matching admin body class is
	 * what the scoped selectors in admin.css rely on.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'toplevel_page_flying-press' === $screen->id;
	}

	/**
	 * @return void
	 */
	public static function register_assets() {
		// FlyingPress remaps Tailwind utilities with !important; a new major
		// could restructure them, leaving a broken skin. Fall back to
		// FlyingPress's native UI until the override is re-verified.
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-flying-press-admin',
			'src'       => 'inc/integrations/flying-press/css/admin.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );
	}

	/**
	 * Opt out of AdminKit's form primitives on the FlyingPress screen —
	 * the React app styles its own inputs/buttons/toggles with Tailwind
	 * and our components/*.css (bare `input`/`textarea`/`select`
	 * selectors under `body.adminkit`) would double-style its fields.
	 * admin.css handles the Tailwind surfaces directly instead.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/enqueue_forms', array( __CLASS__, 'bail_forms_on_fp' ) );
	}

	/**
	 * @param bool $enqueue
	 * @return bool
	 */
	public static function bail_forms_on_fp( $enqueue ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::owns_screen( $screen ) ? false : $enqueue;
	}
}
