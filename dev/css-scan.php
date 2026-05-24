<?php
/**
 * AdminKit CSS scanning library — shared by the dev tooling.
 *
 * Pure functions, no side effects, no CLI: collect CSS files, walk their rule
 * blocks, and classify every color (and every host CSS variable) to a suggested
 * `--ak-*` token by lightness / chroma / hue. Both `adapter-scan.php` (scaffold a
 * new integration) and `adapter-drift.php` (detect when a host's CSS surface
 * changed) require this file so the parsing + classification stay identical.
 *
 * Not loaded by WordPress at runtime — this is a build-time helper.
 *
 * @package AdminKit
 */

if ( defined( 'AK_CSS_SCAN_LIB' ) ) {
	return;
}
define( 'AK_CSS_SCAN_LIB', '1.0.0' );

/**
 * Locate the AdminKit plugin root (the folder holding adminkit.php +
 * inc/integrations) by walking up from the current working directory, with a
 * fallback to this file's known position at <root>/dev/.
 *
 * @return string
 */
function ak_find_root() {
	$d = getcwd();
	while ( is_string( $d ) && '' !== $d && '/' !== $d ) {
		if ( is_file( $d . '/adminkit.php' ) && is_dir( $d . '/inc/integrations' ) ) {
			return $d;
		}
		$d = dirname( $d );
	}
	$rel = dirname( __DIR__ ); // <root>/dev/ -> <root>
	if ( is_file( $rel . '/adminkit.php' ) ) {
		return $rel;
	}
	return getcwd();
}

/**
 * Expand a list of paths / globs into a unique list of .css files.
 *
 * @param string[] $paths Plugin dirs (scanned recursively), single .css files, or globs.
 * @param bool     $rtl   Include *-rtl.css files (skipped by default).
 * @return string[]
 */
function ak_collect_css_files( array $paths, $rtl = false ) {
	$files = array();
	foreach ( $paths as $p ) {
		if ( is_dir( $p ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $p, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( preg_match( '/\.css$/i', $f->getFilename() ) ) {
					$files[] = $f->getPathname();
				}
			}
		} elseif ( is_file( $p ) ) {
			$files[] = $p;
		} else {
			foreach ( glob( $p, GLOB_BRACE ) as $g ) {
				$files[] = $g;
			}
		}
	}
	if ( ! $rtl ) {
		$files = array_filter( $files, static function ( $f ) {
			return ! preg_match( '/-rtl\.css$/i', $f );
		} );
	}
	return array_values( array_unique( $files ) );
}

/**
 * Scan a set of CSS files into a structured color/variable inventory.
 *
 * Returns:
 *   [
 *     'varDefs' => [ '--name' => [ 'value'=>raw, 'token'=>?--ak-*, 'note'=>str ] ],  // Tier A
 *     'colors'  => [ key => [ 'rgba','count','cats','sels','forms','token','dom','note' ] ], // Tier B
 *     'bytes'   => int,
 *   ]
 *
 * Each color's suggested token is resolved from its dominant property category.
 * Callers that want the brand-primary heuristic apply ak_promote_brand() after.
 *
 * @param string[] $files
 * @return array{varDefs:array,colors:array,bytes:int}
 */
function ak_scan_css( array $files ) {
	$varDefs = array();
	$colors  = array();
	$bytes   = 0;

	foreach ( $files as $file ) {
		$css    = (string) file_get_contents( $file );
		$bytes += strlen( $css );
		$css    = preg_replace( '!/\*.*?\*/!s', '', $css ); // strip comments
		if ( null === $css ) {
			continue;
		}

		// Walk leaf rule blocks: group1 = selector-ish, group2 = declaration body.
		if ( ! preg_match_all( '/([^{}]*)\{([^{}]+)\}/s', $css, $blocks, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $blocks as $blk ) {
			$sel  = ak_clean_selector( $blk[1] );
			$body = $blk[2];
			foreach ( explode( ';', $body ) as $decl ) {
				$pos = strpos( $decl, ':' );
				if ( false === $pos ) {
					continue;
				}
				$prop = strtolower( trim( substr( $decl, 0, $pos ) ) );
				$val  = trim( substr( $decl, $pos + 1 ) );
				if ( '' === $prop || '' === $val ) {
					continue;
				}

				// TIER A — custom-property definition.
				if ( 0 === strpos( $prop, '--' ) ) {
					if ( isset( $varDefs[ $prop ] ) ) {
						continue;
					}
					$whole = ak_parse_color( $val ); // the WHOLE value is a single color?
					if ( null !== $whole ) {
						list( $tok, $note ) = ak_classify( $whole, 'bg' );
						$varDefs[ $prop ] = array( 'value' => $val, 'token' => $tok, 'note' => $note );
					} elseif ( preg_match( '/^var\(\s*(--[\w-]+)/', $val, $vm ) ) {
						$varDefs[ $prop ] = array( 'value' => $val, 'token' => null, 'note' => 'alias → ' . $vm[1] );
					} elseif ( null !== ak_first_color( $val ) ) {
						// Composite value (box-shadow / gradient / border shorthand) that
						// merely contains a color — not a clean remap target.
						$varDefs[ $prop ] = array( 'value' => $val, 'token' => null, 'note' => 'composite — skip' );
					}
					continue;
				}

				// TIER B — hardcoded literals on a real property.
				$cat = ak_prop_category( $prop );
				foreach ( ak_all_colors( $val ) as $found ) {
					list( $raw, $rgba ) = $found;
					$key = ak_color_key( $rgba );
					if ( ! isset( $colors[ $key ] ) ) {
						$colors[ $key ] = array( 'rgba' => $rgba, 'count' => 0, 'cats' => array(), 'sels' => array(), 'forms' => array() );
					}
					$colors[ $key ]['count']++;
					$colors[ $key ]['cats'][ $cat ] = ( $colors[ $key ]['cats'][ $cat ] ?? 0 ) + 1;
					$colors[ $key ]['forms'][ $raw ] = true;
					if ( $sel && count( $colors[ $key ]['sels'] ) < 4 && ! in_array( $sel, $colors[ $key ]['sels'], true ) ) {
						$colors[ $key ]['sels'][] = $sel;
					}
				}
			}
		}
	}

	// Resolve a suggested token per color, using its dominant property category.
	foreach ( $colors as $key => &$c ) {
		arsort( $c['cats'] );
		$dom = array_key_first( $c['cats'] ) ?: 'bg';
		list( $c['token'], $c['note'] ) = ak_classify( $c['rgba'], $dom );
		$c['dom'] = $dom;
	}
	unset( $c );

	return array(
		'varDefs' => $varDefs,
		'colors'  => $colors,
		'bytes'   => $bytes,
	);
}

/**
 * Promote the most-used saturated color to the brand primary (heuristic: the
 * dominant accent is almost always the busiest hued color in a plugin's CSS).
 *
 * @param array $colors Passed by reference; the winner's token is set to --ak-primary.
 * @return string|null The winning color key, or null when no hued color qualifies.
 */
function ak_promote_brand( array &$colors ) {
	$brand  = null;
	$brandN = -1;
	foreach ( $colors as $key => $c ) {
		$hsl = ak_rgb_to_hsl( $c['rgba'][0], $c['rgba'][1], $c['rgba'][2] );
		if ( $hsl[1] * 100 >= 12 && $c['rgba'][3] >= 0.95 && $c['count'] > $brandN ) {
			$brand  = $key;
			$brandN = $c['count'];
		}
	}
	if ( null !== $brand ) {
		$colors[ $brand ]['token'] = '--ak-primary';
		$colors[ $brand ]['note']  = 'brand/accent (busiest hued color)';
	}
	return $brand;
}

/* ============================ color helpers ============================ */

function ak_clean_selector( $raw ) {
	$s = trim( (string) $raw );
	// Drop anything before the last block close that leaked in, and @-rules.
	if ( false !== ( $p = strrpos( $s, '}' ) ) ) {
		$s = substr( $s, $p + 1 );
	}
	$s = trim( preg_replace( '/\s+/', ' ', $s ) );
	if ( '' === $s || '@' === ( $s[0] ?? '' ) || 0 === strpos( $s, 'from' ) || 0 === strpos( $s, 'to' ) || preg_match( '/^\d/', $s ) ) {
		return '';
	}
	return $s;
}

function ak_prop_category( $prop ) {
	if ( 'color' === $prop || 'fill' === $prop || 'stroke' === $prop || 'caret-color' === $prop || 'text-decoration-color' === $prop ) {
		return 'text';
	}
	if ( false !== strpos( $prop, 'border' ) || false !== strpos( $prop, 'outline' ) || 'column-rule-color' === $prop ) {
		return 'border';
	}
	if ( false !== strpos( $prop, 'shadow' ) ) {
		return 'shadow';
	}
	return 'bg'; // background, background-color, and the catch-all
}

function ak_cat_to_prop( $cat ) {
	switch ( $cat ) {
		case 'text':
			return 'color';
		case 'border':
			return 'border-color';
		case 'shadow':
			return 'box-shadow';
		default:
			return 'background';
	}
}

function ak_first_color( $val ) {
	$all = ak_all_colors( $val );
	return $all ? $all[0][1] : null;
}

function ak_all_colors( $val ) {
	$out = array();
	if ( preg_match_all( '/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)|\b(?:white|black)\b/i', $val, $m ) ) {
		foreach ( $m[0] as $raw ) {
			$rgba = ak_parse_color( $raw );
			// Skip fully-transparent literals (rgba(…,0)) — gradient/transition
			// placeholders, not real colors.
			if ( null !== $rgba && $rgba[3] > 0.02 ) {
				$out[] = array( strtolower( $raw ), $rgba );
			}
		}
	}
	return $out;
}

function ak_parse_color( $raw ) {
	$raw = trim( $raw );
	if ( preg_match( '/^#([0-9a-fA-F]{3,8})$/', $raw, $m ) ) {
		$h   = $m[1];
		$len = strlen( $h );
		if ( 3 === $len || 4 === $len ) {
			$r = hexdec( str_repeat( $h[0], 2 ) );
			$g = hexdec( str_repeat( $h[1], 2 ) );
			$b = hexdec( str_repeat( $h[2], 2 ) );
			$a = 4 === $len ? hexdec( str_repeat( $h[3], 2 ) ) / 255 : 1.0;
			return array( $r, $g, $b, $a );
		}
		if ( 6 === $len || 8 === $len ) {
			$r = hexdec( substr( $h, 0, 2 ) );
			$g = hexdec( substr( $h, 2, 2 ) );
			$b = hexdec( substr( $h, 4, 2 ) );
			$a = 8 === $len ? hexdec( substr( $h, 6, 2 ) ) / 255 : 1.0;
			return array( $r, $g, $b, $a );
		}
		return null;
	}
	if ( preg_match( '/^rgba?\(([^)]+)\)$/i', $raw, $m ) ) {
		$parts = array_values( array_filter( preg_split( '/[,\/\s]+/', trim( $m[1] ) ), 'strlen' ) );
		if ( count( $parts ) < 3 ) {
			return null;
		}
		return array( ak_chan( $parts[0] ), ak_chan( $parts[1] ), ak_chan( $parts[2] ), isset( $parts[3] ) ? ak_alpha( $parts[3] ) : 1.0 );
	}
	if ( preg_match( '/^hsla?\(([^)]+)\)$/i', $raw, $m ) ) {
		$parts = array_values( array_filter( preg_split( '/[,\/\s]+/', trim( $m[1] ) ), 'strlen' ) );
		if ( count( $parts ) < 3 ) {
			return null;
		}
		$h = floatval( $parts[0] );
		$s = floatval( rtrim( $parts[1], '%' ) ) / 100;
		$l = floatval( rtrim( $parts[2], '%' ) ) / 100;
		list( $r, $g, $b ) = ak_hsl_to_rgb( $h, $s, $l );
		return array( $r, $g, $b, isset( $parts[3] ) ? ak_alpha( $parts[3] ) : 1.0 );
	}
	$lc = strtolower( $raw );
	if ( 'white' === $lc ) {
		return array( 255, 255, 255, 1.0 );
	}
	if ( 'black' === $lc ) {
		return array( 0, 0, 0, 1.0 );
	}
	return null;
}

function ak_chan( $p ) {
	$p = trim( $p );
	if ( '' !== $p && '%' === substr( $p, -1 ) ) {
		return (int) round( floatval( $p ) / 100 * 255 );
	}
	return max( 0, min( 255, (int) round( floatval( $p ) ) ) );
}

function ak_alpha( $p ) {
	$p = trim( $p );
	if ( '' !== $p && '%' === substr( $p, -1 ) ) {
		return floatval( $p ) / 100;
	}
	return floatval( $p );
}

function ak_hsl_to_rgb( $h, $s, $l ) {
	$h = fmod( $h, 360 ) / 360;
	if ( $s <= 0 ) {
		$v = (int) round( $l * 255 );
		return array( $v, $v, $v );
	}
	$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
	$p = 2 * $l - $q;
	return array(
		(int) round( ak_hue( $p, $q, $h + 1 / 3 ) * 255 ),
		(int) round( ak_hue( $p, $q, $h ) * 255 ),
		(int) round( ak_hue( $p, $q, $h - 1 / 3 ) * 255 ),
	);
}

function ak_hue( $p, $q, $t ) {
	if ( $t < 0 ) {
		$t += 1;
	}
	if ( $t > 1 ) {
		$t -= 1;
	}
	if ( $t < 1 / 6 ) {
		return $p + ( $q - $p ) * 6 * $t;
	}
	if ( $t < 1 / 2 ) {
		return $q;
	}
	if ( $t < 2 / 3 ) {
		return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
	}
	return $p;
}

function ak_rgb_to_hsl( $r, $g, $b ) {
	$r  /= 255;
	$g  /= 255;
	$b  /= 255;
	$max = max( $r, $g, $b );
	$min = min( $r, $g, $b );
	$l   = ( $max + $min ) / 2;
	if ( $max === $min ) {
		return array( 0.0, 0.0, $l );
	}
	$d = $max - $min;
	$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
	if ( $max === $r ) {
		$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
	} elseif ( $max === $g ) {
		$h = ( $b - $r ) / $d + 2;
	} else {
		$h = ( $r - $g ) / $d + 4;
	}
	return array( $h * 60, $s, $l );
}

/**
 * Classify an rgba color (in a dominant property category) to a `--ak-*` token.
 * Heuristic by lightness / absolute chroma / hue. Returns [ token, note ].
 *
 * @param array  $rgba   [ r, g, b, a ]
 * @param string $domCat bg | border | text | shadow
 * @return array{0:string,1:string}
 */
function ak_classify( $rgba, $domCat ) {
	list( $r, $g, $b, $a ) = $rgba;
	list( $h, $s, $l )     = ak_rgb_to_hsl( $r, $g, $b );
	$L = $l * 100;
	// Absolute chroma (0–255) is a far more reliable "is this grey?" signal than
	// HSL saturation, which balloons for near-white/near-black neutrals.
	$chroma = max( $r, $g, $b ) - min( $r, $g, $b );

	if ( $a < 0.95 ) {
		if ( $chroma > 24 ) {
			return array( '--ak-primary-subtle', sprintf( 'translucent tint (a=%.2f)', $a ) );
		}
		return array( '--ak-hover-bg', sprintf( 'translucent overlay (a=%.2f)', $a ) );
	}
	// Neutral = a true grey (chroma ≤ 12) OR a low-chroma "tinted grey" that
	// isn't a light tint. Modern UI palettes (Untitled-UI etc.) build their
	// greys with a faint blue cast (#99A0AE, #525866, #222530) — chroma in the
	// 12–24 band; treat those as neutrals, not the brand. A light low-chroma
	// value (L ≥ 90) is a genuine tint and falls through to the subtle branch.
	if ( $chroma <= 12 || ( $chroma <= 24 && $L < 90 ) ) {
		// White used AS TEXT is almost always white-on-a-colored-fill.
		if ( $L >= 92 && 'text' === $domCat ) {
			return array( '--ak-on-accent', 'white text on a fill' );
		}
		if ( 'text' === $domCat ) {
			if ( $L < 28 ) {
				return array( '--ak-heading', 'near-black text' );
			}
			if ( $L < 46 ) {
				return array( '--ak-text', 'body text' );
			}
			return array( '--ak-text-muted', 'muted text' );
		}
		if ( 'border' === $domCat ) {
			return $L >= 80 ? array( '--ak-border', 'grey border' ) : array( '--ak-border-strong', 'grey border (strong)' );
		}
		// Backgrounds — by ROLE: the lightest opaque fill is a card (surface),
		// not the page. The 3-surface split is the main thing to hand-check.
		if ( $L >= 95 ) {
			return array( '--ak-surface', 'white surface (→ --ak-bg if it is the page)' );
		}
		if ( $L >= 88 ) {
			return array( '--ak-elevated', 'light surface (→ --ak-bg / --ak-surface by role)' );
		}
		if ( $L >= 78 ) {
			return array( '--ak-border', 'light divider' );
		}
		if ( $L >= 60 ) {
			return array( '--ak-border-strong', 'strong border' );
		}
		if ( $L >= 46 ) {
			return array( '--ak-text-muted', 'muted' );
		}
		if ( $L >= 28 ) {
			return array( '--ak-text', 'dark fill / text' );
		}
		return array( '--ak-heading', 'near-black' );
	}
	// Hued. A light hued value is a tint (alert / pill background), not the accent.
	if ( $L >= 90 ) {
		if ( $h >= 345 || $h < 16 ) {
			return array( '--ak-error-subtle', 'light red tint' );
		}
		return array( '--ak-primary-subtle', 'light tint (verify hue)' );
	}
	if ( $h >= 95 && $h <= 168 ) {
		return array( '--ak-success', 'green' );
	}
	if ( $h >= 16 && $h < 50 ) {
		return array( '--ak-warning', 'amber / orange' );
	}
	if ( $h >= 345 || $h < 16 ) {
		return array( '--ak-error', 'red' );
	}
	if ( $h >= 168 && $h < 200 ) {
		return array( '--ak-info', 'cyan / teal' );
	}
	return array( '--ak-primary', 'brand / accent' );
}

function ak_color_key( $rgba ) {
	return sprintf( '%02x%02x%02x@%0.2f', $rgba[0], $rgba[1], $rgba[2], $rgba[3] );
}

function ak_color_label( $c ) {
	$hex = sprintf( '#%02x%02x%02x', $c['rgba'][0], $c['rgba'][1], $c['rgba'][2] );
	if ( $c['rgba'][3] < 0.95 ) {
		$hex .= sprintf( ' a%.0f', $c['rgba'][3] * 100 );
	}
	return $hex;
}

function ak_cats_label( $cats ) {
	$out = array();
	foreach ( $cats as $k => $n ) {
		$out[] = $k . '×' . $n;
	}
	return implode( ' ', $out );
}

function ak_trunc( $s, $n ) {
	$s = (string) $s;
	return strlen( $s ) > $n ? substr( $s, 0, $n - 1 ) . '…' : $s;
}

function ak_human_bytes( $b ) {
	if ( $b >= 1048576 ) {
		return round( $b / 1048576, 1 ) . ' MB';
	}
	if ( $b >= 1024 ) {
		return round( $b / 1024 ) . ' KB';
	}
	return $b . ' B';
}
