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
				'key'   => 'custom_dashboard_enabled',
				'group' => $content,
				'label' => __( 'Custom dashboard', 'adminkit' ),
				'desc'  => __( 'Replace the WordPress dashboard with a custom one: greeting, quick actions, stat tiles, recent activity, site health and storage. Off restores the native dashboard.', 'adminkit' ),
			),
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
				'key'   => 'replace_icons_enabled',
				'group' => $appearance,
				'label' => __( 'AdminKit icons', 'adminkit' ),
				'desc'  => __( 'Swap WordPress\'s menu and toolbar icons for AdminKit\'s set. Icons customised elsewhere (e.g. Admin Menu Editor) are left alone.', 'adminkit' ),
			),
			array(
				'key'   => 'hide_footer_enabled',
				'group' => $appearance,
				'label' => __( 'Hide admin footer', 'adminkit' ),
				'desc'  => __( 'Hide the admin footer bar — the "Thank you for creating with WordPress" line and the version number — on every screen, for a cleaner app-like look. Off restores it.', 'adminkit' ),
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
			array(
				'key'   => 'custom_avatars_enabled',
				'group' => $users,
				'label' => __( 'Custom avatars', 'adminkit' ),
				'desc'  => __( 'Adds "AdminKit Portraits (Generated)" to Settings → Discussion → Default Avatar. Pick it there to give every user a unique generated portrait.', 'adminkit' ),
			),
		);

		$rows[] = array(
			'key'             => 'bricks_builder_enabled',
			'group'           => $appearance,
			'label'           => __( 'Bricks builder', 'adminkit' ),
			'desc'            => __( 'Restyle the Bricks builder UI with your tokens. Automatically sets Bricks → Settings → Builder mode to "Custom".', 'adminkit' ),
			'available'       => self::bricks_detected(),
			'unavailableHint' => __( 'Activate the Bricks theme to use this.', 'adminkit' ),
		);

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
		$labels = array(
			'acf'               => 'Advanced Custom Fields (ACF)',
			'admin-menu-editor' => 'Admin Menu Editor',
			'bricks'            => 'Bricks',
			'fluent-booking'    => 'FluentBooking',
			'fluent-smtp'       => 'FluentSMTP',
			'fluentform'        => 'Fluent Forms',
			'flying-press'      => 'FlyingPress',
			'happyfiles'        => 'HappyFiles',
			'query-monitor'     => 'Query Monitor',
			'slim-seo'          => 'Slim SEO',
			'woocommerce'       => 'WooCommerce',
			'wp-migrate-db-pro' => 'WP Migrate DB Pro',
			'wpcode'            => 'WPCode',
		);
		$files  = glob( ADMINKIT_PATH . 'inc/integrations/*/*/class-*.php' );
		if ( ! $files ) {
			return $specs;
		}
		foreach ( $files as $file ) {
			$slug  = substr( basename( $file, '.php' ), strlen( 'class-' ) );
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
				'label' => isset( $labels[ $slug ] ) ? $labels[ $slug ] : ucwords( str_replace( '-', ' ', $slug ) ),
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
		$hosts   = self::integration_host_files();
		$by_file = array();
		foreach ( self::integration_specs() as $s ) {
			if ( 'plugin' !== $s['type'] ) {
				continue;
			}
			foreach ( (array) ( isset( $hosts[ $s['slug'] ] ) ? $hosts[ $s['slug'] ] : array() ) as $file ) {
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
	 * Count CSS custom-property declarations in Bricks's generated token file.
	 *
	 * @return int
	 */
	public static function bricks_token_count() {
		if ( ! class_exists( 'AdminKit_Integration_Bricks' ) ) {
			return 0;
		}
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . AdminKit_Integration_Bricks::TOKENS_REL;
		if ( ! is_readable( $path ) ) {
			return 0;
		}
		$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $css || '' === $css ) {
			return 0;
		}
		return preg_match_all( '/--[a-z0-9_-]+\s*:/i', $css );
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

	/**
	 * Known host plugin file(s) per adapter slug.
	 *
	 * @return array<string, string[]>
	 */
	private static function integration_host_files() {
		return array(
			'acf'               => array( 'advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php', 'secure-custom-fields/secure-custom-fields.php' ),
			'admin-menu-editor' => array( 'admin-menu-editor/menu-editor.php', 'admin-menu-editor-pro/menu-editor.php' ),
			'fluent-booking'    => array( 'fluent-booking/fluent-booking.php', 'fluent-booking-pro/fluent-booking-pro.php' ),
			'fluent-smtp'       => array( 'fluent-smtp/fluent-smtp.php' ),
			'fluentform'        => array( 'fluentform/fluentform.php', 'fluentformpro/fluentformpro.php' ),
			'flying-press'      => array( 'flying-press/flying-press.php' ),
			'happyfiles'        => array( 'happyfiles/happyfiles.php', 'happyfiles-pro/happyfiles.php' ),
			'query-monitor'     => array( 'query-monitor/query-monitor.php' ),
			'slim-seo'          => array( 'slim-seo/slim-seo.php' ),
			'woocommerce'       => array( 'woocommerce/woocommerce.php' ),
			'wp-migrate-db-pro' => array( 'wp-migrate-db-pro/wp-migrate-db-pro.php', 'wp-migrate-db/wp-migrate-db.php' ),
			'wpcode'            => array( 'wpcode/wpcode.php', 'insert-headers-and-footers/ihaf.php' ),
		);
	}
}
