<?php
/** Published-first behavior for native article, page and CPT list screens. @package AdminKit */
defined( 'ABSPATH' ) || exit;

class AdminKit_Content_List {

	public static function init() {
		if ( ! apply_filters( 'adminkit/active_items_first/enabled', AdminKit_Settings::get( 'active_items_first_enabled' ) ) ) {
			return;
		}
		add_action( 'load-edit.php', array( __CLASS__, 'on_content_screen' ) );
	}

	public static function on_content_screen() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) { return; }
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || ! $screen->post_type || 'attachment' === $screen->post_type ) {
			return;
		}
		if ( AdminKit_Settings::get( 'active_items_first_sort' ) ) {
			add_filter( 'posts_orderby', array( __CLASS__, 'published_first' ), 20, 2 );
		}
		if ( ! AdminKit_Settings::get( 'active_items_first_content_default' ) ) { return; }
		if ( ! empty( $screen->id ) ) {
			add_filter( 'views_' . $screen->id, array( __CLASS__, 'preserve_all_view_link' ), 10, 1 );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'GET' !== $method ) {
			return;
		}
		// Only a plain list arrival is a default: preserve every native/custom filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WP list-table URL filter inspection.
		foreach ( array_keys( $_GET ) as $key ) {
			if ( 'post_type' !== $key ) {
				return;
			}
		}
		// The native All link may have no status parameter. Keep it usable.
		$referer = wp_get_referer();
		if ( $referer && 'edit.php' === basename( (string) wp_parse_url( $referer, PHP_URL_PATH ) ) ) {
			parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $args );
			$previous_type = isset( $args['post_type'] ) && is_string( $args['post_type'] ) ? $args['post_type'] : 'post';
			if ( $previous_type === $screen->post_type ) {
				return;
			}
		}
		$counts = wp_count_posts( $screen->post_type, 'readable' );
		if ( empty( $counts->publish ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( array( 'post_type' => $screen->post_type, 'post_status' => 'publish' ), self_admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function published_first( $orderby, $query ) {
		if ( ! AdminKit_Settings::get( 'active_items_first_sort' ) ) { return $orderby; }
		$screen = get_current_screen();
		if ( ! is_admin() || ! $screen || 'edit' !== $screen->base || ! $query->is_main_query() || $query->get( 'post_type' ) !== $screen->post_type ) {
			return $orderby;
		}
		$status = $query->get( 'post_status' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WP list-table URL sort inspection.
		if ( ( $status && 'all' !== $status ) || $query->get( 's' ) || isset( $_GET['orderby'] ) || isset( $_GET['order'] ) ) {
			return $orderby;
		}
		global $wpdb;
		$priority = "CASE WHEN {$wpdb->posts}.post_status = 'publish' THEN 0 ELSE 1 END ASC";
		return $priority . ( $orderby ? ', ' . $orderby : '' );
	}

	/**
	 * Ensure the native "All" view link on single-author sites carries `all_posts=1`
	 * so clicking "All" never loops back to `post_status=publish` when the browser
	 * sends `Referrer-Policy: no-referrer`.
	 *
	 * @param array<string,string> $views Status view links.
	 * @return array<string,string>
	 */
	public static function preserve_all_view_link( $views ) {
		if ( ! is_array( $views ) || empty( $views['all'] ) || ! is_string( $views['all'] ) ) {
			return $views;
		}
		if ( false !== strpos( $views['all'], 'all_posts=' ) || false !== strpos( $views['all'], 'post_status=' ) ) {
			return $views;
		}
		$views['all'] = preg_replace_callback(
			'/\bhref=(["\'])([^"\']+)\1/i',
			static function ( $m ) {
				$sep = ( false === strpos( $m[2], '?' ) ) ? '?' : '&amp;';
				return 'href=' . $m[1] . $m[2] . $sep . 'all_posts=1' . $m[1];
			},
			$views['all'],
			1
		);
		return $views;
	}
}
