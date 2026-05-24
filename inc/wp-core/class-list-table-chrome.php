<?php
/**
 * List-table chrome polish.
 *
 * The status-filter row (`.subsubsub`: All | Active | Inactive …) ships two
 * bits of markup that fight a modern presentation: literal " |" separators as
 * text nodes between the links, and counts wrapped in parentheses, e.g. "(12)".
 * CSS can hide the pipes but can't strip the parentheses, so a small footer
 * script removes both — leaving clean links + numeric counts that
 * wp-core/chrome.css styles into inline pills with round notification badges. It
 * also wraps each list table in a horizontal-scroll container and sizes Quick
 * Edit to the visible width.
 *
 * Behaviour lives in `assets/js/wp-core/list-table-chrome.js`, loaded as a
 * footer script on admin pages; it is a no-op wherever those elements are absent.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_List_Table_Chrome {

	/**
	 * Wire the hook. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the footer script that polishes list tables. No-op when AdminKit
	 * isn't styling the admin.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		AdminKit_Assets::enqueue_script(
			'adminkit-list-table-chrome',
			'assets/js/wp-core/list-table-chrome.js'
		);
	}
}
