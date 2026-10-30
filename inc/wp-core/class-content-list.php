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
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || ! $screen->post_type || 'attachment' === $screen->post_type ) {
			return;
		}
		add_filter( 'posts_orderby', array( __CLASS__, 'published_first' ), 20, 2 );
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		// Only a plain list arrival is a default: preserve every native/custom filter.
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
		$screen = get_current_screen();
		if ( ! is_admin() || ! $screen || 'edit' !== $screen->base || ! $query->is_main_query() || $query->get( 'post_type' ) !== $screen->post_type ) {
			return $orderby;
		}
		$status = $query->get( 'post_status' );
		if ( ( $status && 'all' !== $status ) || $query->get( 's' ) || isset( $_GET['orderby'] ) || isset( $_GET['order'] ) ) {
			return $orderby;
		}
		global $wpdb;
		$priority = "CASE WHEN {$wpdb->posts}.post_status = 'publish' THEN 0 ELSE 1 END ASC";
		return $priority . ( $orderby ? ', ' . $orderby : '' );
	}
}
