<?php
/**
 * Apply validated toolbar layouts and emit their icon CSS.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Toolbar_Renderer {

	/** @var array<string,string> Per-request map of `#wp-admin-bar-<id>` => custom icon override. */
	private static $icon_overrides = array();

	/** @var array<string,bool> Per-request set of `#wp-admin-bar-<id>` nodes that paint on `> .ab-item::before`. */
	private static $ab_item_overrides = array();

	/**
	 * Core WP admin-bar node id => native dashicons codepoint + whether it paints on
	 * `.ab-icon` or `> .ab-item::before`, for restoring the original WP dashicon when a
	 * user explicitly pins a toolbar node back to `wp-default`.
	 *
	 * @var array<string,array{glyph:string,target:string}>
	 */
	private static $toolbar_dashicon_glyphs = array(
		'wp-logo'         => array( 'glyph' => '\\f120', 'target' => 'ab-icon' ),
		'site-name'       => array( 'glyph' => '\\f226', 'target' => 'ab-item' ),
		'site-editor'     => array( 'glyph' => '\\f100', 'target' => 'ab-item' ),
		'updates'         => array( 'glyph' => '\\f463', 'target' => 'ab-icon' ),
		'comments'        => array( 'glyph' => '\\f101', 'target' => 'ab-icon' ),
		'new-content'     => array( 'glyph' => '\\f132', 'target' => 'ab-icon' ),
		'edit'            => array( 'glyph' => '\\f464', 'target' => 'ab-item' ),
		'customize'       => array( 'glyph' => '\\f540', 'target' => 'ab-item' ),
		'command-palette' => array( 'glyph' => '\\f179', 'target' => 'ab-icon' ),
		'search'          => array( 'glyph' => '\\f179', 'target' => 'search' ),
		'menu-toggle'     => array( 'glyph' => '\\f333', 'target' => 'ab-icon' ),
	);


	/**
	 * Apply a validated layout and protection policy to the live
	 * bar: hide (remove), apply custom titles + icons, then reorder. Runs on
	 * `admin_bar_menu` very late so every node already exists.
	 *
	 * @param WP_Admin_Bar $bar
	 * @param array $cfg Validated active-context layout.
	 * @param array $protected Protected node set.
	 * @param bool $branding Whether automatic identity owns wp-logo.
	 * @return void
	 */
	public static function apply( $bar, array $cfg, array $protected, $branding ) {
		// Hide: drop the node (children go with it). Never a protected id.
		$hidden = array();
		foreach ( $cfg['hidden'] as $id ) {
			if ( isset( $protected[ $id ] ) ) {
				continue;
			}
			$hidden[ $id ] = true;
			$bar->remove_node( $id );
		}

		// Apply title + icon overrides to any live node (whether reordered or not).
		$touched = array_unique( array_merge( array_keys( $cfg['titles'] ), array_keys( $cfg['icons'] ) ) );
		foreach ( $touched as $id ) {
			if ( isset( $hidden[ $id ] ) || ( $branding && 'wp-logo' === $id ) ) {
				continue;
			}
			$node = $bar->get_node( $id );
			if ( ! $node ) {
				continue;
			}
			$props = (array) $node;
			if ( isset( $cfg['titles'][ $id ] ) && '' !== $cfg['titles'][ $id ] ) {
				$props = self::apply_node_title( $props, $cfg['titles'][ $id ] );
			}
			if ( isset( $cfg['icons'][ $id ] ) && '' !== $cfg['icons'][ $id ] ) {
				$props = self::apply_node_icon( $id, $props, $cfg['icons'][ $id ] );
			}
			$bar->add_node( $props );
		}

		// Reorder: re-add the kept nodes in the saved order. Re-adding moves a node to
		// the end of its group's run, so walking the saved order rebuilds the sequence;
		// any node NOT in the saved order keeps its native slot (a newly-appeared item
		// just sits where WP added it). A node's children ride along (they reference the
		// re-added parent id).
		foreach ( $cfg['order'] as $id ) {
			if ( isset( $hidden[ $id ] ) || ( $branding && 'wp-logo' === $id ) ) {
				continue;
			}
			$node = $bar->get_node( $id );
			if ( ! $node ) {
				continue; // stale / not present this request
			}
			$bar->remove_node( $id );
			$bar->add_node( (array) $node );
		}
	}

	/**
	 * Replace the visible label of a toolbar node while keeping its icon, count
	 * bubbles, avatar, search form or brand logo intact.
	 *
	 * @param array  $props
	 * @param string $custom_title
	 * @return array
	 */
	private static function apply_node_title( $props, $custom_title ) {
		$safe    = esc_html( $custom_title );
		$safe_at = esc_attr( $custom_title );
		$raw     = isset( $props['title'] ) ? (string) $props['title'] : '';
		$meta    = isset( $props['meta'] ) && is_array( $props['meta'] ) ? $props['meta'] : array();

		if ( isset( $meta['menu_title'] ) ) {
			$meta['menu_title'] = $custom_title;
		}

		if ( false !== strpos( $raw, 'adminbarsearch' ) || false !== strpos( $raw, '<form' ) ) {
			$raw = preg_replace_callback( '/(<label[^>]*>).*?(<\/label>)/is', static function ( $match ) use ( $safe ) { return $match[1] . $safe . $match[2]; }, $raw, 1 );
			$raw = preg_replace_callback( '/(<input[^>]*type=["\']submit["\'][^>]*value=["\'])[^"\']*(["\'])/is', static function ( $match ) use ( $safe_at ) { return $match[1] . $safe_at . $match[2]; }, $raw, 1 );
		} elseif ( false !== strpos( $raw, 'ak-site-name-text' ) ) {
			$raw = preg_replace_callback( '/(<span[^>]*class=["\'][^"\']*ak-site-name-text[^"\']*["\'][^>]*>).*?(<\/span>)/is', static function ( $match ) use ( $safe ) { return $match[1] . $safe . $match[2]; }, $raw, 1 );
		} elseif ( false !== strpos( $raw, 'class="ab-label"' ) || false !== strpos( $raw, "class='ab-label'" ) ) {
			$raw = preg_replace_callback( '/(<span[^>]*class=["\']ab-label["\'][^>]*>).*?(<\/span>)/is', static function ( $match ) use ( $safe ) { return $match[1] . $safe . $match[2]; }, $raw, 1 );
		} elseif ( false !== strpos( $raw, 'display-name' ) ) {
			$raw = preg_replace_callback( '/^.*?(<img\b.*)?$/is', static function ( $match ) use ( $safe ) { return $safe . ( isset( $match[1] ) ? $match[1] : '' ); }, $raw, 1 );
		} elseif ( false !== strpos( $raw, 'ak-theme-icon' ) ) {
			$meta['title'] = $custom_title;
		} elseif ( false !== strpos( $raw, 'ab-icon' ) ) {
			if ( false !== strpos( $raw, 'screen-reader-text' ) ) {
				$raw = preg_replace_callback( '/<span[^>]*class=["\'][^"\']*screen-reader-text[^"\']*["\'][^>]*>.*?<\/span>/is', static function () use ( $safe ) { return '<span class="ab-label">' . $safe . '</span>'; }, $raw, 1 );
			} else {
				$raw .= '<span class="ab-label">' . $safe . '</span>';
			}
		} elseif ( preg_match( '/^(<span[^>]*>.*?<\/span>)\s*[^<]+$/is', $raw, $m ) ) {
			$raw = $m[1] . ' ' . $safe;
		} else {
			$raw = $safe;
		}

		$props['title'] = $raw;
		$props['meta']  = $meta;
		return $props;
	}

	/**
	 * Apply a custom icon override to a toolbar node:
	 *   - for AdminKit SVG nodes (`ak-notifications`, `ak-view-site`, `ak-theme-toggle`),
	 *     replace the inline `<svg class="ak-theme-icon" ...>` directly when a Lucide icon
	 *     is chosen;
	 *   - for `search` (`#wp-admin-bar-search` on the front end), leave the `<form>` intact
	 *     and target `#adminbarsearch::before` in `print_css()`;
	 *   - for other nodes, ensure `.ab-icon` is present (or record an `.ab-item::before`
	 *     override) and queue the CSS rule for `print_css()`.
	 *
	 * @param string $id
	 * @param array  $props
	 * @param string $icon
	 * @return array
	 */
	private static function apply_node_icon( $id, $props, $icon ) {
		$raw = isset( $props['title'] ) ? (string) $props['title'] : '';

		// Inline-SVG AdminKit toolbar buttons: swap the inline SVG directly when a
		// Lucide icon name is picked.
		if ( false !== strpos( $raw, 'ak-theme-icon' ) && class_exists( 'AdminKit_Icons' ) && AdminKit_Icons::has( $icon ) ) {
			$svg = str_replace( '<svg ', '<svg class="ak-theme-icon" ', AdminKit_Icons::display_svg( $icon ) );
			$props['title'] = preg_replace( '/<svg[^>]*class=["\'][^"\']*ak-theme-icon[^"\']*["\'][^>]*>.*?<\/svg>/is', $svg, $raw );
			return $props;
		}

		$dom_id = ( 0 === strpos( $id, 'wp-admin-bar-' ) ) ? $id : 'wp-admin-bar-' . $id;

		if ( 'search' === $id || false !== strpos( $raw, 'adminbarsearch' ) ) {
			self::$icon_overrides[ $dom_id ] = ( 'wp-default' === $icon ) ? 'wp-default:search' : $icon;
			return $props;
		}

		// When a user explicitly overrides `site-name`'s icon, strip any injected brand
		// logo class so the custom icon + site name text render cleanly.
		if ( 'site-name' === $id ) {
			if ( isset( $props['meta']['class'] ) && is_string( $props['meta']['class'] ) ) {
				$props['meta']['class'] = trim( str_replace( 'ak-has-brand-logo', '', $props['meta']['class'] ) );
			}
			if ( false !== strpos( $raw, 'ak-site-name-text' ) && preg_match( '/<span[^>]*class=["\'][^"\']*ak-site-name-text[^"\']*["\'][^>]*>(.*?)<\/span>/is', $raw, $m ) ) {
				$raw            = $m[1];
				$props['title'] = $raw;
			}
		}

		if ( 0 === strpos( $icon, 'dashicons-' ) ) {
			if ( false !== strpos( $raw, 'ab-icon' ) ) {
				$props['title'] = preg_replace(
					'/class=(["\'])([^"\']*\bab-icon\b[^"\']*)\1/i',
					'class=$1$2 dashicons-before ' . esc_attr( $icon ) . '$1',
					$raw,
					1
				);
			} else {
				$props['title'] = '<span class="ab-icon dashicons-before ' . esc_attr( $icon ) . '" aria-hidden="true"></span>' . $raw;
			}
			self::$icon_overrides[ $dom_id ] = $icon;
			return $props;
		}

		if ( 'wp-default' === $icon ) {
			self::$icon_overrides[ $dom_id ] = 'wp-default:' . $id;
			return $props;
		}

		// Lucide icon name or custom SVG data-URI: ensure the node has an `.ab-icon`
		// span (unless it's a known `> .ab-item::before` node like site-name/edit/customize/site-editor/query-monitor).
		$ab_item_ids = array(
			'site-name'        => true,
			'edit'             => true,
			'customize'        => true,
			'site-editor'      => true,
			'archive'          => true,
			'query-monitor'    => true,
			'edit_with_bricks' => true,
			'editor_mode'      => true,
		);
		if ( isset( $ab_item_ids[ $id ] ) ) {
			self::$ab_item_overrides[ $dom_id ] = true;
		} elseif ( false === strpos( $raw, 'ab-icon' ) ) {
			$props['title'] = '<span class="ab-icon" aria-hidden="true"></span>' . $raw;
		}
		self::$icon_overrides[ $dom_id ] = $icon;
		return $props;
	}

	/**
	 * Print per-node toolbar icon CSS overrides in `admin_head` and `wp_head` (at
	 * priority 22, right after `AdminKit_Core_Menu_Icons` at 21 and `AdminKit_Core_Branding`
	 * at 20).
	 *
	 * @return void
	 */
	public static function print_css() {
		if ( empty( self::$icon_overrides ) || ! is_admin_bar_showing() ) {
			return;
		}
		$desktop = '';
		$mobile  = '';

		foreach ( self::$icon_overrides as $dom_id => $icon ) {
			// When `site-name` has an explicit custom icon override, neutralize
			// `AdminKit_Core_Branding`'s `::before` mark so only the chosen icon shows.
			if ( 'wp-admin-bar-site-name' === $dom_id && 0 === strpos( $icon, 'dashicons-' ) ) {
				$desktop .= '.wp-admin #wpadminbar #wp-toolbar #wp-admin-bar-site-name > .ab-item.ab-item.ab-item::before,#wpadminbar #wp-toolbar #wp-admin-bar-site-name > .ab-item.ab-item.ab-item::before{content:none !important;background-image:none !important}';
			}

			if ( 'wp-admin-bar-search' === $dom_id ) {
				$sel = '#wpadminbar #wp-toolbar #wp-admin-bar-search #adminbarsearch::before';
				if ( 0 === strncmp( $icon, 'wp-default:', 11 ) ) {
					$desktop .= $sel . '{content:"\\f179";-webkit-mask:none;mask:none;background:transparent;font:normal 20px/1 dashicons}';
				} else {
					$mask = self::icon_mask( $icon );
					if ( '' !== $mask ) {
						$desktop .= $sel . '{content:"";width:20px;height:20px;top:6px;' . $mask . '}';
					}
				}
				continue;
			}

			if ( 0 === strpos( $icon, 'dashicons-' ) ) {
				// Cancel any AdminKit mask on this node's `.ab-icon` so WP's native
				// dashicons-before class paints its font glyph.
				$sel      = '#wpadminbar #wp-toolbar #' . $dom_id . ' .ab-icon.ab-icon.ab-icon';
				$desktop .= $sel . '{box-sizing:border-box;height:32px;width:20px;padding:6px 0;margin-right:6px}';
				$desktop .= $sel . '::before{-webkit-mask:none;mask:none;background:transparent;font:normal 20px/1 dashicons;display:block;width:20px;height:20px}';
				continue;
			}

			if ( 0 === strncmp( $icon, 'wp-default:', 11 ) ) {
				$raw_id = substr( $icon, 11 );
				if ( isset( self::$toolbar_dashicon_glyphs[ $raw_id ] ) ) {
					$info  = self::$toolbar_dashicon_glyphs[ $raw_id ];
					$glyph = $info['glyph'];
					if ( 'ab-item' === $info['target'] ) {
						$sel      = '.wp-admin #wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before,#wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before';
						$desktop .= $sel . '{content:"' . $glyph . '" !important;-webkit-mask:none;mask:none;background:transparent !important;font:normal 20px/1 dashicons;float:left;width:20px;height:32px;padding:6px 0;margin-right:6px}';
					} else {
						$sel      = '#wpadminbar #wp-toolbar #' . $dom_id . ' .ab-icon.ab-icon.ab-icon::before';
						$desktop .= $sel . '{content:"' . $glyph . '";-webkit-mask:none;mask:none;background:transparent;font:normal 20px/1 dashicons}';
					}
				}
				continue;
			}

			$mask = self::icon_mask( $icon );
			if ( '' === $mask ) {
				continue;
			}

			if ( ! empty( self::$ab_item_overrides[ $dom_id ] ) ) {
				$sel      = '.wp-admin #wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before,#wpadminbar #wp-toolbar #' . $dom_id . ' > .ab-item.ab-item.ab-item::before';
				$desktop .= $sel . '{content:"" !important;background-image:none !important;box-sizing:border-box;float:left;height:32px;width:20px;padding:6px 0;margin-right:6px;position:static;top:auto;' . $mask . '}';
				$mobile  .= $sel . '{content:"" !important;background-image:none !important;box-sizing:border-box;display:block;float:none;width:52px;height:46px;margin:0;text-indent:0;position:static;top:auto;' . $mask . '-webkit-mask-size:22px 22px;mask-size:22px 22px}';
			} else {
				$sel      = '#wpadminbar #wp-toolbar #' . $dom_id . ' .ab-icon.ab-icon.ab-icon';
				$desktop .= $sel . '{box-sizing:border-box;height:32px;width:20px;padding:6px 0;margin-right:6px}';
				$desktop .= $sel . '::before{content:"";display:block;width:20px;height:20px;margin:0;position:static;top:auto;' . $mask . '}';
				$mobile  .= $sel . '{box-sizing:border-box;width:52px;height:46px;margin:0;padding:0}';
				$mobile  .= $sel . '::before{content:"";display:block;width:52px;height:46px;margin:0;position:static;top:auto;' . $mask . '-webkit-mask-size:22px 22px;mask-size:22px 22px}';
			}
		}

		$css = '';
		if ( '' !== $desktop ) {
			$css .= '@media screen and (min-width:783px){' . $desktop . '}';
		}
		if ( '' !== $mobile ) {
			$css .= '@media screen and (max-width:782px){' . $mobile . '}';
		}
		if ( '' !== $css ) {
			echo '<style id="adminkit-toolbar-manager">' . $css . "</style>\n";
		}
	}

	/**
	 * Build the CSS mask declarations for a Lucide icon name or SVG data-URI.
	 *
	 * @param string $icon
	 * @return string
	 */
	private static function icon_mask( $icon ) {
		return AdminKit_Icons::icon_mask( $icon );
	}
}
