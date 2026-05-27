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
		// Toolbar (main-document) token remap — separate stylesheet because
		// the admin bar lives outside QM's shadow root, so the panel CSS
		// injected by the shadow bridge can't reach it.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_toolbar_tokens' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_toolbar_tokens' ) );
	}

	/**
	 * Paint an AdminKit stopwatch icon on QM's admin-bar node when the icons
	 * feature is on. A stopwatch reads as "page timing" — QM's headline number in
	 * the toolbar is the page-generation time, so the glyph mirrors what the node
	 * actually shows (timing + DB query profiling) far better than a generic bug.
	 *
	 * Why a DEDICATED printer instead of the shared `adminkit/toolbar_icons` map:
	 * QM's node renders an `.ab-icon` child, but QM's own toolbar.css forces it
	 * `display:none` on desktop (≥783px) — there it shows a TEXT timing/memory
	 * `.ab-label` instead, only revealing the `.ab-icon` on mobile (≤782px). The
	 * shared map paints on `.ab-icon::before` AND is desktop-gated, so it could
	 * NEVER show in the back office (icon hidden on desktop, CSS absent on mobile).
	 *
	 * Instead we prepend the stopwatch glyph on the link's own `> .ab-item::before`
	 * — the link is always visible (it carries the timing label), so the icon sits
	 * just before that label. NOT desktop-gated, so it paints in every context; it
	 * never touches QM's `.ab-icon` or its timing display, so nothing breaks. Zero
	 * `!important` (keeps this Tier A adapter clean): a 2-id selector on a
	 * `::before` QM/WP-core don't otherwise set, so a plain rule wins.
	 *
	 * @return void
	 */
	protected static function boot() {
		add_action( 'admin_head', array( __CLASS__, 'print_toolbar_icon' ), 21 );
		add_action( 'wp_head', array( __CLASS__, 'print_toolbar_icon' ), 21 );
	}

	/**
	 * Echo the QM toolbar-icon CSS (see boot() for the rationale). Gated exactly
	 * like AdminKit_Core_Menu_Icons: only when the icons feature is on AND the
	 * global should_load pause hasn't disabled AdminKit for this context; on the
	 * front end, only when the admin bar is actually showing.
	 *
	 * @return void
	 */
	public static function print_toolbar_icon() {
		$context = is_admin() ? 'admin' : 'frontend';
		if ( 'frontend' === $context && ! is_admin_bar_showing() ) {
			return;
		}
		if ( ! AdminKit_Settings::get( 'replace_icons_enabled' ) ) {
			return;
		}
		if ( ! apply_filters( 'adminkit/should_load', true, $context ) ) {
			return;
		}

		// Stopwatch / timer glyph (Heroicons-style solid clock) — Query Monitor's
		// headline toolbar number is the page-generation time, so a timer reads far
		// more clearly than a generic bug as "performance / timing". The fill is
		// irrelevant — it's a mask; the visible colour is currentColor (the toolbar
		// foreground). The clock face + hands give the unmistakable "timing" read.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25Zm.75 4.5a.75.75 0 0 0-1.5 0v5.25c0 .199.079.39.22.53l3.182 3.182a.75.75 0 0 0 1.06-1.06l-2.962-2.963V6.75Z" clip-rule="evenodd"/></svg>';
		$uri = 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';

		// Model the floated ::before as exactly the 32px bar (padding 6 + 20 + 6 = 32)
		// and centre a 20px mask in it, just before QM's timing label. Mirrors
		// AdminKit_Core_Menu_Icons::toolbar_ab_item_css(), but NOT wrapped in a
		// desktop media query, so it shows in every context.
		$sel = '#wpadminbar #wp-admin-bar-query-monitor > .ab-item::before';
		$css = $sel . '{content:"";box-sizing:border-box;float:left;height:32px;width:20px;'
			. 'padding:6px 0;margin-right:6px;position:static;top:auto;'
			. 'background-color:currentColor;'
			. '-webkit-mask:' . $uri . ' center/20px 20px no-repeat;'
			. 'mask:' . $uri . ' center/20px 20px no-repeat}';

		echo '<style id="adminkit-qm-icon">' . $css . "</style>\n"; // SVG is URL-encoded.
	}

	/**
	 * Enqueue the toolbar token remap (admin-bar #wpadminbar QM rows). Gated
	 * the same way the shadow bridge is: in the admin, always; on the front
	 * end, only when the admin bar is showing.
	 *
	 * @return void
	 */
	public static function enqueue_toolbar_tokens() {
		if ( ! is_admin() && ! is_admin_bar_showing() ) {
			return;
		}
		$css_rel  = 'inc/integrations/plugins/query-monitor/css/toolbar.css';
		$css_path = ADMINKIT_PATH . $css_rel;
		wp_enqueue_style(
			'adminkit-query-monitor-toolbar',
			ADMINKIT_URL . $css_rel,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ADMINKIT_VERSION
		);
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
