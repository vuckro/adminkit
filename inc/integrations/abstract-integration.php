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
	 * The top-level admin-menu slug whose TITLE feeds the white-label wordmark.
	 * Defaults to slug(); override when the host registers its menu under a
	 * different slug (e.g. FluentCRM uses `fluentcrm-admin`, FluentSMTP the
	 * submenu `fluent-mail`, ACF the CPT `edit.php?post_type=acf-field-group`).
	 *
	 * @return string
	 */
	protected static function wordmark_menu_slug() {
		return static::slug();
	}

	/**
	 * Expose the plugin's WP admin-menu TITLE as a CSS custom property
	 * (`--ak-wordmark`), scoped to the current screen body.
	 *
	 * Adapters that white-label a host's in-app logo render a text wordmark via
	 * `::after { content: var( --ak-wordmark, "Fallback" ) }`. By sourcing that
	 * text from the live menu title (here) instead of hard-coding it, the wordmark
	 * automatically tracks the menu name — so if the user later renames the menu
	 * item (a menu editor, another plugin, a translation), the in-app logo follows.
	 * The CSS fallback keeps it working when the title can't be resolved.
	 *
	 * Auto-hooked on `admin_head` by maybe_init() for every integration; it no-ops
	 * off the owned screen, so it costs nothing where there's no wordmark. Looks in
	 * both the top-level `$menu` and the `$submenu` map so submenu- and CPT-rooted
	 * hosts resolve too.
	 *
	 * @return void
	 */
	public static function print_menu_wordmark() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! static::owns_screen( $screen ) ) {
			return;
		}
		$slug  = static::wordmark_menu_slug();
		$title = '';
		// Top-level items: [ menu_title, cap, menu_slug, ... ].
		if ( ! empty( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ) {
			foreach ( $GLOBALS['menu'] as $item ) {
				if ( isset( $item[2], $item[0] ) && $item[2] === $slug ) {
					$title = (string) $item[0];
					break;
				}
			}
		}
		// Submenu items: $submenu[ parent_slug ] = [ [ menu_title, cap, menu_slug ], … ].
		if ( '' === $title && ! empty( $GLOBALS['submenu'] ) && is_array( $GLOBALS['submenu'] ) ) {
			foreach ( $GLOBALS['submenu'] as $children ) {
				foreach ( (array) $children as $item ) {
					if ( isset( $item[2], $item[0] ) && $item[2] === $slug ) {
						$title = (string) $item[0];
						break 2;
					}
				}
			}
		}
		// Drop update-count bubbles / any markup, collapse whitespace.
		$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $title ) ) );
		if ( '' === $title ) {
			return;
		}
		// CSS string escape (backslash + double-quote). wp_strip_all_tags already
		// removed every "<", so a </style> break-out is impossible.
		$value = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $title );
		// Set the var on body.adminkit (no screen class needed): the <style> is
		// printed ONLY on the owned screen (gated above), so it's already scoped,
		// and CSS custom properties inherit down to each adapter's logo ::after
		// regardless of how that rule is scoped.
		printf(
			'<style id="adminkit-%1$s-wordmark">body.adminkit{--ak-wordmark:"%2$s"}</style>' . "\n",
			esc_attr( static::slug() ),
			$value // CSS-string-escaped above; no markup possible.
		);
	}

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
		// Expose the menu title as --ak-wordmark on the owned screen, so any
		// white-label logo (content: var(--ak-wordmark, …)) tracks the menu name.
		add_action( 'admin_head', array( static::class, 'print_menu_wordmark' ) );
	}
}
