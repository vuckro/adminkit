<?php
/**
 * Settings-page catalogs and derived data.
 *
 * Keeps the page controller focused on menu/REST concerns while this class owns
 * the feature list, integration inventory, and Bricks export metadata consumed
 * by the settings SPA.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Settings_Catalog {

	/**
	 * Feature toggles shown on the Settings tab, in display order. Keys match
	 * settings registered in AdminKit_Settings / AdminKit_Post_Previews.
	 *
	 * @return array
	 */
	public static function features() {
		$appearance = __( 'Appearance', 'adminkit' );
		$navigation = __( 'Navigation', 'adminkit' );
		$tables     = __( 'Tables', 'adminkit' );
		$accounts   = __( 'Accounts', 'adminkit' );

		return array(
			array(
				'key'   => 'theme_toggle_enabled',
				'icon'  => 'moon',
				'group' => $appearance,
				'label' => __( 'Dark mode', 'adminkit' ),
				'desc'  => __( 'Add a light/dark mode switch to the toolbar. When off, light mode is used everywhere.', 'adminkit' ),
			),
			array(
				'key'   => 'module_login_enabled',
				'icon'  => 'log-in',
				'group' => $appearance,
				'label' => __( 'Login screen', 'adminkit' ),
				'desc'  => __( 'Style wp-login.php to match the administration (logo, dark mode and focus states).', 'adminkit' ),
			),
			array(
				'key'   => 'menu_manager_enabled',
				'icon'  => 'panel-left',
				'group' => $navigation,
				'label' => __( 'Menu manager', 'adminkit' ),
				'desc'  => __( 'Reorder, rename, re-icon and hide sidebar menu items from AdminKit → Navigation.', 'adminkit' ),
			),
			array(
				'key'   => 'toolbar_manager_enabled',
				'icon'  => 'panel-top',
				'group' => $navigation,
				'label' => __( 'Toolbar manager', 'adminkit' ),
				'desc'  => __( 'Reorder and hide top toolbar items from AdminKit → Navigation.', 'adminkit' ),
			),
			array(
				'key'   => 'clean_chrome_enabled',
				'icon'  => 'scan',
				'group' => $appearance,
				'label' => __( 'Clean interface', 'adminkit' ),
				'desc'  => __( 'Choose which secondary elements to hide in the WordPress administration.', 'adminkit' ),
				'options' => array(
					array(
						'key' => 'clean_chrome_hide_footer',
						'label' => __( 'Hide the WordPress footer', 'adminkit' ),
						'desc' => __( 'Hide the footer text and WordPress version at the bottom of administration screens.', 'adminkit' ),
						'onLabel' => __( 'Hidden', 'adminkit' ),
						'offLabel' => __( 'Visible', 'adminkit' ),
					),
					array(
						'key' => 'clean_chrome_hide_help',
						'label' => __( 'Hide the Help tab', 'adminkit' ),
						'desc' => __( 'Hide the contextual Help tab at the top of administration screens.', 'adminkit' ),
						'onLabel' => __( 'Hidden', 'adminkit' ),
						'offLabel' => __( 'Visible', 'adminkit' ),
					),
				),
			),
			array(
				'key'   => 'notification_center_enabled',
				'icon'  => 'bell',
				'group' => $appearance,
				'label' => __( 'Notification center', 'adminkit' ),
				'desc'  => __( 'Collect admin notices into a toolbar drawer while keeping inline action confirmations in place.', 'adminkit' ),
			),
			array(
				'key'   => 'active_items_first_enabled',
				'icon'  => 'list-filter',
				'group' => $tables,
				'label' => __( 'Active items first', 'adminkit' ),
				'desc'  => __( 'Choose how content and plugin tables open, and whether published or active items come first.', 'adminkit' ),
				'options' => array(
					array(
						'key' => 'active_items_first_content_default',
						'label' => __( 'Open content tables on Published', 'adminkit' ),
						'desc' => __( 'On arrival, show published posts, pages and custom content. Searches and chosen filters stay unchanged.', 'adminkit' ),
					),
					array(
						'key' => 'active_items_first_plugins_default',
						'label' => __( 'Open the plugins table on Active', 'adminkit' ),
						'desc' => __( 'On arrival, show active plugins. After activation or deactivation, keep the changed plugin visible.', 'adminkit' ),
					),
					array(
						'key' => 'active_items_first_sort',
						'label' => __( 'Group published or active items first in All', 'adminkit' ),
						'desc' => __( 'In All, place published content and active plugins before the others. Explicit sorting and searches take priority.', 'adminkit' ),
					),
				),
			),
			array(
				'key'   => 'post_previews_enabled',
				'icon'  => 'image',
				'group' => $tables,
				'label' => __( 'Post previews', 'adminkit' ),
				'desc'  => __( 'Show a thumbnail column in post and page list tables.', 'adminkit' ),
			),
			array(
				'key'   => 'quick_edit_users_enabled',
				'icon'  => 'user-pen',
				'group' => $accounts,
				'label' => __( 'Users quick edit', 'adminkit' ),
				'desc'  => __( 'Edit first name, last name, email and role inline from the users list.', 'adminkit' ),
			),
			array(
				'key'             => 'username_changer_enabled',
				'icon'            => 'user-round-cog',
				'group'           => $accounts,
				'label'           => __( 'Username changer', 'adminkit' ),
				'desc'            => __( 'Allow administrators to rename a user login on Users → Edit (single-site only; signs the user out).', 'adminkit' ),
				'available'       => ! ( function_exists( 'is_multisite' ) && is_multisite() ),
				'unavailableHint' => __( 'Unavailable on Multisite networks.', 'adminkit' ),
			),
		);
	}

	/**
	 * Memoized map of integration `slug => absolute_file_path` so `glob()` executes
	 * at most once per PHP request across Settings, Catalog and Plugin boot.
	 *
	 * @return array<string, string>
	 */
	public static function integration_files() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map   = array();
		$files = glob( ADMINKIT_PATH . 'inc/integrations/*/*/class-*.php' );
		if ( ! $files ) {
			return $map;
		}
		foreach ( $files as $file ) {
			$slug         = substr( basename( $file, '.php' ), strlen( 'class-' ) );
			$map[ $slug ] = $file;
		}
		return $map;
	}

	/**
	 * Discover integrations the way the orchestrator does.
	 *
	 * @return array<int, array{slug:string,label:string,type:string,class:string}>
	 */
	public static function integration_specs() {
		static $specs = null;
		if ( null !== $specs ) {
			return $specs;
		}
		$specs = array();
		foreach ( self::integration_files() as $slug => $file ) {
			// Core editor chrome is always part of the admin skin, not a plugin
			// opt-in. The feature catalog owns the optional canvas setting.
			if ( 'gutenberg' === $slug ) {
				continue;
			}
			$class = 'AdminKit_Integration_' . str_replace( '-', '_', ucwords( $slug, '-' ) );
			if ( ! class_exists( $class ) || ! method_exists( $class, 'is_active' ) ) {
				continue;
			}
			if ( method_exists( $class, 'type' ) ) {
				$type = ( 'theme' === call_user_func( array( $class, 'type' ) ) ) ? 'theme' : 'plugin';
			} else {
				$type = wp_get_theme( $slug )->exists() ? 'theme' : 'plugin';
			}
			$specs[] = array(
				'slug'  => $slug,
				'label' => call_user_func( array( $class, 'label' ) ),
				'type'  => $type,
				'class' => $class,
			);
		}
		return $specs;
	}

	/**
	 * Build the Plugins-tab list from installed plugins plus active theme adapters.
	 *
	 * @return array<int, array>
	 */
	public static function plugins_list() {
		$by_file       = array();
		$class_by_slug = array();
		foreach ( self::integration_specs() as $s ) {
			$class_by_slug[ $s['slug'] ] = $s['class'];
			if ( 'plugin' !== $s['type'] ) {
				continue;
			}
			foreach ( (array) call_user_func( array( $s['class'], 'host_files' ) ) as $file ) {
				$by_file[ $file ] = $s['slug'];
			}
		}

		$rows = array();
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$generic_off = (array) AdminKit_Settings::get( 'generic_theming_off' );
		$self        = plugin_basename( ADMINKIT_FILE );

		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $self ) {
				continue;
			}

			$slug      = isset( $by_file[ $file ] ) ? $by_file[ $file ] : '';
			$supported = ( '' !== $slug );
			$active    = is_plugin_active( $file );
			$paused    = null;
			if ( $supported && $active && isset( $class_by_slug[ $slug ] ) && method_exists( $class_by_slug[ $slug ], 'paused_version_info' ) ) {
				$paused = call_user_func( array( $class_by_slug[ $slug ], 'paused_version_info' ) );
			}
			$row = array(
				'slug'      => $slug,
				'file'      => $file,
				'label'     => '' !== (string) $data['Name'] ? $data['Name'] : $file,
				'type'      => 'plugin',
				'supported' => $supported,
				'enabled'   => $supported
					? (bool) AdminKit_Settings::get( 'integration_' . $slug . '_enabled' )
					: ! in_array( $file, $generic_off, true ),
				'active'    => $active,
			);
			if ( is_array( $paused ) ) {
				$row['paused'] = $paused;
			}
			$rows[] = $row;
		}

		foreach ( self::integration_specs() as $s ) {
			if ( 'theme' !== $s['type'] || ! call_user_func( array( $s['class'], 'is_active' ) ) ) {
				continue;
			}
			$paused = method_exists( $s['class'], 'paused_version_info' )
				? call_user_func( array( $s['class'], 'paused_version_info' ) )
				: null;
			$row = array(
				'slug'      => $s['slug'],
				'file'      => '',
				'label'     => $s['label'],
				'type'      => 'theme',
				'supported' => true,
				'enabled'   => (bool) AdminKit_Settings::get( 'integration_' . $s['slug'] . '_enabled' ),
				'active'    => true,
			);
			if ( is_array( $paused ) ) {
				$row['paused'] = $paused;
			}
			$rows[] = $row;
		}

		usort( $rows, static function ( $a, $b ) {
			$as = ! empty( $a['system'] );
			$bs = ! empty( $b['system'] );
			if ( $as !== $bs ) {
				return $as ? -1 : 1;
			}
			if ( $a['supported'] !== $b['supported'] ) {
				return $a['supported'] ? -1 : 1;
			}
			return strcasecmp( $a['label'], $b['label'] );
		} );

		return $rows;
	}

	/**
	 * Bricks theme is currently active.
	 *
	 * @return bool
	 */
	public static function bricks_detected() {
		return class_exists( 'AdminKit_Integration_Bricks' )
			&& AdminKit_Integration_Bricks::is_active();
	}

	/**
	 * Bricks tokens are actually flowing into AdminKit.
	 *
	 * @return bool
	 */
	public static function bricks_connected() {
		if ( ! self::bricks_detected() ) {
			return false;
		}
		return (bool) AdminKit_Settings::get( 'integration_bricks_enabled' );
	}

	/**
	 * Register integration toggles and the generic plugin opt-out list.
	 *
	 * @return void
	 */
	public static function register_integration_toggles() {
		foreach ( self::integration_files() as $slug => $_file ) {
			if ( 'gutenberg' === $slug ) { continue; }
			AdminKit_Settings::register( 'integration_' . $slug . '_enabled', array(
				'type'     => 'toggle',
				'group'    => 'integrations',
				'default'  => true,
				'sanitize' => 'rest_sanitize_boolean',
			) );
		}
		AdminKit_Settings::register( 'generic_theming_off', array(
			'type'     => 'array',
			'group'    => 'integrations',
			'default'  => array(),
			'sanitize' => static function ( $v ) {
				if ( ! is_array( $v ) ) {
					return array();
				}
				$clean = array();
				foreach ( $v as $file ) {
					if ( ! is_string( $file ) ) { continue; }
					$file = sanitize_text_field( (string) $file );
					if ( '' !== $file ) {
						$clean[] = $file;
					}
				}
				return array_values( array_unique( $clean ) );
			},
		) );
	}
}
