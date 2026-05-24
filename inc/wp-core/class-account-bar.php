<?php
/**
 * Account menu (admin bar) — point it at the Dashboard.
 *
 * WordPress aims the whole account menu at the profile editor. Day to day the
 * Dashboard is the more useful target, so this re-points the account button, the
 * dropdown header (avatar + name) and the "Edit Profile" item — which is also
 * relabelled "Dashboard" — to wp-admin. Log Out is left untouched.
 *
 * Read-only on the node graph: it re-targets existing nodes after WP builds them
 * (priority 9999) and never adds or removes items, so other plugins' account
 * entries stay intact.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Account_Bar {

	/**
	 * Wire the hook. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'customize' ), 9999 );
	}

	/**
	 * Re-point the account button + dropdown header to the Dashboard, and turn
	 * "Edit Profile" into a "Dashboard" link.
	 *
	 * @param WP_Admin_Bar $bar
	 * @return void
	 */
	public static function customize( $bar ) {
		$dashboard = admin_url();

		foreach ( array( 'my-account', 'user-info' ) as $id ) {
			$node = $bar->get_node( $id );
			if ( $node ) {
				$node->href = $dashboard;
				$bar->add_node( (array) $node );
			}
		}

		$edit = $bar->get_node( 'edit-profile' );
		if ( $edit ) {
			$edit->href  = $dashboard;
			$edit->title = __( 'Dashboard' );
			$bar->add_node( (array) $edit );
		}
	}
}
