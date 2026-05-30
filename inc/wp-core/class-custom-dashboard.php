<?php
/**
 * Custom dashboard — replaces the native wp-admin dashboard (index.php) with a
 * self-contained, server-rendered AdminKit dashboard: a greeting, quick-action
 * buttons, four stat tiles, and a 2-column area (Recent activity / Site health +
 * Storage). It renders its OWN markup inside one full-width dashboard widget (the
 * Settings SPA owns its markup the same way) — NOT a fragile repaint of native DOM.
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
 * transient. Behaviour brick: pure PHP render, no JS.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Custom_Dashboard {

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

		// Recent-activity thumbnails reuse the post-previews hover panel (the script
		// binds to any [data-ak-full]); load it on the dashboard. Its panel CSS is
		// registered for this screen in class-chrome.php.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Load the shared post-previews hover-panel script on the dashboard so the
	 * recent-activity thumbnails get the same on-hover page preview as the list
	 * tables. No-op on any other screen.
	 *
	 * @param string $hook
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}
		if ( class_exists( 'AdminKit_Post_Previews' ) && method_exists( 'AdminKit_Post_Previews', 'enqueue' ) ) {
			AdminKit_Post_Previews::enqueue();
		}
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
		self::render_stats();
		self::render_priorities();
		echo '<div class="ak-dash__grid">';
		echo '<div class="ak-dash__col ak-dash__col--main">';
		self::render_activity();
		echo '</div>';
		echo '<div class="ak-dash__col">';
		self::render_health();
		self::render_storage();
		echo '</div>';
		echo '</div>'; // .ak-dash__grid
		echo '</div>'; // .ak-dash
	}

	/** Greeting + a rotating line — the page's visible heading. Both pick fresh on
	    every load (was a stable daily pick) so the dashboard feels alive. */
	private static function render_header() {
		$user      = wp_get_current_user();
		$name      = $user->first_name ? $user->first_name : $user->display_name;
		$name_html = '<span class="ak-dash__greet-name">' . esc_html( $name ) . '</span>';

		printf(
			'<div class="ak-dash__head"><h1 class="ak-dash__greet">%1$s</h1>'
				. '<p class="ak-dash__sub">%2$s</p></div>',
			self::greeting( $name_html ), // safe HTML: escaped template + the name span
			esc_html( self::subtitle() )
		);
	}

	/**
	 * The description line — a short, refined quote or tip, picked fresh on every
	 * load (was a stable daily pick) so it varies as you come and go. No stored
	 * state. Filterable so a site can supply its own set.
	 *
	 * @return string
	 */
	private static function subtitle() {
		$quotes = array(
			__( '« La simplicité est la sophistication suprême. » — Léonard de Vinci', 'adminkit' ),
			__( '« La perfection, c’est quand il n’y a plus rien à retirer. » — Antoine de Saint-Exupéry', 'adminkit' ),
			__( '« Le bon design, c’est le moins de design possible. » — Dieter Rams', 'adminkit' ),
			__( 'Bien fait vaut mieux que parfait — publiez, puis affinez.', 'adminkit' ),
			__( 'La régularité l’emporte sur l’intensité : une bonne décision à la fois.', 'adminkit' ),
			__( 'Créez aujourd’hui ce dont vous serez fier demain.', 'adminkit' ),
			__( 'La clarté est la politesse de celui qui crée.', 'adminkit' ),
			__( 'Chaque détail compte — c’est leur somme qui fait la différence.', 'adminkit' ),
			__( 'Un grand site se construit page après page.', 'adminkit' ),
			__( 'Un contenu frais garde vos visiteurs engagés.', 'adminkit' ),
			__( 'Pensez à sauvegarder avant les grandes modifications.', 'adminkit' ),
			__( 'Aperçu de votre site en un coup d’œil.', 'adminkit' ),
		);
		$quotes = array_values( (array) apply_filters( 'adminkit/dashboard/quotes', $quotes ) );
		if ( ! $quotes ) {
			return '';
		}
		return $quotes[ wp_rand( 0, count( $quotes ) - 1 ) ];
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
				__( 'Bonjour, %s', 'adminkit' ),
				__( 'Bonne matinée, %s', 'adminkit' ),
				__( 'Prêt pour aujourd’hui, %s ?', 'adminkit' ),
				__( 'Une bonne journée commence', 'adminkit' ),
			);
		} elseif ( $h >= 12 && $h < 18 ) {
			// translators: %s in each line below is the user's name.
			$slot = array(
				__( 'Bon après-midi, %s', 'adminkit' ),
				__( 'Bonjour, %s', 'adminkit' ),
				__( 'L’après-midi est à vous', 'adminkit' ),
			);
		} elseif ( $h >= 18 && $h < 22 ) {
			// translators: %s in each line below is the user's name.
			$slot = array(
				__( 'Bonsoir, %s', 'adminkit' ),
				__( 'Bonne soirée, %s', 'adminkit' ),
				__( 'La soirée est calme pour avancer', 'adminkit' ),
			);
		} else {
			// translators: %s in each line below is the user's name.
			$slot = array(
				__( 'Il est tard, %s', 'adminkit' ),
				__( 'Encore debout, %s ?', 'adminkit' ),
				__( 'Bonsoir, %s', 'adminkit' ),
			);
		}
		// translators: %s in each line below is the user's name.
		$generic = array(
			__( 'Ravi de vous revoir, %s', 'adminkit' ),
			__( '%s est de retour !', 'adminkit' ),
			__( 'Content de vous voir, %s', 'adminkit' ),
			__( 'On reprend où on s’était arrêté ?', 'adminkit' ),
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
			$actions[] = array( 'label' => __( 'Écrire un article', 'adminkit' ), 'url' => admin_url( 'post-new.php' ), 'icon' => 'edit', 'primary' => true );
		}
		if ( current_user_can( 'edit_pages' ) ) {
			$actions[] = array( 'label' => __( 'Nouvelle page', 'adminkit' ), 'url' => admin_url( 'post-new.php?post_type=page' ), 'icon' => 'page' );
		}
		if ( current_user_can( 'upload_files' ) ) {
			$actions[] = array( 'label' => __( 'Ajouter un média', 'adminkit' ), 'url' => admin_url( 'media-new.php' ), 'icon' => 'image' );
		}
		if ( current_user_can( 'create_users' ) ) {
			$actions[] = array( 'label' => __( 'Créer un compte', 'adminkit' ), 'url' => admin_url( 'user-new.php' ), 'icon' => 'user-plus' );
		}
		$actions[] = array( 'label' => __( 'Voir le site', 'adminkit' ), 'url' => home_url( '/' ), 'icon' => 'external', 'blank' => true );

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
		echo '</div>';
	}

	/** Stat tiles — built-in content + every custom post type the user manages (Bricks
	    templates, ACF types, products…), media, comments and accounts. Filterable. */
	private static function render_stats() {
		$tiles = array();

		// Built-in content first.
		$tiles[] = array( 'n' => (int) ( wp_count_posts( 'post' )->publish ?? 0 ), 'label' => __( 'Articles', 'adminkit' ), 'url' => admin_url( 'edit.php' ),                'icon' => 'post' );
		$tiles[] = array( 'n' => (int) ( wp_count_posts( 'page' )->publish ?? 0 ), 'label' => __( 'Pages', 'adminkit' ),    'url' => admin_url( 'edit.php?post_type=page' ), 'icon' => 'page' );

		// Custom post types the user actually manages. show_ui catches types that
		// aren't publicly queryable (e.g. Bricks templates); show_in_menu drops the
		// internal editor types (reusable blocks, templates, ACF field groups…).
		foreach ( get_post_types( array( '_builtin' => false, 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( empty( $pt->show_in_menu ) ) {
				continue;
			}
			$tiles[] = array(
				'n'     => (int) ( wp_count_posts( $pt->name )->publish ?? 0 ),
				'label' => ! empty( $pt->labels->name ) ? $pt->labels->name : $pt->name,
				'url'   => admin_url( 'edit.php?post_type=' . $pt->name ),
				'icon'  => 'layers',
			);
		}

		// Media, comments, accounts.
		$comments = wp_count_comments();
		$users    = count_users();
		$tiles[]  = array( 'n' => (int) ( wp_count_posts( 'attachment' )->inherit ?? 0 ), 'label' => __( 'Médias', 'adminkit' ),       'url' => admin_url( 'upload.php' ),        'icon' => 'image' );
		$tiles[]  = array( 'n' => (int) ( $comments->approved ?? 0 ),                     'label' => __( 'Commentaires', 'adminkit' ), 'url' => admin_url( 'edit-comments.php' ), 'icon' => 'comment' );
		$tiles[]  = array( 'n' => (int) ( $users['total_users'] ?? 0 ),                   'label' => __( 'Comptes', 'adminkit' ),      'url' => admin_url( 'users.php' ),         'icon' => 'users' );

		$tiles = array_values( (array) apply_filters( 'adminkit/dashboard/stats', $tiles ) );

		echo '<div class="ak-dash__stats">';
		foreach ( $tiles as $t ) {
			printf(
				'<a class="ak-card ak-dash__stat" href="%1$s"><span class="ak-dash__stat-ic">%2$s</span>'
					. '<span class="ak-dash__stat-body"><span class="ak-dash__stat-n">%3$s</span>'
					. '<span class="ak-dash__stat-l">%4$s</span></span></a>',
				esc_url( $t['url'] ),
				self::icon( isset( $t['icon'] ) ? $t['icon'] : 'post' ),
				esc_html( number_format_i18n( (int) ( $t['n'] ?? 0 ) ) ),
				esc_html( $t['label'] )
			);
		}
		echo '</div>';
	}

	/** Recent activity card — recent posts, drafts and comments, merged by date. */
	private static function render_activity() {
		$rows = self::recent_activity();

		echo '<section class="ak-card ak-dash__card ak-dash__activity">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%1$s</h2></div>',
			esc_html__( 'Activité récente', 'adminkit' )
		);
		if ( $rows ) {
			echo '<ul class="ak-dash__list">';
			foreach ( $rows as $r ) {
				$has_thumb = ! empty( $r['thumb'] );
				$media     = $has_thumb
					? '<img class="ak-dash__item-thumb" src="' . esc_url( $r['thumb'] ) . '" alt="" loading="lazy" />'
					: self::icon( isset( $r['icon'] ) ? $r['icon'] : 'post' );
				$type      = ! empty( $r['label'] )
					? '<span class="ak-dash__item-type">' . esc_html( $r['label'] ) . '</span>'
					: '';
				// Larger page preview on hover — reuses the post-previews hover panel
				// (the JS binds to any [data-ak-full]). Only when there's a real image.
				$hover     = ( $has_thumb && ! empty( $r['full'] ) )
					? ' data-ak-full="' . esc_url( $r['full'] ) . '"'
					: '';
				printf(
					'<li class="ak-dash__item"><span class="ak-dash__item-ic ak-dash__item-ic--%1$s%6$s"%8$s>%2$s</span>'
						. '<span class="ak-dash__item-main"><span class="ak-dash__item-title">%3$s%7$s</span>'
						. '<span class="ak-dash__item-sub">%4$s</span></span>'
						. '<span class="ak-dash__item-time">%5$s</span></li>',
					esc_attr( $r['type'] ),
					$media, // safe: esc_url'd <img> or author-controlled SVG
					$r['link'] ? '<a href="' . esc_url( $r['link'] ) . '">' . esc_html( $r['title'] ) . '</a>' : esc_html( $r['title'] ),
					esc_html( $r['sub'] ),
					esc_html( $r['time'] ),
					$has_thumb ? ' ak-dash__item-ic--img' : '',
					$type,
					$hover // safe: esc_url'd data attribute
				);
			}
			echo '</ul>';
		} else {
			printf( '<p class="ak-dash__empty">%s</p>', esc_html__( 'Rien de récent pour l’instant.', 'adminkit' ) );
		}
		echo '</section>';
	}

	/** Site-health card — a composite score ring + badge + the real checks. */
	private static function render_health() {
		$h           = self::site_health();
		$score       = (int) $h['score'];
		$critical    = (int) ( $h['critical'] ?? 0 );
		$recommended = (int) ( $h['recommended'] ?? 0 );
		$good        = (int) ( $h['good'] ?? 0 );
		$issues      = $critical + $recommended;

		// Match WordPress's native overall verdict: "Bon" (green) unless there are
		// CRITICAL issues. Recommended items do NOT downgrade it — native Site Health
		// still reads "Bien" with recommendations — so only criticals turn it amber.
		$badge = $critical > 0
			? array( 'warn', __( 'À améliorer', 'adminkit' ) )
			: array( 'ok', __( 'Bon', 'adminkit' ) );

		// Ring geometry: r=26, circumference ≈ 163.36; offset = (1 - score/100) * C.
		$dash = 163.36;
		$off  = round( $dash * ( 1 - max( 0, min( 100, $score ) ) / 100 ), 2 );

		// Headline = overall verdict; sub = passed / critical / recommended breakdown.
		$headline = 0 === $issues
			? __( 'Tout fonctionne', 'adminkit' )
			/* translators: %d: number of Site Health items to address. */
			: sprintf( _n( '%d élément à améliorer', '%d éléments à améliorer', $issues, 'adminkit' ), $issues );

		$parts = array();
		if ( $good > 0 ) {
			/* translators: %d: number of passed Site Health checks. */
			$parts[] = sprintf( _n( '%d réussi', '%d réussis', $good, 'adminkit' ), $good );
		}
		if ( $critical > 0 ) {
			/* translators: %d: number of critical issues. */
			$parts[] = sprintf( _n( '%d critique', '%d critiques', $critical, 'adminkit' ), $critical );
		}
		if ( $recommended > 0 ) {
			/* translators: %d: number of recommended improvements. */
			$parts[] = sprintf( _n( '%d recommandée', '%d recommandées', $recommended, 'adminkit' ), $recommended );
		}
		$sub = $parts ? implode( ' · ', $parts ) : __( 'Analyse indisponible', 'adminkit' );

		echo '<section class="ak-card ak-dash__card ak-dash__health">';
		printf(
			'<div class="ak-card__head"><h2 class="ak-card__title">%1$s</h2><span class="ak-badge ak-badge--%2$s">%3$s</span></div>',
			esc_html__( 'Santé du site', 'adminkit' ),
			esc_attr( $badge[0] ),
			esc_html( $badge[1] )
		);

		printf(
			'<div class="ak-dash__health-top"><span class="ak-dash__ring ak-dash__ring--%5$s">'
				. '<svg viewBox="0 0 60 60" aria-hidden="true"><circle class="ak-dash__ring-bg" cx="30" cy="30" r="26"/>'
				. '<circle class="ak-dash__ring-fg" cx="30" cy="30" r="26" stroke-dasharray="%1$s" stroke-dashoffset="%2$s"/></svg>'
				. '<span class="ak-dash__ring-n">%3$s</span></span>'
				. '<span class="ak-dash__health-txt"><span class="ak-dash__health-h">%4$s</span>'
				. '<span class="ak-dash__health-s">%6$s</span></span></div>',
			esc_attr( $dash ),
			esc_attr( $off ),
			esc_html( number_format_i18n( $score ) ),
			esc_html( $headline ),
			esc_attr( $badge[0] ),
			esc_html( $sub )
		);

		printf(
			'<a class="ak-dash__more" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'site-health.php' ) ),
			esc_html__( 'Voir le rapport complet', 'adminkit' )
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
			esc_html__( 'Stockage', 'adminkit' )
		);

		// Headline: the space that REMAINS (prominent) + the site's footprint as muted
		// context. Falls back to the footprint total when disk-free is unknown.
		if ( $free > 0 ) {
			printf(
				'<p class="ak-dash__store-head"><strong>%1$s</strong> %2$s<span class="ak-dash__store-used"> · %3$s %4$s</span></p>',
				esc_html( size_format( $free, 1 ) ),
				esc_html__( 'disponibles', 'adminkit' ),
				esc_html( size_format( $total, 1 ) ),
				esc_html__( 'utilisés', 'adminkit' )
			);
		} else {
			printf(
				'<p class="ak-dash__store-head"><strong>%1$s</strong> %2$s</p>',
				esc_html( size_format( $total, 1 ) ),
				esc_html__( 'utilisés par votre site', 'adminkit' )
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
			'themes_off'     => max( 0, $themes - 1 ), // every theme but the active one
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
			$items[] = array( 'sev' => 'warn', 'icon' => 'shield', 'title' => __( 'HTTPS inactif', 'adminkit' ), 'desc' => __( 'Sécurité et référencement', 'adminkit' ), 'url' => admin_url( 'site-health.php' ), 'cta' => __( 'Détails', 'adminkit' ) );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! ( defined( 'WP_DEBUG_DISPLAY' ) && ! WP_DEBUG_DISPLAY ) ) {
			$items[] = array( 'sev' => 'warn', 'icon' => 'alert', 'title' => __( 'Mode débogage actif', 'adminkit' ), 'desc' => __( 'À désactiver en production', 'adminkit' ), 'url' => admin_url( 'site-health.php' ), 'cta' => __( 'Détails', 'adminkit' ) );
		}
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			/* translators: %s: current PHP version. */
			$items[] = array( 'sev' => 'warn', 'icon' => 'alert', 'title' => sprintf( __( 'PHP %s à mettre à jour', 'adminkit' ), PHP_VERSION ), 'desc' => __( 'Version trop ancienne', 'adminkit' ), 'url' => admin_url( 'site-health.php' ), 'cta' => __( 'Détails', 'adminkit' ) );
		}
		if ( current_user_can( 'update_core' ) ) {
			$ups = function_exists( 'wp_get_update_data' ) ? (int) ( wp_get_update_data()['counts']['total'] ?? 0 ) : 0;
			if ( $ups > 0 ) {
				/* translators: %d: number of available updates. */
				$items[] = array( 'sev' => 'warn', 'icon' => 'download', 'title' => sprintf( _n( '%d mise à jour disponible', '%d mises à jour disponibles', $ups, 'adminkit' ), $ups ), 'desc' => __( 'Cœur, extensions, thèmes', 'adminkit' ), 'url' => admin_url( 'update-core.php' ), 'cta' => __( 'Mettre à jour', 'adminkit' ) );
			}
		}

		// --- Maintenance suggestions (info) ---
		if ( current_user_can( 'activate_plugins' ) ) {
			$m = self::maintenance_counts();
			if ( $m['plugins_off'] > 0 ) {
				/* translators: %s: number of inactive plugins. */
				$items[] = array( 'sev' => 'info', 'icon' => 'plugin', 'title' => sprintf( _n( '%s extension inactive', '%s extensions inactives', $m['plugins_off'], 'adminkit' ), number_format_i18n( $m['plugins_off'] ) ), 'desc' => __( 'À supprimer si inutile', 'adminkit' ), 'url' => admin_url( 'plugins.php' ), 'cta' => __( 'Gérer', 'adminkit' ) );
			}
			if ( $m['themes_off'] > 0 ) {
				/* translators: %s: number of inactive themes. */
				$items[] = array( 'sev' => 'info', 'icon' => 'appearance', 'title' => sprintf( _n( '%s thème inactif', '%s thèmes inactifs', $m['themes_off'], 'adminkit' ), number_format_i18n( $m['themes_off'] ) ), 'desc' => __( 'À supprimer si inutile', 'adminkit' ), 'url' => admin_url( 'themes.php' ), 'cta' => __( 'Gérer', 'adminkit' ) );
			}
		}

		// --- Content to-dos (todo, only when there's something) ---
		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$comments = wp_count_comments();
		$drafts   = (int) ( $posts->draft ?? 0 ) + (int) ( $pages->draft ?? 0 );
		if ( $drafts > 0 && current_user_can( 'edit_posts' ) ) {
			/* translators: %s: number of draft posts/pages. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'edit', 'title' => sprintf( _n( '%s brouillon à finir', '%s brouillons à finir', $drafts, 'adminkit' ), number_format_i18n( $drafts ) ), 'desc' => '', 'url' => admin_url( 'edit.php?post_status=draft' ), 'cta' => __( 'Ouvrir', 'adminkit' ) );
		}
		$pending = (int) ( $posts->pending ?? 0 );
		if ( $pending > 0 && current_user_can( 'edit_posts' ) ) {
			/* translators: %s: number of posts pending review. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'page', 'title' => sprintf( _n( '%s article en attente', '%s articles en attente', $pending, 'adminkit' ), number_format_i18n( $pending ) ), 'desc' => __( 'En attente de relecture', 'adminkit' ), 'url' => admin_url( 'edit.php?post_status=pending' ), 'cta' => __( 'Ouvrir', 'adminkit' ) );
		}
		$moderate = (int) ( $comments->moderated ?? 0 );
		if ( $moderate > 0 && current_user_can( 'moderate_comments' ) ) {
			/* translators: %s: number of comments awaiting moderation. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'comment', 'title' => sprintf( _n( '%s commentaire à modérer', '%s commentaires à modérer', $moderate, 'adminkit' ), number_format_i18n( $moderate ) ), 'desc' => '', 'url' => admin_url( 'edit-comments.php?comment_status=moderated' ), 'cta' => __( 'Modérer', 'adminkit' ) );
		}
		$future = (int) ( $posts->future ?? 0 );
		if ( $future > 0 && current_user_can( 'edit_posts' ) ) {
			/* translators: %s: number of scheduled publications. */
			$items[] = array( 'sev' => 'todo', 'icon' => 'clock', 'title' => sprintf( _n( '%s publication planifiée', '%s publications planifiées', $future, 'adminkit' ), number_format_i18n( $future ) ), 'desc' => '', 'url' => admin_url( 'edit.php?post_status=future' ), 'cta' => __( 'Voir', 'adminkit' ) );
		}

		return array_values( (array) apply_filters( 'adminkit/dashboard/priorities', $items ) );
	}

	/** "Priorités" — the single "what needs my attention" block (merges the old maintenance + to-do widgets). */
	private static function render_priorities() {
		$items = self::priority_items();

		echo '<section class="ak-card ak-dash__card ak-dash__prio">';
		printf( '<div class="ak-card__head"><h2 class="ak-card__title">%s</h2></div>', esc_html__( 'Tâches prioritaires', 'adminkit' ) );

		if ( ! $items ) {
			printf(
				'<p class="ak-dash__prio-empty">%1$s<span>%2$s</span></p>',
				self::icon( 'check' ),
				esc_html__( 'Tout est en ordre — rien ne requiert votre attention aujourd’hui.', 'adminkit' )
			);
			echo '</section>';
			return;
		}

		echo '<ul class="ak-dash__prio-list">';
		foreach ( $items as $it ) {
			$sev = isset( $it['sev'] ) && in_array( $it['sev'], array( 'warn', 'info', 'todo' ), true ) ? $it['sev'] : 'info';
			printf(
				'<li class="ak-dash__prio-row ak-dash__prio-row--%1$s"><span class="ak-dash__prio-ic">%2$s</span>'
					. '<span class="ak-dash__prio-txt"><span class="ak-dash__prio-title">%3$s</span>%4$s</span>'
					. '<a class="ak-dash__prio-cta" href="%5$s">%6$s</a></li>',
				esc_attr( $sev ),
				self::icon( isset( $it['icon'] ) ? $it['icon'] : 'alert' ),
				esc_html( isset( $it['title'] ) ? $it['title'] : '' ),
				! empty( $it['desc'] ) ? '<span class="ak-dash__prio-desc">' . esc_html( $it['desc'] ) . '</span>' : '',
				esc_url( isset( $it['url'] ) ? $it['url'] : '#' ),
				esc_html( isset( $it['cta'] ) ? $it['cta'] : __( 'Voir', 'adminkit' ) )
			);
		}
		echo '</ul>';
		echo '</section>';
	}

	/* ───────────────────────── data ───────────────────────── */

	/**
	 * Recent posts + drafts + comments, merged and sorted by time (newest first).
	 *
	 * @return array<int,array>
	 */
	private static function recent_activity() {
		$rows = array();

		$has_pp = class_exists( 'AdminKit_Post_Previews' );
		$full   = $has_pp ? AdminKit_Post_Previews::full_size() : array( 1200, 800 ); // same size as the list-table hover

		$posts = get_posts( array(
			'numberposts' => 9,
			'post_status' => array( 'publish', 'draft', 'pending' ),
			'post_type'   => array( 'post', 'page' ),
		) );
		foreach ( $posts as $p ) {
			$draft     = 'publish' !== $p->post_status;
			$pt_obj    = get_post_type_object( $p->post_type );
			$editor_id = (int) get_post_meta( $p->ID, '_edit_last', true ); // last user who edited
			$author    = get_the_author_meta( 'display_name', $editor_id > 0 ? $editor_id : (int) $p->post_author );
			$status    = $draft ? __( 'Brouillon enregistré', 'adminkit' ) : __( 'Publié', 'adminkit' );
			$rows[]    = array(
				'ts'    => (int) get_post_time( 'U', true, $p ),
				'type'  => 'post',
				'icon'  => 'page' === $p->post_type ? 'page' : 'post',
				'thumb' => $has_pp ? AdminKit_Post_Previews::preview_url( $p ) : '',
				'full'  => $has_pp ? AdminKit_Post_Previews::preview_url( $p, $full[0], $full[1] ) : '',
				'label' => ( $pt_obj && ! empty( $pt_obj->labels->singular_name ) ) ? $pt_obj->labels->singular_name : __( 'Contenu', 'adminkit' ),
				'title' => $p->post_title ? $p->post_title : __( '(sans titre)', 'adminkit' ),
				/* translators: 1: author/editor name, 2: status (e.g. Published). */
				'sub'   => $author ? sprintf( __( '%1$s · %2$s', 'adminkit' ), $author, $status ) : $status,
				'link'  => get_edit_post_link( $p->ID, 'raw' ),
			);
		}

		$comments = get_comments( array( 'number' => 9, 'status' => 'all' ) );
		foreach ( $comments as $c ) {
			$pending = '0' === (string) $c->comment_approved;
			$rows[]  = array(
				'ts'    => (int) strtotime( $c->comment_date_gmt . ' UTC' ),
				'type'  => 'comment',
				'icon'  => 'comment',
				'thumb' => get_avatar_url( $c, array( 'size' => 96 ) ),
				'label' => __( 'Commentaire', 'adminkit' ),
				'title' => $c->comment_author ? $c->comment_author : __( 'Commentaire', 'adminkit' ),
				'sub'   => $pending ? __( 'Nouveau commentaire en attente', 'adminkit' ) : __( 'Nouveau commentaire', 'adminkit' ),
				'link'  => admin_url( 'comment.php?action=editcomment&c=' . (int) $c->comment_ID ),
			);
		}

		usort( $rows, static function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		} );
		$rows = array_slice( $rows, 0, 9 );

		$now = time();
		foreach ( $rows as &$r ) {
			$r['time'] = $r['ts'] ? sprintf(
				/* translators: %s: human time difference, e.g. "2 hours". */
				__( 'Il y a %s', 'adminkit' ),
				human_time_diff( $r['ts'], $now )
			) : '';
		}
		unset( $r );

		return apply_filters( 'adminkit/dashboard/activity', $rows );
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
			array( 'ok' => $https, 'label' => $https ? __( 'HTTPS actif', 'adminkit' ) : __( 'HTTPS inactif', 'adminkit' ) ),
			array(
				'ok'    => $php,
				/* translators: %s: PHP version number. */
				'label' => sprintf( __( 'PHP %s', 'adminkit' ), PHP_VERSION ) . ( $php ? ' — ' . __( 'recommandé', 'adminkit' ) : '' ),
			),
			array(
				'ok'    => 0 === $ups,
				'label' => 0 === $ups
					? __( 'Tout est à jour', 'adminkit' )
					/* translators: %d: number of available updates. */
					: sprintf( _n( '%d mise à jour disponible', '%d mises à jour disponibles', $ups, 'adminkit' ), $ups ),
			),
			array( 'ok' => $debug, 'label' => $debug ? __( 'Débogage désactivé', 'adminkit' ) : __( 'Mode débogage actif', 'adminkit' ) ),
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
			array( 'key' => 'media',   'label' => __( 'Médias', 'adminkit' ),          'bytes' => (int) $cached['media'],   'color' => 'var(--ak-primary)' ),
			array( 'key' => 'db',      'label' => __( 'Base de données', 'adminkit' ), 'bytes' => (int) $cached['db'],      'color' => 'var(--ak-warning)' ),
			array( 'key' => 'plugins', 'label' => __( 'Extensions', 'adminkit' ),      'bytes' => (int) $cached['plugins'], 'color' => 'var(--ak-success)' ),
			array( 'key' => 'themes',  'label' => __( 'Thèmes', 'adminkit' ),          'bytes' => (int) $cached['themes'],  'color' => 'var(--ak-info)' ),
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
