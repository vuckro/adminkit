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
	 * hook a tiny bridge script onto the enqueue pass; it injects the CSS into
	 * the shadow root itself. QM renders on every admin page AND on the frontend
	 * for logged-in users (alongside the admin bar), so hook both contexts — no
	 * screen condition; the script no-ops unless QM's host is present.
	 *
	 * @return void
	 */
	public static function register_assets() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_shadow_bridge' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_shadow_bridge' ) );
	}

	/**
	 * Register an AdminKit toolbar icon for QM's admin-bar node when the icons
	 * feature is on (the icon CSS only prints then, so no extra gating here).
	 *
	 * CAVEAT: QM hides its own `.ab-icon` on desktop (≥783px) — there it shows a
	 * TEXT timing/memory label instead, and only reveals the icon on mobile
	 * (≤782px). AdminKit's toolbar icon CSS is itself desktop-only
	 * (`@media min-width:783px`), so this mapping is effectively a no-op on the
	 * surfaces AdminKit paints: registered for completeness/consistency, but QM's
	 * desktop label is deliberately left untouched.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_filter( 'adminkit/toolbar_icons', array( __CLASS__, 'toolbar_icons' ) );
	}

	/**
	 * QM's admin-bar node id is `wp-admin-bar-query-monitor`. Map a gauge/activity
	 * glyph onto it (raw SVG markup — the filter value is used as a CSS mask, so
	 * the fill colour is irrelevant). It renders an `.ab-icon` child, so the
	 * default `.ab-icon` selector form in AdminKit_Core_Menu_Icons applies.
	 *
	 * @param array<string,string> $map
	 * @return array<string,string>
	 */
	public static function toolbar_icons( $map ) {
		$map['wp-admin-bar-query-monitor'] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M2.25 13.5a9.75 9.75 0 0 1 19.5 0c0 .76-.09 1.51-.25 2.23a1.5 1.5 0 0 1-1.46 1.17H4.46A1.5 1.5 0 0 1 3 15.73a9.8 9.8 0 0 1-.75-2.23Zm14.4-4.16a.75.75 0 0 1 0 1.06l-2.6 2.6a2.25 2.25 0 1 1-1.06-1.06l2.6-2.6a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/></svg>';
		return $map;
	}

	/**
	 * Enqueue the shadow-root bridge and hand it the CSS URL (mtime-stamped so
	 * edits skip the browser cache, matching AdminKit_Assets::do_enqueue()).
	 *
	 * @return void
	 */
	public static function enqueue_shadow_bridge() {
		// QM only paints its frontend panel when the admin bar is showing (a
		// logged-in user who can view it) — the same gate AdminKit uses to load
		// its frontend tokens, so --ak-* are present to inherit into the shadow.
		// Skip public / logged-out page views entirely.
		if ( ! is_admin() && ! is_admin_bar_showing() ) {
			return;
		}

		$js_rel   = 'inc/integrations/plugins/query-monitor/js/shadow-bridge.js';
		$css_rel  = 'inc/integrations/plugins/query-monitor/css/admin.css';
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
