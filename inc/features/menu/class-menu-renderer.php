<?php
/**
 * Menu renderer — applies validated layouts to `$menu` / `$submenu`, remaps
 * moved plugin submenus & active-screen highlights, and emits per-item icon CSS
 * and custom URL anchor overrides.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Menu_Renderer {

	/** @var array<string,string> Per-request map of injected class => icon override. */
	private static $icon_overrides = array();

	/** @var array<string,string> Per-request map of injected class => custom URL. */
	private static $url_overrides = array();

	/** @var array<string,bool> Per-request set of injected classes whose anchor opens in a new tab. */
	private static $new_tab = array();

	/** @var bool Whether any item was tagged hidden this request. */
	private static $has_hidden = false;

	/** @var array<string,string> Submenu slug => new parent, for items moved across sections. */
	private static $moved = array();

	/**
	 * Remove WordPress's legacy Link Manager menu when `link_manager_enabled` is off.
	 *
	 * @return void
	 */
	public static function hide_legacy_link_manager() {
		if ( get_option( 'link_manager_enabled' ) ) {
			return;
		}
		global $menu, $submenu;
		$slugs = array( 'link-manager.php', 'link-add.php', 'edit-tags.php?taxonomy=link_category' );
		if ( is_array( $menu ) ) {
			foreach ( $menu as $i => $item ) {
				if ( isset( $item[2] ) && in_array( (string) $item[2], $slugs, true ) ) {
					unset( $menu[ $i ] );
				}
			}
		}
		foreach ( $slugs as $slug ) {
			unset( $submenu[ $slug ] );
		}
	}

	/**
	 * Apply icon overrides, visibility classes, custom links/separators and submenu moves.
	 *
	 * @return void
	 */
	public static function apply() {
		self::$icon_overrides = array();
		self::$url_overrides  = array();
		self::$new_tab        = array();
		self::$has_hidden     = false;
		self::$moved          = array();

		$config = AdminKit_Menu_Store::get_config();
		if ( empty( $config['top'] ) && empty( $config['sub'] ) ) {
			return;
		}
		global $menu, $submenu;

		$top_by_slug = array();
		foreach ( $config['top'] as $t ) {
			$top_by_slug[ $t['slug'] ] = $t;
		}

		$n = 0;
		$u = 0;
		foreach ( (array) $menu as $pos => $item ) {
			if ( empty( $item[2] ) || ! isset( $top_by_slug[ $item[2] ] ) ) {
				continue;
			}
			$cfg = $top_by_slug[ $item[2] ];
			if ( isset( $cfg['title'] ) && '' !== $cfg['title'] ) {
				$menu[ $pos ][0] = self::apply_menu_title( isset( $item[0] ) ? $item[0] : '', $cfg['title'] );
			}
			if ( ! empty( $cfg['hidden'] ) ) {
				if ( AdminKit_Settings_Page::SLUG !== $item[2] ) {
					$menu[ $pos ][4]  = trim( ( isset( $menu[ $pos ][4] ) ? $menu[ $pos ][4] : '' ) . ' ak-mh' );
					self::$has_hidden = true;
				}
				continue;
			}
			$icon = isset( $cfg['icon'] ) ? (string) $cfg['icon'] : '';
			if ( 0 === strpos( $icon, 'dashicons-' ) ) {
				$menu[ $pos ][6] = $icon;
			} elseif ( '' !== $icon && AdminKit_Menu_Config::is_icon( $icon ) ) {
				$cls             = 'ak-mi-' . ( ++$n );
				$menu[ $pos ][4] = trim( ( isset( $menu[ $pos ][4] ) ? $menu[ $pos ][4] : '' ) . ' ' . $cls );
				if ( 'wp-default' === $icon ) {
					$dashicon_class               = AdminKit_Menu_Snapshot::dashicon_class( isset( $item[6] ) ? $item[6] : '' );
					self::$icon_overrides[ $cls ] = 'wp-default' . ( $dashicon_class ? ':' . $dashicon_class : '' );
				} else {
					$menu[ $pos ][6]              = 'none';
					self::$icon_overrides[ $cls ] = $icon;
				}
			}
			if ( ! empty( $cfg['url'] ) ) {
				$safe_override = AdminKit_Menu_Config::sanitize_menu_url( $cfg['url'] );
				if ( '' !== $safe_override ) {
					$ucls                         = 'ak-mu-' . ( ++$u );
					$menu[ $pos ][4]              = trim( ( isset( $menu[ $pos ][4] ) ? $menu[ $pos ][4] : '' ) . ' ' . $ucls );
					self::$url_overrides[ $ucls ] = $safe_override;
				}
			}
		}

		// Accessible top-level parents for cross-parent submenu moves.
		$accessible_parents = array();
		foreach ( (array) $menu as $top_item ) {
			if ( empty( $top_item[2] ) ) {
				continue;
			}
			$top_classes = ( isset( $top_item[4] ) && is_string( $top_item[4] ) ) ? $top_item[4] : '';
			if ( false !== strpos( $top_classes, 'wp-menu-separator' ) ) {
				continue;
			}
			$top_slug = (string) $top_item[2];
			$top_cap  = isset( $top_item[1] ) ? (string) $top_item[1] : '';
			if ( ( '' !== $top_cap && current_user_can( $top_cap ) ) || ! empty( $submenu[ $top_slug ] ) ) {
				$accessible_parents[ $top_slug ] = true;
			}
		}

		$desired_in_place = array();
		$desired_moved    = array();
		foreach ( $config['sub'] as $parent => $subs ) {
			foreach ( $subs as $s ) {
				if ( empty( $s['slug'] ) ) {
					continue;
				}
				$entry = array(
					'parent' => (string) $parent,
					'hidden' => ! empty( $s['hidden'] ),
					'title'  => isset( $s['title'] ) ? (string) $s['title'] : '',
				);
				$desired_in_place[ $parent . "\0" . $s['slug'] ] = $entry;
				$desired_moved[ $s['slug'] ]                     = $entry;
			}
		}
		foreach ( array_keys( (array) $submenu ) as $cur_parent ) {
			$items = isset( $submenu[ $cur_parent ] ) ? $submenu[ $cur_parent ] : null;
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $i => $item ) {
				if ( empty( $item[2] ) ) {
					continue;
				}
				$sub_slug = (string) $item[2];
				$comp_key = $cur_parent . "\0" . $sub_slug;
				if ( isset( $desired_in_place[ $comp_key ] ) ) {
					$d = $desired_in_place[ $comp_key ];
				} elseif ( isset( $desired_moved[ $sub_slug ] ) ) {
					$d = $desired_moved[ $sub_slug ];
				} else {
					continue;
				}
				if ( '' !== $d['title'] ) {
					$item[0] = self::apply_menu_title( isset( $item[0] ) ? $item[0] : '', $d['title'] );
				}
				if ( $d['hidden'] ) {
					$item[4]          = trim( ( isset( $item[4] ) ? $item[4] : '' ) . ' ak-mh' );
					self::$has_hidden = true;
				}
				if ( $cur_parent !== $d['parent'] && isset( $accessible_parents[ $d['parent'] ] ) ) {
					unset( $submenu[ $cur_parent ][ $i ] );
					$submenu[ $d['parent'] ][] = $item;
					self::$moved[ $sub_slug ]  = $d['parent'];
					self::remap_moved_plugin_submenu( $sub_slug, (string) $cur_parent, $d['parent'] );
				} else {
					$submenu[ $cur_parent ][ $i ] = $item;
				}
			}
		}
		foreach ( $config['sub'] as $parent => $subs ) {
			self::reorder_submenu( $parent, $subs );
		}

		$config_top = array();
		foreach ( $config['top'] as $t ) {
			$config_top[ $t['slug'] ] = true;
		}
		foreach ( (array) $menu as $pos => $item ) {
			if ( empty( $item[2] ) || isset( $config_top[ $item[2] ] ) ) {
				continue;
			}
			if ( isset( $item[4] ) && is_string( $item[4] ) && false !== strpos( $item[4], 'wp-menu-separator' ) ) {
				unset( $menu[ $pos ] );
			}
		}
		$existing = array();
		foreach ( (array) $menu as $item ) {
			if ( ! empty( $item[2] ) ) {
				$existing[ $item[2] ] = true;
			}
		}

		foreach ( $config['top'] as $t ) {
			$type = isset( $t['type'] ) ? $t['type'] : 'item';
			if ( 'item' === $type || isset( $existing[ $t['slug'] ] ) ) {
				continue;
			}
			if ( 'separator' === $type ) {
				$classes = 'wp-menu-separator';
				if ( ! empty( $t['hidden'] ) ) {
					$classes         .= ' ak-mh';
					self::$has_hidden = true;
				}
				$menu[] = array( '', 'read', $t['slug'], '', $classes, '', '' );
			} elseif ( 'link' === $type ) {
				$safe_href = AdminKit_Menu_Config::sanitize_menu_url( $t['slug'] );
				if ( '' === $safe_href ) {
					continue;
				}
				if ( function_exists( 'esc_url' ) ) {
					$safe_href = esc_url( $safe_href, array( 'http', 'https', 'mailto', 'tel' ) );
				}
				if ( '' === $safe_href ) {
					continue;
				}
				$icon                         = ( ! empty( $t['icon'] ) && AdminKit_Menu_Config::is_icon( $t['icon'] ) ) ? (string) $t['icon'] : 'app';
				$cls                          = 'ak-mi-' . ( ++$n );
				$classes                      = 'menu-top ' . $cls;
				self::$icon_overrides[ $cls ] = $icon;
				if ( ! empty( $t['new_tab'] ) ) {
					self::$new_tab[ $cls ] = true;
				}
				if ( ! empty( $t['hidden'] ) ) {
					$classes         .= ' ak-mh';
					self::$has_hidden = true;
				}
				$raw_title = ( isset( $t['title'] ) && '' !== $t['title'] ) ? $t['title'] : $safe_href;
				$title     = function_exists( 'esc_html' ) ? esc_html( $raw_title ) : htmlspecialchars( $raw_title, ENT_QUOTES, 'UTF-8' );
				$menu[]    = array( $title, 'read', $safe_href, $title, $classes, '', 'none' );
			}
		}
	}

	/**
	 * Escape a custom menu title while preserving live WordPress notification bubbles
	 * (`awaiting-mod`, `update-plugins`, `count-N`, `menu-counter`), including nested spans.
	 *
	 * @param string $original_html Native `$menu[0]` / `$submenu[0]` markup.
	 * @param string $custom_title  Saved user label.
	 * @return string
	 */
	public static function apply_menu_title( $original_html, $custom_title ) {
		$safe = function_exists( 'esc_html' ) ? esc_html( (string) $custom_title ) : htmlspecialchars( (string) $custom_title, ENT_QUOTES, 'UTF-8' );
		if ( false === strpos( (string) $original_html, '<span' ) ) {
			return $safe;
		}
		if ( preg_match_all( '~<span\b[^>]*class=(["\'])[^"\']*\b(?:awaiting-mod|update-plugins|count-\d+|menu-counter)\b[^"\']*\1[^>]*>((?:[^<]+|<(?!/?span\b)|<span\b[^>]*>(?2)</span>)*)</span>~is', (string) $original_html, $matches ) && ! empty( $matches[0] ) ) {
			return $safe . ' ' . implode( ' ', $matches[0] );
		}
		return $safe;
	}

	/**
	 * Remap WordPress's internal `$_parent_pages`, `$_registered_pages` and page-hook
	 * actions when a plugin submenu (`add_submenu_page`) is moved across top-level parents.
	 *
	 * @param string $slug       Submenu slug (`$item[2]`).
	 * @param string $old_parent Original parent slug.
	 * @param string $new_parent New parent slug.
	 * @return void
	 */
	private static function remap_moved_plugin_submenu( $slug, $old_parent, $new_parent ) {
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			return;
		}
		if ( ! isset( $GLOBALS['_parent_pages'][ $slug ] ) && false !== strpos( $slug, '.php' ) ) {
			return;
		}
		$old_hook                          = get_plugin_page_hookname( $slug, $old_parent );
		$GLOBALS['_parent_pages'][ $slug ] = $new_parent;
		$new_hook                          = get_plugin_page_hookname( $slug, $new_parent );
		if ( $new_hook === $old_hook || '' === $new_hook ) {
			return;
		}
		$was_registered = ! empty( $GLOBALS['_registered_pages'][ $old_hook ] )
			|| ( function_exists( 'has_action' ) && has_action( $old_hook ) );
		if ( ! $was_registered ) {
			return;
		}
		$GLOBALS['_registered_pages'][ $new_hook ] = true;
		if ( function_exists( 'add_action' ) && function_exists( 'do_action' ) ) {
			foreach ( array( '', 'load-', 'admin_print_styles-', 'admin_print_scripts-' ) as $prefix ) {
				$src_hook = $prefix . $old_hook;
				$dst_hook = $prefix . $new_hook;
				if ( '' === $prefix || ( function_exists( 'has_action' ) && has_action( $src_hook ) ) ) {
					add_action(
						$dst_hook,
						static function () use ( $src_hook ) {
							do_action( $src_hook );
						}
					);
				}
			}
		}
	}

	/**
	 * Reorder `$submenu[$parent]` by configured positions.
	 *
	 * @param string $parent      Parent menu slug.
	 * @param array  $config_subs Configured child entries.
	 * @return void
	 */
	private static function reorder_submenu( $parent, $config_subs ) {
		global $submenu;
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$order = array();
		foreach ( $config_subs as $s ) {
			$order[ $s['slug'] ] = (int) $s['position'];
		}

		$decorated = array();
		foreach ( array_values( $submenu[ $parent ] ) as $i => $item ) {
			$slug        = isset( $item[2] ) ? (string) $item[2] : '';
			$rank        = isset( $order[ $slug ] ) ? $order[ $slug ] : ( 100000 + $i );
			$decorated[] = array(
				'rank' => $rank,
				'i'    => $i,
				'item' => $item,
			);
		}
		usort(
			$decorated,
			static function ( $a, $b ) {
				return ( $a['rank'] <=> $b['rank'] ) ?: ( $a['i'] <=> $b['i'] );
			}
		);

		$new = array();
		foreach ( $decorated as $row ) {
			$new[] = $row['item'];
		}
		$submenu[ $parent ] = $new;
	}

	/**
	 * `menu_order` filter: configured top-level slugs first, then newly discovered items
	 * in first-seen activation order.
	 *
	 * @param array $menu_order Native WordPress menu order.
	 * @return array
	 */
	public static function order_top_level( $menu_order ) {
		$config = AdminKit_Menu_Store::get_config();
		if ( empty( $config['top'] ) || ! is_array( $menu_order ) ) {
			return $menu_order;
		}

		$ranks = array();
		foreach ( $config['top'] as $t ) {
			$ranks[ $t['slug'] ] = (int) $t['position'];
		}
		asort( $ranks );

		$ordered = array();
		foreach ( array_keys( $ranks ) as $slug ) {
			if ( in_array( $slug, $menu_order, true ) ) {
				$ordered[] = $slug;
			}
		}

		$new = array();
		foreach ( $menu_order as $slug ) {
			if ( ! in_array( $slug, $ordered, true ) ) {
				$new[] = $slug;
			}
		}
		if ( $new ) {
			$rank = array_flip( self::seen_order( $menu_order ) );
			usort(
				$new,
				static function ( $a, $b ) use ( $rank ) {
					$ra = isset( $rank[ $a ] ) ? $rank[ $a ] : PHP_INT_MAX;
					$rb = isset( $rank[ $b ] ) ? $rank[ $b ] : PHP_INT_MAX;
					return $ra <=> $rb;
				}
			);
			$ordered = array_merge( $ordered, $new );
		}
		return $ordered;
	}

	/**
	 * Per-site record of top-level slugs in first-seen order (`manage_options` writes only).
	 *
	 * @param array $current Top-level slugs present right now.
	 * @return array
	 */
	public static function seen_order( $current ) {
		$seen = get_option( 'adminkit_menu_seen', array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		$kept = array();
		foreach ( $seen as $slug ) {
			if ( in_array( $slug, $current, true ) && ! in_array( $slug, $kept, true ) ) {
				$kept[] = $slug;
			}
		}
		foreach ( $current as $slug ) {
			if ( ! in_array( $slug, $kept, true ) ) {
				$kept[] = $slug;
			}
		}
		if ( $kept !== $seen && current_user_can( 'manage_options' ) ) {
			update_option( 'adminkit_menu_seen', $kept, false );
		}
		return $kept;
	}

	/**
	 * `parent_file` remap for moved submenus.
	 *
	 * @param string $parent_file Native parent file.
	 * @return string
	 */
	public static function remap_parent_file( $parent_file ) {
		$slug = self::current_menu_slug();
		return ( '' !== $slug && isset( self::$moved[ $slug ] ) ) ? self::$moved[ $slug ] : $parent_file;
	}

	/**
	 * `submenu_file` remap for moved submenus.
	 *
	 * @param string|null $submenu_file Native submenu file.
	 * @return string|null
	 */
	public static function remap_submenu_file( $submenu_file ) {
		$slug = self::current_menu_slug();
		return ( '' !== $slug && isset( self::$moved[ $slug ] ) ) ? $slug : $submenu_file;
	}

	/**
	 * Current screen's menu slug shaped like a `$submenu` item's `[2]`.
	 *
	 * @return string
	 */
	private static function current_menu_slug() {
		global $pagenow, $typenow, $taxnow, $plugin_page;
		if ( ! empty( $plugin_page ) ) {
			return (string) $plugin_page;
		}
		if ( in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true ) && ! empty( $taxnow ) ) {
			return 'edit-tags.php?taxonomy=' . $taxnow . ( ! empty( $typenow ) && 'post' !== $typenow ? '&post_type=' . $typenow : '' );
		}
		if ( ! empty( $typenow ) && ! empty( $pagenow ) ) {
			return $pagenow . '?post_type=' . $typenow;
		}
		return isset( $pagenow ) ? (string) $pagenow : '';
	}

	/**
	 * Emit per-item icon CSS collected during `apply()`.
	 *
	 * @return void
	 */
	public static function print_css() {
		$css = self::$has_hidden ? '#adminmenu .ak-mh{display:none}' : '';
		foreach ( self::$icon_overrides as $cls => $icon ) {
			if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $cls ) ) {
				continue;
			}
			if ( 0 === strncmp( $icon, 'wp-default', 10 ) ) {
				$dashicon = strlen( $icon ) > 11 ? substr( $icon, 11 ) : '';
				if ( '' !== $dashicon && preg_match( '/^dashicons-[a-z0-9-]+$/', $dashicon ) ) {
					$glyph = isset( AdminKit_Icons::MENU_DASHICON_GLYPHS[ $dashicon ] ) ? AdminKit_Icons::MENU_DASHICON_GLYPHS[ $dashicon ] : '';
					$sel   = '#adminmenu .' . $cls . ' .wp-menu-image.' . $dashicon;
					$css  .= $sel . '{box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
					$css  .= $sel . '::before{-webkit-mask-image:none;mask-image:none;background:transparent;'
						. 'display:block;width:36px;height:34px;line-height:34px;text-align:center;'
						. 'position:static;top:auto;'
						. ( '' !== $glyph ? 'content:"' . $glyph . '";font:normal 20px/1 dashicons;speak:never;' : '' )
						. '}';
				}
				continue;
			}
			$mask = AdminKit_Icons::icon_mask( $icon );
			if ( '' === $mask ) {
				continue;
			}
			$sel  = '#adminmenu .' . $cls . ' .wp-menu-image';
			$css .= $sel . '{box-sizing:border-box;width:36px;height:34px;line-height:34px;text-align:center}';
			$css .= $sel . '::before{content:"";display:inline-block;width:20px;height:20px;margin:0;padding:0;'
				. 'vertical-align:middle;position:relative;top:-2px;' . $mask . '}';
		}
		if ( '' !== $css ) {
			echo '<style id="adminkit-menu-manager">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized CSS masks/codepoints.
		}
	}

	/**
	 * Repoint custom-URL menu anchors and `target="_blank"` flags via a compact footer script.
	 *
	 * @return void
	 */
	public static function print_url_overrides() {
		if ( empty( self::$url_overrides ) && empty( self::$new_tab ) ) {
			return;
		}
		$map = array();
		foreach ( self::$url_overrides as $cls => $url ) {
			if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $cls ) ) {
				continue;
			}
			$safe = AdminKit_Menu_Config::sanitize_menu_url( $url );
			if ( '' !== $safe ) {
				$map[ $cls ]['href'] = $safe;
			}
		}
		foreach ( self::$new_tab as $cls => $on ) {
			if ( preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $cls ) ) {
				$map[ $cls ]['blank'] = true;
			}
		}
		if ( empty( $map ) ) {
			return;
		}
		echo '<script id="adminkit-menu-urls">'
			. '(function(m){Object.keys(m).forEach(function(c){'
			. 'var a=document.querySelector("#adminmenu ."+c+">a");if(!a){return;}'
			. 'if(m[c].href){a.href=m[c].href;}'
			. 'if(m[c].blank){a.target="_blank";a.rel="noopener noreferrer";}'
			. '});})(' . wp_json_encode( $map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');</script>' . "\n";
	}
}
