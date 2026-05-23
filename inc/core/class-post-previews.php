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
 * A WordPress.com mShots screenshot of the post's own permalink — the actual
 * rendered page. mShots only reaches PUBLIC URLs, so on a local dev host
 * (localhost, *.local, *.test) — where it would only ever return its
 * "generating" placeholder — we fall back to the post's featured image for a
 * cleaner view. When there's neither a usable screenshot nor a featured image,
 * a flat placeholder shows instead of a broken image.
 *
 *   ⚠ On a public site a just-published page can briefly show mShots' own
 *   "generating" placeholder until the screenshot is ready (it lands on the next
 *   view). That transient state is left as-is — kept deliberately simple.
 *
 * ── Modularity / the future settings ("CBI") page ───────────────────────────
 * The whole feature is gated by is_enabled(), which reads the
 * `post_previews_enabled` setting (registered here, default ON). The future
 * settings page only has to write
 *   adminkit_settings['post_previews_enabled'] = false
 * to switch it off — no code change. Which post types get the column is curated
 * through a filter, and the provider + image sizes are filterable too, so a
 * settings UI can drive any of them later without touching this class.
 *
 * Filters:
 *   adminkit/post_previews/enabled      (bool)                         master on/off
 *   adminkit/post_previews/post_types   (string[] $types)              list tables that get the column
 *   adminkit/post_previews/thumb_size   (int[2] [w,h])                 column thumbnail px (mShots request)
 *   adminkit/post_previews/full_size    (int[2] [w,h])                 hover preview px (mShots request)
 *   adminkit/post_previews/thumb_url    (string $url, WP_Post, $w, $h) override the small URL
 *   adminkit/post_previews/full_url     (string $url, WP_Post, $w, $h) override the large URL
 *
 * The hover panel is built by a small inline footer script (like
 * AdminKit_Core_List_Table_Chrome) so the asset registry stays CSS-only.
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
			'src'       => 'assets/css/components/post-previews.css',
			'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
			'context'   => 'admin',
			'condition' => array( __CLASS__, 'owns_screen' ),
		) );

		add_action( 'current_screen', array( __CLASS__, 'maybe_hook_screen' ) );
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

		add_action( 'admin_footer', array( __CLASS__, 'print_script' ) );
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

		$label = '<span class="screen-reader-text">' . esc_html__( 'Preview', 'adminkit' ) . '</span>';

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

		if ( $permalink && ! self::is_local_site() ) {
			$thumb = self::mshots_url( $permalink, $tw, $th );
			$full  = self::mshots_url( $permalink, $fw, $fh );
		} else {
			// No public screenshot possible here → the featured image is nicer.
			$thumb = (string) get_the_post_thumbnail_url( $post, 'medium' );
			$full  = (string) get_the_post_thumbnail_url( $post, 'large' );
		}

		$thumb = apply_filters( 'adminkit/post_previews/thumb_url', $thumb, $post, $tw, $th );
		$full  = apply_filters( 'adminkit/post_previews/full_url', $full, $post, $fw, $fh );

		if ( '' === (string) $thumb ) {
			return '<span class="ak-preview ak-preview--empty" aria-hidden="true"></span>';
		}

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
	 * Build an mShots screenshot URL for a target URL at the given size.
	 *
	 * @param string $url
	 * @param int    $w
	 * @param int    $h
	 * @return string
	 */
	private static function mshots_url( $url, $w, $h ) {
		return add_query_arg(
			array( 'w' => $w, 'h' => $h ),
			self::MSHOTS_BASE . rawurlencode( $url )
		);
	}

	/**
	 * Whether this is a local/dev host mShots can't screenshot. Kept simple —
	 * covers the common local cases; a public site always resolves to false.
	 *
	 * @return bool
	 */
	private static function is_local_site() {
		static $local = null;
		if ( null === $local ) {
			$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
				|| (bool) preg_match( '/\.(local|test|localhost)$/', $host );
		}
		return $local;
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
	 * Hover preview request size [w, h]. 3:2, filterable.
	 *
	 * @return int[]
	 */
	private static function full_size() {
		$s = (array) apply_filters( 'adminkit/post_previews/full_size', array( 900, 600 ) );
		return array( max( 1, (int) ( $s[0] ?? 900 ) ), max( 1, (int) ( $s[1] ?? 600 ) ) );
	}

	/**
	 * Print the inline footer script that builds the hover preview panel.
	 * No-op when AdminKit isn't styling this screen.
	 *
	 * @return void
	 */
	public static function print_script() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		$loading_label = esc_js( __( 'Loading preview…', 'adminkit' ) );
		$broken_label  = esc_js( __( 'Preview unavailable', 'adminkit' ) );
		?>
<script id="adminkit-post-previews">
(function () {
	if (!document.querySelector('.ak-preview')) { return; }
	var LOADING_LABEL = '<?php echo $loading_label; ?>';
	var BROKEN_LABEL = '<?php echo $broken_label; ?>';

	// If a screenshot fails to load, flag its cell as broken (a flat
	// placeholder) instead of showing the browser's broken-image glyph.
	function wireThumb(img) {
		if (img.dataset.akWired) { return; }
		img.dataset.akWired = '1';
		img.addEventListener('error', function () {
			var span = img.closest('.ak-preview');
			if (span) { span.classList.add('ak-preview--broken'); }
		});
	}
	Array.prototype.forEach.call(document.querySelectorAll('.ak-preview__thumb'), wireThumb);

	// One shared floating panel, reused across rows.
	var pop = null, popImg = null, hideTimer = null, current = null;

	function ensurePop() {
		if (pop) { return; }
		pop = document.createElement('div');
		pop.id = 'ak-preview-pop';
		pop.setAttribute('role', 'tooltip');
		popImg = document.createElement('img');
		popImg.alt = '';
		pop.appendChild(popImg);
		pop.setAttribute('data-loading-label', LOADING_LABEL);
		pop.setAttribute('data-broken-label', BROKEN_LABEL);
		document.body.appendChild(pop);
		pop.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
		pop.addEventListener('mouseleave', scheduleHide);
	}

	function position(anchor) {
		var r = anchor.getBoundingClientRect();
		var pw = pop.offsetWidth, ph = pop.offsetHeight, gap = 12;
		var left = r.right + gap;
		if (left + pw > window.innerWidth - 8) { left = r.left - gap - pw; } // flip left
		if (left < 8) { left = 8; }
		var top = r.top + r.height / 2 - ph / 2; // vertically centered on the thumb
		if (top < 8) { top = 8; }
		if (top + ph > window.innerHeight - 8) { top = window.innerHeight - 8 - ph; }
		pop.style.left = Math.round(left) + 'px';
		pop.style.top = Math.round(top) + 'px';
	}

	function show(span) {
		ensurePop();
		clearTimeout(hideTimer);
		current = span;
		var full = span.getAttribute('data-ak-full');
		pop.className = 'is-visible is-loading';
		popImg.onload = function () { pop.classList.remove('is-loading'); position(span); };
		popImg.onerror = function () {
			pop.classList.remove('is-loading');
			pop.classList.add('is-broken');
			position(span);
		};
		popImg.setAttribute('src', full || '');
		position(span);
	}

	function scheduleHide() { hideTimer = setTimeout(hide, 120); }

	function hide() {
		if (!pop) { return; }
		pop.classList.remove('is-visible');
		current = null;
	}

	document.addEventListener('mouseover', function (e) {
		var span = e.target.closest ? e.target.closest('.ak-preview') : null;
		if (span && span !== current && !span.classList.contains('ak-preview--empty')) { show(span); }
	});
	document.addEventListener('mouseout', function (e) {
		var span = e.target.closest ? e.target.closest('.ak-preview') : null;
		if (span) { scheduleHide(); }
	});
	window.addEventListener('scroll', hide, true);
	window.addEventListener('resize', hide);
})();
</script>
		<?php
	}
}
