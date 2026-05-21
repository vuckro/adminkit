<?php
/**
 * Base class for AdminKit integrations.
 *
 * Each integration lives in its own folder under `inc/integrations/{slug}/`
 * with a `class-{slug}.php` file declaring a class that extends this one.
 * The plugin orchestrator auto-discovers those files via glob and queues
 * `maybe_init()` on `after_setup_theme`.
 *
 * Contract for subclasses:
 *   - `slug()`            REQUIRED — short identifier (e.g. `woocommerce`).
 *   - `is_active()`       REQUIRED — return true when the host plugin/theme
 *                                    is loaded (e.g. `class_exists( 'WooCommerce' )`).
 *   - `owns_screen()`     OPTIONAL — return true on screens the integration
 *                                    cares about (used as the closure body of
 *                                    asset-registry `condition` entries).
 *   - `register_assets()` OPTIONAL — call `AdminKit_Assets::register()` for
 *                                    each CSS file shipped by the integration.
 *   - `boot()`            OPTIONAL — add filters/actions for runtime behavior
 *                                    that isn't asset-related (e.g. Bricks's
 *                                    `should_load` bypass).
 *
 * `maybe_init()` is concrete and orchestrates `is_active → register_assets → boot`.
 * Subclasses should NOT override `maybe_init()` unless they truly need to.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

abstract class AdminKit_Integration_Base {

	/**
	 * Short identifier — used in CSS handles, log lines, debug output.
	 *
	 * @return string
	 */
	abstract public static function slug();

	/**
	 * Whether the host plugin/theme is active. Called inside
	 * `maybe_init()` on `after_setup_theme`, so plugin constants and
	 * classes are guaranteed to be loaded by the time it runs.
	 *
	 * @return bool
	 */
	abstract public static function is_active();

	/**
	 * Whether the integration owns the given screen — drives the
	 * conditional-loading closure in the asset registry.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return false;
	}

	/**
	 * Register CSS/JS with the asset registry. Override and call
	 * `AdminKit_Assets::register( [ ... ] )` for each asset.
	 *
	 * @return void
	 */
	public static function register_assets() {}

	/**
	 * Wire any non-asset behavior (filters, actions). Default: no-op.
	 *
	 * @return void
	 */
	protected static function boot() {}

	/**
	 * Entry point — orchestrates the lifecycle. Called on
	 * `after_setup_theme` by the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function maybe_init() {
		if ( ! static::is_active() ) {
			return;
		}
		static::register_assets();
		static::boot();
	}
}
