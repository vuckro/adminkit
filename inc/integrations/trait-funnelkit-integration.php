<?php
/**
 * Shared FunnelKit (BWF) integration wiring.
 *
 * FunnelKit Builder (`funnel-builder`) and FunnelKit Automations
 * (`wp-marketing-automations`) share the same BWF admin shell, keyboard
 * behaviors (`funnel-builder/js/shared.js`) and base CSS layers
 * (`menu.css`, `shared.css`, `controls.css`). This trait centralises
 * their hook registration, menu icon preservation and asset layering so
 * each adapter only declares its host identity and screen matchers.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

trait AdminKit_FunnelKit_Integration {

	/**
	 * FunnelKit app variant suffix (`builder` or `automations`).
	 *
	 * @return string
	 */
	abstract protected static function funnelkit_variant();

	/**
	 * Lucide icon name used when no Menu Manager override is present.
	 *
	 * @return string
	 */
	abstract protected static function funnelkit_menu_icon();

	/**
	 * Both FunnelKit hosts are verified against their 3.x branch.
	 *
	 * @return string
	 */
	protected static function max_tested_host_version() {
		return '3';
	}

	/**
	 * Wire FunnelKit body classes, keyboard enhancements and menu icon.
	 *
	 * @return void
	 */
	protected static function boot() {
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		add_filter( 'admin_body_class', array( static::class, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_behaviors' ) );
		add_filter( 'adminkit/menu_slug_icons', array( static::class, 'menu_icons' ) );
	}

	/**
	 * Supply the default Lucide sidebar icon unless overridden in Menu Manager.
	 *
	 * @param array<string, string> $icons Slug => SVG map.
	 * @return array<string, string>
	 */
	public static function menu_icons( $icons ) {
		$menu_slug = static::wordmark_menu_slug();
		foreach ( (array) ( $GLOBALS['menu'] ?? array() ) as $item ) {
			if ( isset( $item[2], $item[4] ) && $menu_slug === $item[2] && false !== strpos( $item[4], 'ak-mi-' ) ) {
				return $icons;
			}
		}
		$icons[ $menu_slug ] = AdminKit_Icons::svg( static::funnelkit_menu_icon() );
		return $icons;
	}

	/**
	 * Enqueue the shared FunnelKit keyboard/accessibility behavior script.
	 *
	 * @return void
	 */
	public static function enqueue_behaviors() {
		if ( static::owns_screen( get_current_screen() ) && apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			AdminKit_Assets::enqueue_script(
				'adminkit-funnelkit-keyboard',
				'inc/integrations/plugins/funnel-builder/js/shared.js'
			);
		}
	}

	/**
	 * Append the shared and app-specific FunnelKit body classes on owned screens.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		if ( ! static::owns_screen( get_current_screen() ) ) {
			return $classes;
		}
		return $classes . ' adminkit-funnelkit adminkit-funnelkit-' . static::funnelkit_variant();
	}

	/**
	 * Register the shared BWF menu/base/controls stylesheets around the adapter's admin.css.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( ! static::host_within_tested_range() ) {
			return;
		}
		$variant = static::funnelkit_variant();
		$prefix  = 'adminkit-funnelkit-' . $variant;

		AdminKit_Assets::register(
			array(
				'handle'  => 'adminkit-funnelkit-menu',
				'src'     => 'inc/integrations/plugins/funnel-builder/css/menu.css',
				'deps'    => array( AdminKit_Assets::TOKENS_HANDLE ),
				'context' => 'admin',
			)
		);
		AdminKit_Assets::register(
			array(
				'handle'    => $prefix . '-shared',
				'src'       => 'inc/integrations/plugins/funnel-builder/css/shared.css',
				'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
				'context'   => 'admin',
				'condition' => array( static::class, 'owns_screen' ),
			)
		);
		AdminKit_Assets::register(
			array(
				'handle'    => $prefix,
				'src'       => 'inc/integrations/plugins/' . static::slug() . '/css/admin.css',
				'deps'      => array( $prefix . '-shared' ),
				'context'   => 'admin',
				'condition' => array( static::class, 'owns_screen' ),
			)
		);
		AdminKit_Assets::register(
			array(
				'handle'    => $prefix . '-controls',
				'src'       => 'inc/integrations/plugins/funnel-builder/css/controls.css',
				'deps'      => array( $prefix ),
				'context'   => 'admin',
				'condition' => array( static::class, 'owns_screen' ),
			)
		);
	}
}
