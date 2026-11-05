<?php
/** Deterministic two-level placement. Pure planner: no globals, hooks or storage writes.
 *
 * @package AdminKit
 */
defined( 'ABSPATH' ) || exit;

class AdminKit_Menu_Layout {
	/** Plan from the pristine tree; never iterate a tree while moving its rows.
	 *
	 * @param array    $menu Native top-level rows.
	 * @param array    $submenu Native child rows.
	 * @param array    $config Normalized saved layout.
	 * @param array    $accessible Available destination parents.
	 * @param callable $can_access Capability predicate.
	 * @return array
	 */
	public static function plan( array $menu, array $submenu, array $config, array $accessible, callable $can_access ) {
		$top = array_column( $config['top'], null, 'slug' );
		$local = array();
		$destinations = array();
		foreach ( $config['sub'] as $parent => $children ) {
			foreach ( $children as $child ) {
				$local[ $parent ][ $child['slug'] ] = $child;
				$destinations[ $child['slug'] ][ $parent ] = true;
			}
		}
		// A cross-parent move is unambiguous only with one configured destination.
		$target = array();
		foreach ( $destinations as $slug => $parents ) {
			if ( 1 === count( $parents ) ) { $target[ $slug ] = (string) array_key_first( $parents ); }
		}
		$demoted = array();
		$rows = array();
		$removed = array();
		foreach ( $menu as $position => $item ) {
			$slug = (string) $item[2];
			$parent = $target[ $slug ] ?? $slug;
			if ( isset( $top[ $slug ] ) || 'adminkit' === $slug || $parent === $slug || ! isset( $accessible[ $parent ] ) || ! empty( $top[ $parent ]['hidden'] ) || ! $can_access( $item[1] ) ) { continue; }
			// Do not flatten into a parent which is itself configured below another.
			if ( isset( $target[ $parent ] ) && $target[ $parent ] !== $parent && ! isset( $top[ $parent ] ) ) { continue; }
			$demoted[ $slug ] = $parent;
			$removed[] = $position;
			$rows[] = array( 'origin' => '', 'fallback' => $parent, 'item' => array( $item[0], $item[1], $slug, $item[3] ?? $item[0] ) );
		}
		foreach ( $submenu as $parent => $children ) {
			foreach ( $children as $item ) {
				if ( empty( $item[2] ) || ( isset( $demoted[ $parent ] ) && $item[2] === $parent ) ) { continue; }
				$rows[] = array( 'origin' => (string) $parent, 'fallback' => $demoted[ $parent ] ?? (string) $parent, 'item' => $item );
			}
		}
		$output = array();
		$moves = array();
		foreach ( $rows as $row ) {
			$slug = (string) $row['item'][2];
			$parent = $row['fallback'];
			// An explicit local rule wins; otherwise use a unique requested move.
			$rule = $local[ $parent ][ $slug ] ?? array();
			if ( ! isset( $local[ $parent ][ $slug ] ) && isset( $target[ $slug ] ) ) {
				$requested = $target[ $slug ];
				$rule = $local[ $requested ][ $slug ];
				if ( isset( $accessible[ $requested ] ) ) { $parent = $requested; }
			}
			// One physical route per parent, even if a plugin registered it twice.
			unset( $output[ $parent ][ $slug ] );
			$output[ $parent ][ $slug ] = array( 'item' => $row['item'], 'rule' => $rule );
			if ( $row['origin'] !== $parent ) {
				$moves[ $row['origin'] . "\0" . $slug ] = array( $slug, $row['origin'], $parent );
			}
		}
		return array( 'children' => $output, 'removed' => $removed, 'moves' => array_values( $moves ) );
	}
}
