#!/usr/bin/env php
<?php
/**
 * AdminKit adapter scaffolder — scans a host plugin/theme's CSS and drafts the
 * token mapping, so a new integration starts from a generated base instead of a
 * blank file. You then do the fine-tuning, not the grunt-work discovery.
 *
 * It implements the front of the process documented in docs/INTEGRATIONS.md
 * ("Target the host's colors in the right order"):
 *
 *   1. TIER A — the host's own CSS variables. Every `--x: <color>` definition is
 *      a remap target; the script emits a paste-ready block redefining each to a
 *      `--ak-*` token. Remap these and the host follows, dark mode included.
 *   2. TIER B — hardcoded color literals (hex / rgb / hsl). Ranked by frequency,
 *      grouped by the property they sit on (background / border / text), each
 *      classified to a suggested `--ak-*` token by lightness, saturation + hue.
 *
 * The color→token suggestions are HEURISTIC — they get the base ~right; you
 * confirm and nudge. Run the adminkit-adapter-audit skill afterwards to check
 * the finished adapter's override debt.
 *
 * Usage (run from anywhere inside the AdminKit repo):
 *   php .claude/skills/adminkit-adapter-scan/adapter-scan.php <path|glob> [<path|glob> …] [options]
 *
 *   <path>          a plugin dir (scanned recursively for *.css), a single .css
 *                   file, or a shell glob. Point it at the host's FRONTEND css
 *                   too — that's often where the :root variable layer lives.
 *
 * Options:
 *   --slug=NAME     name used in the scaffold's comment + scope (default: guessed
 *                   from the first path). REQUIRED with --emit (names the folder).
 *   --scope=SEL     extra selector to scope rules under (e.g. .acf-admin-page).
 *   --top=N         cap the Tier B table at N colors (default 40).
 *   --no-rtl        skip *-rtl.css files (on by default; pass --rtl to include).
 *   --rtl           include *-rtl.css files.
 *   --emit          instead of printing, WRITE the integration to disk:
 *                   inc/integrations/{slug}/class-{slug}.php (a live-but-inert stub)
 *                   + css/admin.css (the scaffold). The loader auto-discovers it.
 *   --force         with --emit, overwrite an existing folder's two generated
 *                   files (never touches sibling hand-split CSS).
 *
 * Examples (SCAN below = php .claude/skills/adminkit-adapter-scan/adapter-scan.php):
 *   SCAN ../fluentform
 *   SCAN ../woocommerce/assets/client/admin --slug=woocommerce --top=60
 *   SCAN "../fluent-crm/assets/**\/*.css" --slug=fluent-crm
 *   SCAN ../slim-seo/css --slug=slim-seo --emit
 *
 * @package AdminKit
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'AK_SCAN_CMD', 'php .claude/skills/adminkit-adapter-scan/adapter-scan.php' );
define( 'AK_AUDIT_CMD', 'php .claude/skills/adminkit-adapter-audit/adapter-audit.php' );

/**
 * Locate the AdminKit plugin root (the folder holding adminkit.php +
 * inc/integrations) by walking up from the current working directory, with a
 * fallback to this skill's known position at <root>/.claude/skills/<name>/.
 */
function ak_find_root() {
	$d = getcwd();
	while ( is_string( $d ) && '' !== $d && '/' !== $d ) {
		if ( is_file( $d . '/adminkit.php' ) && is_dir( $d . '/inc/integrations' ) ) {
			return $d;
		}
		$d = dirname( $d );
	}
	$rel = dirname( __DIR__, 3 ); // <root>/.claude/skills/<name>/ -> <root>
	if ( is_file( $rel . '/adminkit.php' ) ) {
		return $rel;
	}
	return getcwd();
}

$argv = $_SERVER['argv'];
array_shift( $argv );

$paths = array();
$opts  = array( 'slug' => '', 'scope' => '', 'top' => 40, 'rtl' => false, 'emit' => false, 'force' => false, 'slug_set' => false );
foreach ( $argv as $arg ) {
	if ( '--no-rtl' === $arg ) { $opts['rtl'] = false; continue; }
	if ( '--rtl' === $arg )    { $opts['rtl'] = true;  continue; }
	if ( '--emit' === $arg )   { $opts['emit'] = true;  continue; }
	if ( '--force' === $arg )  { $opts['force'] = true; continue; }
	if ( preg_match( '/^--slug=(.+)$/', $arg, $m ) )  { $opts['slug'] = $m[1]; $opts['slug_set'] = true; continue; }
	if ( preg_match( '/^--scope=(.+)$/', $arg, $m ) ) { $opts['scope'] = $m[1]; continue; }
	if ( preg_match( '/^--top=(\d+)$/', $arg, $m ) )  { $opts['top']   = (int) $m[1]; continue; }
	$paths[] = $arg;
}

if ( ! $paths ) {
	fwrite( STDERR, 'usage: ' . AK_SCAN_CMD . " <path|glob> [more…] [--slug=] [--scope=] [--top=N] [--rtl]\n" );
	fwrite( STDERR, '       ' . AK_SCAN_CMD . " <path|glob> [more…] --slug=NAME --emit [--force]\n" );
	exit( 2 );
}

// ── Collect files ─────────────────────────────────────────────────────────────
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
if ( ! $opts['rtl'] ) {
	$files = array_filter( $files, static function ( $f ) {
		return ! preg_match( '/-rtl\.css$/i', $f );
	} );
}
$files = array_values( array_unique( $files ) );
if ( ! $files ) {
	fwrite( STDERR, "no .css files matched.\n" );
	exit( 2 );
}
if ( '' === $opts['slug'] ) {
	$opts['slug'] = preg_replace( '/[^a-z0-9-]/', '', strtolower( basename( rtrim( $paths[0], '/' ) ) ) ) ?: 'host';
}

// --emit names a folder + class, so it needs an explicit, kebab-case slug.
if ( $opts['emit'] ) {
	if ( ! $opts['slug_set'] ) {
		fwrite( STDERR, "--emit requires an explicit --slug=NAME (it names the integration folder + class).\n" );
		exit( 2 );
	}
	if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $opts['slug'] ) ) {
		fwrite( STDERR, "--slug must be kebab-case (a-z, 0-9, hyphens), got: {$opts['slug']}\n" );
		exit( 2 );
	}
}

// ── Scan ──────────────────────────────────────────────────────────────────────
$varDefs = array();  // name => ['value'=>raw, 'token'=>?, 'note'=>?]
$colors  = array();  // key  => ['rgba'=>..,'count'=>,'cats'=>[cat=>n],'sels'=>[],'forms'=>[raw=>1]]
$bytes   = 0;

foreach ( $files as $file ) {
	$css    = (string) file_get_contents( $file );
	$bytes += strlen( $css );
	$css    = preg_replace( '!/\*.*?\*/!s', '', $css );           // strip comments
	if ( null === $css ) { continue; }

	// Walk leaf rule blocks: group1 = selector-ish, group2 = declaration body.
	if ( ! preg_match_all( '/([^{}]*)\{([^{}]+)\}/s', $css, $blocks, PREG_SET_ORDER ) ) {
		continue;
	}
	foreach ( $blocks as $blk ) {
		$sel  = ak_clean_selector( $blk[1] );
		$body = $blk[2];
		foreach ( explode( ';', $body ) as $decl ) {
			$pos = strpos( $decl, ':' );
			if ( false === $pos ) { continue; }
			$prop = strtolower( trim( substr( $decl, 0, $pos ) ) );
			$val  = trim( substr( $decl, $pos + 1 ) );
			if ( '' === $prop || '' === $val ) { continue; }

			// TIER A — custom-property definition.
			if ( 0 === strpos( $prop, '--' ) ) {
				if ( isset( $varDefs[ $prop ] ) ) { continue; }
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

// Promote the most-used saturated color to the brand primary (heuristic: the
// dominant accent is almost always the busiest hued color in a plugin's CSS).
$brand = null; $brandN = -1;
foreach ( $colors as $key => $c ) {
	$hsl = ak_rgb_to_hsl( $c['rgba'][0], $c['rgba'][1], $c['rgba'][2] );
	if ( $hsl[1] * 100 >= 12 && $c['rgba'][3] >= 0.95 && $c['count'] > $brandN ) {
		$brand = $key; $brandN = $c['count'];
	}
}
if ( null !== $brand ) {
	$colors[ $brand ]['token'] = '--ak-primary';
	$colors[ $brand ]['note']  = 'brand/accent (busiest hued color)';
}

uasort( $colors, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

// ── Report ─────────────────────────────────────────────────────────────────────
$slug = $opts['slug'];
$nl   = "\n";
echo $nl . str_repeat( '=', 78 ) . $nl;
printf( "AdminKit adapter scan — %s%s", $slug, $nl );
printf( "%d file(s), %s scanned%s", count( $files ), ak_human_bytes( $bytes ), $nl );
echo str_repeat( '=', 78 ) . $nl;

// TIER A
echo $nl . "TIER A — host CSS variables (remap these; the host follows for free)" . $nl;
echo str_repeat( '-', 78 ) . $nl;
if ( ! $varDefs ) {
	echo "  (none found — host hardcodes its colors, so it's a Tier B adapter)" . $nl;
} else {
	ksort( $varDefs );
	$remap = array_filter( $varDefs, static function ( $d ) { return null !== $d['token']; } );
	$other = array_filter( $varDefs, static function ( $d ) { return null === $d['token']; } );
	printf( "  %-34s %-22s %s%s", 'host variable', 'value', '→ suggested', $nl );
	foreach ( $remap as $name => $d ) {
		printf( "  %-34s %-22s %s%s", ak_trunc( $name, 34 ), ak_trunc( $d['value'], 22 ), $d['token'], $nl );
	}
	if ( $other ) {
		printf( "  %s· %d alias/composite vars follow the roots above (not remap targets)%s", $nl, count( $other ), $nl );
	}
}

// TIER B
echo $nl . "TIER B — hardcoded colors (override these; ranked by use)" . $nl;
echo str_repeat( '-', 78 ) . $nl;
printf( "  %-11s %5s  %-16s %-18s %s%s", 'color', 'uses', 'props', '→ suggested', 'note', $nl );
$shown = 0;
foreach ( $colors as $key => $c ) {
	if ( $shown++ >= $opts['top'] ) { break; }
	printf(
		"  %-11s %5d  %-16s %-18s %s%s",
		ak_color_label( $c ),
		$c['count'],
		ak_cats_label( $c['cats'] ),
		$c['token'],
		$c['note'],
		$nl
	);
}
if ( count( $colors ) > $opts['top'] ) {
	printf( "  … and %d more (raise --top to see them)%s", count( $colors ) - $opts['top'], $nl );
}

// ── Build the scaffold (the css/admin.css body) ──────────────────────────────────
$scope     = trim( 'body.adminkit ' . $opts['scope'] );
$colorVars = array_filter( $varDefs, static function ( $d ) { return null !== $d['token']; } );
$isTierA   = (bool) $colorVars;

$scaffold = "/* {$slug} — token bridge (generated by the adminkit-adapter-scan skill; hand-tune). */" . $nl . $nl;

if ( $colorVars ) {
	$scaffold .= "/* ---- Tier A: remap the host's variables ---- */" . $nl;
	$scaffold .= $scope . " {" . $nl;
	foreach ( $colorVars as $name => $d ) {
		$scaffold .= sprintf( "\t%s: var(%s); /* was %s */%s", $name, $d['token'], ak_trunc( $d['value'], 28 ), $nl );
	}
	$scaffold .= "}" . $nl . $nl;
}

// Tier B starter: group the top colors by suggested token + property, one rule per
// group from the sample selectors. Clearly a DRAFT — selectors need pruning.
$groups = array(); // token => prop => [selectors]
$shown  = 0;
foreach ( $colors as $key => $c ) {
	if ( $shown++ >= $opts['top'] ) { break; }
	if ( ! $c['sels'] ) { continue; }
	$prop = ak_cat_to_prop( $c['dom'] );
	foreach ( $c['sels'] as $s ) {
		$groups[ $c['token'] ][ $prop ][ $s ] = true;
	}
}
if ( $groups ) {
	$scaffold .= "/* ---- Tier B: starter overrides (DRAFT — prune + merge selectors) ---- */" . $nl;
	foreach ( $groups as $tok => $byProp ) {
		foreach ( $byProp as $prop => $sels ) {
			$list = array_slice( array_keys( $sels ), 0, 8 );
			$list = array_map( static function ( $s ) use ( $scope ) { return $scope . ' ' . $s; }, $list );
			$scaffold .= implode( ',' . $nl, $list ) . " {" . $nl;
			$scaffold .= sprintf( "\t%s: var(%s);%s", $prop, $tok, $nl );
			$scaffold .= "}" . $nl . $nl;
		}
	}
}

// ── Emit to disk, or print ───────────────────────────────────────────────────────
if ( $opts['emit'] ) {
	ak_emit( $slug, $scaffold, $isTierA, $opts['force'], $nl );
	exit( 0 );
}

echo $nl . str_repeat( '=', 78 ) . $nl;
echo "SCAFFOLD — paste into inc/integrations/" . $slug . "/css/admin.css, then refine" . $nl;
echo str_repeat( '=', 78 ) . $nl . $nl;
echo $scaffold;
echo str_repeat( '-', 78 ) . $nl;
echo "Next: trim the Tier B selectors, verify in the browser, then run" . $nl;
echo '  ' . AK_AUDIT_CMD . "   (Tier A target = 0 !important)" . $nl . $nl;
exit( 0 );


/* ============================ helpers ============================ */

/**
 * Derive the integration class name with the loader's EXACT rule
 * (inc/class-plugin.php). A mismatch means the folder silently never loads.
 * e.g. 'fluent-crm' -> 'AdminKit_Integration_Fluent_Crm'.
 */
function ak_studly_class( $slug ) {
	return 'AdminKit_Integration_' . str_replace( '-', '_', ucwords( $slug, '-' ) );
}

/**
 * Write inc/integrations/{slug}/{class-{slug}.php, css/admin.css}. The class is
 * LIVE but INERT (is_active/owns_screen return false) until the human fills the
 * TODOs, so a fresh emit can never mis-skin a screen.
 */
function ak_emit( $slug, $scaffold_css, $isTierA, $force, $nl ) {
	$dir       = ak_find_root() . '/inc/integrations/' . $slug;
	$class     = ak_studly_class( $slug );
	$classFile = $dir . '/class-' . $slug . '.php';
	$cssFile   = $dir . '/css/admin.css';

	if ( is_dir( $dir ) && ! $force ) {
		fwrite( STDERR, $nl . "x inc/integrations/{$slug}/ already exists — pass --force to overwrite its two generated files, or remove the folder.{$nl}" );
		exit( 2 );
	}
	if ( ! is_dir( $dir . '/css' ) && ! mkdir( $dir . '/css', 0755, true ) && ! is_dir( $dir . '/css' ) ) {
		fwrite( STDERR, "x could not create {$dir}/css{$nl}" );
		exit( 1 );
	}
	if ( false === file_put_contents( $cssFile, $scaffold_css )
		|| false === file_put_contents( $classFile, ak_class_stub( $slug, $class, $isTierA ) ) ) {
		fwrite( STDERR, "x could not write files into {$dir}{$nl}" );
		exit( 1 );
	}

	$tier = $isTierA ? 'A (variable remap — no version gate)' : 'B (selector overrides — version-gated)';
	echo $nl . str_repeat( '=', 78 ) . $nl;
	echo "EMITTED inc/integrations/{$slug}/   Tier {$tier}" . $nl;
	echo str_repeat( '=', 78 ) . $nl;
	echo "  class-{$slug}.php  ->  {$class}" . $nl;
	echo "  css/admin.css      ->  the scaffold above" . $nl;
	echo $nl . "LIVE but INERT (is_active()/owns_screen() return false). Finish it:" . $nl;
	echo "  1. is_active()   — grep the host for its *_VERSION constant." . $nl;
	echo "  2. owns_screen() — inspect <body class> on the host's admin screen." . $nl;
	if ( ! $isTierA ) {
		echo "  3. host_version() + max_tested_host_version() — the host's current major." . $nl;
	}
	echo "  -> fine-tune css/admin.css (docs/ONBOARDING-A-PLUGIN.md), then: " . AK_AUDIT_CMD . $nl . $nl;
}

/**
 * The generated class-{slug}.php body. Built line-by-line (not heredoc) so the
 * emitted PHP is unambiguous. Tier A omits the version-gate methods + bail.
 */
function ak_class_stub( $slug, $class, $isTierA ) {
	$L   = array();
	$L[] = '<?php';
	$L[] = '/**';
	$L[] = ' * ' . $class . ' — GENERATED by the adminkit-adapter-scan skill (--emit); hand-tune.';
	$L[] = ' *';
	if ( $isTierA ) {
		$L[] = ' * Tier A: the host exposes CSS variables — css/admin.css remaps them to';
		$L[] = ' * AdminKit tokens. Confirm the two TODOs below and the skin is live.';
	} else {
		$L[] = ' * Tier B: the host hardcodes its colors — css/admin.css overrides them by';
		$L[] = ' * selector. Confirm the TODOs below, including the version gate.';
	}
	$L[] = ' *';
	$L[] = ' * @package AdminKit';
	$L[] = ' */';
	$L[] = '';
	$L[] = "defined( 'ABSPATH' ) || exit;";
	$L[] = '';
	$L[] = 'class ' . $class . ' extends AdminKit_Integration_Base {';
	$L[] = '';
	$L[] = "\tpublic static function slug() {";
	$L[] = "\t\treturn '" . $slug . "';";
	$L[] = "\t}";
	$L[] = '';
	$L[] = "\t/**";
	$L[] = "\t * TODO confirm host detection. Find the host's version constant with:";
	$L[] = "\t *   grep -rn \"define.*_VERSION\" ../" . $slug . " --include=*.php";
	$L[] = "\t */";
	$L[] = "\tpublic static function is_active() {";
	$L[] = "\t\treturn false; // e.g. defined( 'HOST_VERSION' );";
	$L[] = "\t}";
	$L[] = '';
	$L[] = "\t/**";
	$L[] = "\t * TODO confirm the screen scope. Open the host's admin page and inspect";
	$L[] = "\t * <body class> for the host wrapper class or the screen-id class. SPAs keep";
	$L[] = "\t * one slug across sub-pages, so a strpos() substring usually suffices.";
	$L[] = "\t */";
	$L[] = "\tpublic static function owns_screen( \$screen ) {";
	$L[] = "\t\treturn false; // e.g. \$screen && false !== strpos( \$screen->id, '" . $slug . "' );";
	$L[] = "\t}";
	if ( ! $isTierA ) {
		$L[] = '';
		$L[] = "\t/**";
		$L[] = "\t * Tier B version gate. Set host_version() from the constant above and";
		$L[] = "\t * max_tested_host_version() to the host's current MAJOR. (Delete all three";
		$L[] = "\t * methods + the bail in register_assets() for a pure Tier A remap.)";
		$L[] = "\t */";
		$L[] = "\tprotected static function host_version() {";
		$L[] = "\t\treturn null; // TODO: defined( 'HOST_VERSION' ) ? HOST_VERSION : null;";
		$L[] = "\t}";
		$L[] = '';
		$L[] = "\tprotected static function max_tested_host_version() {";
		$L[] = "\t\treturn null; // TODO: the host's current major, e.g. '6'.";
		$L[] = "\t}";
	}
	$L[] = '';
	$L[] = "\tpublic static function register_assets() {";
	if ( ! $isTierA ) {
		$L[] = "\t\tif ( ! static::host_within_tested_range() ) {";
		$L[] = "\t\t\treturn; // past the tested major — fall back to the host's native UI.";
		$L[] = "\t\t}";
	}
	$L[] = "\t\tAdminKit_Assets::register( array(";
	$L[] = "\t\t\t'handle'    => 'adminkit-" . $slug . "-admin',";
	$L[] = "\t\t\t'src'       => 'inc/integrations/" . $slug . "/css/admin.css',";
	$L[] = "\t\t\t'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),";
	$L[] = "\t\t\t'context'   => 'admin',";
	$L[] = "\t\t\t'condition' => array( __CLASS__, 'owns_screen' ),";
	$L[] = "\t\t) );";
	$L[] = "\t}";
	$L[] = '}';
	$L[] = '';
	return implode( "\n", $L );
}

function ak_clean_selector( $raw ) {
	$s = trim( (string) $raw );
	// Drop anything before the last block close that leaked in, and @-rules.
	if ( false !== ( $p = strrpos( $s, '}' ) ) ) { $s = substr( $s, $p + 1 ); }
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
		case 'text':   return 'color';
		case 'border': return 'border-color';
		case 'shadow': return 'box-shadow';
		default:       return 'background';
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
			if ( null !== $rgba && $rgba[3] > 0.02 ) { $out[] = array( strtolower( $raw ), $rgba ); }
		}
	}
	return $out;
}

function ak_parse_color( $raw ) {
	$raw = trim( $raw );
	if ( preg_match( '/^#([0-9a-fA-F]{3,8})$/', $raw, $m ) ) {
		$h = $m[1]; $len = strlen( $h );
		if ( 3 === $len || 4 === $len ) {
			$r = hexdec( str_repeat( $h[0], 2 ) ); $g = hexdec( str_repeat( $h[1], 2 ) ); $b = hexdec( str_repeat( $h[2], 2 ) );
			$a = 4 === $len ? hexdec( str_repeat( $h[3], 2 ) ) / 255 : 1.0;
			return array( $r, $g, $b, $a );
		}
		if ( 6 === $len || 8 === $len ) {
			$r = hexdec( substr( $h, 0, 2 ) ); $g = hexdec( substr( $h, 2, 2 ) ); $b = hexdec( substr( $h, 4, 2 ) );
			$a = 8 === $len ? hexdec( substr( $h, 6, 2 ) ) / 255 : 1.0;
			return array( $r, $g, $b, $a );
		}
		return null;
	}
	if ( preg_match( '/^rgba?\(([^)]+)\)$/i', $raw, $m ) ) {
		$parts = array_values( array_filter( preg_split( '/[,\/\s]+/', trim( $m[1] ) ), 'strlen' ) );
		if ( count( $parts ) < 3 ) { return null; }
		return array( ak_chan( $parts[0] ), ak_chan( $parts[1] ), ak_chan( $parts[2] ), isset( $parts[3] ) ? ak_alpha( $parts[3] ) : 1.0 );
	}
	if ( preg_match( '/^hsla?\(([^)]+)\)$/i', $raw, $m ) ) {
		$parts = array_values( array_filter( preg_split( '/[,\/\s]+/', trim( $m[1] ) ), 'strlen' ) );
		if ( count( $parts ) < 3 ) { return null; }
		$h = floatval( $parts[0] );
		$s = floatval( rtrim( $parts[1], '%' ) ) / 100;
		$l = floatval( rtrim( $parts[2], '%' ) ) / 100;
		list( $r, $g, $b ) = ak_hsl_to_rgb( $h, $s, $l );
		return array( $r, $g, $b, isset( $parts[3] ) ? ak_alpha( $parts[3] ) : 1.0 );
	}
	$lc = strtolower( $raw );
	if ( 'white' === $lc ) { return array( 255, 255, 255, 1.0 ); }
	if ( 'black' === $lc ) { return array( 0, 0, 0, 1.0 ); }
	return null;
}

function ak_chan( $p ) {
	$p = trim( $p );
	if ( '' !== $p && '%' === substr( $p, -1 ) ) { return (int) round( floatval( $p ) / 100 * 255 ); }
	return max( 0, min( 255, (int) round( floatval( $p ) ) ) );
}

function ak_alpha( $p ) {
	$p = trim( $p );
	if ( '' !== $p && '%' === substr( $p, -1 ) ) { return floatval( $p ) / 100; }
	return floatval( $p );
}

function ak_hsl_to_rgb( $h, $s, $l ) {
	$h = fmod( $h, 360 ) / 360;
	if ( $s <= 0 ) { $v = (int) round( $l * 255 ); return array( $v, $v, $v ); }
	$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
	$p = 2 * $l - $q;
	return array(
		(int) round( ak_hue( $p, $q, $h + 1 / 3 ) * 255 ),
		(int) round( ak_hue( $p, $q, $h ) * 255 ),
		(int) round( ak_hue( $p, $q, $h - 1 / 3 ) * 255 ),
	);
}

function ak_hue( $p, $q, $t ) {
	if ( $t < 0 ) { $t += 1; }
	if ( $t > 1 ) { $t -= 1; }
	if ( $t < 1 / 6 ) { return $p + ( $q - $p ) * 6 * $t; }
	if ( $t < 1 / 2 ) { return $q; }
	if ( $t < 2 / 3 ) { return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6; }
	return $p;
}

function ak_rgb_to_hsl( $r, $g, $b ) {
	$r /= 255; $g /= 255; $b /= 255;
	$max = max( $r, $g, $b ); $min = min( $r, $g, $b );
	$l = ( $max + $min ) / 2;
	if ( $max === $min ) { return array( 0.0, 0.0, $l ); }
	$d = $max - $min;
	$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
	if ( $max === $r ) { $h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 ); }
	elseif ( $max === $g ) { $h = ( $b - $r ) / $d + 2; }
	else { $h = ( $r - $g ) / $d + 4; }
	return array( $h * 60, $s, $l );
}

function ak_classify( $rgba, $domCat ) {
	list( $r, $g, $b, $a ) = $rgba;
	list( $h, $s, $l ) = ak_rgb_to_hsl( $r, $g, $b );
	$L = $l * 100;
	// Absolute chroma (0–255) is a far more reliable "is this grey?" signal than
	// HSL saturation, which balloons for near-white/near-black neutrals.
	$chroma = max( $r, $g, $b ) - min( $r, $g, $b );

	if ( $a < 0.95 ) {
		if ( $chroma > 24 ) { return array( '--ak-primary-subtle', sprintf( 'translucent tint (a=%.2f)', $a ) ); }
		return array( '--ak-hover-bg', sprintf( 'translucent overlay (a=%.2f)', $a ) );
	}
	// Neutral = a true grey (chroma ≤ 12) OR a low-chroma "tinted grey" that
	// isn't a light tint. Modern UI palettes (Untitled-UI etc.) build their
	// greys with a faint blue cast (#99A0AE, #525866, #222530) — chroma in the
	// 12–24 band; treat those as neutrals, not the brand. A light low-chroma
	// value (L ≥ 90) is a genuine tint and falls through to the subtle branch.
	if ( $chroma <= 12 || ( $chroma <= 24 && $L < 90 ) ) {
		// White used AS TEXT is almost always white-on-a-colored-fill.
		if ( $L >= 92 && 'text' === $domCat ) { return array( '--ak-on-accent', 'white text on a fill' ); }
		if ( 'text' === $domCat ) {
			if ( $L < 28 ) { return array( '--ak-heading', 'near-black text' ); }
			if ( $L < 46 ) { return array( '--ak-text', 'body text' ); }
			return array( '--ak-text-muted', 'muted text' );
		}
		if ( 'border' === $domCat ) {
			return $L >= 80 ? array( '--ak-border', 'grey border' ) : array( '--ak-border-strong', 'grey border (strong)' );
		}
		// Backgrounds — by ROLE: the lightest opaque fill is a card (surface),
		// not the page. The 3-surface split is the main thing to hand-check.
		if ( $L >= 95 ) { return array( '--ak-surface', 'white surface (→ --ak-bg if it is the page)' ); }
		if ( $L >= 88 ) { return array( '--ak-elevated', 'light surface (→ --ak-bg / --ak-surface by role)' ); }
		if ( $L >= 78 ) { return array( '--ak-border', 'light divider' ); }
		if ( $L >= 60 ) { return array( '--ak-border-strong', 'strong border' ); }
		if ( $L >= 46 ) { return array( '--ak-text-muted', 'muted' ); }
		if ( $L >= 28 ) { return array( '--ak-text', 'dark fill / text' ); }
		return array( '--ak-heading', 'near-black' );
	}
	// Hued. A light hued value is a tint (alert / pill background), not the accent.
	if ( $L >= 90 ) {
		if ( $h >= 345 || $h < 16 ) { return array( '--ak-error-subtle', 'light red tint' ); }
		return array( '--ak-primary-subtle', 'light tint (verify hue)' );
	}
	if ( $h >= 95 && $h <= 168 )  { return array( '--ak-success', 'green' ); }
	if ( $h >= 16 && $h < 50 )    { return array( '--ak-warning', 'amber / orange' ); }
	if ( $h >= 345 || $h < 16 )   { return array( '--ak-error', 'red' ); }
	if ( $h >= 168 && $h < 200 )  { return array( '--ak-info', 'cyan / teal' ); }
	return array( '--ak-primary', 'brand / accent' );
}

function ak_color_key( $rgba ) {
	return sprintf( '%02x%02x%02x@%0.2f', $rgba[0], $rgba[1], $rgba[2], $rgba[3] );
}

function ak_color_label( $c ) {
	$hex = sprintf( '#%02x%02x%02x', $c['rgba'][0], $c['rgba'][1], $c['rgba'][2] );
	if ( $c['rgba'][3] < 0.95 ) { $hex .= sprintf( ' a%.0f', $c['rgba'][3] * 100 ); }
	return $hex;
}

function ak_cats_label( $cats ) {
	$out = array();
	foreach ( $cats as $k => $n ) { $out[] = $k . '×' . $n; }
	return implode( ' ', $out );
}

function ak_trunc( $s, $n ) {
	$s = (string) $s;
	return strlen( $s ) > $n ? substr( $s, 0, $n - 1 ) . '…' : $s;
}

function ak_human_bytes( $b ) {
	if ( $b >= 1048576 ) { return round( $b / 1048576, 1 ) . ' MB'; }
	if ( $b >= 1024 ) { return round( $b / 1024 ) . ' KB'; }
	return $b . ' B';
}
