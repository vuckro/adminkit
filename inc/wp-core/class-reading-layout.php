<?php
/** Purpose-led grouping for native Reading settings and taxonomy lists. */
defined( 'ABSPATH' ) || exit;

class AdminKit_Core_Reading_Layout {
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	public static function enqueue() {
		$screen = get_current_screen();
		if ( ! $screen || ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		if ( 'options-reading' === $screen->id ) {
			$strings = array(
				'home' => __( 'Homepage & archives', 'adminkit' ),
				'homeDesc' => __( 'Choose your homepage content and the number of posts shown in archives.', 'adminkit' ),
				'feeds' => __( 'RSS feeds', 'adminkit' ),
				'feedsDesc' => __( 'Set how many posts feeds include and how much content they share.', 'adminkit' ),
				'visibility' => __( 'Search engine visibility', 'adminkit' ),
				'visibilityDesc' => __( 'Choose whether to ask search engines to avoid indexing this site.', 'adminkit' ),
			);
			AdminKit_Assets::enqueue_script( 'adminkit-reading-layout', 'assets/js/wp-screens/options-reading.js', array(), 'window.AdminKitReadingLayout=' . wp_json_encode( $strings ) . ';' );
		}
		if ( 'edit-tags' === $screen->base ) {
			$taxonomy = get_taxonomy( $screen->taxonomy );
			$strings = array(
				'title' => $taxonomy ? $taxonomy->labels->items_list : __( 'Manage terms', 'adminkit' ),
				'desc' => __( 'Find, edit and organize existing terms.', 'adminkit' ),
			);
			AdminKit_Assets::enqueue_script( 'adminkit-taxonomy-layout', 'assets/js/wp-screens/taxonomy.js', array( 'adminkit-list-table-chrome' ), 'window.AdminKitTaxonomyLayout=' . wp_json_encode( $strings ) . ';' );
		}
	}
}
