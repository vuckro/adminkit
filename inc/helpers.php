<?php
/**
 * Generic helpers.
 *
 * Screen-specific detection lives in `AdminKit_Screen`. This class
 * keeps a backwards-compatible alias for callers that still use
 * `AdminKit_Helpers::is_block_editor()`.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Helpers {

	/**
	 * Backwards-compatible alias. Use `AdminKit_Screen::is_block_editor()`
	 * in new code.
	 *
	 * @return bool
	 */
	public static function is_block_editor() {
		return AdminKit_Screen::is_block_editor();
	}
}
