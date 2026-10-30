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
		$content    = __( 'Content & lists', 'adminkit' );
		$appearance = __( 'Appearance', 'adminkit' );
		$users      = __( 'Users & access', 'adminkit' );
		$rows       = array(

			array(
				'key'   => 'notification_center_enabled',
				'group' => $content,
				'label' => __( 'Notification center', 'adminkit' ),
				'desc'  => __( 'Adds a bell to the toolbar that collects admin notices into a side drawer, keeping success confirmations inline. Off restores every notice to its normal place.', 'adminkit' ),
			),
			array(
				'key'   => 'post_previews_enabled',
				'group' => $content,
				'label' => __( 'Post previews', 'adminkit' ),
				'desc'  => __( 'Adds a thumbnail column to post-type list tables — the featured image first, with a live WordPress.com mShots screenshot as fallback on production sites (skipped on localhost where mShots can\'t reach the page anyway).', 'adminkit' ),
			),
			array(
				'key'   => 'active_items_first_enabled',
				'group' => $content,
				'label' => __( 'Active items first', 'adminkit' ),
				'desc'  => __( 'Prioritize published articles, pages and custom post types, and active plugins. Open their lists on Published or Active when available, and group published or active items first in All. Explicit filters, searches and content sorting are preserved. Off restores the native WordPress lists.', 'adminkit' ),
			),
			array(
				'key'   => 'branding_enabled',
				'group' => $appearance,
				'label' => __( 'Brand identity', 'adminkit' ),
				'desc'  => __( 'Use the administration logo and site icon in the toolbar. Off restores the native WordPress identity and keeps your saved logo.', 'adminkit' ),
				'settingsUrl' => admin_url( 'options-general.php#ak-general-logo-url' ),
			),
			array(
				'key'   => 'theme_toggle_enabled',
				'group' => $appearance,
				'label' => __( 'Dark mode', 'adminkit' ),
				'desc'  => __( 'Adds a light/dark toggle to the admin bar. Off forces light mode site-wide.', 'adminkit' ),
			),
			array(
				'key'   => 'module_login_enabled',
				'group' => $appearance,
				'label' => __( 'Login screen', 'adminkit' ),
				'desc'  => __( 'Restyle wp-login.php to match the admin (logo, dark mode, focus states).', 'adminkit' ),
			),
			array(
				'key'   => 'editor_content_theme',
				'group' => $appearance,
				'label' => __( 'Block editor', 'adminkit' ),
				'desc'  => __( 'Theme the Gutenberg canvas in light and dark. Off keeps the canvas matching your live site exactly.', 'adminkit' ),
			),
			array(
				'key'   => 'menu_manager_enabled',
				'group' => $appearance,
				'label' => __( 'Menu manager', 'adminkit' ),
				'desc'  => __( 'Reorder the admin menu and submenus, set a per-item icon (an AdminKit glyph, a WordPress dashicon, or your own), and hide entries — configured in the Menu tab. Off restores the native WordPress menu (your saved layout is kept).', 'adminkit' ),
			),
			array(
				'key'   => 'toolbar_manager_enabled',
				'group' => $appearance,
				'label' => __( 'Toolbar manager', 'adminkit' ),
				'desc'  => __( 'Reorder and hide the top-level items in the admin toolbar (the bar across the top of the screen) — configured in the Toolbar tab. Off restores the native toolbar (your saved layout is kept).', 'adminkit' ),
			),
			array(
				'key'   => 'hide_footer_enabled',
				'group' => $appearance,
				'label' => __( 'Hide admin footer', 'adminkit' ),
				'desc'  => __( 'Hide the admin footer bar — the "Thank you for creating with WordPress" line and the version number — on every screen, for a cleaner app-like look. Off restores it.', 'adminkit' ),
			),
			array(
				'key'   => 'hide_help_button_enabled',
				'group' => $appearance,
				'label' => __( 'Hide the Help button', 'adminkit' ),
				'desc'  => __( 'Hide the contextual "Help" tab at the top-right of admin screens, for cleaner chrome. "Screen Options" is left in place. Off restores it.', 'adminkit' ),
			),
			array(
				'key'   => 'replace_icons_enabled',
				'group' => $appearance,
				'label' => __( 'AdminKit icons', 'adminkit' ),
				'desc'  => __( 'Replace the WordPress menu icons with the AdminKit set. Off restores the native dashicons (icons you chose in the menu manager are kept).', 'adminkit' ),
			),
			array(
				'key'   => 'quick_edit_users_enabled',
				'group' => $users,
				'label' => __( 'Users quick edit', 'adminkit' ),
				'desc'  => __( 'Edit first name, last name, email and role inline from the users list — no need to open the full profile.', 'adminkit' ),
			),
			array(
				'key'   => 'username_changer_enabled',
				'group' => $users,
				'label' => __( 'Username changer', 'adminkit' ),
				'desc'  => __( 'Lets admins rename a user\'s login on Users → Edit (WordPress disables this by default). Sensitive — invalidates the user\'s active sessions; they must sign in again. Single-site only.', 'adminkit' ),
			),


		);

		$summaries = array(
			'branding_enabled' => __( 'Personalize the identity displayed in the toolbar.', 'adminkit' ),
			'notification_center_enabled' => __( 'Collect admin notices in one place.', 'adminkit' ),
			'post_previews_enabled' => __( 'Recognize your publications by their thumbnails.', 'adminkit' ),
			'active_items_first_enabled' => __( 'Find published content and active plugins first.', 'adminkit' ),
			'theme_toggle_enabled' => __( 'Let each user choose light or dark mode.', 'adminkit' ),
			'module_login_enabled' => __( 'Match the login screen to your administration.', 'adminkit' ),
			'editor_content_theme' => __( 'Extend light and dark styling to the editor canvas.', 'adminkit' ),
			'menu_manager_enabled' => __( 'Organize navigation and hide unnecessary entries.', 'adminkit' ),
			'toolbar_manager_enabled' => __( 'Organize shortcuts in the top toolbar.', 'adminkit' ),
			'hide_footer_enabled' => __( 'Remove the WordPress footer and version line.', 'adminkit' ),
			'hide_help_button_enabled' => __( 'Hide contextual help on admin screens.', 'adminkit' ),
			'replace_icons_enabled' => __( 'Use the AdminKit icon set in the admin menu.', 'adminkit' ),
			'quick_edit_users_enabled' => __( 'Edit account details directly from the users list.', 'adminkit' ),
			'username_changer_enabled' => __( 'Allow administrators to rename a user login.', 'adminkit' ),
		);
		foreach ( $rows as &$row ) {
			$row['summary'] = isset( $summaries[ $row['key'] ] ) ? $summaries[ $row['key'] ] : '';
		}
		unset( $row );

		return $rows;
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
		$specs  = array();
		$files  = glob( ADMINKIT_PATH . 'inc/integrations/*/*/class-*.php' );
		if ( ! $files ) {
			return $specs;
		}
		foreach ( $files as $file ) {
			$slug  = substr( basename( $file, '.php' ), strlen( 'class-' ) );
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
		$by_file = array();
		foreach ( self::integration_specs() as $s ) {
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
				$rows[] = array(
					'slug'      => '',
					'file'      => $file,
					'label'     => '' !== (string) $data['Name'] ? $data['Name'] : 'AdminKit',
					'type'      => 'plugin',
					'supported' => false,
					'system'    => true,
					'enabled'   => true,
					'active'    => true,
				);
				continue;
			}
			$slug      = isset( $by_file[ $file ] ) ? $by_file[ $file ] : '';
			$supported = ( '' !== $slug );
			$rows[]    = array(
				'slug'      => $slug,
				'file'      => $file,
				'label'     => '' !== (string) $data['Name'] ? $data['Name'] : $file,
				'type'      => 'plugin',
				'supported' => $supported,
				'enabled'   => $supported
					? (bool) AdminKit_Settings::get( 'integration_' . $slug . '_enabled' )
					: ! in_array( $file, $generic_off, true ),
				'active'    => is_plugin_active( $file ),
			);
		}

		foreach ( self::integration_specs() as $s ) {
			if ( 'theme' !== $s['type'] || ! call_user_func( array( $s['class'], 'is_active' ) ) ) {
				continue;
			}
			$rows[] = array(
				'slug'      => $s['slug'],
				'file'      => '',
				'label'     => $s['label'],
				'type'      => 'theme',
				'supported' => true,
				'enabled'   => (bool) AdminKit_Settings::get( 'integration_' . $s['slug'] . '_enabled' ),
				'active'    => true,
			);
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
		self::register_integration_toggles();
		return (bool) AdminKit_Settings::get( 'integration_bricks_enabled' );
	}

	/**
	 * Register integration toggles and the generic plugin opt-out list.
	 *
	 * @return void
	 */
	public static function register_integration_toggles() {
		foreach ( self::integration_specs() as $s ) {
			AdminKit_Settings::register( 'integration_' . $s['slug'] . '_enabled', array(
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
