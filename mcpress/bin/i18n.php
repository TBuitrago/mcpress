<?php
/**
 * MCPress translation tooling (development only, not shipped in the release zip).
 *
 *   php bin/i18n.php pot     → languages/mcpress.pot from every __()/_e()/esc_*__()/_n()/_x() call
 *   php bin/i18n.php build   → languages/mcpress-es.mo and mcpress-es.l10n.php from languages/mcpress-es.po
 *
 * No WordPress, gettext or Composer needed.
 *
 * @package MCPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$cmd  = isset( $argv[1] ) ? $argv[1] : '';

if ( 'pot' === $cmd ) {
	$entries = mcpress_i18n_extract( $root );
	file_put_contents( $root . '/languages/mcpress.pot', mcpress_i18n_pot( $entries ) );
	echo count( $entries ) . " strings → languages/mcpress.pot\n";
	exit( 0 );
}
if ( 'build' === $cmd ) {
	$po = mcpress_i18n_parse_po( file_get_contents( $root . '/languages/mcpress-es.po' ) );
	file_put_contents( $root . '/languages/mcpress-es.mo', mcpress_i18n_mo( $po ) );
	file_put_contents( $root . '/languages/mcpress-es.l10n.php', mcpress_i18n_l10n( $po ) );
	$missing = 0;
	foreach ( $po as $e ) {
		if ( '' === $e['msgid'] ) {
			continue;
		}
		if ( '' === implode( '', $e['msgstr'] ) ) {
			++$missing;
		}
	}
	echo count( $po ) - 1 . " entries compiled, $missing untranslated\n";
	exit( 0 );
}
fwrite( STDERR, "Usage: php bin/i18n.php pot|build\n" );
exit( 1 );

/** @return array<string, array> keyed by context\x04msgid */
function mcpress_i18n_extract( $root ) {
	$funcs = array(
		'__'         => array( 'msgid' ),
		'_e'         => array( 'msgid' ),
		'esc_html__' => array( 'msgid' ),
		'esc_html_e' => array( 'msgid' ),
		'esc_attr__' => array( 'msgid' ),
		'esc_attr_e' => array( 'msgid' ),
		'_x'         => array( 'msgid', 'context' ),
		'esc_html_x' => array( 'msgid', 'context' ),
		'esc_attr_x' => array( 'msgid', 'context' ),
		'_n'         => array( 'msgid', 'plural' ),
		'_nx'        => array( 'msgid', 'plural', null, 'context' ),
	);
	$entries = array();
	$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	$files   = array();
	foreach ( $it as $f ) {
		$rel = substr( $f->getPathname(), strlen( $root ) + 1 );
		if ( 'php' === $f->getExtension() && 0 !== strpos( $rel, 'bin/' ) && 0 !== strpos( $rel, 'tests/' ) && 0 !== strpos( $rel, 'languages/' ) ) {
			$files[] = $rel;
		}
	}
	sort( $files );
	foreach ( $files as $rel ) {
		$tokens  = token_get_all( file_get_contents( $root . '/' . $rel ) );
		$comment = null;
		$count   = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && T_COMMENT === $t[0] && false !== stripos( $t[1], 'translators:' ) ) {
				$comment = trim( preg_replace( '#^/\*+|\*+/$|^//#', '', $t[1] ) );
				continue;
			}
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $funcs[ $t[1] ] ) ) {
				continue;
			}
			// Next non-whitespace token must be "(".
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( '(' !== $tokens[ $j ] ) {
				continue;
			}
			$args  = array();
			$depth = 0;
			$cur   = null;
			for ( $k = $j + 1; $k < $count; $k++ ) {
				$tk = $tokens[ $k ];
				if ( '(' === $tk || '[' === $tk ) {
					$depth++;
					$cur = false;
					continue;
				}
				if ( ')' === $tk || ']' === $tk ) {
					if ( 0 === $depth ) {
						$args[] = $cur;
						break;
					}
					$depth--;
					continue;
				}
				if ( ',' === $tk && 0 === $depth ) {
					$args[] = $cur;
					$cur    = null;
					continue;
				}
				if ( is_array( $tk ) && T_WHITESPACE === $tk[0] ) {
					continue;
				}
				if ( 0 === $depth && null === $cur && is_array( $tk ) && T_CONSTANT_ENCAPSED_STRING === $tk[0] ) {
					$cur = stripcslashes( substr( $tk[1], 1, -1 ) );
					if ( "'" === $tk[1][0] ) {
						$cur = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), substr( $tk[1], 1, -1 ) );
					}
					continue;
				}
				$cur = false;
			}
			$map    = $funcs[ $t[1] ];
			$domain = end( $args );
			if ( 'mcpress' !== $domain ) {
				$comment = null;
				continue;
			}
			$e = array( 'msgid' => '', 'plural' => null, 'context' => null );
			foreach ( $map as $idx => $name ) {
				if ( $name && isset( $args[ $idx ] ) && is_string( $args[ $idx ] ) ) {
					$e[ $name ] = $args[ $idx ];
				}
			}
			if ( '' === $e['msgid'] ) {
				$comment = null;
				continue;
			}
			$key = ( $e['context'] ? $e['context'] . "\x04" : '' ) . $e['msgid'];
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = $e + array( 'refs' => array(), 'comment' => null );
			}
			$entries[ $key ]['refs'][] = $rel . ':' . $t[2];
			if ( $comment ) {
				$entries[ $key ]['comment'] = $comment;
			}
			$comment = null;
		}
	}
	return $entries;
}

function mcpress_i18n_quote( $s ) {
	$s = str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $s );
	return '"' . $s . '"';
}

function mcpress_i18n_pot( array $entries ) {
	$out = "# Copyright (C) TBA Digitals\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: MCPress\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: mcpress\\n\"\n\n";
	foreach ( $entries as $e ) {
		if ( $e['comment'] ) {
			$out .= '#. ' . $e['comment'] . "\n";
		}
		$out .= '#: ' . implode( ' ', array_slice( $e['refs'], 0, 4 ) ) . "\n";
		if ( false !== strpos( $e['msgid'], '%' ) ) {
			$out .= "#, php-format\n";
		}
		if ( $e['context'] ) {
			$out .= 'msgctxt ' . mcpress_i18n_quote( $e['context'] ) . "\n";
		}
		$out .= 'msgid ' . mcpress_i18n_quote( $e['msgid'] ) . "\n";
		if ( $e['plural'] ) {
			$out .= 'msgid_plural ' . mcpress_i18n_quote( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
		} else {
			$out .= "msgstr \"\"\n\n";
		}
	}
	return $out;
}

/** Minimal .po parser: msgctxt, msgid, msgid_plural, msgstr, msgstr[n]. */
function mcpress_i18n_parse_po( $text ) {
	$entries = array();
	$e       = null;
	$last    = null;
	$flush   = function () use ( &$e, &$entries ) {
		if ( $e && null !== $e['msgid'] ) {
			$entries[] = $e;
		}
		$e = null;
	};
	foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			$flush();
			continue;
		}
		if ( '#' === $line[0] ) {
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/', $line, $m ) ) {
			if ( ! $e ) {
				$e = array( 'context' => null, 'msgid' => null, 'plural' => null, 'msgstr' => array() );
			}
			$val = stripcslashes( $m[3] );
			if ( 'msgctxt' === $m[1] ) {
				if ( null !== $e['msgid'] ) {
					$flush();
					$e = array( 'context' => null, 'msgid' => null, 'plural' => null, 'msgstr' => array() );
				}
				$e['context'] = $val;
				$last         = array( 'context' );
			} elseif ( 'msgid' === $m[1] ) {
				if ( null !== $e['msgid'] ) {
					$flush();
					$e = array( 'context' => null, 'msgid' => null, 'plural' => null, 'msgstr' => array() );
				}
				$e['msgid'] = $val;
				$last       = array( 'msgid' );
			} elseif ( 'msgid_plural' === $m[1] ) {
				$e['plural'] = $val;
				$last        = array( 'plural' );
			} else {
				$n                 = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
				$e['msgstr'][ $n ] = $val;
				$last              = array( 'msgstr', $n );
			}
			continue;
		}
		if ( preg_match( '/^"(.*)"$/', $line, $m ) && $e && $last ) {
			$val = stripcslashes( $m[1] );
			if ( 'msgstr' === $last[0] ) {
				$e['msgstr'][ $last[1] ] .= $val;
			} else {
				$e[ $last[0] ] .= $val;
			}
		}
	}
	$flush();
	return $entries;
}

function mcpress_i18n_key( array $e ) {
	return ( null !== $e['context'] ? $e['context'] . "\x04" : '' ) . $e['msgid'];
}

function mcpress_i18n_mo( array $entries ) {
	$table = array();
	foreach ( $entries as $e ) {
		if ( '' !== $e['msgid'] && '' === implode( '', $e['msgstr'] ) ) {
			continue;
		}
		$key = mcpress_i18n_key( $e ) . ( null !== $e['plural'] ? "\0" . $e['plural'] : '' );
		ksort( $e['msgstr'] );
		$table[ $key ] = implode( "\0", $e['msgstr'] );
	}
	ksort( $table, SORT_STRING );
	$n       = count( $table );
	$o_off   = 28;
	$t_off   = $o_off + 8 * $n;
	$d_off   = $t_off + 8 * $n;
	$ids     = '';
	$strs    = '';
	$o_table = '';
	$t_table = '';
	$keys    = array_keys( $table );
	foreach ( $keys as $k ) {
		$o_table .= pack( 'VV', strlen( $k ), $d_off + strlen( $ids ) );
		$ids     .= $k . "\0";
	}
	$s_start = $d_off + strlen( $ids );
	foreach ( $keys as $k ) {
		$v        = $table[ $k ];
		$t_table .= pack( 'VV', strlen( $v ), $s_start + strlen( $strs ) );
		$strs    .= $v . "\0";
	}
	return pack( 'VVVVVVV', 0x950412de, 0, $n, $o_off, $t_off, 0, 0 ) . $o_table . $t_table . $ids . $strs;
}

/** WordPress 6.5+ PHP translation file. */
function mcpress_i18n_l10n( array $entries ) {
	$messages = array();
	$headers  = array();
	foreach ( $entries as $e ) {
		if ( '' === $e['msgid'] ) {
			foreach ( explode( "\n", isset( $e['msgstr'][0] ) ? $e['msgstr'][0] : '' ) as $h ) {
				if ( false !== strpos( $h, ':' ) ) {
					list( $k, $v ) = explode( ':', $h, 2 );
					$headers[ strtolower( trim( $k ) ) ] = trim( $v );
				}
			}
			continue;
		}
		if ( '' === implode( '', $e['msgstr'] ) ) {
			continue;
		}
		ksort( $e['msgstr'] );
		$messages[ mcpress_i18n_key( $e ) ] = implode( "\0", $e['msgstr'] );
	}
	$data = array(
		'domain'       => 'mcpress',
		'plural-forms' => isset( $headers['plural-forms'] ) ? $headers['plural-forms'] : 'nplurals=2; plural=n != 1;',
		'language'     => 'es',
		'messages'     => $messages,
	);
	return '<?php' . "\nreturn " . var_export( $data, true ) . ";\n";
}
