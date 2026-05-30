<?php
/**
 * Post previews — a screenshot thumbnail in post-type list tables.
 *
 * Adds a small thumbnail column immediately left of the Title in the list
 * table of every publicly-viewable post type (posts, pages, and public CPTs —
 * Bricks templates, Woo products, ACF-registered types, …). Hovering a
 * thumbnail opens a larger floating preview, so you can eyeball a page before
 * opening it — no need to leave wp-admin to remember what a page looks like.
 *
 * ── Thumbnail source ────────────────────────────────────────────────────────
 * The post's featured image (the product image, for WooCommerce products) — a
 * predictable, instant thumbnail with no external dependency. When there's no
 * featured image, a clean icon placeholder shows instead of a broken image.
 *
 * A live WordPress.com mShots screenshot of the post's own rendered page is
 * available as an alternative, but OFF by default: it only reaches public URLs
 * and can briefly show mShots' own "generating" placeholder until the screenshot
 * is ready. Opt in per-site via the provider filter:
 *     add_filter( 'adminkit/post_previews/provider', static function () { return 'mshots'; } );
 * mShots screenshots refresh once per `refresh_interval` window (a coarse cache
 * key in the request URL; set the interval to 0 to pin them).
 *
 * ── Modularity / the future settings ("CBI") page ───────────────────────────
 * The whole feature is gated by is_enabled(), which reads the
 * `post_previews_enabled` setting (registered here, default ON). The future
 * settings page only has to write
 *   adminkit_settings['post_previews_enabled'] = false
 * to switch it off — no code change. Which post types get the column is curated
 * through a filter, and the image sizes and refresh cadence are filterable too,
 * so a settings UI can drive any of them later without touching this class.
 *
 * Filters:
 *   adminkit/post_previews/enabled          (bool)                         master on/off
 *   adminkit/post_previews/post_types       (string[] $types)              list tables that get the column
 *   adminkit/post_previews/thumb_size       (int[2] [w,h])                 column thumbnail px (mShots request)
 *   adminkit/post_previews/full_size        (int[2] [w,h])                 hover preview px (mShots request)
 *   adminkit/post_previews/refresh_interval (int $seconds)                 screenshot refresh window (0 = pin)
 *   adminkit/post_previews/thumb_url        (string $url, WP_Post, $w, $h) override the small URL
 *   adminkit/post_previews/full_url         (string $url, WP_Post, $w, $h) override the large URL
 *
 * The hover panel is driven by assets/js/wp-core/post-previews.js, loaded as a
 * footer script only on a targeted list-table screen.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Post_Previews {

	/** List-table column id. WP renders it as the `.column-ak_preview` cell. */
	const COLUMN = 'ak_preview';

	/** mShots screenshot endpoint — target URL is appended url-encoded. */
	const MSHOTS_BASE = 'https://s0.wordpress.com/mshots/v1/';

	/** Memoized target post-type list (per request). @var string[]|null */
	private static $targets = null;

	/** Post type of the list-table screen currently being hooked. @var string */
	private static $screen_pt = '';

	/**
	 * Register the setting + wire the screen hook. Called once from the
	 * plugin orchestrator. The setting is registered unconditionally so the
	 * future settings page can discover it even while the feature is off.
	 *
	 * @return void
	 */
	public static function init() {
		AdminKit_Settings::register( 'post_previews_enabled', array( 'default' => true ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		AdminKit_Assets::register( array(
			'handle'    => 'adminkit-post-previews',
			'src'       => 'assets/css/wp-components/post-previews.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );

		add_action( 'current_screen', array( __CLASS__, 'maybe_hook_screen' ) );

		// Quick Edit saves a single row over admin-ajax.php ('inline-save'), where
		// `current_screen` never fires — so maybe_hook_screen() is skipped and the
		// row WP regenerates would drop our column, leaving it one cell short of the
		// header and shifting every value one column to the left. Re-attach the
		// column for that one request; priority 0 runs before core's inline-save
		// handler renders the row.
		add_action( 'wp_ajax_inline-save', array( __CLASS__, 'hook_inline_save' ), 0 );
	}

	/**
	 * Master switch. Reads the registered setting (default ON) and runs it
	 * through a filter so code can force it either way.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'adminkit/post_previews/enabled', AdminKit_Settings::get( 'post_previews_enabled' ) );
	}

	/**
	 * Whether AdminKit should load the previews CSS on this screen — true on
	 * the list table (`edit` base) of a targeted post type. Drives the asset
	 * registry's `condition` closure.
	 *
	 * @param \WP_Screen|null $screen
	 * @return bool
	 */
	public static function owns_screen( $screen ) {
		return $screen && 'edit' === $screen->base && self::is_target( $screen->post_type );
	}

	/**
	 * On a targeted list-table screen, wire the column filter + render action
	 * for that post type and queue the footer script. `current_screen` fires
	 * after `init` (so every CPT is registered) and before the table renders.
	 *
	 * @param \WP_Screen $screen
	 * @return void
	 */
	public static function maybe_hook_screen( $screen ) {
		if ( ! self::owns_screen( $screen ) ) {
			return;
		}
		$pt = $screen->post_type;
		self::$screen_pt = $pt;

		// Add the column on the SCREEN-ID filter at a late priority. WP seeds
		// the list table's own columns into `manage_{$screen->id}_columns` at
		// priority 0, so this is the last word on the column set — running at 99
		// lands our column even when a host rebuilds the set from scratch (e.g.
		// WooCommerce's product table, whose define_columns() would otherwise
		// drop ours or shove it to the far right).
		add_filter( "manage_{$screen->id}_columns", array( __CLASS__, 'add_column' ), 99 );

		// The custom-column render action fires for every post type, pages
		// included (the per-type action is the last one core runs), for any
		// non-built-in column present in the final set — so a single hook
		// covers posts, pages and CPTs with no risk of a double render.
		add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Re-attach the preview column during a Quick Edit inline-save.
	 *
	 * Quick Edit posts to admin-ajax.php, which never fires `current_screen`, so
	 * maybe_hook_screen() doesn't run and the row WP regenerates omits our column —
	 * leaving it one cell short of the header and shifting every value one column
	 * to the left. Wire the same column filter + render action as maybe_hook_screen(),
	 * scoped to the request's own (sanitized) screen + post type. The save and its
	 * nonce / capability checks stay core's job — this only adds a display filter —
	 * so reading $_POST to route it is safe. No-op for a non-targeted post type.
	 *
	 * @return void
	 */
	public static function hook_inline_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core verifies the inline-save nonce; these are read only to wire a display filter.
		$post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$screen_name = isset( $_POST['screen'] ) ? sanitize_text_field( wp_unslash( $_POST['screen'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! self::is_target( $post_type ) ) {
			return;
		}
		self::$screen_pt = $post_type;

		// Resolve the screen exactly as core's inline-save handler does
		// (_get_list_table → convert_to_screen) so our `manage_{id}_columns` filter
		// name matches the one WP_Posts_List_Table runs for the regenerated row.
		$screen = $screen_name ? convert_to_screen( $screen_name ) : null;
		if ( $screen && ! empty( $screen->id ) ) {
			add_filter( "manage_{$screen->id}_columns", array( __CLASS__, 'add_column' ), 99 );
		}
		add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Place the preview column. Normally just after the checkbox (so it sits to
	 * the left of the Title). On WooCommerce products — which already ship a
	 * product-image 'thumb' column — it takes that column's slot instead, so the
	 * row keeps a single image column rather than two.
	 *
	 * @param array $columns
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( isset( $columns[ self::COLUMN ] ) ) {
			return $columns;
		}

		// Visible column header. English source string — translate to "Aperçu"
		// etc. via the adminkit text domain (e.g. Loco Translate).
		$label = esc_html__( 'Preview', 'adminkit' );

		// On products, replace WC's product-image column; elsewhere, follow cb.
		$replace = ( 'product' === self::$screen_pt && isset( $columns['thumb'] ) );

		$out = array();
		foreach ( $columns as $key => $value ) {
			if ( $replace && 'thumb' === $key ) {
				$out[ self::COLUMN ] = $label; // our preview takes the image slot
				continue;
			}
			$out[ $key ] = $value;
			if ( ! $replace && 'cb' === $key ) {
				$out[ self::COLUMN ] = $label;
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out = array( self::COLUMN => $label ) + $out; // no cb/thumb anchor
		}
		return $out;
	}

	/**
	 * Render the thumbnail cell for our column (no-op for any other column).
	 *
	 * @param string $column
	 * @param int    $post_id
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		echo self::markup( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup()
	}

	/**
	 * Build the thumbnail markup for a post. On a public site it's an mShots
	 * screenshot of the post's permalink (the real page); on a local host —
	 * where mShots can't reach the site and would only return its "generating"
	 * placeholder — it falls back to the featured image (the product image, for
	 * WooCommerce products) for a cleaner view. With neither, a flat placeholder.
	 * URLs are escaped here.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private static function markup( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		list( $tw, $th ) = self::thumb_size();
		list( $fw, $fh ) = self::full_size();

		$permalink = get_permalink( $post );

		// Featured image by default (predictable, instant, no external service). A
		// live mShots screenshot is opt-in per-site via the provider filter.
		$provider = apply_filters( 'adminkit/post_previews/provider', 'featured' );

		if ( $permalink && 'mshots' === $provider ) {
			$thumb = self::mshots_url( $permalink, $tw, $th );
			$full  = self::mshots_url( $permalink, $fw, $fh );
		} else {
			// No public screenshot → the featured image is nicer.
			$thumb = (string) get_the_post_thumbnail_url( $post, 'medium' );
			$full  = (string) get_the_post_thumbnail_url( $post, 'large' );
		}

		$thumb = apply_filters( 'adminkit/post_previews/thumb_url', $thumb, $post, $tw, $th );
		$full  = apply_filters( 'adminkit/post_previews/full_url', $full, $post, $fw, $fh );

		if ( '' === (string) $thumb ) {
			return '<span class="ak-preview ak-preview--empty" aria-hidden="true"></span>';
		}

		// A larger screenshot shows on hover (post-previews.js); store its URL on
		// the cell. The thumbnail itself is non-interactive — screenshots refresh
		// automatically once per window via the `v` bucket in mshots_url().
		return sprintf(
			'<span class="ak-preview" data-ak-full="%1$s">'
				. '<img class="ak-preview__thumb" src="%2$s" '
				. 'width="56" height="42" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="" />'
				. '</span>',
			esc_url( $full ),
			esc_url( $thumb )
		);
	}

	/**
	 * Best preview-thumbnail URL for a post, reusing this feature's provider logic
	 * so it matches the list-table previews: an mShots screenshot of the permalink on
	 * a public site, the featured image on a local host (mShots can't reach it) or for
	 * non-published posts, and '' when neither is available. Public so other surfaces
	 * (e.g. the dashboard's recent-activity list) can show the very same thumbnail.
	 *
	 * @param int|\WP_Post $post
	 * @param int          $w
	 * @param int          $h
	 * @return string
	 */
	public static function preview_url( $post, $w = 160, $h = 120 ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$provider = apply_filters( 'adminkit/post_previews/provider', 'featured' );
		if ( 'mshots' === $provider && 'publish' === get_post_status( $post ) ) {
			$permalink = get_permalink( $post );
			$url       = $permalink ? self::mshots_url( $permalink, $w, $h ) : '';
		} else {
			// Featured image — pick a registered size that covers the requested width
			// crisply (the dashboard cards ask for a big one; the list column a small).
			$size = $w >= 768 ? 'large' : ( $w >= 384 ? 'medium_large' : 'medium' );
			$url  = (string) get_the_post_thumbnail_url( $post, $size );
		}
		return (string) apply_filters( 'adminkit/post_previews/thumb_url', $url, $post, $w, $h );
	}

	/**
	 * Build an mShots screenshot URL for a target URL at the given size.
	 *
	 * @param string $url
	 * @param int    $w
	 * @param int    $h
	 * @return string
	 */
	private static function mshots_url( $url, $w, $h ) {
		$args = array( 'w' => $w, 'h' => $h );

		// Rotate the cache key once per interval so the screenshot refreshes
		// instead of staying frozen forever — yet the URL is stable WITHIN the
		// interval, so the browser and mShots keep serving the cached image (no
		// re-fetch on every load). Default weekly; 0 disables the rotation.
		$interval = (int) apply_filters( 'adminkit/post_previews/refresh_interval', WEEK_IN_SECONDS );
		if ( $interval > 0 ) {
			$args['v'] = (int) floor( time() / $interval );
		}

		return add_query_arg( $args, self::MSHOTS_BASE . rawurlencode( $url ) );
	}

	/**
	 * Post types that get a preview column: every publicly-viewable type
	 * except attachments (the Media library uses a different list table).
	 * Memoized per request; curate via the filter.
	 *
	 * @return string[]
	 */
	public static function target_post_types() {
		if ( null !== self::$targets ) {
			return self::$targets;
		}
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name || ! is_post_type_viewable( $pt ) ) {
				continue;
			}
			$types[] = $pt->name;
		}
		$types = apply_filters( 'adminkit/post_previews/post_types', $types );
		self::$targets = array_values( array_unique( array_filter( (array) $types ) ) );
		return self::$targets;
	}

	/**
	 * @param string $post_type
	 * @return bool
	 */
	public static function is_target( $post_type ) {
		return $post_type && in_array( $post_type, self::target_post_types(), true );
	}

	/**
	 * Column thumbnail request size [w, h]. 4:3, filterable.
	 *
	 * @return int[]
	 */
	private static function thumb_size() {
		$s = (array) apply_filters( 'adminkit/post_previews/thumb_size', array( 160, 120 ) );
		return array( max( 1, (int) ( $s[0] ?? 160 ) ), max( 1, (int) ( $s[1] ?? 120 ) ) );
	}

	/**
	 * Hover preview request size [w, h]. 3:2, filterable. Public so other surfaces
	 * (the dashboard's recent-activity hover) request the SAME size — one source of
	 * truth. Sized ~2× the on-screen panel so it stays crisp on HiDPI screens.
	 *
	 * @return int[]
	 */
	public static function full_size() {
		$s = (array) apply_filters( 'adminkit/post_previews/full_size', array( 1200, 800 ) );
		return array( max( 1, (int) ( $s[0] ?? 1200 ) ), max( 1, (int) ( $s[1] ?? 800 ) ) );
	}

	/**
	 * Enqueue the footer script that builds the hover preview panel. i18n labels
	 * ride along as an inline bootstrap. No-op when AdminKit isn't styling this
	 * screen. Hooked from maybe_hook_screen(), so it only fires on a targeted
	 * list-table screen.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-post-previews',
			'assets/js/wp-core/post-previews.js',
			array(),
			'window.AdminKitPostPreviews=' . wp_json_encode( array(
				'loading' => __( 'Loading preview…', 'adminkit' ),
				'broken'  => __( 'Preview unavailable', 'adminkit' ),
			) ) . ';'
		);
	}
}
