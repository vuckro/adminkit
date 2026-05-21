<?php
/**
 * Dashboard widget registry — stub.
 *
 * Future integrations (WooCommerce, FluentCart, …) can register a
 * widget to appear on the WordPress dashboard. The registry is
 * intentionally dormant: it only hooks `wp_dashboard_setup` once at
 * least one widget has been registered, so sites that don't use any
 * dashboard customisation pay zero overhead.
 *
 * Public API:
 *   AdminKit_Dashboard::register_widget( $id, $title, $callback )
 *   AdminKit_Dashboard::init()                       // call once from orchestrator
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Dashboard {

	/**
	 * Registered widgets. Each entry: array{title:string, callback:callable}.
	 *
	 * @var array<string, array>
	 */
	private static $widgets = array();

	/**
	 * Register a dashboard widget. Call BEFORE `init()` (i.e. from an
	 * integration's `boot()` method).
	 *
	 * @param string   $id       Unique widget id.
	 * @param string   $title    Human-readable title.
	 * @param callable $callback Renders the widget content.
	 * @return void
	 */
	public static function register_widget( $id, $title, $callback ) {
		if ( ! is_callable( $callback ) ) {
			return;
		}
		self::$widgets[ $id ] = array(
			'title'    => (string) $title,
			'callback' => $callback,
		);
	}

	/**
	 * Wire `wp_dashboard_setup` only if a widget was registered. No-op
	 * otherwise.
	 *
	 * @return void
	 */
	public static function init() {
		if ( empty( self::$widgets ) ) {
			return;
		}
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'mount' ) );
	}

	/**
	 * Add every registered widget to the dashboard.
	 *
	 * @return void
	 */
	public static function mount() {
		foreach ( self::$widgets as $id => $widget ) {
			wp_add_dashboard_widget( $id, $widget['title'], $widget['callback'] );
		}
	}
}
