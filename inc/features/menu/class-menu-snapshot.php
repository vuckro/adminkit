<?php
/**
 * Menu snapshot — captures the pristine native `$menu` / `$submenu` tree on
 * AdminKit settings screens before `AdminKit_Menu_Renderer::apply()` mutates it,
 * and assembles the lightweight `editor_data()` payload.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Menu_Snapshot {

	/** @var array Pristine menu snapshot for the editor (built on AdminKit settings pages). */
	private static $snapshot = array();

	/**
	 * Capture the pristine menu on AdminKit settings screens, before `apply()` mutates it.
	 *
	 * @return void
	 */
	public static function capture() {
		if ( ! self::is_menu_editor_screen() ) {
			return;
		}
		global $menu, $submenu;
		self::$snapshot = self::build_snapshot( (array) $menu, (array) $submenu );
	}

	/**
	 * Whether the current request is one of the five native AdminKit settings pages.
	 *
	 * @return bool
	 */
	public static function is_menu_editor_screen() {
		if ( ! is_admin() || empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		return in_array(
			sanitize_key( wp_unslash( $_GET['page'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			array( AdminKit_Settings_Page::SLUG, 'adminkit-features', 'adminkit-design', 'adminkit-navigation', 'adminkit-configuration' ),
			true
		);
	}

	/**
	 * Turn the live `$menu` / `$submenu` globals into a clean tree for the editor:
	 *   `[ { slug, title, dashicon, children:[ { slug, title } ] }, … ]`
	 *
	 * @param array $menu    Global `$menu` array.
	 * @param array $submenu Global `$submenu` array.
	 * @return array
	 */
	public static function build_snapshot( $menu, $submenu ) {
		uksort( $menu, 'strnatcasecmp' );

		$out = array();
		foreach ( $menu as $item ) {
			if ( empty( $item[2] ) ) {
				continue;
			}
			$classes = ( isset( $item[4] ) && is_string( $item[4] ) ) ? $item[4] : '';
			if ( false !== strpos( $classes, 'wp-menu-separator' ) ) {
				$out[] = array(
					'slug'     => (string) $item[2],
					'type'     => 'separator',
					'title'    => '',
					'children' => array(),
				);
				continue;
			}
			$slug = (string) $item[2];
			$node = array(
				'slug'     => $slug,
				'title'    => self::clean_title( isset( $item[0] ) ? $item[0] : '' ),
				'dashicon' => self::dashicon_class( isset( $item[6] ) ? $item[6] : '' ),
				'children' => array(),
			);
			if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					if ( empty( $sub[2] ) ) {
						continue;
					}
					$node['children'][] = array(
						'slug'  => (string) $sub[2],
						'title' => self::clean_title( isset( $sub[0] ) ? $sub[0] : '' ),
					);
				}
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * Strip count bubbles / update badges (including nested spans) from a menu title.
	 *
	 * @param string $title Raw menu title markup.
	 * @return string
	 */
	public static function clean_title( $title ) {
		$title = (string) $title;
		while ( preg_match( '/<span\b[^>]*>(?:(?!<span\b).)*?<\/span>/is', $title ) ) {
			$title = preg_replace( '/<span\b[^>]*>(?:(?!<span\b).)*?<\/span>/is', '', $title );
		}
		$title = wp_strip_all_tags( (string) $title );
		return trim( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Return a valid `dashicons-*` class name, or `''` for image / SVG / data-URI icons.
	 *
	 * @param mixed $icon Raw `$menu[6]` slot value.
	 * @return string
	 */
	public static function dashicon_class( $icon ) {
		return ( is_string( $icon ) && preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) ? $icon : '';
	}

	/**
	 * Build the editor payload handed to `AdminKit_Settings_Page::boot_data()`.
	 *
	 * @param bool $enabled Whether `AdminKit_Menu_Manager::is_enabled()` is active.
	 * @return array
	 */
	public static function editor_data( $enabled ) {
		$dashicons = array_values( array_diff( AdminKit_Icons::WP_DASHICONS, AdminKit_Core_Menu_Icons::mapped_classes() ) );
		$config    = $enabled ? AdminKit_Menu_Store::get_config() : array( 'top' => array(), 'sub' => array() );

		$initial = array_merge(
			array_keys( AdminKit_Icons::ALIASES ),
			array( 'app', 'link', 'house', 'pencil', 'eye', 'plus', 'refresh-cw', 'search', 'sliders-horizontal', 'palette', 'bell', 'moon', 'external-link', 'user', 'users', 'wrench' )
		);
		foreach ( ! empty( $config['top'] ) ? $config['top'] : array() as $t ) {
			if ( ! empty( $t['icon'] ) && is_string( $t['icon'] ) ) {
				$initial[] = $t['icon'];
			}
		}
		if ( class_exists( 'AdminKit_Toolbar_Config' ) ) {
			$tb = AdminKit_Toolbar_Config::get_config();
			foreach ( array( 'admin', 'frontend' ) as $ctx ) {
				foreach ( isset( $tb[ $ctx ]['icons'] ) && is_array( $tb[ $ctx ]['icons'] ) ? $tb[ $ctx ]['icons'] : array() as $ic ) {
					if ( is_string( $ic ) && '' !== $ic ) {
						$initial[] = $ic;
					}
				}
			}
		}
		$json_file = ADMINKIT_PATH . 'assets/icons/lucide.json';
		$icons_url = defined( 'ADMINKIT_URL' ) && is_file( $json_file )
			? ADMINKIT_URL . 'assets/icons/lucide.json?ver=' . (string) filemtime( $json_file )
			: '';

		return array(
			'menu'        => self::$snapshot,
			'config'      => $config,
			'icons'       => AdminKit_Icons::subset( $initial ),
			'iconsUrl'    => $icons_url,
			'iconAliases' => AdminKit_Icons::ALIASES,
			'dashicons'   => $dashicons,
			'route'       => '/adminkit/v1/menu',
			'self'        => AdminKit_Settings_Page::SLUG,
			'seen'        => get_option( 'adminkit_menu_seen', array() ),
		);
	}
}
