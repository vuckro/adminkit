<?php
/**
 * Query Monitor integration — Tier A adapter, delivered into QM's shadow root.
 *
 * QM 4.0 renders its panel inside an OPEN SHADOW ROOT (host element
 * #query-monitor-container). CSS selectors don't cross a shadow boundary, so a
 * normally-enqueued stylesheet can never match #query-monitor-main — which is
 * why a plain `body.adminkit #query-monitor-main {…}` rule does nothing here.
 *
 * CSS *custom properties* DO inherit across the boundary, though. So the actual
 * theming (css/admin.css) just re-points QM's --qm-* vars at AdminKit --ak-*
 * tokens scoped to #query-monitor-main; the --ak-* values resolve via
 * inheritance from the main document's :root and flip with AdminKit's dark mode
 * for free. The only trick is GETTING that stylesheet inside the shadow root:
 * js/shadow-bridge.js waits for the host's shadowRoot and appends css/admin.css
 * as a <link>, then mirrors AdminKit's light/dark mode onto the panel's
 * data-theme so QM's own color-scheme (native scrollbars + the SQL value tint
 * left on QM's light-dark()) follows too. One-way only (AdminKit → QM).
 *
 * The admin-bar toolbar (assets/build/toolbar.css) is intentionally left on
 * QM's native colours.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Query_Monitor extends AdminKit_Integration_Base {

	public static function slug() {
		return 'query-monitor';
	}

	/**
	 * QM defines QM_VERSION in its bootstrap (query-monitor.php).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'QM_VERSION' );
	}

	/**
	 * The token remap can't ride the AdminKit asset registry: that enqueues
	 * into the main document, which QM's shadow-DOM panel never sees. Instead
	 * hook a tiny bridge script onto the admin enqueue pass; it injects the CSS
	 * into the shadow root itself. QM prints on every admin page, so no screen
	 * condition — the script no-ops unless the QM host is present.
	 *
	 * @return void
	 */
	public static function register_assets() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_shadow_bridge' ) );
	}

	/**
	 * Enqueue the shadow-root bridge and hand it the CSS URL (mtime-stamped so
	 * edits skip the browser cache, matching AdminKit_Assets::do_enqueue()).
	 *
	 * @return void
	 */
	public static function enqueue_shadow_bridge() {
		$js_rel   = 'inc/integrations/query-monitor/js/shadow-bridge.js';
		$css_rel  = 'inc/integrations/query-monitor/css/admin.css';
		$js_path  = ADMINKIT_PATH . $js_rel;
		$css_path = ADMINKIT_PATH . $css_rel;
		$handle   = 'adminkit-query-monitor';

		wp_enqueue_script(
			$handle,
			ADMINKIT_URL . $js_rel,
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : ADMINKIT_VERSION,
			true
		);
		wp_localize_script( $handle, 'adminkitQM', array(
			'cssUrl' => add_query_arg(
				'ver',
				file_exists( $css_path ) ? (string) filemtime( $css_path ) : ADMINKIT_VERSION,
				ADMINKIT_URL . $css_rel
			),
		) );
	}
}
