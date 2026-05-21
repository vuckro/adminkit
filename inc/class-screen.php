<?php
/**
 * Screen-detection helpers.
 *
 * Thin wrappers around `get_current_screen()` used by asset-registry
 * conditions and integration classes. Each helper is pure and safe to
 * call from any hook callback — they return sensible defaults when
 * `get_current_screen()` isn't available yet.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Screen {

	/**
	 * Current screen id, or empty string if not in admin context.
	 *
	 * @return string
	 */
	public static function id() {
		$screen = self::get();
		return $screen ? (string) $screen->id : '';
	}

	/**
	 * Parent base of the current screen (e.g. `woocommerce`, `fluent-crm`).
	 * Useful to match all sub-pages of an integration menu.
	 *
	 * @return string
	 */
	public static function parent_base() {
		$screen = self::get();
		return $screen ? (string) $screen->parent_base : '';
	}

	/**
	 * Whether the current screen id matches one of the given ids.
	 *
	 * @param string[] $ids
	 * @return bool
	 */
	public static function is_one_of( array $ids ) {
		$id = self::id();
		return $id !== '' && in_array( $id, $ids, true );
	}

	/**
	 * Whether the current admin screen is the Gutenberg block editor.
	 *
	 * @return bool
	 */
	public static function is_block_editor() {
		$screen = self::get();
		return $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
	}

	/**
	 * Resolve the current screen object, or null if unavailable.
	 *
	 * @return \WP_Screen|null
	 */
	public static function get() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}
		$screen = get_current_screen();
		return $screen ?: null;
	}
}
