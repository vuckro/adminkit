<?php
/**
 * Base class for AdminKit integrations.
 *
 * Each integration lives in its own folder under
 * `inc/integrations/{plugins|themes}/{slug}/` with a `class-{slug}.php` file
 * declaring a class that extends this one.
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
 *   - `host_version()` + `max_tested_host_version()`
 *                        OPTIONAL — Tier B adapters (those overriding the
 *                                    host's own selectors) declare these so a
 *                                    new host major degrades to the host's
 *                                    native UI instead of a broken skin.
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
	 * The host plugin's current version string, or null when unknown.
	 * Override alongside max_tested_host_version() in version-gated adapters.
	 *
	 * @return string|null
	 */
	protected static function host_version() {
		return null;
	}

	/**
	 * Highest host version this integration's CSS has been verified against,
	 * or null for no upper bound.
	 *
	 * Tier B adapters — the ones that override the host's own selectors
	 * (hardcoded hex, deep component classes) instead of just remapping its
	 * CSS variables — set this. When the host ships a new MAJOR version those
	 * targeted selectors may have been renamed, which makes the override
	 * silently miss and lets the host's original colors bleed back. Gating on
	 * it degrades that case to the host's clean native UI instead. Pure
	 * variable-remap adapters (Tier A) rarely break, so they leave it null.
	 *
	 * @return string|null
	 */
	protected static function max_tested_host_version() {
		return null;
	}

	/**
	 * Whether the host's MAJOR version is within the tested range. True when
	 * either bound is unset (the common case). Comparing majors only keeps the
	 * skin on across the host's minor/patch updates and falls back only when a
	 * new major — the release most likely to reshuffle classes — lands.
	 *
	 * @return bool
	 */
	protected static function host_within_tested_range() {
		$max = static::max_tested_host_version();
		$cur = static::host_version();
		if ( null === $max || null === $cur ) {
			return true;
		}
		return (int) explode( '.', $cur )[0] <= (int) explode( '.', $max )[0];
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
	 * Entry point — orchestrates the lifecycle. Called on `after_setup_theme` by
	 * the plugin orchestrator. Runs only when the host is active AND the
	 * integration is enabled — the latter via `adminkit/integration_enabled`
	 * (wired to the per-integration toggle on Settings → Plugins). So a user can
	 * switch off AdminKit's adapter for a specific host without touching the host.
	 *
	 * @return void
	 */
	public static function maybe_init() {
		if ( ! static::is_active() ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/integration_enabled', true, static::slug() ) ) {
			return;
		}
		static::register_assets();
		static::boot();
	}
}
