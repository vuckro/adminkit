<?php
/**
 * Custom dashboard — replaces the native wp-admin dashboard (index.php) with a
 * self-contained, server-rendered AdminKit dashboard: a greeting, quick-action
 * buttons and a 2-column area — recent content (left, preview cards) and a right
 * rail (overview counters, site health + to-dos, storage). It renders its OWN
 * markup inside one full-width dashboard widget (the Settings SPA owns its markup
 * the same way) — NOT a fragile repaint of native DOM.
 *
 * Reversible: when the feature toggle is OFF, init() returns early and the native
 * dashboard renders untouched.
 *
 * Data is real wherever WordPress exposes it (post/page/comment/user counts, recent
 * posts + drafts + comments, HTTPS / PHP / updates checks, and the real Media +
 * Database sizes). The three things WP has no generic source for are FILTERS that
 * hide gracefully — no invented numbers:
 *   adminkit/dashboard/enabled        (bool)    master on/off
 *   adminkit/dashboard/quick_actions  (array)   the quick-action buttons
 *   adminkit/dashboard/activity       (array)   recent-activity rows
 *   adminkit/dashboard/site_health    (array)   the health card data (score, badge, checks)
 *   adminkit/dashboard/storage        (array)   storage segments (hosts can add a "backups" row)
 *   adminkit/dashboard/storage_total  (int)     total quota in bytes (0 = no quota → no bar/percent)
 *
 * Expensive reads (uploads-dir size, DB size, health checks) are cached in a 12h
 * transient. Behaviour: server-rendered; the only JS is a tiny header clock tick.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Custom_Dashboard {

	/** Per-user meta storing the dashboard card layout (column → ordered keys). */
	const LAYOUT_META = 'adminkit_dash_layout';

	/** Nonce + AJAX action name for saving the per-user card layout. */
	const LAYOUT_ACTION = 'adminkit_dash_layout';

	/** Per-user meta storing the set of HIDDEN card keys (visibility, independent of order). */
	const HIDDEN_META = 'adminkit_dash_hidden';

	/** Nonce + AJAX action name for saving the per-user card visibility. */
	const HIDDEN_ACTION = 'adminkit_dash_visibility';

	/** Nonce + AJAX action name for rendering one card (Customize re-show, no reload). */
	const CARD_ACTION = 'adminkit_dash_card';

	/**
	 * Register the setting + wire the dashboard replacement. The setting is
	 * registered unconditionally so the Settings page can discover it while off.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'custom_dashboard_enabled', array( 'default' => true ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Fires only on the dashboard, after core + plugins have registered their
		// widgets (default priority 10) — priority 20 lets us clear them.
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'replace_widgets' ), 20 );

		// The right-rail "Site preview" iframe loads the home with ?ak-preview so the
		// front-end admin bar is dropped from the thumbnail (a clean public view).
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_in_preview' ) );

		// Per-user drag-to-arrange: the sortable on the dashboard + the layout saver.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::LAYOUT_ACTION, array( __CLASS__, 'ajax_save_layout' ) );
		// Per-user card visibility (the "Customize" panel).
		add_action( 'wp_ajax_' . self::HIDDEN_ACTION, array( __CLASS__, 'ajax_save_visibility' ) );
		add_action( 'wp_ajax_' . self::CARD_ACTION, array( __CLASS__, 'ajax_render_card' ) );
	}

	/**
	 * Master switch — registered setting (default ON) through a filter.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/dashboard/enabled', AdminKit_Settings::get( 'custom_dashboard_enabled' ) );
	}

	/**
	 * Clear every dashboard widget (core + plugin) and mount one full-width
	 * AdminKit widget. CSS strips the postbox chrome + hides the native page H1
	 * (the greeting replaces it). Off ⇒ this never runs ⇒ native dashboard.
	 *
	 * @return void
	 */
	public static function replace_widgets() {
		// Remove every registered dashboard meta box (all contexts) so only ours
		// shows — a clean replacement, not a custom widget bolted onto the stock set.
		$GLOBALS['wp_meta_boxes']['dashboard'] = array();

		// Drop WP's "Welcome" panel too (we own the greeting).
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		wp_add_dashboard_widget(
			'adminkit_dashboard',
			esc_html__( 'Dashboard', 'adminkit' ), // hidden via CSS; kept for screen readers
			array( __CLASS__, 'render' )
		);
	}

	/* ───────────────────────── render ───────────────────────── */

	/**
	 * Output the whole dashboard. Markup is escaped at each leaf; inline SVGs are
	 * author-controlled.
	 *
	 * @return void
	 */
	public static function render() {
		echo '<div class="ak-dash">';
		AdminKit_Dashboard_Cards::render_header();
		AdminKit_Dashboard_Cards::render_actions();
		self::render_grid();
		echo '</div>'; // .ak-dash
	}

	/**
	 * Build the "Customize" control + its card-visibility panel — a per-user on/off
	 * for each dashboard card, complementing the drag-to-arrange order. Returns the
	 * markup (rendered inside the quick-actions row so it shares the button shape);
	 * dashboard.js wires the open/close + change behaviour. One labelled checkbox per
	 * known card, in layout order, checked = visible.
	 *
	 * @return string Safe HTML — escaped leaves + author-controlled inline SVG.
	 */
	private static function tools_html() {
		$cards  = self::grid_cards();
		$layout = self::layout();
		$hidden = self::hidden_cards();

		// All known cards in the user's column order, de-duplicated, with any card
		// missing from the saved layout appended (so nothing is ever unlistable).
		$ordered = array();
		foreach ( array( 'main', 'side' ) as $col ) {
			foreach ( $layout[ $col ] as $key ) {
				if ( isset( $cards[ $key ] ) && ! isset( $ordered[ $key ] ) ) {
					$ordered[ $key ] = $cards[ $key ];
				}
			}
		}
		foreach ( $cards as $key => $def ) {
			if ( ! isset( $ordered[ $key ] ) ) {
				$ordered[ $key ] = $def;
			}
		}

		$gear = '<svg class="ak-dash-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';

		$html  = '<div class="ak-dash__tools" data-ak-dash-tools>';
		$html .= sprintf(
			'<button type="button" class="ak-btn ak-dash__customize" data-ak-customize aria-expanded="false" aria-haspopup="true">%s<span>%s</span></button>',
			$gear,
			esc_html__( 'Customize', 'adminkit' )
		);

		$html .= '<div class="ak-dash__panel" data-ak-panel hidden>';
		$html .= '<p class="ak-dash__panel-h">' . esc_html__( 'Cards to display', 'adminkit' ) . '</p>';
		foreach ( $ordered as $key => $def ) {
			$label = isset( $def['label'] ) ? $def['label'] : $key;
			$html .= sprintf(
				'<label class="ak-dash__panel-row"><input type="checkbox" data-ak-card-toggle="%1$s"%2$s /><span>%3$s</span></label>',
				esc_attr( $key ),
				isset( $hidden[ $key ] ) ? '' : ' checked',
				esc_html( $label )
			);
		}
		$html .= '</div>'; // .ak-dash__panel
		$html .= '</div>'; // .ak-dash__tools
		return $html;
	}

	/* ───────────────── drag-to-arrange (per-user card layout) ─────────────────
	 * The grid cards are emitted from a keyed registry in the order the current
	 * user arranged them (saved per-user, like WP's native meta boxes). This is the
	 * seam the future "choose which cards to show" setting plugs into: curate
	 * grid_cards() (or its filter) and the layout + persistence below keep working.
	 */

	/**
	 * The reorderable grid cards: key => { cb: callable, col: default column }.
	 * `key` is the stable identity persisted in the layout; `col` ('main'|'side')
	 * is where a card falls back before the user arranges anything (or when a new
	 * card ships in an update). Filterable so an integration — or a future setting
	 * — can curate the set.
	 *
	 * @return array<string,array{cb:callable,col:string}>
	 */
	private static function grid_cards() {
		$cards = array(
			'glance'   => array( 'cb' => array( 'AdminKit_Dashboard_Cards', 'render_glance' ),   'col' => 'main', 'label' => __( 'At a glance', 'adminkit' ) ),
			'health'   => array( 'cb' => array( 'AdminKit_Dashboard_Cards', 'render_health' ),   'col' => 'main', 'label' => __( 'Site health', 'adminkit' ) ),
			'content'  => array( 'cb' => array( 'AdminKit_Dashboard_Cards', 'render_content' ),  'col' => 'main', 'label' => __( 'Recent changes', 'adminkit' ) ),
			'storage'  => array( 'cb' => array( 'AdminKit_Dashboard_Cards', 'render_storage' ),  'col' => 'main', 'label' => __( 'Storage', 'adminkit' ) ),
			'preview'  => array( 'cb' => array( 'AdminKit_Dashboard_Cards', 'render_preview' ),  'col' => 'side', 'label' => __( 'Site preview', 'adminkit' ) ),
			'adminkit' => array( 'cb' => array( 'AdminKit_Dashboard_Cards', 'render_adminkit' ), 'col' => 'side', 'label' => __( 'AdminKit', 'adminkit' ) ),
			'online'   => array( 'cb' => array( 'AdminKit_Dashboard_Cards', 'render_online' ),   'col' => 'side', 'label' => __( 'Online', 'adminkit' ) ),
		);
		return (array) apply_filters( 'adminkit/dashboard/cards', $cards );
	}

	/**
	 * The canonical default layout — the column + order a fresh install shows,
	 * BEFORE the user drags anything. This is the single source of truth for
	 * default placement: it positions filter-added cards too (e.g. 'stats',
	 * which the Statistics module appends via the cards filter and which would
	 * otherwise fall to the end), and it can place a card in a different column
	 * than its registry `col` fallback (e.g. 'glance' shows in the side rail).
	 *
	 * A card present in grid_cards() but NOT listed here (a third-party addition)
	 * falls back to its registry `col`, appended after the canonical ones.
	 * Filterable so an integration can re-curate the first-run arrangement.
	 *
	 * @return array{main:string[],side:string[]}
	 */
	private static function default_order() {
		return (array) apply_filters( 'adminkit/dashboard/default_order', array(
			'main' => array( 'stats', 'health', 'content', 'storage' ),
			'side' => array( 'preview', 'glance', 'adminkit', 'online' ),
		) );
	}

	/**
	 * The card order per column for the current user — the saved arrangement
	 * reconciled with the live card set: unknown keys dropped, any card not yet
	 * placed appended to its default column (so a card added in an update still
	 * appears, and a removed one disappears cleanly). Always returns both columns.
	 *
	 * @return array{main:string[],side:string[]}
	 */
	private static function layout() {
		$cards = self::grid_cards();

		// Default column + order: the canonical layout first (it also positions
		// filter-added cards like 'stats'), then any card not listed there falls
		// back to its registry `col`, appended after.
		$canon   = self::default_order();
		$default = array( 'main' => array(), 'side' => array() );
		$seen    = array();
		foreach ( array( 'main', 'side' ) as $col ) {
			if ( empty( $canon[ $col ] ) || ! is_array( $canon[ $col ] ) ) {
				continue;
			}
			foreach ( $canon[ $col ] as $key ) {
				if ( isset( $cards[ $key ] ) && empty( $seen[ $key ] ) ) {
					$default[ $col ][] = $key;
					$seen[ $key ]      = true;
				}
			}
		}
		foreach ( $cards as $key => $def ) {
			if ( ! empty( $seen[ $key ] ) ) {
				continue;
			}
			$col               = ( isset( $def['col'] ) && 'side' === $def['col'] ) ? 'side' : 'main';
			$default[ $col ][] = $key;
		}

		$saved = get_user_meta( get_current_user_id(), self::LAYOUT_META, true );
		$saved = is_array( $saved ) ? $saved : array();

		$cols   = array( 'main' => array(), 'side' => array() );
		$placed = array();

		// 1. The order the user explicitly arranged (honouring cross-column moves).
		foreach ( array( 'main', 'side' ) as $col ) {
			if ( empty( $saved[ $col ] ) || ! is_array( $saved[ $col ] ) ) {
				continue;
			}
			foreach ( $saved[ $col ] as $key ) {
				if ( isset( $cards[ $key ] ) && empty( $placed[ $key ] ) ) {
					$cols[ $col ][] = $key;
					$placed[ $key ] = true;
				}
			}
		}

		// 2. Cards the user never arranged (e.g. one shipped in an update, like the
		//    glance band) land at their default position — right after the last
		//    default-predecessor already in that column — not dumped at the end.
		foreach ( array( 'main', 'side' ) as $col ) {
			foreach ( $default[ $col ] as $i => $key ) {
				if ( ! empty( $placed[ $key ] ) ) {
					continue;
				}
				$pos = 0;
				for ( $j = 0; $j < $i; $j++ ) {
					$idx = array_search( $default[ $col ][ $j ], $cols[ $col ], true );
					if ( false !== $idx ) {
						$pos = $idx + 1;
					}
				}
				array_splice( $cols[ $col ], $pos, 0, array( $key ) );
				$placed[ $key ] = true;
			}
		}

		return $cols;
	}

	/**
	 * The 2-column card grid, emitted in the user's saved order. Each card's markup
	 * is captured and wrapped in a sortable item carrying its key; a card that
	 * renders nothing (disabled / no capability) is skipped, leaving no empty slot.
	 * With JS off it is a normal, static dashboard (progressive enhancement).
	 *
	 * @return void
	 */
	private static function render_grid() {
		$cards  = self::grid_cards();
		$layout = self::layout();
		$hidden = self::hidden_cards();

		echo '<div class="ak-dash__grid" data-ak-dash-grid>';
		foreach ( array( 'main', 'side' ) as $col ) {
			printf( '<div class="ak-dash__col ak-dash__col--%1$s" data-ak-col="%1$s">', esc_attr( $col ) );
			foreach ( $layout[ $col ] as $key ) {
				// Hidden cards are skipped BEFORE the render call — a card the user
				// turned off costs nothing (no callback, no DB reads). Re-showing one
				// fetches its markup over AJAX (ajax_render_card), no page reload.
				if ( isset( $hidden[ $key ] ) ) {
					continue;
				}
				if ( empty( $cards[ $key ]['cb'] ) || ! is_callable( $cards[ $key ]['cb'] ) ) {
					continue;
				}
				ob_start();
				call_user_func( $cards[ $key ]['cb'] );
				$html = trim( (string) ob_get_clean() );
				if ( '' === $html ) {
					continue; // card produced nothing (disabled / no capability)
				}
				printf( '<div class="ak-dash__card-wrap" data-ak-card="%s">', esc_attr( $key ) );
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each card's markup is escaped at its own leaves.
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Load the drag-to-arrange sortable on the dashboard only, with a small boot
	 * (ajax URL + nonce + action + label). Footer script, so the grid is already in
	 * the DOM when it runs.
	 *
	 * @param string $hook
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}
		$data = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'action'       => self::LAYOUT_ACTION,
			'nonce'        => wp_create_nonce( self::LAYOUT_ACTION ),
			'hiddenAction' => self::HIDDEN_ACTION,
			'hiddenNonce'  => wp_create_nonce( self::HIDDEN_ACTION ),
			'cardAction'   => self::CARD_ACTION,
			'cardNonce'    => wp_create_nonce( self::CARD_ACTION ),
			'i18n'         => array( 'reorder' => __( 'Drag to reorder this card', 'adminkit' ) ),
		);
		// Stats card: the body-render AJAX (range selector + Customize re-show).
		if ( class_exists( 'AdminKit_Stats_Dashboard' ) ) {
			$data['statsAction'] = AdminKit_Stats_Dashboard::RENDER_ACTION;
			$data['statsNonce']  = wp_create_nonce( AdminKit_Stats_Dashboard::RENDER_ACTION );
		}
		$boot = 'window.AdminKitDash=' . wp_json_encode( $data ) . ';';
		AdminKit_Assets::enqueue_script( 'adminkit-dashboard', 'assets/js/wp-screens/dashboard.js', array(), $boot );
	}

	/**
	 * Persist the current user's card layout. Accepts a JSON `layout` of
	 * { main:[keys], side:[keys] }; keys are sanitised and intersected with the
	 * known card set (each kept once) so only a valid layout is ever stored.
	 *
	 * @return void
	 */
	public static function ajax_save_layout() {
		check_ajax_referer( self::LAYOUT_ACTION );
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$known = array_keys( self::grid_cards() );
		$raw   = isset( $_POST['layout'] ) ? sanitize_text_field( wp_unslash( $_POST['layout'] ) ) : '';
		$in    = json_decode( $raw, true );

		$clean = array( 'main' => array(), 'side' => array() );
		$seen  = array();
		if ( is_array( $in ) ) {
			foreach ( array( 'main', 'side' ) as $col ) {
				if ( empty( $in[ $col ] ) || ! is_array( $in[ $col ] ) ) {
					continue;
				}
				foreach ( $in[ $col ] as $key ) {
					$key = sanitize_key( $key );
					if ( in_array( $key, $known, true ) && empty( $seen[ $key ] ) ) {
						$clean[ $col ][] = $key;
						$seen[ $key ]    = true;
					}
				}
			}
		}

		update_user_meta( get_current_user_id(), self::LAYOUT_META, $clean );
		wp_send_json_success();
	}

	/**
	 * The set of hidden card keys for the current user, as a key => true map (O(1)
	 * lookup), filtered to known cards so a stale key never hides nothing or breaks.
	 *
	 * @return array<string,true>
	 */
	private static function hidden_cards() {
		$saved = get_user_meta( get_current_user_id(), self::HIDDEN_META, true );
		if ( ! is_array( $saved ) ) {
			return array();
		}
		$known = array_keys( self::grid_cards() );
		$out   = array();
		foreach ( $saved as $key ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, $known, true ) ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * Persist the current user's hidden-card set. Accepts a JSON `hidden` array of
	 * card keys; keys are sanitised and intersected with the known set (each kept
	 * once) so only a valid set is ever stored.
	 *
	 * @return void
	 */
	public static function ajax_save_visibility() {
		check_ajax_referer( self::HIDDEN_ACTION );
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$known  = array_keys( self::grid_cards() );
		$raw    = isset( $_POST['hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['hidden'] ) ) : '';
		$in     = json_decode( $raw, true );
		$hidden = array();
		if ( is_array( $in ) ) {
			foreach ( $in as $key ) {
				$key = sanitize_key( $key );
				if ( in_array( $key, $known, true ) && ! in_array( $key, $hidden, true ) ) {
					$hidden[] = $key;
				}
			}
		}

		update_user_meta( get_current_user_id(), self::HIDDEN_META, $hidden );
		wp_send_json_success();
	}

	/**
	 * Render a SINGLE card's wrapper markup for the given key — used by the Customize
	 * panel to re-show a card without a full page reload. Returns the same
	 * `<div class="ak-dash__card-wrap" …>` the grid emits, so the JS can drop it
	 * straight into a column. Empty when the key is unknown or the card renders
	 * nothing (no capability).
	 *
	 * @return void
	 */
	public static function ajax_render_card() {
		check_ajax_referer( self::CARD_ACTION );
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$key   = isset( $_POST['card'] ) ? sanitize_key( wp_unslash( $_POST['card'] ) ) : '';
		$cards = self::grid_cards();
		if ( '' === $key || empty( $cards[ $key ]['cb'] ) || ! is_callable( $cards[ $key ]['cb'] ) ) {
			wp_send_json_error( 'unknown_card', 400 );
		}

		ob_start();
		call_user_func( $cards[ $key ]['cb'] );
		$html = trim( (string) ob_get_clean() );
		if ( '' === $html ) {
			wp_send_json_success( array( 'html' => '', 'col' => self::card_default_col( $key ) ) );
		}

		wp_send_json_success( array(
			'html' => '<div class="ak-dash__card-wrap" data-ak-card="' . esc_attr( $key ) . '">' . $html . '</div>',
			'col'  => self::card_default_col( $key ),
		) );
	}

	/**
	 * The default column ('main'|'side') a card falls into — where a re-shown card
	 * should land if it isn't already placed in the saved layout.
	 *
	 * @param string $key
	 * @return string
	 */
	private static function card_default_col( $key ) {
		// Honour the canonical layout first (so a re-shown card returns to the
		// same column it ships in), then the registry `col` fallback.
		$canon = self::default_order();
		if ( in_array( $key, isset( $canon['side'] ) ? $canon['side'] : array(), true ) ) {
			return 'side';
		}
		if ( in_array( $key, isset( $canon['main'] ) ? $canon['main'] : array(), true ) ) {
			return 'main';
		}
		$cards = self::grid_cards();
		return ( isset( $cards[ $key ]['col'] ) && 'side' === $cards[ $key ]['col'] ) ? 'side' : 'main';
	}

	/** Greeting + a rotating line — the page's visible heading. Both pick fresh on
	    every load (was a stable daily pick) so the dashboard feels alive. */
	/**
	 * Drop the front-end admin bar for the site-preview iframe only (it requests the
	 * home with ?ak-preview), so the thumbnail shows the public view, not your toolbar.
	 *
	 * @param bool $show
	 * @return bool
	 */
	public static function hide_admin_bar_in_preview( $show ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle, no state change.
		return isset( $_GET['ak-preview'] ) ? false : $show;
	}

}
