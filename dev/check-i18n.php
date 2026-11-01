<?php
/**
 * Catalog drift plus the PHP-bootstrap contract used by build-free JavaScript.
 *
 * Ensures POT/PO/MO/l10n.php stay in sync with source code and verifies that
 * every UI string read by runtime JavaScript comes from a localized PHP array
 * (`__()`, `_x()`, `_n()`, `esc_html__()`, `esc_attr__()`, etc.) with zero
 * untranslated JS fallback copy.
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );
chdir( $root );

$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/i18n.php' ) . ' --check';
passthru( $command, $status );
if ( 0 !== $status ) {
	exit( $status );
}

$php = '';
$inc_iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( 'inc', FilesystemIterator::SKIP_DOTS )
);
foreach ( $inc_iterator as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$php .= file_get_contents( $file->getPathname() ) . "\n";
	}
}

preg_match_all( '/[\'"]([A-Za-z][A-Za-z0-9_]*)[\'"]\s*=>/', $php, $matches );
$keys = array_fill_keys( $matches[1], true );

$brands = array(
	'AdminKit',
	'Lucide',
	'Geist (AdminKit)',
	'Inter (Google Fonts)',
	'Plus Jakarta Sans (Google Fonts)',
	'DM Sans (Google Fonts)',
	'Manrope (Google Fonts)',
	'Outfit (Google Fonts)',
	'Poppins (Google Fonts)',
	'Space Grotesk (Google Fonts)',
	'IBM Plex Sans (Google Fonts)',
);

preg_match_all(
	'/[\'"]([A-Za-z][A-Za-z0-9_]*)[\'"]\s*=>\s*(?:__|esc_html__|esc_attr__|_x|esc_html_x|esc_attr_x|_n|_nx)\(/',
	$php,
	$translated
);
$translated_keys = array_fill_keys( $translated[1], true );

$technical = array_fill_keys(
	array(
		'pendingClass',
		'readyClass',
		'cards',
		'sections',
		'role_levels',
		'role_descriptions',
		'ajaxUrl',
	),
	true
);

$checked = 0;
foreach ( array( 'assets/js', 'inc/integrations' ) as $directory ) {
	$js_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $js_iterator as $file ) {
		if ( 'js' !== $file->getExtension() ) {
			continue;
		}
		$source = (string) file_get_contents( $file->getPathname() );
		// Strip block comments before checking UI literals (preserving strings/regexes).
		$source = (string) preg_replace( '~/\*.*?\*/~s', '', $source );

		if ( preg_match( '/\b(?:__|_x|_n|_nx)\s*\(/', $source ) ) {
			throw new RuntimeException(
				'JS gettext needs an extractor/runtime loader; use the existing PHP bootstrap: ' . $file->getPathname()
			);
		}

		preg_match_all( '/\b(I|S|L|DATA|D|C)\.([A-Za-z][A-Za-z0-9_]*)/', $source, $uses, PREG_SET_ORDER );
		foreach ( $uses as $use ) {
			if ( 'I' === $use[1] && 'profile-account.js' === basename( $file->getPathname() ) ) {
				continue;
			}
			if ( ! isset( $keys[ $use[2] ] ) ) {
				throw new RuntimeException( 'Missing PHP bootstrap key ' . $use[2] . ' for ' . $file->getPathname() );
			}
			if (
				in_array( $use[1], array( 'I', 'S', 'L', 'DATA' ), true )
				&& ! isset( $translated_keys[ $use[2] ] )
				&& ! isset( $technical[ $use[2] ] )
			) {
				throw new RuntimeException( 'JS copy is not a translated PHP bootstrap value: ' . $use[2] );
			}
			++$checked;
		}

		$literal = '(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")';
		preg_match_all(
			'/(?:\btext|\btitle|\blabel|\bplaceholder|[\'"]aria-label[\'"])\s*:\s*(' . $literal . ')/',
			$source,
			$strings
		);
		preg_match_all(
			'/(?:\btextContent\s*=|\.(?:title|placeholder)\s*=|\bcreateTextNode\s*\(|\b(?:alert|confirm)\s*\()\s*(' . $literal . ')/',
			$source,
			$extra
		);
		$all_literals = array_merge( $strings[1], $extra[1] );

		foreach ( $all_literals as $quoted ) {
			$text = substr( $quoted, 1, -1 );
			if (
				preg_match( '/[A-Za-z]/', $text )
				&& ! in_array( $text, $brands, true )
				&& ! preg_match( '/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i', $text )
			) {
				throw new RuntimeException( 'Untranslated JS UI literal ' . $quoted . ' in ' . $file->getPathname() );
			}
		}

		if ( preg_match( '/\b(?:I|S|L|DATA)\.[A-Za-z0-9_]+\s*\|\|\s*[\'"][A-Za-z]/', $source ) ) {
			throw new RuntimeException( 'Untranslated JS fallback in ' . $file->getPathname() );
		}
	}
}

// Verify French typographic and glossary invariants across languages/adminkit-fr_FR.po.
$fr_po = (string) file_get_contents( 'languages/adminkit-fr_FR.po' );
preg_match_all( '/^msgstr(?:\[\d+\])?\s+(".*")$/m', $fr_po, $fr_matches );
foreach ( $fr_matches[1] as $raw_fr ) {
	$fr = (string) json_decode( $raw_fr, true, 512, JSON_THROW_ON_ERROR );
	if ( preg_match( "/[A-Za-zÀ-ÿ]'[A-Za-zÀ-ÿ]/u", $fr ) ) {
		throw new RuntimeException( 'Straight ASCII apostrophe in fr_FR translation (use ’): ' . $fr );
	}
	if ( preg_match( '/\x20[:;?!]|«\x20|\x20»/u', $fr ) ) {
		throw new RuntimeException( 'ASCII space before French double punctuation or inside guillemets (use NBSP \\u{00A0}): ' . $fr );
	}
}

echo "OK: $checked JS bootstrap references; UI copy is centralized in translated PHP; fr_FR typography verified.\n";
