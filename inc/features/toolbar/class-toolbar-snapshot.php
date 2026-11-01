<?php
/**
 * Pristine toolbar collection, context metadata and snapshot cache.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Toolbar_Snapshot {

	const SNAPSHOT_KEY = 'adminkit_toolbar_nodes';
	const FRONTEND_SNAPSHOT_KEY = 'adminkit_toolbar_nodes_frontend';

	/**
	 * GET payload — the pristine top-level nodes for both `admin` and `frontend`
	 * contexts, independently of saved layouts.
	 *
	 * @return array{admin:array,frontend:array}
	 */
	public static function read() {
		$admin_nodes = get_transient( self::SNAPSHOT_KEY );
		if ( ! is_array( $admin_nodes ) || ! $admin_nodes ) {
			$admin_nodes = self::snapshot_nodes();
		}
		$admin_nodes = self::filter_context_nodes( $admin_nodes, 'admin' );

		$frontend_nodes = get_transient( self::FRONTEND_SNAPSHOT_KEY );
		if ( ! is_array( $frontend_nodes ) || ! $frontend_nodes ) {
			$frontend_nodes = self::snapshot_frontend_nodes( $admin_nodes );
		} else {
			$frontend_nodes = self::ensure_frontend_nodes( self::filter_context_nodes( $frontend_nodes, 'frontend' ) );
		}

		return array( 'admin' => $admin_nodes, 'frontend' => $frontend_nodes );
	}

	/**
	 * Clear cached node snapshots when branding or site-icon settings change so the
	 * editor immediately reflects the updated `site-name` / `wp-logo` state.
	 *
	 * @return void
	 */
	public static function flush_snapshots() {
		delete_transient( self::SNAPSHOT_KEY );
		delete_transient( self::FRONTEND_SNAPSHOT_KEY );
	}

	/**
	 * Capture the live top-level nodes from a REAL admin-bar render (admin context) into a
	 * transient, BEFORE apply() reorders/hides. Hooked only on the Toolbar page (via
	 * enqueue()), so it's free everywhere else.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function capture( $bar ) {
		if ( is_object( $bar ) ) {
			set_transient( self::SNAPSHOT_KEY, self::extract_nodes( $bar, 'admin' ), HOUR_IN_SECONDS );
		}
	}

	/**
	 * In-request FALLBACK snapshot: build a throwaway bar (our apply() detached) and
	 * extract its top-level nodes. Runs in the CALLER's context (REST = not is_admin()),
	 * so it can miss `is_admin()`-gated nodes — exactly why capture() is preferred.
	 *
	 * @return array<int,array{id:string,label:string,group:string,defaultIcon:string,dashicon:string}>
	 */
	private static function snapshot_nodes() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		remove_action( 'admin_bar_menu', array( 'AdminKit_Toolbar_Manager', 'capture_frontend' ), 99998 );
		remove_action( 'admin_bar_menu', array( 'AdminKit_Toolbar_Manager', 'apply' ), 99999 );
		$bar = new WP_Admin_Bar();
		$bar->initialize();
		$bar->add_menus();
		do_action( 'admin_bar_menu', $bar );
		add_action( 'admin_bar_menu', array( 'AdminKit_Toolbar_Manager', 'capture_frontend' ), 99998 );
		add_action( 'admin_bar_menu', array( 'AdminKit_Toolbar_Manager', 'apply' ), 99999 );

		return self::extract_nodes( $bar, 'admin' );
	}

	/**
	 * Synthesize the front-end toolbar node list when no front-end page has been visited
	 * yet since the transient cleared. Adapts the admin snapshot to the front-end context
	 * (dropping wp-admin-only nodes and adding front-end core nodes like `site-editor` /
	 * `customize`, `edit`, and `search`).
	 *
	 * @param array<int,array> $admin_nodes
	 * @return array<int,array>
	 */
	private static function snapshot_frontend_nodes( $admin_nodes ) {
		$admin_only = array(
			'menu-toggle'                      => true,
			'command-palette'                  => true,
			'ak-notifications'                 => true,
			'woocommerce-site-visibility-badge' => true,
			'archive'                          => true,
		);
		$out = array();
		foreach ( $admin_nodes as $node ) {
			$id = isset( $node['id'] ) ? (string) $node['id'] : '';
			if ( '' === $id || isset( $admin_only[ $id ] ) ) {
				continue;
			}
			if ( 'ak-view-site' === $id ) {
				$defaults            = self::node_default_icons( 'ak-view-site', 'frontend' );
				$node['label']       = __( 'Dashboard', 'adminkit' );
				$node['defaultIcon'] = $defaults['defaultIcon'];
				$node['dashicon']    = $defaults['dashicon'];
			}
			$out[] = $node;
		}
		return self::ensure_frontend_nodes( $out );
	}

	/**
	 * Ensure context-dependent front-end core nodes (`site-editor` or `customize`,
	 * `edit`, and `search`) are always present in the front-end editor list even if the
	 * snapshot was captured on an archive/home view where `edit` wasn't rendered.
	 *
	 * @param array<int,array> $nodes
	 * @return array<int,array>
	 */
	private static function ensure_frontend_nodes( $nodes ) {
		$by_id = array();
		foreach ( $nodes as $n ) {
			if ( isset( $n['id'] ) ) {
				$by_id[ $n['id'] ] = $n;
			}
		}

		$is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$extra_after    = array();

		if ( $is_block_theme && ! isset( $by_id['site-editor'] ) ) {
			$defaults                    = self::node_default_icons( 'site-editor', 'frontend' );
			$extra_after['site-name'][] = array(
				'id'          => 'site-editor',
				'label'       => __( 'Edit Site' ),
				'group'       => 'root',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		} elseif ( ! $is_block_theme && ! isset( $by_id['customize'] ) ) {
			$defaults                    = self::node_default_icons( 'customize', 'frontend' );
			$extra_after['site-name'][] = array(
				'id'          => 'customize',
				'label'       => __( 'Customize' ),
				'group'       => 'root',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}

		if ( ! isset( $by_id['edit'] ) ) {
			$defaults    = self::node_default_icons( 'edit', 'frontend' );
			$pt_obj      = function_exists( 'get_post_type_object' ) ? get_post_type_object( 'page' ) : null;
			$edit_label  = ( $pt_obj && isset( $pt_obj->labels->edit_item ) ) ? $pt_obj->labels->edit_item : __( 'Edit Page' );
			$anchor      = isset( $by_id['new-content'] ) ? 'new-content' : 'site-name';
			$extra_after[ $anchor ][] = array(
				'id'          => 'edit',
				'label'       => $edit_label,
				'group'       => 'root',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}

		$out = array();
		foreach ( $nodes as $n ) {
			$out[] = $n;
			$id    = isset( $n['id'] ) ? $n['id'] : '';
			if ( isset( $extra_after[ $id ] ) ) {
				foreach ( $extra_after[ $id ] as $ins ) {
					$out[] = $ins;
				}
				unset( $extra_after[ $id ] );
			}
		}
		foreach ( $extra_after as $ins_list ) {
			foreach ( $ins_list as $ins ) {
				$out[] = $ins;
			}
		}

		if ( ! isset( $by_id['search'] ) ) {
			$defaults = self::node_default_icons( 'search', 'frontend' );
			$out[]    = array(
				'id'          => 'search',
				'label'       => __( 'Search' ),
				'group'       => 'secondary',
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}

		return $out;
	}

	/**
	 * Refresh context-dependent metadata (such as hiding `wp-logo` and updating
	 * `site-name`'s default icon when `branding_enabled` is active) on a cached snapshot.
	 *
	 * @param array<int,array> $nodes
	 * @param string           $context `'admin'` | `'frontend'`
	 * @return array<int,array>
	 */
	private static function filter_context_nodes( $nodes, $context ) {
		$branding = (bool) AdminKit_Settings::get( 'branding_enabled' );
		$out      = array();
		foreach ( $nodes as $n ) {
			if ( ! is_array( $n ) || empty( $n['id'] ) ) {
				continue;
			}
			$id = (string) $n['id'];
			if ( $branding && 'wp-logo' === $id ) {
				continue;
			}
			if ( 'my-account' === $id ) {
				$n['label'] = __( 'My Account', 'adminkit' );
			}
			$defaults         = self::node_default_icons( $id, $context );
			$n['defaultIcon'] = $defaults['defaultIcon'];
			$n['dashicon']    = $defaults['dashicon'];
			$out[]            = $n;
		}
		return $out;
	}

	/**
	 * Extract + classify the TOP-LEVEL items from a built admin bar into the editor's
	 * shape. Shared by capture() (real bar) and snapshot_nodes() (throwaway bar).
	 *
	 * @param WP_Admin_Bar $bar
	 * @param string       $context `'admin'` | `'frontend'`
	 * @return array<int,array{id:string,label:string,group:string,defaultIcon:string,dashicon:string}>
	 */
	private static function extract_nodes( $bar, $context = 'admin' ) {
		$nodes = $bar->get_nodes();
		if ( ! is_array( $nodes ) ) {
			return array();
		}

		$branding = (bool) AdminKit_Settings::get( 'branding_enabled' );
		$out      = array();
		foreach ( $nodes as $id => $node ) {
			$id = (string) $id;
			if ( 'root' === $id || ! empty( $node->group ) ) {
				continue; // the root container + group wrappers aren't items
			}
			// When AdminKit's automatic toolbar identity is active, `#wp-admin-bar-wp-logo`
			// is hidden by CSS (`AdminKit_Core_Branding`) and its mark lives on `site-name`.
			if ( $branding && 'wp-logo' === $id ) {
				continue;
			}
			$parent = isset( $node->parent ) ? $node->parent : false;
			if ( false === $parent || '' === $parent || 'root' === $parent ) {
				$side = 'root';
			} elseif ( 'top-secondary' === $parent ) {
				$side = 'secondary';
			} else {
				continue; // a dropdown child — not a top-level bar item
			}

			$defaults = self::node_default_icons( $id, $context );
			$out[]    = array(
				'id'          => $id,
				'label'       => 'my-account' === $id ? __( 'My Account', 'adminkit' ) : self::node_label( $node ),
				'group'       => $side,
				'defaultIcon' => $defaults['defaultIcon'],
				'dashicon'    => $defaults['dashicon'],
			);
		}
		return $out;
	}

	/**
	 * Default icon metadata for a toolbar node so the editor's icon button previews
	 * the same glyph the bar displays by default in the given context (`admin` vs
	 * `frontend`, and accounting for `branding_enabled`).
	 *
	 * @param string $id
	 * @param string $context `'admin'` | `'frontend'`
	 * @return array{defaultIcon:string,dashicon:string}
	 */
	private static function node_default_icons( $id, $context = 'admin' ) {
		$branding = (bool) AdminKit_Settings::get( 'branding_enabled' );
		if ( 'site-name' === $id ) {
			// When automatic branding is on, `site-name` displays the brand mark (falling
			// back to the WordPress `W` logomark `\f120`), not the generic house glyph.
			if ( $branding ) {
				return array(
					'defaultIcon' => '',
					'dashicon'    => 'dashicons-wordpress',
				);
			}
			return array(
				'defaultIcon' => 'home',
				'dashicon'    => 'dashicons-admin-home',
			);
		}
		if ( 'ak-view-site' === $id ) {
			return array(
				'defaultIcon' => 'frontend' === $context ? 'home' : 'external-link',
				'dashicon'    => '',
			);
		}
		$lucide_map = array(
			'menu-toggle'         => 'menu',
			'comments'            => 'chat',
			'new-content'         => 'plus',
			'updates'             => 'update',
			'command-palette'     => 'search',
			'search'              => 'search',
			'edit'                => 'document-pencil',
			'customize'           => 'sliders',
			'site-editor'         => 'app',
			'archive'             => 'eye',
			'ak-notifications'    => 'bell',
			'ak-theme-toggle'     => 'sun',
			'query-monitor'       => 'clock',
			'my-account'          => 'user',
			'ame-quick-search-tb' => 'search',
			'edit_with_bricks'    => 'brush',
			'editor_mode'         => 'app',
		);
		$dashicon_map = array(
			'wp-logo'         => 'dashicons-wordpress',
			'menu-toggle'     => 'dashicons-menu',
			'comments'        => 'dashicons-admin-comments',
			'new-content'     => 'dashicons-plus',
			'updates'         => 'dashicons-update',
			'command-palette' => 'dashicons-search',
			'search'          => 'dashicons-search',
			'edit'            => 'dashicons-edit',
			'customize'       => 'dashicons-admin-customizer',
			'site-editor'     => 'dashicons-layout',
			'my-account'      => 'dashicons-admin-users',
		);
		return array(
			'defaultIcon' => isset( $lucide_map[ $id ] ) ? $lucide_map[ $id ] : '',
			'dashicon'    => isset( $dashicon_map[ $id ] ) ? $dashicon_map[ $id ] : '',
		);
	}

	/**
	 * A readable, plain-text label for a node, in three falls:
	 *   1. the visible title — but with `aria-hidden="true"` spans dropped first, so a
	 *      node like Comments reads "0 awaiting moderation", not "00 …" (the decorative
	 *      count bubble duplicates the screen-reader count);
	 *   2. for icon-only nodes (no visible text — `ak-view-site`, `ak-theme-toggle`, …)
	 *      the accessible tooltip `meta['title']` ("View site"), never the raw id;
	 *   3. a humanized id as the last resort (`ak-foo-bar` → "Foo bar").
	 *
	 * @param object $node
	 * @return string
	 */
	private static function node_label( $node ) {
		$raw = isset( $node->title ) ? (string) $node->title : '';
		// Drop decorative, aria-hidden spans (count bubbles, icon glyphs) before stripping.
		$raw   = preg_replace( '/<span[^>]*aria-hidden=["\']true["\'][^>]*>.*?<\/span>/is', ' ', $raw );
		$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
		if ( '' !== $title ) {
			return $title;
		}
		// Icon-only node → the accessible tooltip (meta['title']) if any.
		if ( isset( $node->meta['title'] ) && '' !== trim( (string) $node->meta['title'] ) ) {
			return trim( wp_strip_all_tags( (string) $node->meta['title'] ) );
		}
		// Last resort: humanize the id (drop the wp-admin-bar- / ak- prefix, dashes → spaces).
		$id = isset( $node->id ) ? (string) $node->id : '';
		$id = preg_replace( '/^(wp-admin-bar-|ak-)/', '', $id );
		return ucfirst( trim( str_replace( array( '-', '_' ), ' ', $id ) ) );
	}

	/** Capture an already authorized frontend bar. Permission checks belong to the facade. */
	public static function capture_frontend( $bar ) {
		$nodes = self::ensure_frontend_nodes( self::extract_nodes( $bar, 'frontend' ) );
		set_transient( self::FRONTEND_SNAPSHOT_KEY, $nodes, HOUR_IN_SECONDS );
	}
}
