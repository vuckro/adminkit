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
	 * Paint an AdminKit bug icon on QM's admin-bar node when the icons feature
	 * is on.
	 *
	 * Why a DEDICATED printer instead of the shared `adminkit/toolbar_icons` map:
	 * QM's node renders an `.ab-icon` child, but QM's own toolbar.css forces it
	 * `display:none` on desktop (≥783px) — there it shows a TEXT timing/memory
	 * `.ab-label` instead, only revealing the `.ab-icon` on mobile (≤782px). The
	 * shared map paints on `.ab-icon::before` AND is desktop-gated, so it could
	 * NEVER show in the back office (icon hidden on desktop, CSS absent on mobile).
	 *
	 * Instead we prepend the bug glyph on the link's own `> .ab-item::before` — the
	 * link is always visible (it carries the timing label), so the icon sits just
	 * before that label. NOT desktop-gated, so it paints in every context; it never
	 * touches QM's `.ab-icon` or its timing display, so nothing breaks. Zero
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

		// Bug glyph (Heroicons-style solid) — Query Monitor is a debugging tool. The
		// fill is irrelevant — it's a mask; the visible colour is currentColor (the
		// toolbar foreground).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#000"><path fill-rule="evenodd" d="M8.478 1.6a.75.75 0 0 1 .273 1.024 3.72 3.72 0 0 0-.425 1.122c.058.057.118.114.18.168A4.491 4.491 0 0 1 12 4.5c1.413 0 2.687.65 3.514 1.667.06-.054.12-.111.178-.168a3.717 3.717 0 0 0-.425-1.123.75.75 0 1 1 1.296-.752 5.23 5.23 0 0 1 .717 2.483c0 .386-.273.744-.674.79-.643.073-1.27.207-1.875.396-.069.408-.193.798-.366 1.16.421.5.74 1.087.929 1.728.336-.092.687-.156 1.046-.19a.75.75 0 1 1 .14 1.493 7.51 7.51 0 0 0-1.026.214c.013.169.02.34.02.512v.5a7.5 7.5 0 0 0 1.052.211.75.75 0 1 1-.14 1.494 8.974 8.974 0 0 1-1.135-.226 4.502 4.502 0 0 1-3.085 3.117l-.001.077v.493c.398.058.787.146 1.166.262a.75.75 0 0 1-.434 1.435 7.516 7.516 0 0 0-.97-.221 4.494 4.494 0 0 1-1.193 2.084.75.75 0 0 1-1.06 0 4.494 4.494 0 0 1-1.193-2.084 7.512 7.512 0 0 0-.97.221.75.75 0 0 1-.434-1.435c.379-.116.768-.204 1.166-.262v-.493l-.002-.077a4.502 4.502 0 0 1-3.084-3.117 8.97 8.97 0 0 1-1.135.226.75.75 0 1 1-.14-1.494c.357-.034.706-.097 1.052-.211v-.5c0-.172.007-.343.02-.512a7.51 7.51 0 0 0-1.026-.214.75.75 0 1 1 .14-1.494c.359.034.71.098 1.046.19.189-.64.508-1.228.93-1.728a4.493 4.493 0 0 1-.367-1.16 7.476 7.476 0 0 0-1.875-.395.75.75 0 0 1-.674-.79 5.23 5.23 0 0 1 .717-2.484.75.75 0 0 1 1.022-.272Z" clip-rule="evenodd"/></svg>';
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
