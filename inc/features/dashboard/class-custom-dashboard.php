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
		self::render_header();
		self::render_actions();
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
			'glance'   => array( 'cb' => array( __CLASS__, 'render_glance' ),   'col' => 'main', 'label' => __( 'At a glance', 'adminkit' ) ),
			'health'   => array( 'cb' => array( __CLASS__, 'render_health' ),   'col' => 'main', 'label' => __( 'Site health', 'adminkit' ) ),
			'content'  => array( 'cb' => array( __CLASS__, 'render_content' ),  'col' => 'main', 'label' => __( 'Recent changes', 'adminkit' ) ),
			'storage'  => array( 'cb' => array( __CLASS__, 'render_storage' ),  'col' => 'main', 'label' => __( 'Storage', 'adminkit' ) ),
			'preview'  => array( 'cb' => array( __CLASS__, 'render_preview' ),  'col' => 'side', 'label' => __( 'Site preview', 'adminkit' ) ),
			'adminkit' => array( 'cb' => array( __CLASS__, 'render_adminkit' ), 'col' => 'side', 'label' => __( 'AdminKit', 'adminkit' ) ),
			'online'   => array( 'cb' => array( __CLASS__, 'render_online' ),   'col' => 'side', 'label' => __( 'Online', 'adminkit' ) ),
		);
		return (array) apply_filters( 'adminkit/dashboard/cards', $cards );
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

		// Default column + order, straight from the registry.
		$default = array( 'main' => array(), 'side' => array() );
		foreach ( $cards as $key => $def ) {
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
		$cards = self::grid_cards();
		return ( isset( $cards[ $key ]['col'] ) && 'side' === $cards[ $key ]['col'] ) ? 'side' : 'main';
	}

	/** Greeting + a rotating line — the page's visible heading. Both pick fresh on
	    every load (was a stable daily pick) so the dashboard feels alive. */
	private static function render_header() {
		$user      = wp_get_current_user();
		$name      = $user->first_name ? $user->first_name : $user->display_name;
		$name_html = '<span class="ak-dash__greet-name">' . esc_html( $name ) . '</span>';

		// Quick "online now" line under the clock (fills the otherwise-empty top-right).
		$online = '';
		if ( class_exists( 'AdminKit_Online_Users' ) && AdminKit_Online_Users::is_enabled() && current_user_can( 'list_users' ) ) {
			$n = (int) AdminKit_Online_Users::online_count();
			if ( $n > 0 ) {
				$online = sprintf(
					'<a class="ak-dash__head-online" href="%1$s"><span class="ak-dash__head-online-dot"></span>%2$s</a>',
					esc_url( admin_url( 'users.php?orderby=adminkit_last_login&order=desc' ) ),
					/* translators: %s: number of users online now. */
					esc_html( sprintf( _n( '%s online now', '%s online now', $n, 'adminkit' ), number_format_i18n( $n ) ) )
				);
			}
		}

		printf(
			'<div class="ak-dash__head"><div class="ak-dash__greeting"><a class="ak-dash__avatar" href="%6$s" aria-label="%7$s" title="%7$s">%4$s</a>'
				. '<div class="ak-dash__head-text"><h1 class="ak-dash__greet">%1$s</h1>'
				. '<p class="ak-dash__sub">%2$s</p></div></div>'
				. '<div class="ak-dash__head-aside">%3$s%5$s</div></div>',
			self::greeting( $name_html ), // %1 safe HTML: escaped template + the name span
			esc_html( self::subtitle() ), // %2
			self::head_clock(),           // %3 safe HTML: escaped date/time + a static tick script
			get_avatar( $user->ID, 56 ),  // %4 safe: core-escaped <img>
			$online,                      // %5 safe: built above
			esc_url( get_edit_profile_url( $user->ID ) ), // %6 the avatar links to the profile now
			esc_attr__( 'Edit my profile', 'adminkit' )   // %7 accessible name for the photo link
		);
	}

	/**
	 * A small date + live clock for the header's top-right corner. The date and the
	 * initial time are server-rendered (site format + locale) so it reads correctly
	 * with JS off; a tiny inline script then keeps the time ticking — and refines the
	 * date — in the viewer's own locale. No enqueue, no dependency, no stored state.
	 *
	 * @return string Safe HTML.
	 */
	private static function head_clock() {
		$locale = str_replace( '_', '-', get_locale() );
		$tz     = wp_timezone_string();                                   // "Europe/Paris" or "+02:00"
		$fmt_t  = (string) get_option( 'time_format' );
		$is12h  = (bool) preg_match( '/[aAgh]/', $fmt_t );                // 12-hour clock?
		$offset = (int) round( (float) get_option( 'gmt_offset' ) * 60 ); // minutes east of UTC
		$date   = date_i18n( (string) get_option( 'date_format' ) );      // site timezone + format
		$time   = date_i18n( $fmt_t );                                    // site timezone + format

		$inner = '<span class="ak-dash__clock-time" data-ak-clock-time>' . esc_html( $time ) . '</span>'
			. '<span class="ak-dash__clock-date" data-ak-clock-date>' . esc_html( $date ) . '</span>';
		$data  = ' data-ak-locale="' . esc_attr( $locale ) . '" data-ak-tz="' . esc_attr( $tz ) . '"'
			. ' data-ak-off="' . esc_attr( (string) $offset ) . '" data-ak-12="' . ( $is12h ? '1' : '0' ) . '"';

		// Admins: the clock is a shortcut to the site's Language / Timezone / date-time settings.
		if ( current_user_can( 'manage_options' ) ) {
			$box = '<a class="ak-dash__clock" href="' . esc_url( admin_url( 'options-general.php' ) ) . '#locale"'
				. $data . ' title="' . esc_attr__( 'Language, date & time settings', 'adminkit' ) . '">' . $inner . '</a>';
		} else {
			$box = '<div class="ak-dash__clock"' . $data . '>' . $inner . '</div>';
		}

		// Progressive enhancement: tick the time in the SITE's timezone + 12/24h format (the
		// server values above are already site-correct). Named zones tick via toLocaleTimeString;
		// bare UTC offsets are computed by hand. The date stays server-rendered (site format).
		$js = "<script>(function(){var b=document.querySelector('.ak-dash__clock');if(!b)return;"
			. "var loc=b.getAttribute('data-ak-locale')||undefined,tz=b.getAttribute('data-ak-tz'),"
			. "off=parseInt(b.getAttribute('data-ak-off')||'0',10),h12=b.getAttribute('data-ak-12')==='1',"
			. "t=b.querySelector('[data-ak-clock-time]');"
			. "function f(){try{var o={hour:h12?'numeric':'2-digit',minute:'2-digit',hour12:h12},n;"
			. "if(tz&&/[A-Za-z]/.test(tz)){o.timeZone=tz;n=new Date();}"
			. "else{o.timeZone='UTC';n=new Date(Date.now()+off*60000);}"
			. "if(t)t.textContent=n.toLocaleTimeString(loc,o);}catch(e){}}"
			. "f();setInterval(f,10000);})();</script>";

		return $box . $js;
	}

	/**
	 * The description line — a short, refined quote or tip, picked fresh on every
	 * load (was a stable daily pick) so it varies as you come and go. No stored
	 * state. Filterable so a site can supply its own set.
	 *
	 * @return string
	 */
	private static function subtitle() {
		$h = (int) current_time( 'G' );

		// A few time-appropriate openers to set the mood.
		if ( $h >= 5 && $h < 12 ) {
			$slot = array(
				__( 'A fresh start — pick one thing to ship today.', 'adminkit' ),
				__( 'Morning is a great time to plan and publish.', 'adminkit' ),
				__( 'Coffee first, then a quick win.', 'adminkit' ),
			);
		} elseif ( $h >= 12 && $h < 18 ) {
			$slot = array(
				__( 'Keep the momentum — small steps add up.', 'adminkit' ),
				__( 'A good afternoon to refine and improve.', 'adminkit' ),
			);
		} elseif ( $h >= 18 && $h < 22 ) {
			$slot = array(
				__( 'Wrapping up? A quick backup is a good habit.', 'adminkit' ),
				__( 'Evening is a good time for a calm review.', 'adminkit' ),
			);
		} else {
			$slot = array(
				__( 'Late-night focus — remember to save often.', 'adminkit' ),
				__( 'Quiet hours are good for deep work.', 'adminkit' ),
			);
		}

		// Useful, always-relevant tips plus a few timeless lines for warmth.
		$tips = array(
			__( 'Back up before big changes — future you will thank you.', 'adminkit' ),
			__( 'Strong passwords and two-factor auth keep your account safe.', 'adminkit' ),
			__( 'Keep plugins and themes updated to stay secure and fast.', 'adminkit' ),
			__( 'Compress images before upload for faster pages.', 'adminkit' ),
			__( 'Add alt text to images — better SEO and accessibility.', 'adminkit' ),
			__( 'Fewer plugins, fewer surprises — keep only what you use.', 'adminkit' ),
			__( 'Most visitors are on mobile — preview your pages there.', 'adminkit' ),
			__( 'Internal links help readers and search engines explore.', 'adminkit' ),
			__( 'A clear menu helps visitors find what matters.', 'adminkit' ),
			__( 'Descriptive titles win clicks and rankings.', 'adminkit' ),
			__( 'Test your contact form now and then.', 'adminkit' ),
			__( 'Fresh content keeps your visitors coming back.', 'adminkit' ),
			__( 'A fast site is a friendly site — mind your page weight.', 'adminkit' ),
			__( 'Deactivate and delete plugins you no longer use.', 'adminkit' ),
			__( 'Set a featured image — it shapes how posts are shared.', 'adminkit' ),
			__( 'Simplicity is the ultimate sophistication. — Leonardo da Vinci', 'adminkit' ),
			__( 'Good design is as little design as possible. — Dieter Rams', 'adminkit' ),
			__( 'Done is better than perfect — publish, then refine.', 'adminkit' ),
			__( 'Consistency beats intensity: one good decision at a time.', 'adminkit' ),
			__( 'Clarity is the courtesy of the maker.', 'adminkit' ),
		);

		$pool = array_values( (array) apply_filters( 'adminkit/dashboard/quotes', array_merge( $slot, $tips ), $h ) );
		if ( ! $pool ) {
			return '';
		}
		return $pool[ wp_rand( 0, count( $pool ) - 1 ) ];
	}

	/**
	 * The greeting title — a full line (not just a prefix word) drawn from a pool
	 * that mixes time-appropriate openers with a few time-neutral ones, picked fresh
	 * each load. Templates use %s for the styled name span; name-less lines are fine.
	 * Filterable (the current hour is passed) so a site can supply its own set.
	 *
	 * @param string $name_html Pre-escaped <span> holding the user's name.
	 * @return string Safe HTML — escaped template with the name span dropped in.
	 */
	private static function greeting( $name_html ) {
		$h = (int) current_time( 'G' );
		if ( $h >= 5 && $h < 12 ) {
			// translators: %s in each line below is the user's name.
			$slot = array(
				__( 'Hello, %s', 'adminkit' ),
				__( 'Good morning, %s', 'adminkit' ),
				__( 'Ready for today, %s?', 'adminkit' ),
				__( 'A good day begins', 'adminkit' ),
				__( 'Rise and shine, %s', 'adminkit' ),
				__( 'Fresh start, %s', 'adminkit' ),
			);
		} elseif ( $h >= 12 && $h < 18 ) {
			// translators: %s in each line below is the user's name.
			$slot = array(
				__( 'Good afternoon, %s', 'adminkit' ),
				__( 'Hello, %s', 'adminkit' ),
				__( 'The afternoon is yours', 'adminkit' ),
				__( 'Keep it going, %s', 'adminkit' ),
				__( 'Making progress, %s?', 'adminkit' ),
			);
		} elseif ( $h >= 18 && $h < 22 ) {
			// translators: %s in each line below is the user's name.
			$slot = array(
				__( 'Good evening, %s', 'adminkit' ),
				__( 'Have a good evening, %s', 'adminkit' ),
				__( 'A quiet evening to get ahead', 'adminkit' ),
				__( 'Winding down, %s?', 'adminkit' ),
			);
		} else {
			// translators: %s in each line below is the user's name.
			$slot = array(
				__( 'Working late, %s', 'adminkit' ),
				__( 'Still up, %s?', 'adminkit' ),
				__( 'Good evening, %s', 'adminkit' ),
				__( 'Burning the midnight oil, %s?', 'adminkit' ),
			);
		}
		// translators: %s in each line below is the user's name.
		$generic = array(
			__( 'Great to see you again, %s', 'adminkit' ),
			__( '%s is back!', 'adminkit' ),
			__( 'Glad to see you, %s', 'adminkit' ),
			__( 'Pick up where you left off?', 'adminkit' ),
			__( 'Welcome back, %s', 'adminkit' ),
			__( 'Let\'s make something great, %s', 'adminkit' ),
		);
		$pool = array_values( (array) apply_filters( 'adminkit/dashboard/greetings', array_merge( $slot, $generic ), $h ) );
		if ( ! $pool ) {
			return $name_html;
		}
		$tpl = (string) $pool[ wp_rand( 0, count( $pool ) - 1 ) ];
		// Escape the template, then drop the (already-safe) name span into %s.
		// Name-less templates have no %s and render as-is.
		return ( false !== strpos( $tpl, '%s' ) )
			? sprintf( esc_html( $tpl ), $name_html )
			: esc_html( $tpl );
	}

	/** Quick actions — capability-gated buttons; the first is the primary CTA. */
	private static function render_actions() {
		$actions = array();
		if ( current_user_can( 'edit_posts' ) ) {
			$actions[] = array( 'label' => __( 'Write a post', 'adminkit' ), 'url' => admin_url( 'post-new.php' ), 'icon' => 'edit', 'primary' => true );
		}
		if ( current_user_can( 'edit_pages' ) ) {
			$actions[] = array( 'label' => __( 'New page', 'adminkit' ), 'url' => admin_url( 'post-new.php?post_type=page' ), 'icon' => 'page' );
		}
		if ( current_user_can( 'upload_files' ) ) {
			$actions[] = array( 'label' => __( 'Add media', 'adminkit' ), 'url' => admin_url( 'media-new.php' ), 'icon' => 'image' );
		}
		if ( current_user_can( 'create_users' ) ) {
			$actions[] = array( 'label' => __( 'Add user', 'adminkit' ), 'url' => admin_url( 'user-new.php' ), 'icon' => 'user-plus' );
		}
		// "Edit my profile" is on the header avatar now (click your photo), and "View site"
		// lives in the Site-preview card — both dropped here to avoid duplicates.

		$actions = apply_filters( 'adminkit/dashboard/quick_actions', $actions );
		if ( ! $actions ) {
			return;
		}

		echo '<div class="ak-dash__actions">';
		foreach ( $actions as $a ) {
			printf(
				'<a class="ak-btn%1$s" href="%2$s"%5$s>%3$s<span>%4$s</span></a>',
				! empty( $a['primary'] ) ? ' ak-btn--primary' : '',
				esc_url( $a['url'] ),
				self::icon( isset( $a['icon'] ) ? $a['icon'] : '' ),
				esc_html( $a['label'] ),
				! empty( $a['blank'] ) ? ' target="_blank" rel="noopener"' : ''
			);
		}
		// The "Customize" control rides the same row, pushed to the right (margin-left
		// auto in CSS) so it shares the quick-actions button shape and baseline.
		echo self::tools_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped leaves.
		echo '</div>';
	}

	/** "At a glance" band — five live content counters, each with a one-line micro-stat.
	    Real WordPress numbers (read-only); fills the top strip of the dashboard. Filterable. */
	private static function render_glance() {
		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$comments = wp_count_comments();
		$users    = count_users();

		$drafts    = (int) ( $posts->draft ?? 0 );
		$pending   = (int) ( $posts->pending ?? 0 ) + (int) ( $pages->pending ?? 0 );
		$moderated = (int) ( $comments->moderated ?? 0 );
		$online    = ( class_exists( 'AdminKit_Online_Users' ) && AdminKit_Online_Users::is_enabled() )
			? (int) AdminKit_Online_Users::online_count() : 0;

		$media_bytes = 0;
		foreach ( self::storage()['segments'] as $seg ) {
			if ( 'media' === ( $seg['key'] ?? '' ) ) {
				$media_bytes = (int) $seg['bytes'];
				break;
			}
		}

		$tiles   = array();
		$tiles[] = array(
			'icon' => 'post', 'label' => __( 'Posts', 'adminkit' ), 'url' => admin_url( 'edit.php' ),
			'n'    => (int) ( $posts->publish ?? 0 ),
			/* translators: %s: number of draft posts. */
			'sub'  => $drafts > 0 ? sprintf( _n( '%s draft', '%s drafts', $drafts, 'adminkit' ), number_format_i18n( $drafts ) ) : __( 'No drafts', 'adminkit' ),
		);
		$tiles[] = array(
			'icon' => 'page', 'label' => __( 'Pages', 'adminkit' ), 'url' => admin_url( 'edit.php?post_type=page' ),
			'n'    => (int) ( $pages->publish ?? 0 ),
			/* translators: %s: number of items pending review. */
			'sub'  => $pending > 0 ? sprintf( __( '%s pending review', 'adminkit' ), number_format_i18n( $pending ) ) : __( 'All published', 'adminkit' ),
		);

		// Every custom post type the user manages (Bricks templates, ACF types, products…),
		// for a full picture of the site's content.
		foreach ( get_post_types( array( '_builtin' => false, 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( empty( $pt->show_in_menu ) ) {
				continue;
			}
			$c = wp_count_posts( $pt->name );
			$d = (int) ( $c->draft ?? 0 );
			$tiles[] = array(
				'icon'  => 'layers',
				'label' => ! empty( $pt->labels->name ) ? $pt->labels->name : $pt->name,
				'url'   => admin_url( 'edit.php?post_type=' . $pt->name ),
				'n'     => (int) ( $c->publish ?? 0 ),
				/* translators: %s: number of drafts. */
				'sub'   => $d > 0 ? sprintf( _n( '%s draft', '%s drafts', $d, 'adminkit' ), number_format_i18n( $d ) ) : __( 'No drafts', 'adminkit' ),
			);
		}

		$tiles[] = array(
			'icon' => 'comment', 'label' => __( 'Comments', 'adminkit' ), 'url' => admin_url( 'edit-comments.php' ),
			'n'    => (int) ( $comments->approved ?? 0 ),
			/* translators: %s: number of comments in moderation. */
			'sub'  => sprintf( __( '%s in moderation', 'adminkit' ), number_format_i18n( $moderated ) ),
		);
		$tiles[] = array(
			'icon' => 'image', 'label' => __( 'Media', 'adminkit' ), 'url' => admin_url( 'upload.php' ),
			'n'    => (int) ( wp_count_posts( 'attachment' )->inherit ?? 0 ),
			/* translators: %s: media size on disk, e.g. "162 MB". */
			'sub'  => sprintf( __( '%s on disk', 'adminkit' ), size_format( $media_bytes, 0 ) ),
		);
		$tiles[] = array(
			'icon' => 'users', 'label' => __( 'Users', 'adminkit' ), 'url' => admin_url( 'users.php' ),
			'n'    => (int) ( $users['total_users'] ?? 0 ),
			/* translators: %s: number of users online now. */
			'sub'  => $online > 0 ? sprintf( _n( '%s online now', '%s online now', $online, 'adminkit' ), number_format_i18n( $online ) ) : __( 'None online', 'adminkit' ),
		);
		$tiles = array_values( (array) apply_filters( 'adminkit/dashboard/glance', $tiles ) );
		if ( ! $tiles ) {
			return;
		}

		echo '<section class="ak-card ak-dash__card ak-dash__glance-section">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%s</h2></div>',
			esc_html__( 'At a glance', 'adminkit' )
		);
		echo '<div class="ak-dash__glance">';
		foreach ( $tiles as $t ) {
			printf(
				'<a class="ak-dash__glance-card" href="%1$s"><span class="ak-dash__glance-ic">%2$s</span>'
					. '<span class="ak-dash__glance-n">%3$s</span>'
					. '<span class="ak-dash__glance-text"><span class="ak-dash__glance-l">%4$s</span>'
					. '<span class="ak-dash__glance-sub">%5$s</span></span></a>',
				esc_url( isset( $t['url'] ) ? $t['url'] : '#' ),
				self::icon( isset( $t['icon'] ) ? $t['icon'] : 'post' ),
				esc_html( number_format_i18n( (int) ( $t['n'] ?? 0 ) ) ),
				esc_html( isset( $t['label'] ) ? $t['label'] : '' ),
				esc_html( isset( $t['sub'] ) ? $t['sub'] : '' )
			);
		}
		echo '</div>';
		echo '</section>';
	}

	/** Site preview — a scaled, non-interactive thumbnail of the live home page. It
	    replaces the old counters, which duplicated the admin menu. Click opens the site. */
	private static function render_preview() {
		$home = home_url( '/' );

		echo '<section class="ak-card ak-dash__card ak-dash__preview-card">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%1$s</h2>'
				. '<a class="ak-dash__head-link" href="%2$s">%3$s</a></div>',
			esc_html__( 'Site preview', 'adminkit' ),
			esc_url( admin_url( 'options-general.php' ) ),
			esc_html__( 'Settings', 'adminkit' )
		);
		printf(
			'<a class="ak-dash__preview" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s">'
				. '<iframe class="ak-dash__preview-frame" src="%3$s" loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true"></iframe>'
				. '</a>',
			esc_url( $home ),
			esc_attr__( 'View site', 'adminkit' ),
			esc_url( add_query_arg( 'ak-preview', '1', $home ) )
		);
		printf(
			'<a class="ak-btn ak-dash__preview-btn" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span>%3$s</span></a>',
			esc_url( $home ),
			self::icon( 'external' ), // author-controlled inline SVG
			esc_html__( 'View site', 'adminkit' )
		);
		echo '</section>';
	}

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

	/** AdminKit quick access — jump straight from the dashboard to the settings
	    sections (Brand · Features · Menu). Same cap as the settings page itself. */
	private static function render_adminkit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$base    = admin_url( 'admin.php?page=adminkit' );
		$palette = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".9" fill="currentColor" stroke="none"/><circle cx="17.5" cy="10.5" r=".9" fill="currentColor" stroke="none"/><circle cx="8.5" cy="7.5" r=".9" fill="currentColor" stroke="none"/><circle cx="6.5" cy="12.5" r=".9" fill="currentColor" stroke="none"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>';
		$gear    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>';
		$lines   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
		$bars    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx=".5"/><rect x="12.5" y="8" width="3" height="10" rx=".5"/><rect x="18" y="5" width="3" height="13" rx=".5"/></svg>';
		$chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

		// Brand + Features stay tabs inside the Settings SPA (hash links); Menu and
		// Statistics are their own AdminKit pages now, each linked when it's enabled.
		$links = array(
			array( 'url' => $base . '#brand',    'label' => __( 'Brand', 'adminkit' ),    'icon' => $palette ),
			array( 'url' => $base . '#features', 'label' => __( 'Features', 'adminkit' ), 'icon' => $gear ),
		);
		if ( class_exists( 'AdminKit_Menu_Manager' ) && AdminKit_Menu_Manager::is_enabled() ) {
			$links[] = array( 'url' => admin_url( 'admin.php?page=' . AdminKit_Settings_Page::SLUG_MENU ), 'label' => __( 'Menu', 'adminkit' ), 'icon' => $lines );
		}
		if ( class_exists( 'AdminKit_Stats_Tracker' ) && AdminKit_Stats_Tracker::is_enabled() ) {
			$links[] = array( 'url' => admin_url( 'admin.php?page=' . AdminKit_Settings_Page::SLUG_STATS ), 'label' => __( 'Statistics', 'adminkit' ), 'icon' => $bars );
		}

		echo '<section class="ak-card ak-dash__card ak-dash__adminkit">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%1$s</h2>'
				. '<a class="ak-dash__head-link" href="%2$s">%3$s</a></div>',
			esc_html__( 'AdminKit', 'adminkit' ),
			esc_url( $base ),
			esc_html__( 'Settings', 'adminkit' )
		);
		echo '<ul class="ak-dash__adminkit-list">';
		foreach ( $links as $l ) {
			printf(
				'<li><a class="ak-dash__adminkit-row" href="%1$s"><span class="ak-dash__adminkit-ic">%2$s</span>'
					. '<span class="ak-dash__adminkit-l">%3$s</span><span class="ak-dash__adminkit-go">%4$s</span></a></li>',
				esc_url( $l['url'] ),
				$l['icon'],   // author-controlled inline SVG
				esc_html( $l['label'] ),
				$chevron      // author-controlled inline SVG
			);
		}
		echo '</ul>';
		echo '</section>';
	}

	/** Recent-content card — the latest-modified posts/pages/CPTs as a grid of preview cards. */
	private static function render_content() {
		$rows = self::recent_content();

		echo '<section class="ak-card ak-dash__card ak-dash__recent">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%s</h2></div>',
			esc_html__( 'Recent changes', 'adminkit' )
		);

		if ( ! $rows ) {
			printf( '<p class="ak-dash__empty">%s</p>', esc_html__( 'Nothing recent yet.', 'adminkit' ) );
			echo '</section>';
			return;
		}

		echo '<ul class="ak-dash__recent-list">';
		foreach ( $rows as $r ) {
			$has_thumb = ! empty( $r['thumb'] );
			$media     = $has_thumb
				? '<img class="ak-dash__rc-img" src="' . esc_url( $r['thumb'] ) . '" alt="" loading="lazy" />'
				: self::icon( ! empty( $r['icon'] ) ? $r['icon'] : 'page' );
			// A larger preview that pops up on thumbnail hover (image rows only).
			$zoom      = $has_thumb
				? '<span class="ak-dash__rc-zoom"><img src="' . esc_url( $r['thumb'] ) . '" alt="" loading="lazy" /></span>'
				: '';

			$edit_url = $r['link'] ? $r['link'] : '#';
			$view_url = ! empty( $r['view'] ) ? $r['view'] : $edit_url;

			// On hover: a pen (edit) and an eye (open the live page in a new tab).
			$actions = sprintf(
				'<span class="ak-dash__rc-actions">'
					. '<a class="ak-dash__rc-act" href="%1$s" aria-label="%2$s" title="%2$s">%3$s</a>'
					. '<a class="ak-dash__rc-act" href="%4$s" target="_blank" rel="noopener" aria-label="%5$s" title="%5$s">%6$s</a>'
					. '</span>',
				esc_url( $edit_url ),
				esc_attr__( 'Edit', 'adminkit' ),
				self::icon( 'edit' ),
				esc_url( $view_url ),
				esc_attr__( 'View site', 'adminkit' ),
				self::icon( 'eye' )
			);

			$meta_parts = array_filter( array( (string) $r['type'], (string) $r['time'] ) );
			$meta_text  = $meta_parts ? implode( ' · ', array_map( 'esc_html', $meta_parts ) ) : '';

			printf(
				'<li class="ak-dash__rc-row"><span class="ak-dash__rc-thumb">%2$s</span>%8$s'
					. '<a class="ak-dash__rc-main" href="%1$s">'
						. '<span class="ak-dash__rc-title">%3$s</span>'
						. '<span class="ak-dash__rc-meta">%6$s</span></a>'
					. '<span class="ak-badge ak-badge--%4$s ak-dash__rc-badge">%5$s</span>'
					. '%7$s</li>',
				esc_url( $edit_url ),
				$media,     // safe: esc_url'd <img> or author-controlled SVG
				esc_html( $r['title'] ),
				esc_attr( $r['state'] ),
				esc_html( $r['status'] ),
				$meta_text, // already escaped above
				$actions,   // safe: built above with escaped URLs + author SVGs
				$zoom       // safe: esc_url'd <img>
			);
		}
		echo '</ul>';
		echo '</section>';
	}

	/** Site-health card — a composite score ring + badge + the real checks. */
	private static function render_health() {
		$h           = self::site_health();
		$score       = (int) $h['score'];
		$critical    = (int) ( $h['critical'] ?? 0 );
		$recommended = (int) ( $h['recommended'] ?? 0 );
		$good        = (int) ( $h['good'] ?? 0 );

		// Match WordPress's native overall verdict: "Bon" (green) unless there are
		// CRITICAL issues. Recommended items do NOT downgrade it — native Site Health
		// still reads "Bien" with recommendations — so only criticals turn it amber.
		$badge = $critical > 0
			? array( 'warn', __( 'Needs attention', 'adminkit' ) )
			: array( 'ok', __( 'Good', 'adminkit' ) );

		// Ring geometry: r=26, circumference ≈ 163.36; offset = (1 - score/100) * C.
		$dash = 163.36;
		$off  = round( $dash * ( 1 - max( 0, min( 100, $score ) ) / 100 ), 2 );

		$total = max( 1, $good + $recommended + $critical );

		echo '<section class="ak-card ak-dash__card ak-dash__health">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%1$s</h2><span class="ak-badge ak-badge--%2$s">%3$s</span></div>',
			esc_html__( 'Site health', 'adminkit' ),
			esc_attr( $badge[0] ),
			esc_html( $badge[1] )
		);

		printf(
			'<div class="ak-dash__health-top"><span class="ak-dash__ring ak-dash__ring--%5$s">'
				. '<svg viewBox="0 0 60 60" aria-hidden="true"><circle class="ak-dash__ring-bg" cx="30" cy="30" r="26"/>'
				. '<circle class="ak-dash__ring-fg" cx="30" cy="30" r="26" stroke-dasharray="%1$s" stroke-dashoffset="%2$s"/></svg>'
				. '<span class="ak-dash__ring-n">%3$s<span class="ak-dash__ring-l">%4$s</span></span></span>'
				. '<div class="ak-dash__health-stats">'
					. '<span class="ak-dash__health-stat ak-dash__health-stat--ok"><span class="ak-dash__health-stat-n">%6$s</span><span class="ak-dash__health-stat-l">%7$s</span></span>'
					. '<span class="ak-dash__health-stat ak-dash__health-stat--warn"><span class="ak-dash__health-stat-n">%8$s</span><span class="ak-dash__health-stat-l">%9$s</span></span>'
					. '<span class="ak-dash__health-stat ak-dash__health-stat--bad"><span class="ak-dash__health-stat-n">%10$s</span><span class="ak-dash__health-stat-l">%11$s</span></span>'
				. '</div></div>',
			esc_attr( $dash ),
			esc_attr( $off ),
			esc_html( number_format_i18n( $score ) ),
			esc_html__( 'Score', 'adminkit' ),
			esc_attr( $badge[0] ),
			esc_html( number_format_i18n( $good ) ),
			esc_html__( 'Passed', 'adminkit' ),
			esc_html( number_format_i18n( $recommended ) ),
			esc_html__( 'Recommended', 'adminkit' ),
			esc_html( number_format_i18n( $critical ) ),
			esc_html__( 'To address', 'adminkit' )
		);

		printf(
			'<div class="ak-dash__bar" aria-hidden="true">'
				. '<span class="ak-dash__bar-seg" style="width:%1$s%%;background:var(--ak-success)"></span>'
				. '<span class="ak-dash__bar-seg" style="width:%2$s%%;background:var(--ak-warning)"></span>'
				. '<span class="ak-dash__bar-seg" style="width:%3$s%%;background:var(--ak-error)"></span></div>',
			esc_attr( round( $good / $total * 100, 2 ) ),
			esc_attr( round( $recommended / $total * 100, 2 ) ),
			esc_attr( round( $critical / $total * 100, 2 ) )
		);

		echo '<p class="ak-dash__prio-h">' . esc_html__( 'To-do', 'adminkit' ) . '</p>';
		self::render_action_list( self::priority_items() );

		printf(
			'<a class="ak-dash__more" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'site-health.php' ) ),
			esc_html__( 'View full report', 'adminkit' )
		);
		echo '</section>';
	}

	/** Online-users card — who is signed in now (from AdminKit_Online_Users), or the most recent logins if nobody is online. Soft-depends on the module: absent/off ⇒ no card. */
	private static function render_online() {
		if ( ! class_exists( 'AdminKit_Online_Users' ) || ! AdminKit_Online_Users::is_enabled() || ! current_user_can( 'list_users' ) ) {
			return;
		}

		// Up to 5 rows: online users first, then padded with the most recent logins so
		// the card stays full. Each row carries its own online flag.
		$limit = 5;
		$rows  = array();
		$seen  = array();
		foreach ( AdminKit_Online_Users::online_users( $limit ) as $r ) {
			$rows[] = array( 'user' => $r['user'], 'since' => (int) $r['since'], 'online' => true );
			$seen[ $r['user']->ID ] = true;
		}
		if ( count( $rows ) < $limit ) {
			foreach ( AdminKit_Online_Users::recent_logins( $limit + count( $seen ) ) as $r ) {
				if ( count( $rows ) >= $limit ) {
					break;
				}
				if ( isset( $seen[ $r['user']->ID ] ) ) {
					continue; // already shown as online
				}
				$rows[] = array( 'user' => $r['user'], 'since' => (int) $r['since'], 'online' => false );
			}
		}

		$count = AdminKit_Online_Users::online_count();

		echo '<section class="ak-card ak-dash__card ak-dash__online">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%1$s</h2>%2$s</div>',
			$count > 0 ? esc_html__( 'Online now', 'adminkit' ) : esc_html__( 'Recent logins', 'adminkit' ),
			$count > 0 ? '<span class="ak-badge ak-badge--ok">' . esc_html( number_format_i18n( $count ) ) . '</span>' : ''
		);

		if ( ! $rows ) {
			printf( '<p class="ak-dash__empty">%s</p>', esc_html__( 'No recent sign-ins yet.', 'adminkit' ) );
			echo '</section>';
			return;
		}

		$now = time();
		echo '<ul class="ak-dash__online-list">';
		foreach ( $rows as $r ) {
			$user = $r['user'];
			$link = get_edit_user_link( $user->ID );
			// Online → the word "Online" (the dot already signals presence); recent
			// (the fallback list) → the time of their last login.
			$meta = $r['online']
				? esc_html__( 'Online', 'adminkit' )
				: ( ! empty( $r['since'] )
					/* translators: %s: human time difference, e.g. "2 hours". */
					? esc_html( sprintf( __( '%s ago', 'adminkit' ), human_time_diff( (int) $r['since'], $now ) ) )
					: '' );
			printf(
				'<li><a class="ak-dash__online-row" href="%1$s"><span class="ak-dash__online-av">%2$s%3$s</span>'
					. '<span class="ak-dash__online-name">%4$s</span>'
					. '<span class="ak-dash__online-meta">%5$s</span></a></li>',
				esc_url( $link ? $link : admin_url( 'users.php' ) ),
				get_avatar( $user->ID, 32 ), // safe: core-escaped <img>
				$r['online'] ? '<span class="ak-dash__online-dot"></span>' : '',
				esc_html( $user->display_name ? $user->display_name : $user->user_login ),
				$meta // already escaped above
			);
		}
		echo '</ul>';
		printf(
			'<a class="ak-dash__more" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'users.php?orderby=adminkit_last_login&order=desc' ) ),
			esc_html__( 'View all online accounts', 'adminkit' )
		);
		echo '</section>';
	}

	/** Storage card — the site's install footprint (uploads / db / plugins / themes / core), with server space left as context. */
	private static function render_storage() {
		$s     = self::storage();
		$total = (int) $s['total'];
		$free  = (int) ( $s['disk_free'] ?? 0 );
		$segs  = $s['segments'];

		echo '<section class="ak-card ak-dash__card ak-dash__storage">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%1$s</h2></div>',
			esc_html__( 'Storage', 'adminkit' )
		);

		// Headline: the site's footprint (prominent) + free space as muted context.
		if ( $free > 0 ) {
			printf(
				'<p class="ak-dash__store-head"><strong>%1$s</strong> %2$s<span class="ak-dash__store-used"> · %3$s %4$s</span></p>',
				esc_html( size_format( $total, 1 ) ),
				esc_html__( 'used', 'adminkit' ),
				esc_html( size_format( $free, 1 ) ),
				esc_html__( 'available', 'adminkit' )
			);
		} else {
			printf(
				'<p class="ak-dash__store-head"><strong>%1$s</strong> %2$s</p>',
				esc_html( size_format( $total, 1 ) ),
				esc_html__( 'used by your site', 'adminkit' )
			);
		}

		// Bar — segments proportional to (footprint + free), so the grey track that
		// stays empty reads as the available space ("la couleur grise = ce qui reste").
		$basis = $free > 0 ? max( 1, $total + $free ) : max( 1, $total );
		echo '<div class="ak-dash__bar">';
		foreach ( $segs as $seg ) {
			printf(
				'<span class="ak-dash__bar-seg" style="width:%1$s%%;background:%2$s"></span>',
				esc_attr( round( (int) ( $seg['bytes'] ?? 0 ) / $basis * 100, 2 ) ),
				esc_attr( $seg['color'] ?? 'var(--ak-primary)' )
			);
		}
		echo '</div>';

		// Legend — each segment + size.
		echo '<ul class="ak-dash__legend">';
		foreach ( $segs as $seg ) {
			printf(
				'<li><span class="ak-dash__dot" style="background:%1$s"></span>%2$s<span class="ak-dash__legend-v">%3$s</span></li>',
				esc_attr( $seg['color'] ?? 'var(--ak-primary)' ),
				esc_html( $seg['label'] ?? '' ),
				esc_html( size_format( (int) ( $seg['bytes'] ?? 0 ), 1 ) )
			);
		}
		echo '</ul>';

		echo '</section>';
	}

	/** Maintenance card — plugin / theme inventory (active vs inactive) + a gentle advisory. */
	/**
	 * Plugin / theme inventory counts — a shared signal for the Priorités feed.
	 *
	 * @return array{plugins_active:int,plugins_off:int,themes_off:int}
	 */
	private static function maintenance_counts() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$total  = count( $all );
		$on     = min( $total, count( array_unique( $active ) ) );
		$themes = function_exists( 'wp_get_themes' ) ? count( wp_get_themes() ) : 1;

		return array(
			'plugins_active' => $on,
			'plugins_off'    => max( 0, $total - $on ),
			'themes_off'     => max( 0, $themes - 2 ), // active + 1 fallback = healthy; only flag extras
		);
	}

	/**
	 * The "Priorités" feed — everything that may need attention, drawn from signals
	 * the dashboard already computes: site-health alerts, maintenance suggestions and
	 * content to-dos. Each item = {sev,icon,title,desc,url,cta}; appended warn→info→todo
	 * so the list is already in priority order. Capability-gated + filterable.
	 *
	 * @return array<int,array>
	 */
	private static function priority_items() {
		$items = array();

		// --- Site-health alerts (warn) ---
		$https = is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		if ( ! $https ) {
			$items[] = array( 'sev' => 'bad', 'icon' => 'shield', 'title' => __( 'HTTPS off', 'adminkit' ), 'desc' => __( 'Security and SEO', 'adminkit' ), 'url' => admin_url( 'site-health.php' ), 'cta' => __( 'Details', 'adminkit' ) );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! ( defined( 'WP_DEBUG_DISPLAY' ) && ! WP_DEBUG_DISPLAY ) ) {
			$items[] = array( 'sev' => 'bad', 'icon' => 'alert', 'title' => __( 'Debug mode on', 'adminkit' ), 'desc' => __( 'Turn off in production', 'adminkit' ), 'url' => admin_url( 'site-health.php' ), 'cta' => __( 'Details', 'adminkit' ) );
		}
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			/* translators: %s: current PHP version. */
			$items[] = array( 'sev' => 'warn', 'icon' => 'alert', 'title' => sprintf( __( 'Update PHP %s', 'adminkit' ), PHP_VERSION ), 'desc' => __( 'Version too old', 'adminkit' ), 'url' => admin_url( 'site-health.php' ), 'cta' => __( 'Details', 'adminkit' ) );
		}
		if ( current_user_can( 'update_core' ) ) {
			$ups = function_exists( 'wp_get_update_data' ) ? (int) ( wp_get_update_data()['counts']['total'] ?? 0 ) : 0;
			if ( $ups > 0 ) {
				/* translators: %d: number of available updates. */
				$items[] = array( 'sev' => 'warn', 'icon' => 'download', 'title' => sprintf( _n( '%d update available', '%d updates available', $ups, 'adminkit' ), $ups ), 'desc' => __( 'Core, plugins, themes', 'adminkit' ), 'url' => admin_url( 'update-core.php' ), 'cta' => __( 'Update', 'adminkit' ) );
			}
		}

		// --- Maintenance suggestions (info) ---
		if ( current_user_can( 'activate_plugins' ) ) {
			$m = self::maintenance_counts();
			if ( $m['plugins_off'] > 0 ) {
				/* translators: %s: number of inactive plugins. */
				$items[] = array( 'sev' => 'warn', 'icon' => 'plugin', 'title' => sprintf( _n( '%s inactive plugin', '%s inactive plugins', $m['plugins_off'], 'adminkit' ), number_format_i18n( $m['plugins_off'] ) ), 'desc' => __( 'Remove if unused', 'adminkit' ), 'url' => admin_url( 'plugins.php?plugin_status=inactive' ), 'cta' => __( 'Manage', 'adminkit' ) );
			}
			if ( $m['themes_off'] > 0 ) {
				/* translators: %s: number of inactive themes. */
				$items[] = array( 'sev' => 'warn', 'icon' => 'appearance', 'title' => sprintf( _n( '%s inactive theme', '%s inactive themes', $m['themes_off'], 'adminkit' ), number_format_i18n( $m['themes_off'] ) ), 'desc' => __( 'Remove if unused', 'adminkit' ), 'url' => admin_url( 'themes.php' ), 'cta' => __( 'Manage', 'adminkit' ) );
			}
		}

		// --- Content to-dos (todo, only when there's something) ---
		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$comments = wp_count_comments();
		$drafts   = (int) ( $posts->draft ?? 0 ) + (int) ( $pages->draft ?? 0 );
		if ( $drafts > 0 && current_user_can( 'edit_posts' ) ) {
			/* translators: %s: number of draft posts/pages. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'edit', 'title' => sprintf( _n( '%s draft to finish', '%s drafts to finish', $drafts, 'adminkit' ), number_format_i18n( $drafts ) ), 'desc' => '', 'url' => admin_url( 'edit.php?post_status=draft' ), 'cta' => __( 'Open', 'adminkit' ) );
		}
		$pending = (int) ( $posts->pending ?? 0 );
		if ( $pending > 0 && current_user_can( 'edit_posts' ) ) {
			/* translators: %s: number of posts pending review. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'page', 'title' => sprintf( _n( '%s post pending', '%s posts pending', $pending, 'adminkit' ), number_format_i18n( $pending ) ), 'desc' => __( 'Awaiting review', 'adminkit' ), 'url' => admin_url( 'edit.php?post_status=pending' ), 'cta' => __( 'Open', 'adminkit' ) );
		}
		$moderate = (int) ( $comments->moderated ?? 0 );
		if ( $moderate > 0 && current_user_can( 'moderate_comments' ) ) {
			/* translators: %s: number of comments awaiting moderation. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'comment', 'title' => sprintf( _n( '%s comment to moderate', '%s comments to moderate', $moderate, 'adminkit' ), number_format_i18n( $moderate ) ), 'desc' => '', 'url' => admin_url( 'edit-comments.php?comment_status=moderated' ), 'cta' => __( 'Moderate', 'adminkit' ) );
		}
		$future = (int) ( $posts->future ?? 0 );
		if ( $future > 0 && current_user_can( 'edit_posts' ) ) {
			/* translators: %s: number of scheduled publications. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'clock', 'title' => sprintf( _n( '%s scheduled post', '%s scheduled posts', $future, 'adminkit' ), number_format_i18n( $future ) ), 'desc' => '', 'url' => admin_url( 'edit.php?post_status=future' ), 'cta' => __( 'View', 'adminkit' ) );
		}

		return array_values( (array) apply_filters( 'adminkit/dashboard/priorities', $items ) );
	}

	/**
	 * Render the actionable to-do list — site-health alerts, maintenance suggestions
	 * and content tasks. Shown inside the Actions à faire card; empty → a compact
	 * all-clear state.
	 *
	 * @param array<int,array> $items
	 * @return void
	 */
	private static function render_action_list( $items ) {
		if ( ! $items ) {
			printf(
				'<p class="ak-dash__prio-empty">%1$s<span>%2$s</span></p>',
				self::icon( 'check' ),
				esc_html__( 'All clear — nothing to do today.', 'adminkit' )
			);
			return;
		}

		echo '<ul class="ak-dash__prio-list">';
		foreach ( $items as $it ) {
			$sev = isset( $it['sev'] ) && in_array( $it['sev'], array( 'bad', 'warn', 'todo' ), true ) ? $it['sev'] : 'todo';
			printf(
				'<li class="ak-dash__prio-row ak-dash__prio-row--%1$s"><span class="ak-dash__prio-ic">%2$s</span>'
					. '<span class="ak-dash__prio-txt"><span class="ak-dash__prio-title">%3$s</span>%4$s</span>'
					. '<a class="ak-dash__prio-cta" href="%5$s">%6$s</a></li>',
				esc_attr( $sev ),
				self::icon( isset( $it['icon'] ) ? $it['icon'] : 'alert' ),
				esc_html( isset( $it['title'] ) ? $it['title'] : '' ),
				! empty( $it['desc'] ) ? '<span class="ak-dash__prio-desc">' . esc_html( $it['desc'] ) . '</span>' : '',
				esc_url( isset( $it['url'] ) ? $it['url'] : '#' ),
				esc_html( isset( $it['cta'] ) ? $it['cta'] : __( 'View', 'adminkit' ) )
			);
		}
		echo '</ul>';
	}

	/* ───────────────────────── data ───────────────────────── */

	/**
	 * The 4 most recently-modified items the user manages — posts, pages and every
	 * auto-detected custom post type — newest first, for the recent-modifications grid.
	 *
	 * @return array<int,array>
	 */
	private static function recent_content() {
		$has_pp = class_exists( 'AdminKit_Post_Previews' );

		// post + page + every CPT the user manages (auto-detected, same set as the counters).
		$types = array( 'post', 'page' );
		foreach ( get_post_types( array( '_builtin' => false, 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( ! empty( $pt->show_in_menu ) ) {
				$types[] = $pt->name;
			}
		}

		$posts = get_posts( array(
			'numberposts' => 5,
			'orderby'     => 'modified',
			'post_status' => array( 'publish', 'draft', 'pending' ),
			'post_type'   => $types,
		) );

		$rows = array();
		foreach ( $posts as $p ) {
			$rows[] = self::content_row( $p, $has_pp );
		}

		return apply_filters( 'adminkit/dashboard/content', $rows );
	}

	/**
	 * Build one recent-content card row from a post.
	 *
	 * @param WP_Post $p
	 * @param bool    $has_pp Whether the post-previews helper is available.
	 * @return array
	 */
	private static function content_row( $p, $has_pp ) {
		$pt_obj = get_post_type_object( $p->post_type );
		$raw    = has_excerpt( $p ) ? $p->post_excerpt : strip_shortcodes( (string) $p->post_content );
		$ts     = (int) get_post_modified_time( 'U', true, $p );

		if ( 'publish' === $p->post_status ) {
			$status = __( 'Published', 'adminkit' );
			$state  = 'ok';
		} elseif ( 'pending' === $p->post_status ) {
			$status = __( 'Pending', 'adminkit' );
			$state  = 'warn';
		} else {
			$status = __( 'Draft', 'adminkit' );
			$state  = 'muted';
		}

		return array(
			'type'    => ( $pt_obj && ! empty( $pt_obj->labels->singular_name ) ) ? $pt_obj->labels->singular_name : __( 'Content', 'adminkit' ),
			'icon'    => 'page' === $p->post_type ? 'page' : ( 'post' === $p->post_type ? 'post' : 'layers' ),
			'thumb'   => $has_pp ? AdminKit_Post_Previews::preview_url( $p, 600, 400 ) : '',
			'title'   => $p->post_title ? $p->post_title : __( '(no title)', 'adminkit' ),
			'excerpt' => wp_trim_words( wp_strip_all_tags( $raw ), 16, '…' ),
			'status'  => $status,
			'state'   => $state,
			'time'    => $ts ? sprintf(
				/* translators: %s: human time difference, e.g. "2 hours". */
				__( '%s ago', 'adminkit' ),
				human_time_diff( $ts, time() )
			) : '',
			'link'    => get_edit_post_link( $p->ID, 'raw' ),
			'view'    => 'publish' === $p->post_status ? get_permalink( $p ) : get_preview_post_link( $p ),
		);
	}

	/**
	 * Composite health: a small set of real, cheap checks + a 0–100 score (the
	 * share that pass). NOT the WP Site-Health async percentage — that's computed
	 * in the browser; this is a quick server-side read. The full report link goes
	 * to the authoritative screen. Cached 12h. Filterable.
	 *
	 * @return array{score:int,checks:array<int,array{ok:bool,label:string}>}
	 */
	private static function site_health() {
		$cached = get_transient( 'adminkit_dash_health_v4' );
		if ( is_array( $cached ) ) {
			return apply_filters( 'adminkit/dashboard/site_health', $cached );
		}

		// Short, real signals for the displayed checks (the same data Site Health
		// reads) — kept concise so the card stays clean.
		$https = is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$php   = version_compare( PHP_VERSION, '8.0', '>=' );
		$ups   = function_exists( 'wp_get_update_data' ) ? (int) ( wp_get_update_data()['counts']['total'] ?? 0 ) : 0;
		$debug = ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! ( defined( 'WP_DEBUG_DISPLAY' ) && ! WP_DEBUG_DISPLAY ) );

		$checks = array(
			array( 'ok' => $https, 'label' => $https ? __( 'HTTPS on', 'adminkit' ) : __( 'HTTPS off', 'adminkit' ) ),
			array(
				'ok'    => $php,
				/* translators: %s: PHP version number. */
				'label' => sprintf( __( 'PHP %s', 'adminkit' ), PHP_VERSION ) . ( $php ? ' — ' . __( 'recommended', 'adminkit' ) : '' ),
			),
			array(
				'ok'    => 0 === $ups,
				'label' => 0 === $ups
					? __( 'Everything\'s up to date', 'adminkit' )
					/* translators: %d: number of available updates. */
					: sprintf( _n( '%d update available', '%d updates available', $ups, 'adminkit' ), $ups ),
			),
			array( 'ok' => $debug, 'label' => $debug ? __( 'Debug mode off', 'adminkit' ) : __( 'Debug mode on', 'adminkit' ) ),
		);

		// Score + issue counts from WordPress's NATIVE Site Health (curated cheap
		// DIRECT tests). Fall back to the share of the checks above when unavailable.
		$native = self::native_health();
		if ( $native ) {
			$score       = $native['score'];
			$critical    = $native['critical'];
			$recommended = $native['recommended'];
			$good        = $native['good'];
		} else {
			$pass        = count( array_filter( wp_list_pluck( $checks, 'ok' ) ) );
			$score       = (int) round( $pass / max( 1, count( $checks ) ) * 100 );
			$critical    = 0;
			$recommended = count( $checks ) - $pass;
			$good        = $pass;
		}

		$data = array(
			'score'       => $score,
			'critical'    => $critical,
			'recommended' => $recommended,
			'good'        => $good,
			'checks'      => $checks,
		);
		set_transient( 'adminkit_dash_health_v4', $data, 6 * HOUR_IN_SECONDS );

		return apply_filters( 'adminkit/dashboard/site_health', $data );
	}

	/**
	 * WordPress's native Site Health summary from a curated set of cheap DIRECT
	 * tests (no HTTP / loopback): counts by status + a derived score,
	 * (good + recommended·0.5) / total · 100. Null when Site Health isn't loadable
	 * so the caller can fall back to its own quick checks.
	 *
	 * @return array{score:int,critical:int,recommended:int,good:int}|null
	 */
	private static function native_health() {
		// Prefer WordPress's OWN stored Site Health result so the dashboard matches
		// the Site Health screen exactly (its JS writes this transient after the full
		// async run, incl. checks we don't run inline). Falls through when absent.
		$stored = get_transient( 'health-check-site-status-result' );
		if ( $stored ) {
			$r = json_decode( $stored, true );
			if ( is_array( $r ) && isset( $r['good'], $r['recommended'], $r['critical'] ) ) {
				$g     = (int) $r['good'];
				$rec   = (int) $r['recommended'];
				$crit  = (int) $r['critical'];
				$total = $g + $rec + $crit;
				if ( $total ) {
					return array(
						'score'       => (int) round( ( $g + $rec * 0.5 ) / $total * 100 ),
						'critical'    => $crit,
						'recommended' => $rec,
						'good'        => $g,
					);
				}
			}
		}

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			$file = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
		if ( ! class_exists( 'WP_Site_Health' ) || ! method_exists( 'WP_Site_Health', 'get_instance' ) ) {
			return null;
		}

		$sh     = WP_Site_Health::get_instance();
		$counts = array( 'good' => 0, 'recommended' => 0, 'critical' => 0 );
		$tests  = array(
			'wordpress_version', 'php_version', 'sql_server', 'utf8mb4_support',
			'https_status', 'ssl_support', 'is_in_debug_mode', 'plugin_version',
			'theme_version', 'plugin_theme_auto_updates', 'scheduled_events',
		);
		foreach ( $tests as $t ) {
			$fn = 'get_test_' . $t;
			if ( ! method_exists( $sh, $fn ) ) {
				continue;
			}
			$res = $sh->$fn();
			if ( is_array( $res ) && isset( $res['status'], $counts[ $res['status'] ] ) ) {
				++$counts[ $res['status'] ];
			}
		}

		$total = array_sum( $counts );
		if ( ! $total ) {
			return null;
		}
		return array(
			'score'       => (int) round( ( $counts['good'] + $counts['recommended'] * 0.5 ) / $total * 100 ),
			'critical'    => $counts['critical'],
			'recommended' => $counts['recommended'],
			'good'        => $counts['good'],
		);
	}

	/**
	 * The site's storage footprint, measured the way WordPress's own Site Health
	 * does (WP_Debug_Data::get_sizes): native recurse_dirsize() over uploads,
	 * plugins, themes and the WP-core remainder, plus the database. Cached 12h in
	 * one transient (the scans are heavy). disk_free is kept as secondary "server
	 * space left" context. Hosts can add a segment (adminkit/dashboard/storage) or
	 * pin an explicit total (adminkit/dashboard/storage_total).
	 *
	 * @return array{segments:array,total:int,disk_free:int}
	 */
	private static function storage() {
		$cached = get_transient( 'adminkit_dash_storage_v2' );
		if ( ! is_array( $cached ) ) {
			$cached = self::measure_storage();
			set_transient( 'adminkit_dash_storage_v2', $cached, 12 * HOUR_IN_SECONDS );
		}

		$segments = array(
			array( 'key' => 'media',   'label' => __( 'Media', 'adminkit' ),          'bytes' => (int) $cached['media'],   'color' => 'var(--ak-primary)' ),
			array( 'key' => 'db',      'label' => __( 'Database', 'adminkit' ), 'bytes' => (int) $cached['db'],      'color' => 'var(--ak-warning)' ),
			array( 'key' => 'plugins', 'label' => __( 'Plugins', 'adminkit' ),      'bytes' => (int) $cached['plugins'], 'color' => 'var(--ak-success)' ),
			array( 'key' => 'themes',  'label' => __( 'Themes', 'adminkit' ),          'bytes' => (int) $cached['themes'],  'color' => 'var(--ak-info)' ),
			array( 'key' => 'core',    'label' => __( 'WordPress', 'adminkit' ),       'bytes' => (int) $cached['core'],    'color' => 'color-mix(in srgb, var(--ak-primary) 55%, var(--ak-error) 45%)' ),
		);
		// Hosts / backup plugins can append a {key,label,bytes,color} segment.
		$segments = array_values( (array) apply_filters( 'adminkit/dashboard/storage', $segments ) );

		$total = 0;
		foreach ( $segments as $seg ) {
			$total += (int) ( $seg['bytes'] ?? 0 );
		}
		// A host can still pin an explicit total (e.g. a hosting-plan allowance).
		$total = (int) apply_filters( 'adminkit/dashboard/storage_total', $total );

		return array(
			'segments'  => $segments,
			'total'     => $total,
			'disk_free' => (int) ( $cached['disk_free'] ?? 0 ),
		);
	}

	/**
	 * The heavy reads behind storage(), isolated so the caller can cache them.
	 * Uses the native recurse_dirsize() on the same paths WP_Debug_Data::get_sizes()
	 * measures; core = ABSPATH with the sub-trees excluded.
	 *
	 * @return array{media:int,db:int,plugins:int,themes:int,core:int,disk_free:int}
	 */
	private static function measure_storage() {
		$uploads   = wp_get_upload_dir();
		$up_dir    = ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) ? untrailingslashit( $uploads['basedir'] ) : '';
		$plug_dir  = defined( 'WP_PLUGIN_DIR' ) ? untrailingslashit( WP_PLUGIN_DIR ) : '';
		$theme_dir = untrailingslashit( get_theme_root() );

		// Bust any stale dirsize cache (a 0 cached while a dir was empty) before measuring.
		if ( function_exists( 'clean_dirsize_cache' ) ) {
			foreach ( array( $up_dir, $plug_dir, $theme_dir, untrailingslashit( ABSPATH ) ) as $d ) {
				if ( $d ) {
					clean_dirsize_cache( $d );
				}
			}
		}

		// Core = the rest of ABSPATH, excluding the sub-trees measured separately
		// (full paths, matched during the walk — exactly like get_sizes()).
		$exclude = array_values( array_filter( array( $up_dir, $plug_dir, $theme_dir ) ) );

		global $wpdb;
		$disk_free = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : 0;

		return array(
			'media'     => self::dir_bytes( $up_dir ),
			'db'        => (int) $wpdb->get_var( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()' ),
			'plugins'   => self::dir_bytes( $plug_dir ),
			'themes'    => self::dir_bytes( $theme_dir ),
			'core'      => self::dir_bytes( ABSPATH, $exclude ),
			'disk_free' => is_numeric( $disk_free ) ? (int) $disk_free : 0,
		);
	}

	/**
	 * recurse_dirsize() wrapper — null (hit the time budget) / false (unreadable) → 0,
	 * so a huge or locked-down install never hangs or fatals the dashboard.
	 *
	 * @param string $dir
	 * @param array  $exclude Full paths to skip during the walk.
	 * @return int
	 */
	private static function dir_bytes( $dir, $exclude = array() ) {
		if ( ! $dir || ! function_exists( 'recurse_dirsize' ) ) {
			return 0;
		}
		$size = recurse_dirsize( $dir, $exclude );
		return is_numeric( $size ) ? (int) $size : 0;
	}

	/* ───────────────────────── icons ───────────────────────── */

	/**
	 * Inline SVG icon (1.5px stroke, currentColor) by key. Author-controlled
	 * markup — safe to echo. Unknown keys render nothing.
	 *
	 * @param string $name
	 * @return string
	 */
	private static function icon( $name ) {
		$paths = array(
			'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
			'page'     => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M5 3h9l5 5v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/>',
			'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
			'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
			'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/>',
			'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
			'post'     => '<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
			'comment'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>',
			'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
			'check'    => '<path d="M20 6 9 17l-5-5"/>',
			'alert'    => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
			'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
			'moon'     => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
			'appearance' => '<circle cx="13.5" cy="6.5" r=".6" fill="currentColor"/><circle cx="17" cy="10.5" r=".6" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".6" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".6" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.6-.7 1.6-1.6 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.6 1.6-1.6H16c3 0 5.5-2.5 5.5-5.6C21.5 6 17.5 2 12 2Z"/>',
			'plugin'   => '<path d="M9 2v5M15 2v5M6 7h12v4a6 6 0 0 1-12 0V7ZM12 17v5"/>',
			'tools'    => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4 2.5-2.5Z"/>',
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
			'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
			'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
			'layers'   => '<path d="M12 2 2 7l10 5 10-5-10-5ZM2 17l10 5 10-5M2 12l10 5 10-5"/>',
			'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z"/>',
		);
		if ( empty( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="ak-dash-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
	}
}
