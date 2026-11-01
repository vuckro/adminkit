<?php
/**
 * Deterministic i18n catalog builder and verifier.
 *
 * Extracts only `'adminkit'`-domain gettext calls (rejecting un-exempted foreign
 * or default-domain calls), preserves `translators:` comments, and compiles
 * `languages/adminkit.pot`, `adminkit-fr_FR.po`, `.mo` and WP 6.5+ `.l10n.php`.
 * Run `--write` to synchronize; `--check` never writes the worktree.
 *
 * @package AdminKit
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );
chdir( $root );

/**
 * Parse a PO/POT file into keyed entry arrays.
 *
 * @param string $source Raw PO/POT text.
 * @return array<string, array<string, mixed>>
 */
function ak_po_read( string $source ): array {
	$entries = array();
	foreach ( preg_split( '/\n\s*\n/', trim( $source ) ) as $block ) {
		$entry = array(
			'comments' => array(),
			'refs'     => array(),
			'msgstr'   => array(),
		);
		$field = null;
		$index = 0;
		foreach ( explode( "\n", $block ) as $line ) {
			if ( 0 === strpos( $line, '#.' ) ) {
				$comment = trim( substr( $line, 2 ) );
				if ( '' !== $comment ) {
					$entry['comments'][] = $comment;
				}
				continue;
			}
			if ( 0 === strpos( $line, '#: ' ) ) {
				$entry['refs'] = array_merge( $entry['refs'], preg_split( '/\s+/', substr( $line, 3 ) ) );
				continue;
			}
			if ( 0 === strpos( $line, '#,' ) ) {
				$entry['flags'] = trim( substr( $line, 2 ) );
				if ( false !== strpos( $line, 'fuzzy' ) ) {
					$entry['fuzzy'] = true;
				}
				continue;
			}
			if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+(".*")$/', $line, $m ) ) {
				$field = $m[1];
				$index = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
				$value = json_decode( $m[3], true, 512, JSON_THROW_ON_ERROR );
				if ( 'msgstr' === $field ) {
					$entry[ $field ][ $index ] = $value;
				} else {
					$entry[ $field ] = $value;
				}
			} elseif ( preg_match( '/^(".*")$/', $line, $m ) && $field ) {
				$value = json_decode( $m[1], true, 512, JSON_THROW_ON_ERROR );
				if ( 'msgstr' === $field ) {
					$entry[ $field ][ $index ] .= $value;
				} else {
					$entry[ $field ] .= $value;
				}
			}
		}
		if ( ! isset( $entry['msgid'] ) || '' === $entry['msgid'] ) {
			continue;
		}
		$key             = isset( $entry['msgctxt'] ) ? $entry['msgctxt'] . "\4" . $entry['msgid'] : $entry['msgid'];
		$entries[ $key ] = $entry;
	}
	return $entries;
}

/**
 * Filter PHP source so xgettext only sees `'adminkit'`-domain gettext calls,
 * throwing on any un-exempted missing/foreign textdomain.
 *
 * @param string $code Raw PHP file contents.
 * @param string $file Relative file path for diagnostics.
 * @return string
 */
function ak_filter_domain_php( string $code, string $file ): string {
	$domain_arg = array(
		'__'         => 2,
		'_e'         => 2,
		'esc_html__' => 2,
		'esc_attr__' => 2,
		'esc_html_e' => 2,
		'esc_attr_e' => 2,
		'_x'         => 3,
		'_ex'        => 3,
		'esc_html_x' => 3,
		'esc_attr_x' => 3,
		'_n'         => 4,
		'_nx'        => 5,
	);
	$tokens        = token_get_all( $code );
	$count         = count( $tokens );
	$allow_missing = false;
	$out           = '';
	$prev_sig      = '';
	for ( $i = 0; $i < $count; $i++ ) {
		$tok  = $tokens[ $i ];
		$text = is_array( $tok ) ? $tok[1] : $tok;
		if ( is_array( $tok ) && ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) ) {
			if ( false !== strpos( $text, 'phpcs:disable' ) && false !== strpos( $text, 'WordPress.WP.I18n.MissingArgDomain' ) ) {
				$allow_missing = true;
			} elseif ( false !== strpos( $text, 'phpcs:enable' ) && ( false !== strpos( $text, 'WordPress.WP.I18n.MissingArgDomain' ) || 'phpcs:enable' === trim( (string) preg_replace( '~^/\*+|\*+/$|^//+\s*~', '', $text ) ) ) ) {
				$allow_missing = false;
			}
			$out .= $text;
			continue;
		}
		if ( is_array( $tok ) && T_WHITESPACE === $tok[0] ) {
			$out .= $text;
			continue;
		}
		if ( is_array( $tok ) && T_STRING === $tok[0] && isset( $domain_arg[ $text ] ) && ! in_array( $prev_sig, array( '->', '?->', '::', 'function', 'new' ), true ) ) {
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				++$j;
			}
			if ( $j < $count && '(' === $tokens[ $j ] ) {
				$depth   = 1;
				$args    = array( '' );
				$arg_idx = 0;
				for ( $k = $j + 1; $k < $count && $depth > 0; $k++ ) {
					$ct = $tokens[ $k ];
					$cs = is_array( $ct ) ? $ct[1] : $ct;
					if ( '(' === $cs || '[' === $cs || '{' === $cs ) {
						++$depth;
						$args[ $arg_idx ] .= $cs;
					} elseif ( ')' === $cs || ']' === $cs || '}' === $cs ) {
						--$depth;
						if ( $depth > 0 ) {
							$args[ $arg_idx ] .= $cs;
						}
					} elseif ( ',' === $cs && 1 === $depth ) {
						++$arg_idx;
						$args[ $arg_idx ] = '';
					} elseif ( ! is_array( $ct ) || ( T_COMMENT !== $ct[0] && T_DOC_COMMENT !== $ct[0] ) ) {
						$args[ $arg_idx ] .= $cs;
					}
				}
				$pos = $domain_arg[ $text ] - 1;
				$dom = isset( $args[ $pos ] ) ? trim( $args[ $pos ] ) : '';
				if ( "'adminkit'" !== $dom && '"adminkit"' !== $dom ) {
					if ( ! $allow_missing ) {
						throw new RuntimeException( sprintf( "Gettext call %s() must use 'adminkit' domain in %s:%d", $text, $file, $tok[2] ) );
					}
					$out     .= 'strval';
					$prev_sig = $text;
					continue;
				}
			}
		}
		$out     .= $text;
		$prev_sig = $text;
	}
	return $out;
}

/**
 * Run an external binary and return stdout, throwing on non-zero exit.
 *
 * @param array<int, string> $command Argument vector.
 * @param string|null        $cwd     Optional working directory.
 * @return string
 */
function ak_command( array $command, ?string $cwd = null ): string {
	$pipes   = array();
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Cannot run ' . $command[0] );
	}
	fclose( $pipes[0] );
	$out    = (string) stream_get_contents( $pipes[1] );
	$err    = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	if ( 0 !== $status ) {
		throw new RuntimeException( implode( ' ', $command ) . "\n" . $err );
	}
	return $out;
}

/**
 * Quote a PO string deterministically.
 *
 * @param string $text String to quote.
 * @return string
 */
function ak_quote( string $text ): string {
	return json_encode( $text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
}

/**
 * Build deterministic POT/PO file header.
 *
 * @param bool $french True for `fr_FR`, false for `.pot`.
 * @return string
 */
function ak_po_header( bool $french ): string {
	$fields = array(
		'Project-Id-Version: AdminKit 1.0.0',
		'Report-Msgid-Bugs-To: https://github.com/vuckro/adminkit/issues',
		'POT-Creation-Date: 2026-10-31 00:00+0100',
		'PO-Revision-Date: 2026-10-31 00:00+0100',
		'Last-Translator: AdminKit',
		'Language-Team: ' . ( $french ? 'French' : '' ),
		'Language: ' . ( $french ? 'fr_FR' : '' ),
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: 8bit',
		'Plural-Forms: nplurals=2; plural=(n > 1);',
		'X-Domain: adminkit',
	);
	$out    = '# AdminKit — ' . ( $french ? 'French translation.' : 'translation template.' ) . "\n# Copyright (C) 2026 Waaskit\n# GPL-2.0-or-later\nmsgid \"\"\nmsgstr \"\"\n";
	foreach ( $fields as $field ) {
		$out .= ak_quote( $field . "\n" ) . "\n";
	}
	return $out . "\n";
}

$temp = sys_get_temp_dir() . '/adminkit-i18n-' . bin2hex( random_bytes( 6 ) );
mkdir( $temp . '/src', 0700, true );
register_shutdown_function(
	static function () use ( $temp ) {
		if ( ! is_dir( $temp ) ) {
			return;
		}
		$rm = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $rm as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $temp );
	}
);

try {
	$files = array( 'adminkit.php', 'uninstall.php' );
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( 'inc', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = str_replace( '\\', '/', $file->getPathname() );
		}
	}
	sort( $files );

	foreach ( $files as $rel ) {
		$dest = $temp . '/src/' . $rel;
		$dir  = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		file_put_contents( $dest, ak_filter_domain_php( (string) file_get_contents( $rel ), $rel ) );
	}

	$command = array(
		'xgettext',
		'--language=PHP',
		'--from-code=UTF-8',
		'--add-comments=translators:',
		'--keyword=',
		'--keyword=__:1',
		'--keyword=_e:1',
		'--keyword=_x:1,2c',
		'--keyword=_ex:1,2c',
		'--keyword=_n:1,2',
		'--keyword=_nx:1,2,4c',
		'--keyword=esc_html__:1',
		'--keyword=esc_attr__:1',
		'--keyword=esc_html_e:1',
		'--keyword=esc_attr_e:1',
		'--keyword=esc_html_x:1,2c',
		'--keyword=esc_attr_x:1,2c',
		'--add-location=file',
		'--no-wrap',
		'--output=' . $temp . '/source.pot',
	);
	ak_command( array_merge( $command, $files ), $temp . '/src' );

	$source = ak_po_read( (string) file_get_contents( $temp . '/source.pot' ) );
	ksort( $source, SORT_STRING );

	// WordPress translates plugin metadata dynamically, outside explicit gettext calls.
	$header = (string) file_get_contents( 'adminkit.php' );
	foreach ( array( 'Plugin Name', 'Description', 'Author' ) as $field ) {
		if ( preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':\s*(.+)$/m', $header, $match ) ) {
			$message = trim( $match[1] );
			if ( ! isset( $source[ $message ] ) ) {
				$source[ $message ] = array(
					'msgid'    => $message,
					'comments' => array(),
					'refs'     => array( 'adminkit.php' ),
					'msgstr'   => array(),
				);
			}
		}
	}
	ksort( $source, SORT_STRING );

	$existing = ak_po_read( (string) file_get_contents( 'languages/adminkit-fr_FR.po' ) );
	$missing  = array();
	foreach ( $source as $key => $entry ) {
		if ( ! isset( $existing[ $key ] ) || empty( $existing[ $key ]['msgstr'][0] ) || ! empty( $existing[ $key ]['fuzzy'] ) ) {
			$missing[ $key ] = $entry['refs'];
		}
	}
	if ( in_array( '--missing', $argv, true ) ) {
		echo json_encode( $missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		exit;
	}
	if ( $missing ) {
		throw new RuntimeException( 'Missing French translations: ' . implode( ', ', array_keys( $missing ) ) );
	}

	$pot      = ak_po_header( false );
	$po       = ak_po_header( true );
	$messages = array();
	foreach ( $source as $key => $entry ) {
		$base = '';
		if ( ! empty( $entry['comments'] ) ) {
			foreach ( array_values( array_unique( $entry['comments'] ) ) as $comment ) {
				$base .= '#. ' . $comment . "\n";
			}
		}
		$base .= '#: ' . implode( ' ', array_unique( $entry['refs'] ) ) . "\n";
		if ( ! empty( $entry['flags'] ) ) {
			$base .= '#, ' . $entry['flags'] . "\n";
		}
		if ( isset( $entry['msgctxt'] ) ) {
			$base .= 'msgctxt ' . ak_quote( $entry['msgctxt'] ) . "\n";
		}
		$base .= 'msgid ' . ak_quote( $entry['msgid'] ) . "\n";
		if ( isset( $entry['msgid_plural'] ) ) {
			$base .= 'msgid_plural ' . ak_quote( $entry['msgid_plural'] ) . "\n";
		}
		$pot .= $base;
		$po  .= $base;

		$count        = isset( $entry['msgid_plural'] ) ? 2 : 1;
		$translations = array();
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $existing[ $key ]['msgstr'][ $i ] ) || '' === $existing[ $key ]['msgstr'][ $i ] ) {
				throw new RuntimeException( 'Missing plural translation: ' . $key );
			}
			$label          = 'msgstr' . ( $count > 1 ? '[' . $i . ']' : '' ) . ' ';
			$pot           .= $label . "\"\"\n";
			$po            .= $label . ak_quote( $existing[ $key ]['msgstr'][ $i ] ) . "\n";
			$translations[] = $existing[ $key ]['msgstr'][ $i ];
		}
		$pot             .= "\n";
		$po              .= "\n";
		$messages[ $key ] = implode( "\0", $translations );
	}

	$l10n = "<?php\n// Generated by php dev/i18n.php --write.\nreturn " . var_export(
		array(
			'project-id-version' => 'AdminKit 1.0.0',
			'language'           => 'fr_FR',
			'plural-forms'       => 'nplurals=2; plural=(n > 1);',
			'messages'           => $messages,
		),
		true
	) . ";\n";

	file_put_contents( $temp . '/fr.po', $po );
	ak_command( array( 'msgfmt', '--check', '--check-format', '--output-file=' . $temp . '/fr.mo', $temp . '/fr.po' ) );

	$artifacts = array(
		'languages/adminkit.pot'        => rtrim( $pot ) . "\n",
		'languages/adminkit-fr_FR.po'   => rtrim( $po ) . "\n",
		'languages/adminkit-fr_FR.mo'   => (string) file_get_contents( $temp . '/fr.mo' ),
		'languages/adminkit-fr_FR.l10n.php' => $l10n,
	);
	$drift     = array();
	foreach ( $artifacts as $file => $contents ) {
		if ( in_array( '--write', $argv, true ) ) {
			file_put_contents( $file, $contents );
		} elseif ( ! is_file( $file ) || file_get_contents( $file ) !== $contents ) {
			$drift[] = $file;
		}
	}
	if ( $drift ) {
		throw new RuntimeException( 'i18n drift: ' . implode( ', ', $drift ) . '. Run php dev/i18n.php --write.' );
	}
	echo 'OK: ' . count( $source ) . " active messages; POT/PO/MO/PHP catalogs agree with source, no missing/dead entries.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
