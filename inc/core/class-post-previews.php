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
 * ── Thumbnail source (the "provider") ───────────────────────────────────────
 *   'mshots'    WordPress.com's mShots service screenshots the live URL. The
 *               default on a public site.
 *               ⚠ mShots runs on wp.com's servers and CANNOT reach a local dev
 *               host (localhost, *.local, *.test, private IPs). On those hosts,
 *               and for any non-published post (mShots can't shoot a non-public
 *               URL), the provider falls back to 'featured' so something useful
 *               still shows. Expose the site (e.g. Local's "Live Link") to get
 *               real screenshots in development.
 *   'featured'  The post's featured image.
 *
 * Whatever the provider, the browser-side <img> degrades gracefully:
 *   primary src → (onerror) featured image → (onerror) a flat placeholder box,
 * so a missing screenshot never shows a broken image.
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
 *   adminkit/post_previews/provider     (string $provider, WP_Post)    'mshots' | 'featured'
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

		// In WP these dynamic hooks fire for EVERY post type, pages included
		// (the per-type filter/action is the last one core runs), so a single
		// pair covers posts, pages and CPTs with no risk of a double render.
		add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'add_column' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );

		add_action( 'admin_footer', array( __CLASS__, 'print_script' ) );
	}

	/**
	 * Insert the preview column right after the checkbox (so it sits to the
	 * left of Title). Falls back to prepending when there's no checkbox.
	 *
	 * @param array $columns
	 * @return array
	 */
	public static function add_column( $columns ) {
		$label = '<span class="screen-reader-text">' . esc_html__( 'Preview', 'adminkit' ) . '</span>';

		if ( ! isset( $columns['cb'] ) ) {
			return array( self::COLUMN => $label ) + $columns;
		}

		$out = array();
		foreach ( $columns as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'cb' === $key ) {
				$out[ self::COLUMN ] = $label;
			}
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
	 * Build the thumbnail markup for a post. URLs are escaped here; the
	 * browser-side fallback chain lives in the data-* attributes.
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

		$feat_thumb = (string) get_the_post_thumbnail_url( $post, 'medium' );
		$feat_full  = (string) get_the_post_thumbnail_url( $post, 'large' );

		if ( 'featured' === self::provider_for( $post ) ) {
			$thumb = $feat_thumb;
			$full  = $feat_full;
		} else {
			$permalink = get_permalink( $post );
			$thumb     = $permalink ? self::mshots_url( $permalink, $tw, $th ) : $feat_thumb;
			$full      = $permalink ? self::mshots_url( $permalink, $fw, $fh ) : $feat_full;
		}

		$thumb = apply_filters( 'adminkit/post_previews/thumb_url', $thumb, $post, $tw, $th );
		$full  = apply_filters( 'adminkit/post_previews/full_url', $full, $post, $fw, $fh );

		// No screenshot and no featured image → flat placeholder, nothing to hover.
		if ( '' === $thumb && '' === $feat_thumb ) {
			return '<span class="ak-preview ak-preview--empty" aria-hidden="true"></span>';
		}
		if ( '' === $thumb ) {
			$thumb = $feat_thumb;
		}

		return sprintf(
			'<span class="ak-preview" data-ak-full="%1$s" data-ak-full-fallback="%2$s">'
				. '<img class="ak-preview__thumb" src="%3$s" data-ak-fallback="%4$s" '
				. 'width="56" height="42" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="" />'
				. '</span>',
			esc_url( $full ),
			esc_url( $feat_full ),
			esc_url( $thumb ),
			esc_url( $feat_thumb )
		);
	}

	/**
	 * Decide the thumbnail source for a post. mShots everywhere except where
	 * it can't reach: local hosts and non-published posts fall back to the
	 * featured image.
	 *
	 * @param \WP_Post $post
	 * @return string 'mshots' | 'featured'
	 */
	private static function provider_for( $post ) {
		$provider = ( self::is_local_site() || 'publish' !== get_post_status( $post ) ) ? 'featured' : 'mshots';
		return apply_filters( 'adminkit/post_previews/provider', $provider, $post );
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
	 * Whether this site is a local/dev host mShots can't reach. Conservative
	 * on purpose — a public site must always resolve to mShots. Memoized.
	 *
	 * @return bool
	 */
	private static function is_local_site() {
		static $local = null;
		if ( null !== $local ) {
			return $local;
		}

		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			return $local = true;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( $host );
		if ( '' === $host ) {
			return $local = false;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return $local = true;
		}
		foreach ( array( '.local', '.test', '.localhost', '.wip', '.example', '.invalid' ) as $tld ) {
			if ( substr( $host, -strlen( $tld ) ) === $tld ) {
				return $local = true;
			}
		}
		// RFC 1918 private ranges (10/8, 172.16/12, 192.168/16).
		if ( preg_match( '/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host ) ) {
			return $local = true;
		}
		return $local = false;
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

	// Thumbnail fallback chain: primary src -> featured image -> broken marker.
	function wireThumb(img) {
		if (img.dataset.akWired) { return; }
		img.dataset.akWired = '1';
		img.addEventListener('error', function () {
			var fb = img.getAttribute('data-ak-fallback');
			if (fb && img.getAttribute('src') !== fb) { img.setAttribute('src', fb); return; }
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
		var fb = span.getAttribute('data-ak-full-fallback');
		pop.className = 'is-visible is-loading';
		popImg.onload = function () { pop.classList.remove('is-loading'); position(span); };
		popImg.onerror = function () {
			if (fb && popImg.getAttribute('src') !== fb) { popImg.setAttribute('src', fb); return; }
			pop.classList.remove('is-loading');
			pop.classList.add('is-broken');
			position(span);
		};
		popImg.setAttribute('src', full || fb || '');
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
