<?php
/**
 * Database: tables, SQL, serialization-safe search & replace.
 *
 * MCPress tables are invisible and untouchable here, in every mode. Writes that mention
 * MCPress anywhere are refused (no self-escalation through SQL).
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\Installer;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Database {

	const MAX_ROWS = 2000;

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'db_tables',
				'title'        => 'List database tables',
				'group'        => 'database',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Tables of this site with engine, row estimate and size. Plugin tables included.',
				'input_schema' => H::schema( array( 'columns_of' => H::str( 'Also describe the columns of this table.' ) ) ),
				'callback'     => array( __CLASS__, 'db_tables' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'db_query',
				'title'        => 'Run SQL',
				'group'        => 'database',
				'risk'         => array( __CLASS__, 'query_risk' ),
				'base_risk'    => Policy::RISK_READ,
				'description'  => 'Run one SQL statement. SELECT / SHOW / DESCRIBE / EXPLAIN are reads; anything else (INSERT, UPDATE, DELETE, ALTER…) is a critical write. Use {prefix} for the table prefix, e.g. SELECT * FROM {prefix}posts LIMIT 5. Prefer the dedicated tools: they fire WordPress hooks and clear caches.',
				'input_schema' => H::schema(
					array(
						'sql'      => H::str( 'One SQL statement.' ),
						'max_rows' => H::int( 'Max rows returned (1-2000).', array( 'minimum' => 1, 'maximum' => self::MAX_ROWS, 'default' => 200 ) ),
					),
					array( 'sql' )
				),
				'callback'     => array( __CLASS__, 'db_query' ),
				'guard'        => array( __CLASS__, 'guard_query' ),
				'preview'      => array( __CLASS__, 'preview_query' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'search_replace',
				'title'        => 'Search & replace in the database',
				'group'        => 'database',
				'risk'         => array( __CLASS__, 'replace_risk' ),
				'base_risk'    => Policy::RISK_READ,
				'description'  => 'Replace text across database tables (e.g. an old domain), keeping serialized PHP data valid and also matching JSON-escaped forms (https:\/\/…) used by Elementor. dry_run=true (default) only counts; dry_run=false is critical.',
				'input_schema' => H::schema(
					array(
						'search'          => H::str( 'Text to find.' ),
						'replace'         => H::str( 'Replacement.' ),
						'tables'          => H::arr( 'string', 'Tables to process (default: every table of this site).' ),
						'skip_columns'    => array_merge( H::arr( 'string', 'Columns to skip.' ), array( 'default' => array( 'guid' ) ) ),
						'case_sensitive'  => H::bool( 'Case-sensitive match.', true ),
						'json_escaped'    => H::bool( 'Also replace the JSON-escaped form (slashes as \/).', true ),
						'dry_run'         => H::bool( 'Only report what would change.', true ),
					),
					array( 'search', 'replace' )
				),
				'callback'     => array( __CLASS__, 'search_replace' ),
				'guard'        => array( __CLASS__, 'guard_replace' ),
				'capability'   => 'manage_options',
			)
		);
	}

	/* -------------------------------------------------------------- Helpers */

	private static function prefixed( $sql ) {
		global $wpdb;
		return str_replace( '{prefix}', $wpdb->prefix, (string) $sql );
	}

	/** Leading keyword, ignoring comments and parentheses. */
	private static function keyword( $sql ) {
		$s = preg_replace( '#/\*.*?\*/#s', ' ', (string) $sql );
		$s = preg_replace( '/^\s*(--|#)[^\n]*\n/m', ' ', $s );
		$s = ltrim( $s, " \t\n\r(" );
		return strtoupper( (string) strtok( $s, " \t\n\r(" ) );
	}

	public static function is_read( $sql ) {
		$kw = self::keyword( $sql );
		if ( ! in_array( $kw, array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH' ), true ) ) {
			return false;
		}
		if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE|@)|\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\b|\b(SLEEP|BENCHMARK|LOAD_FILE)\s*\(/i', $sql ) ) {
			return false;
		}
		if ( 'WITH' === $kw && preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
			return false;
		}
		return true;
	}

	private static function has_second_statement( $sql ) {
		// Strip quoted strings, then look for a semicolon followed by more SQL.
		$s = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|`[^`]*`/s", "''", (string) $sql );
		return (bool) preg_match( '/;\s*\S/', rtrim( $s, "; \t\n\r" ) );
	}

	private static function mentions_mcpress( $sql ) {
		return (bool) preg_match( '/mcpress/i', (string) $sql );
	}

	private static function touches_mcpress_tables( $sql ) {
		foreach ( Installer::tables() as $t ) {
			if ( false !== stripos( (string) $sql, $t ) ) {
				return true;
			}
		}
		global $wpdb;
		return (bool) preg_match( '/' . preg_quote( $wpdb->prefix, '/' ) . 'mcpress_/i', (string) $sql );
	}

	public static function query_risk( array $args ) {
		return self::is_read( self::prefixed( isset( $args['sql'] ) ? $args['sql'] : '' ) ) ? Policy::RISK_READ : Policy::RISK_CRITICAL;
	}

	public static function replace_risk( array $args ) {
		return ( ! isset( $args['dry_run'] ) || $args['dry_run'] ) ? Policy::RISK_READ : Policy::RISK_CRITICAL;
	}

	public static function guard_query( array $args ) {
		$sql = self::prefixed( $args['sql'] );
		if ( self::has_second_statement( $sql ) ) {
			return H::error( 'one_statement', 'Send one SQL statement per call.' );
		}
		if ( self::touches_mcpress_tables( $sql ) ) {
			return H::error( 'self_protection', 'MCPress tables (connections, keys, approvals, log) are off-limits to the AI.' );
		}
		if ( ! self::is_read( $sql ) && self::mentions_mcpress( $sql ) ) {
			return H::error( 'self_protection', 'SQL writes that touch MCPress data are refused. Permissions are changed in wp-admin → MCPress.' );
		}
		return null;
	}

	public static function guard_replace( array $args ) {
		if ( '' === (string) $args['search'] ) {
			return H::error( 'invalid_input', 'search must not be empty.' );
		}
		if ( self::mentions_mcpress( $args['search'] ) || self::mentions_mcpress( $args['replace'] ) ) {
			return H::error( 'self_protection', 'Replacing text that mentions MCPress is refused.' );
		}
		return null;
	}

	/** Tables of this site (prefix match), minus MCPress' own. */
	public static function site_tables() {
		global $wpdb;
		$all  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$skip = array_map( 'strtolower', Installer::tables() );
		$out  = array();
		foreach ( (array) $all as $t ) {
			if ( in_array( strtolower( $t ), $skip, true ) ) {
				continue;
			}
			// On multisite, the main site prefix also matches sub-site tables (wp_2_posts).
			if ( is_multisite() && is_main_site() && preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $t ) ) {
				continue;
			}
			$out[] = $t;
		}
		return $out;
	}

	/* ------------------------------------------------------------ Callbacks */

	public static function db_tables( array $args ) {
		global $wpdb;
		$tables = self::site_tables();
		$status = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );
		$by     = array();
		foreach ( (array) $status as $row ) {
			$by[ $row['Name'] ] = $row;
		}
		$out = array();
		foreach ( $tables as $t ) {
			$s     = isset( $by[ $t ] ) ? $by[ $t ] : array();
			$out[] = array(
				'table'   => $t,
				'engine'  => isset( $s['Engine'] ) ? $s['Engine'] : null,
				'rows'    => isset( $s['Rows'] ) ? (int) $s['Rows'] : null,
				'size_kb' => isset( $s['Data_length'] ) ? (int) round( ( $s['Data_length'] + $s['Index_length'] ) / 1024 ) : null,
			);
		}
		$result = array( 'prefix' => $wpdb->prefix, 'tables' => $out );
		if ( ! empty( $args['columns_of'] ) ) {
			$table = self::prefixed( $args['columns_of'] );
			if ( ! in_array( $table, $tables, true ) ) {
				return H::error( 'not_found', 'Unknown table (or not allowed).' );
			}
			$result['columns'] = $wpdb->get_results( 'DESCRIBE `' . esc_sql( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $result;
	}

	public static function db_query( array $args ) {
		global $wpdb;
		$sql  = trim( rtrim( trim( self::prefixed( $args['sql'] ) ), ';' ) );
		$read = self::is_read( $sql );
		$max  = (int) $args['max_rows'];

		$wpdb->suppress_errors( true );
		$previous_show = $wpdb->show_errors( false );

		if ( $read ) {
			if ( 'SELECT' === self::keyword( $sql ) && ! preg_match( '/\bLIMIT\s+\d+(\s*,\s*\d+)?(\s+OFFSET\s+\d+)?\s*$/i', $sql ) ) {
				$sql .= ' LIMIT ' . ( $max + 1 );
			}
			$wpdb->query( 'SET SESSION MAX_EXECUTION_TIME=30000' ); // MySQL ≥ 5.7.8; ignored elsewhere.
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$err  = $wpdb->last_error;
			$wpdb->show_errors( $previous_show );
			$wpdb->suppress_errors( false );
			if ( $err ) {
				return H::error( 'sql_error', $err, 'Check table and column names with db_tables (columns_of).', true );
			}
			$rows      = (array) $rows;
			$truncated = count( $rows ) > $max;
			return array(
				'rows'      => array_slice( $rows, 0, $max ),
				'count'     => min( count( $rows ), $max ),
				'truncated' => $truncated,
			);
		}

		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$err      = $wpdb->last_error;
		$wpdb->show_errors( $previous_show );
		$wpdb->suppress_errors( false );
		if ( false === $affected || $err ) {
			return H::error( 'sql_error', $err ? $err : 'The statement failed.', null, true );
		}
		wp_cache_flush();
		return array(
			'rows_affected' => (int) $affected,
			'insert_id'     => $wpdb->insert_id ? (int) $wpdb->insert_id : null,
			'note'          => 'The object cache was flushed.',
		);
	}

	public static function preview_query( array $args ) {
		return array(
			'sql'  => self::prefixed( $args['sql'] ),
			'kind' => self::is_read( self::prefixed( $args['sql'] ) ) ? 'read' : 'WRITE (' . self::keyword( self::prefixed( $args['sql'] ) ) . ')',
		);
	}

	/** Serialization-safe search & replace. */
	public static function search_replace( array $args ) {
		global $wpdb;
		$search  = (string) $args['search'];
		$replace = (string) $args['replace'];
		$dry     = ! empty( $args['dry_run'] );
		$cs      = ! empty( $args['case_sensitive'] );
		$pairs   = array( $search => $replace );
		if ( ! empty( $args['json_escaped'] ) && false !== strpos( $search, '/' ) ) {
			$pairs[ str_replace( '/', '\/', $search ) ] = str_replace( '/', '\/', $replace );
		}
		$allowed = self::site_tables();
		$tables  = ! empty( $args['tables'] ) ? array_map( array( __CLASS__, 'prefixed' ), (array) $args['tables'] ) : $allowed;
		$skip    = array_map( 'strtolower', (array) $args['skip_columns'] );
		$report  = array();
		$total   = 0;
		$skipped = 0;
		$samples = array();
		$start   = microtime( true );

		foreach ( $tables as $table ) {
			if ( ! in_array( $table, $allowed, true ) ) {
				$report[ $table ] = 'skipped (unknown or not allowed)';
				continue;
			}
			$cols = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$pk   = array();
			$text = array();
			foreach ( (array) $cols as $c ) {
				if ( 'PRI' === $c['Key'] ) {
					$pk[] = $c['Field'];
				}
				if ( preg_match( '/char|text|blob|json/i', $c['Type'] ) && ! in_array( strtolower( $c['Field'] ), $skip, true ) ) {
					$text[] = $c['Field'];
				}
			}
			if ( ! $pk || ! $text ) {
				continue;
			}
			$changed_rows = 0;
			foreach ( $text as $col ) {
				$like_parts = array();
				$params     = array();
				foreach ( array_keys( $pairs ) as $needle ) {
					$like_parts[] = "`$col` LIKE %s";
					$params[]     = '%' . $wpdb->esc_like( $needle ) . '%';
				}
				$select = 'SELECT `' . implode( '`,`', array_map( 'esc_sql', $pk ) ) . "`, `$col` AS v FROM `" . esc_sql( $table ) . '` WHERE (' . implode( ' OR ', $like_parts ) . ')';
				// Keyset paging on a single-column primary key: every row is visited once, even when
				// the replacement still contains the search text. Composite keys: one bounded batch.
				$single = 1 === count( $pk );
				$last   = null;
				do {
					if ( $single ) {
						$sql  = $select . ( null === $last ? '' : ' AND `' . esc_sql( $pk[0] ) . '` > %s' ) . ' ORDER BY `' . esc_sql( $pk[0] ) . '` ASC LIMIT 500';
						$rows = $wpdb->get_results( $wpdb->prepare( $sql, null === $last ? $params : array_merge( $params, array( $last ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					} else {
						$rows = $wpdb->get_results( $wpdb->prepare( $select . ' LIMIT 5000', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					}
					foreach ( (array) $rows as $row ) {
						if ( $single ) {
							$last = $row[ $pk[0] ];
						}
						$old = $row['v'];
						$new = self::replace_value( $old, $pairs, $cs, $skipped );
						if ( $new === $old ) {
							continue;
						}
						++$changed_rows;
						if ( count( $samples ) < 8 ) {
							$samples[] = array(
								'table'  => $table,
								'column' => $col,
								'row'    => array_intersect_key( $row, array_flip( $pk ) ),
								'before' => H::context_snippet( $old, self::first_needle( $old, $pairs ), 60 ),
							);
						}
						if ( ! $dry ) {
							$where = array_intersect_key( $row, array_flip( $pk ) );
							$wpdb->update( $table, array( $col => $new ), $where );
						}
					}
				} while ( $single && $rows && count( $rows ) === 500 && microtime( true ) - $start < 240 );
			}
			if ( $changed_rows ) {
				$report[ $table ] = $changed_rows;
				$total           += $changed_rows;
			}
		}
		if ( ! $dry && $total ) {
			wp_cache_flush();
		}
		return array(
			'dry_run'         => $dry,
			'rows_changed'    => $total,
			'by_table'        => $report ? $report : new \stdClass(),
			'samples'         => $samples,
			'skipped_objects' => $skipped,
			'note'            => $skipped ? 'Some serialized values contain PHP objects and were left untouched. Handle them with run_php if needed.' : null,
			'next'            => $dry && $total ? 'Run again with dry_run=false to apply.' : null,
		);
	}

	private static function first_needle( $text, array $pairs ) {
		foreach ( array_keys( $pairs ) as $n ) {
			if ( false !== stripos( $text, $n ) ) {
				return $n;
			}
		}
		return (string) key( $pairs );
	}

	/** Replace inside plain strings and (recursively) inside serialized arrays and strings. */
	public static function replace_value( $value, array $pairs, $case_sensitive, &$skipped_objects ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false === $data && 'b:0;' !== $value ) {
				return $value; // Corrupt serialized data: leave it alone.
			}
			if ( self::contains_object( $data ) ) {
				++$skipped_objects;
				return $value;
			}
			return serialize( self::replace_deep( $data, $pairs, $case_sensitive, $skipped_objects ) ); // phpcs:ignore
		}
		return self::replace_string( $value, $pairs, $case_sensitive );
	}

	private static function replace_deep( $data, array $pairs, $cs, &$skipped ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$nk         = is_string( $k ) ? self::replace_string( $k, $pairs, $cs ) : $k;
				$out[ $nk ] = self::replace_deep( $v, $pairs, $cs, $skipped );
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			return is_serialized( $data ) ? self::replace_value( $data, $pairs, $cs, $skipped ) : self::replace_string( $data, $pairs, $cs );
		}
		return $data;
	}

	private static function replace_string( $s, array $pairs, $cs ) {
		if ( ! is_string( $s ) ) {
			return $s;
		}
		return $cs ? strtr( $s, $pairs ) : str_ireplace( array_keys( $pairs ), array_values( $pairs ), $s );
	}

	private static function contains_object( $data ) {
		if ( is_object( $data ) ) {
			return true;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $v ) {
				if ( self::contains_object( $v ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
