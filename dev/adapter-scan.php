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
 * confirm and nudge. Run adapter-audit.php afterwards to check the finished
 * adapter's override debt, and adapter-drift.php to snapshot the host so future
 * host updates are detectable.
 *
 * Usage (run from anywhere inside the AdminKit repo):
 *   php dev/adapter-scan.php <path|glob> [<path|glob> …] [options]
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
 *                   inc/integrations/plugins/{slug}/class-{slug}.php (a live-but-inert stub)
 *                   + css/admin.css (the scaffold). The loader auto-discovers it.
 *   --force         with --emit, overwrite an existing folder's two generated
 *                   files (never touches sibling hand-split CSS).
 *
 * Examples (SCAN below = php dev/adapter-scan.php):
 *   SCAN ../advanced-custom-fields --slug=acf
 *   SCAN ../woocommerce/assets/client/admin --slug=woocommerce --top=60
 *   SCAN "../fluent-crm/assets/**\/*.css" --slug=fluent-crm
 *   SCAN ../slim-seo/css --slug=slim-seo --emit
 *
 * @package AdminKit
 */

error_reporting( E_ALL & ~E_DEPRECATED );

require_once __DIR__ . '/css-scan.php';

define( 'AK_SCAN_CMD', 'php dev/adapter-scan.php' );
define( 'AK_AUDIT_CMD', 'php dev/adapter-audit.php' );

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
$files = ak_collect_css_files( $paths, $opts['rtl'] );
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
$scan    = ak_scan_css( $files );
$varDefs = $scan['varDefs'];
$colors  = $scan['colors'];
$bytes   = $scan['bytes'];

ak_promote_brand( $colors );
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

$scaffold = "/* {$slug} — token bridge (generated by adapter-scan.php; hand-tune). */" . $nl . $nl;

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
echo "SCAFFOLD — paste into inc/integrations/plugins/" . $slug . "/css/admin.css, then refine" . $nl;
echo str_repeat( '=', 78 ) . $nl . $nl;
echo $scaffold;
echo str_repeat( '-', 78 ) . $nl;
echo "Next: trim the Tier B selectors, verify in the browser, then run" . $nl;
echo '  ' . AK_AUDIT_CMD . "   (Tier A target = 0 !important)" . $nl . $nl;
exit( 0 );


/* ============================ emit helpers ============================ */

/**
 * Derive the integration class name with the loader's EXACT rule
 * (inc/class-plugin.php). A mismatch means the folder silently never loads.
 * e.g. 'fluent-crm' -> 'AdminKit_Integration_Fluent_Crm'.
 */
function ak_studly_class( $slug ) {
	return 'AdminKit_Integration_' . str_replace( '-', '_', ucwords( $slug, '-' ) );
}

/**
 * Write inc/integrations/plugins/{slug}/{class-{slug}.php, css/admin.css}. The class is
 * LIVE but INERT (is_active/owns_screen return false) until the human fills the
 * TODOs, so a fresh emit can never mis-skin a screen.
 *
 * Defaults to the plugins/ group (the common case — the scanner targets a host
 * plugin's CSS). For a theme adapter, move the emitted folder to themes/.
 */
function ak_emit( $slug, $scaffold_css, $isTierA, $force, $nl ) {
	$dir       = ak_find_root() . '/inc/integrations/plugins/' . $slug;
	$class     = ak_studly_class( $slug );
	$classFile = $dir . '/class-' . $slug . '.php';
	$cssFile   = $dir . '/css/admin.css';

	if ( is_dir( $dir ) && ! $force ) {
		fwrite( STDERR, $nl . "x inc/integrations/plugins/{$slug}/ already exists — pass --force to overwrite its two generated files, or remove the folder.{$nl}" );
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
	echo "EMITTED inc/integrations/plugins/{$slug}/   Tier {$tier}" . $nl;
	echo str_repeat( '=', 78 ) . $nl;
	echo "  class-{$slug}.php  ->  {$class}" . $nl;
	echo "  css/admin.css      ->  the scaffold above" . $nl;
	echo $nl . "LIVE but INERT (is_active()/owns_screen() return false). Finish it:" . $nl;
	echo "  1. is_active()   — grep the host for its *_VERSION constant." . $nl;
	echo "  2. owns_screen() — inspect <body class> on the host's admin screen." . $nl;
	if ( ! $isTierA ) {
		echo "  3. host_version() + max_tested_host_version() — the host's current major." . $nl;
	}
	echo "  -> fine-tune css/admin.css (docs/INTEGRATIONS.md), then: " . AK_AUDIT_CMD . $nl . $nl;
}

/**
 * The generated class-{slug}.php body. Built line-by-line (not heredoc) so the
 * emitted PHP is unambiguous. Tier A omits the version-gate methods + bail.
 */
function ak_class_stub( $slug, $class, $isTierA ) {
	$L   = array();
	$L[] = '<?php';
	$L[] = '/**';
	$L[] = ' * ' . $class . ' — GENERATED by adapter-scan.php (--emit); hand-tune.';
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
	$L[] = "\t\t\t'src'       => 'inc/integrations/plugins/" . $slug . "/css/admin.css',";
	$L[] = "\t\t\t'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),";
	$L[] = "\t\t\t'context'   => 'admin',";
	$L[] = "\t\t\t'condition' => array( __CLASS__, 'owns_screen' ),";
	$L[] = "\t\t) );";
	$L[] = "\t}";
	$L[] = '}';
	$L[] = '';
	return implode( "\n", $L );
}
