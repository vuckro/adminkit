<?php
/**
 * Menu configuration — validation, URL sanitisation and Multisite preservation
 * for the sidebar Menu Manager.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Menu_Config {

	/** Stable identity is parent + slug; the last saved row wins conflicts.
	 *
	 * @param array $rows Ordered rows sharing one parent.
	 * @return array
	 */
	public static function unique_rows( array $rows ) {
		$unique = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) || ! is_string( $row['slug'] ) ) { continue; }
			$slug = (string) $row['slug'];
			unset( $unique[ $slug ] );
			$unique[ $slug ] = $row;
		}
		return array_values( $unique );
	}

	/** Normalize legacy duplicates on read, without rewriting stored settings.
	 *
	 * @param array $config Saved layout.
	 * @return array
	 */
	public static function normalize( array $config ) {
		$sub = array();
		foreach ( is_array( $config['sub'] ?? null ) ? $config['sub'] : array() as $parent => $rows ) {
			$sub[ $parent ] = self::unique_rows( is_array( $rows ) ? $rows : array() );
		}
		return array( 'top' => self::unique_rows( is_array( $config['top'] ?? null ) ? $config['top'] : array() ), 'sub' => $sub );
	}

	/** Reject malformed writes atomically; legacy reads still use normalisation.
	 *
	 * @param mixed $items REST rows.
	 * @return bool
	 */
	public static function valid_items( $items ) {
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 1000 ) { return false; }
		foreach ( $items as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['slug'] ) || ! is_string( $row['slug'] ) || '' === trim( $row['slug'] ) ) { return false; }
			foreach ( array( 'parent', 'type', 'title', 'icon', 'url' ) as $field ) {
				if ( isset( $row[ $field ] ) && ! is_string( $row[ $field ] ) ) { return false; }
				if ( array_key_exists( $field, $row ) && null === $row[ $field ] ) { return false; }
			}
			$type = $row['type'] ?? 'item';
			if ( ! in_array( $type, array( 'item', 'link', 'separator' ), true ) || ( '' !== ( $row['parent'] ?? '' ) && 'item' !== $type ) ) { return false; }
			foreach ( array( 'hidden', 'newTab' ) as $field ) {
				if ( array_key_exists( $field, $row ) && ! is_bool( $row[ $field ] ) ) { return false; }
			}
			if ( isset( $row['position'] ) && ( ! is_int( $row['position'] ) || $row['position'] < 0 || $row['position'] > 1000000 ) ) { return false; }
			if ( array_key_exists( 'position', $row ) && null === $row['position'] ) { return false; }
			if ( strlen( $row['parent'] ?? '' ) > 190 || strlen( $row['slug'] ) > ( 'link' === $type ? 2048 : 190 ) ) { return false; }
			$title = $row['title'] ?? '';
			if ( strlen( $title ) > 760 || false === preg_match_all( '/./us', $title, $characters ) || count( $characters[0] ) > 190 ) { return false; }
			if ( 'link' === $type && '' === self::sanitize_menu_url( $row['slug'] ) ) { return false; }
			if ( ! empty( $row['url'] ) && '' === self::sanitize_menu_url( $row['url'] ) ) { return false; }
			if ( ! empty( $row['icon'] ) && '' === self::sanitize_icon( $row['icon'] ) ) { return false; }
		}
		return true;
	}

	/**
	 * Sanitise submitted rows. Icons are validated against `AdminKit_Icons`; slugs are
	 * trimmed/length-capped while preserving query strings (`edit.php?post_type=page`);
	 * custom link URLs are strictly validated against attribute breakout and unsafe schemes.
	 *
	 * @param array $items Raw submitted item rows.
	 * @return array
	 */
	public static function sanitize_items( $items ) {
		$clean = array();
		foreach ( $items as $it ) {
			if ( empty( $it['slug'] ) || ! is_string( $it['slug'] ) ) {
				continue;
			}
			$parent = ( isset( $it['parent'] ) && is_string( $it['parent'] ) ) ? $it['parent'] : '';
			$type   = ( '' === $parent && isset( $it['type'] ) && in_array( $it['type'], array( 'link', 'separator' ), true ) ) ? $it['type'] : 'item';
			$icon   = ( '' === $parent && isset( $it['icon'] ) ) ? self::sanitize_icon( $it['icon'] ) : '';
			$title  = ( isset( $it['title'] ) && is_string( $it['title'] ) ) ? sanitize_text_field( $it['title'] ) : '';
			// A custom link stores its destination URL as the slug; everything else is a plain slug.
			$slug   = ( 'link' === $type ) ? self::sanitize_menu_url( $it['slug'] ) : self::sanitize_slug( $it['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			$clean[] = array(
				'parent'   => self::sanitize_slug( $parent ),
				'slug'     => $slug,
				'position' => isset( $it['position'] ) ? (int) $it['position'] : 0,
				'icon'     => $icon,
				'url'      => isset( $it['url'] ) ? self::sanitize_menu_url( $it['url'] ) : '',
				'new_tab'  => isset( $it['newTab'] ) && rest_sanitize_boolean( $it['newTab'] ),
				'hidden'   => isset( $it['hidden'] ) && rest_sanitize_boolean( $it['hidden'] ),
				'type'     => $type,
				'title'    => $title,
			);
		}
		$unique = array();
		foreach ( $clean as $row ) {
			$key = $row['parent'] . "\0" . $row['slug'];
			unset( $unique[ $key ] );
			$unique[ $key ] = $row;
		}
		return array_values( $unique );
	}

	/**
	 * On Multisite, a non-super-admin's editor snapshot excludes super-admin-only menu
	 * items (`manage_network`), so `navigation-menu.js` omits them from its payload.
	 * Preserve any previously configured native `item` rows that were absent from this
	 * submission so saving as a site administrator never wipes Super Admin rules.
	 *
	 * @param array $items Sanitised submitted rows.
	 * @return array
	 */
	public static function preserve_invisible_multisite_items( array $items ) {
		$prev = AdminKit_Menu_Store::get_config();
		if ( empty( $prev['top'] ) && empty( $prev['sub'] ) ) {
			return $items;
		}
		$seen_top = array();
		$seen_sub = array();
		$max_pos  = 0;
		foreach ( $items as $it ) {
			if ( '' === $it['parent'] ) {
				$seen_top[ $it['slug'] ] = true;
				if ( $it['position'] >= $max_pos ) {
					$max_pos = $it['position'] + 1;
				}
			} else {
				$seen_sub[ $it['parent'] . "\0" . $it['slug'] ] = true;
				$seen_sub[ $it['slug'] ]                        = true;
			}
		}
		foreach ( ! empty( $prev['top'] ) ? $prev['top'] : array() as $t ) {
			$type = isset( $t['type'] ) ? (string) $t['type'] : 'item';
			if ( 'item' !== $type || empty( $t['slug'] ) || isset( $seen_top[ $t['slug'] ] ) || isset( $seen_sub[ $t['slug'] ] ) ) {
				continue;
			}
			$items[] = array(
				'parent'   => '',
				'slug'     => (string) $t['slug'],
				'position' => $max_pos++,
				'icon'     => isset( $t['icon'] ) ? (string) $t['icon'] : '',
				'url'      => isset( $t['url'] ) ? (string) $t['url'] : '',
				'new_tab'  => ! empty( $t['new_tab'] ),
				'hidden'   => ! empty( $t['hidden'] ),
				'type'     => 'item',
				'title'    => isset( $t['title'] ) ? (string) $t['title'] : '',
			);
		}
		foreach ( ! empty( $prev['sub'] ) ? $prev['sub'] : array() as $parent => $subs ) {
			foreach ( (array) $subs as $s ) {
				if ( empty( $s['slug'] ) || isset( $seen_sub[ $parent . "\0" . $s['slug'] ] ) || isset( $seen_sub[ $s['slug'] ] ) || isset( $seen_top[ $s['slug'] ] ) ) {
					continue;
				}
				$items[] = array(
					'parent'   => (string) $parent,
					'slug'     => (string) $s['slug'],
					'position' => isset( $s['position'] ) ? (int) $s['position'] : 999,
					'icon'     => '',
					'url'      => isset( $s['url'] ) ? self::sanitize_menu_url( $s['url'] ) : '',
					'new_tab'  => false,
					'hidden'   => ! empty( $s['hidden'] ),
					'type'     => 'item',
					'title'    => isset( $s['title'] ) ? (string) $s['title'] : '',
				);
			}
		}
		return $items;
	}

	/**
	 * Strictly validate a custom menu link or URL override against attribute breakout,
	 * protocol-relative redirects and unsafe URI schemes.
	 *
	 * `wp-admin/menu-header.php` outputs custom menu slugs inside single-quoted
	 * `href='...'` without attribute escaping, and `esc_url_raw()` preserves `'`.
	 * Rejecting quotes, angle brackets, backticks, backslashes and control characters
	 * closes stored XSS both at write time and at render time.
	 *
	 * @param mixed $url Raw candidate URL or relative admin path.
	 * @return string Validated URL, or '' when unsafe.
	 */
	public static function sanitize_menu_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}
		if ( preg_match( '/[\x00-\x20\x7f\'"<>`\\\\]/', $url ) || 0 === strpos( $url, '//' ) ) {
			return '';
		}
		$colon    = strpos( $url, ':' );
		$boundary = strcspn( $url, '/?#' );
		if ( false !== $colon && $colon < $boundary ) {
			$scheme = strtolower( substr( $url, 0, $colon ) );
			if ( ! in_array( $scheme, array( 'http', 'https', 'mailto', 'tel' ), true ) ) {
				return '';
			}
			if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
				$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					return '';
				}
			}
			if ( function_exists( 'esc_url_raw' ) ) {
				$url = esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
			}
			if ( '' === $url || preg_match( '/[\x00-\x20\x7f\'"<>`\\\\]/', $url ) ) {
				return '';
			}
			return $url;
		}
		return preg_match( '/^(?:\/[a-zA-Z0-9_.\-\/~%]*|[a-zA-Z0-9_.\-]+)(?:[?#][^\x00-\x20\x7f\'"<>`\\\\]*)?$/', $url ) ? $url : '';
	}

	/**
	 * Trim + length-cap a menu slug without mangling its query string / extension.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	public static function sanitize_slug( $slug ) {
		$slug = wp_strip_all_tags( (string) $slug );
		return substr( trim( $slug ), 0, 190 );
	}

	/**
	 * Sanitise an icon value via `AdminKit_Icons`.
	 *
	 * @param mixed $icon Raw icon name, sentinel or custom SVG.
	 * @return string
	 */
	public static function sanitize_icon( $icon ) {
		return AdminKit_Icons::sanitize_icon( $icon );
	}

	/**
	 * Whether a stored icon value is usable.
	 *
	 * @param mixed $icon Candidate icon value.
	 * @return bool
	 */
	public static function is_icon( $icon ) {
		return '' !== AdminKit_Icons::sanitize_icon( $icon );
	}
}
