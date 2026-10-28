<?php
/**
 * Files inside the WordPress installation.
 *
 * - Paths are relative to the WordPress root (ABSPATH); wp-content may live elsewhere (WP_CONTENT_DIR).
 * - Writes take a backup first; PHP is syntax-checked; after changing a PHP file or .htaccess the
 *   site is probed and the change is rolled back automatically if the site stops answering.
 * - MCPress' own folder cannot be written; its backup folder is reachable only through the backup tools.
 * - DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT are respected.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\Backups;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Tools\Helpers as H;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Files {

	const MAX_READ   = 524288;  // 512 KB per read.
	const MAX_WRITE  = 5242880; // 5 MB per write.
	const PHP_EXT    = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'inc' );
	const SKIP_DIRS  = array( '.git', '.svn', 'node_modules', '.idea', '.vscode' );

	public static function register( Tool_Registry $r ) {
		$path = H::str( 'Path relative to the WordPress root, e.g. wp-content/themes/mytheme/functions.php.' );

		$r->register(
			array(
				'name'         => 'list_files',
				'title'        => 'List files',
				'group'        => 'files',
				'risk'         => Policy::RISK_READ,
				'description'  => 'List a directory of the WordPress installation (optionally recursive), with size and modification time.',
				'input_schema' => H::schema(
					array(
						'path'      => H::str( 'Directory relative to the WordPress root.', array( 'default' => 'wp-content' ) ),
						'recursive' => H::bool( 'Include subdirectories.', false ),
						'depth'     => H::int( 'Max depth when recursive (1-6).', array( 'minimum' => 1, 'maximum' => 6, 'default' => 3 ) ),
						'pattern'   => H::str( 'Only names matching this glob, e.g. *.php.' ),
					)
				),
				'callback'     => array( __CLASS__, 'list_files' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'read_file',
				'title'        => 'Read a file',
				'group'        => 'files',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Read a text file (whole or a line range) with its SHA-256. Binary files come back as metadata, or base64 when asked. wp-config.php and .env are blocked while sensitive-data protection is on.',
				'input_schema' => H::schema(
					array(
						'path'       => $path,
						'start_line' => H::int( 'First line (1-based).', array( 'minimum' => 1 ) ),
						'end_line'   => H::int( 'Last line (inclusive).', array( 'minimum' => 1 ) ),
						'as_base64'  => H::bool( 'Return binary content as base64 (≤ 512 KB).', false ),
					),
					array( 'path' )
				),
				'callback'     => array( __CLASS__, 'read_file' ),
				'guard'        => array( __CLASS__, 'guard_read' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'search_files',
				'title'        => 'Search in files',
				'group'        => 'files',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Find text (or a regular expression) inside files of a directory. Returns file, line number and the line.',
				'input_schema' => H::schema(
					array(
						'query'          => H::str( 'Text to find (or PCRE pattern without delimiters when regex=true).' ),
						'path'           => H::str( 'Directory to search.', array( 'default' => 'wp-content/themes' ) ),
						'regex'          => H::bool( 'Treat query as a regular expression.', false ),
						'case_sensitive' => H::bool( 'Case sensitive.', false ),
						'extensions'     => H::arr( 'string', 'Only these extensions (default: common text files).' ),
						'max_results'    => H::int( 'Max matches (1-500).', array( 'minimum' => 1, 'maximum' => 500, 'default' => 100 ) ),
					),
					array( 'query' )
				),
				'callback'     => array( __CLASS__, 'search_files' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'write_file',
				'title'        => 'Write a file',
				'group'        => 'files',
				'risk'         => array( __CLASS__, 'write_risk' ),
				'base_risk'    => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Create or overwrite a file. A backup is taken first, PHP is syntax-checked, and after changing PHP or .htaccess the site is probed and the change rolled back if it breaks. wp-config.php, .htaccess, core files, mu-plugins and drop-ins are critical. Prefer edit_file for small changes.',
				'input_schema' => H::schema(
					array(
						'path'            => $path,
						'content'         => H::str( 'Full new content.' ),
						'encoding'        => H::enum( array( 'utf8', 'base64' ), 'Encoding of content.', 'utf8' ),
						'create_dirs'     => H::bool( 'Create missing parent directories.', true ),
						'expected_sha256' => H::str( 'Only write if the current file still has this SHA-256 (from read_file).' ),
					),
					array( 'path', 'content' )
				),
				'callback'     => array( __CLASS__, 'write_file' ),
				'guard'        => array( __CLASS__, 'guard_write' ),
				'preview'      => array( __CLASS__, 'preview_write' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'edit_file',
				'title'        => 'Edit a file',
				'group'        => 'files',
				'risk'         => array( __CLASS__, 'write_risk' ),
				'base_risk'    => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Replace an exact, unique piece of text in a file (replace_all for every occurrence). Same safety net as write_file: backup, PHP syntax check, health probe with automatic rollback.',
				'input_schema' => H::schema(
					array(
						'path'        => $path,
						'old_text'    => H::str( 'Exact text to replace.' ),
						'new_text'    => H::str( 'Replacement.' ),
						'replace_all' => H::bool( 'Replace every occurrence.', false ),
					),
					array( 'path', 'old_text', 'new_text' )
				),
				'callback'     => array( __CLASS__, 'edit_file' ),
				'guard'        => array( __CLASS__, 'guard_write' ),
				'preview'      => array( __CLASS__, 'preview_edit' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_file',
				'title'        => 'Delete a file',
				'group'        => 'files',
				'risk'         => array( __CLASS__, 'write_risk' ),
				'base_risk'    => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Delete one file (a backup is kept; restore_file_backup brings it back). Directories are not deleted.',
				'input_schema' => H::schema( array( 'path' => $path ), array( 'path' ) ),
				'callback'     => array( __CLASS__, 'delete_file' ),
				'guard'        => array( __CLASS__, 'guard_write' ),
				'preview'      => array( __CLASS__, 'preview_write' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'list_file_backups',
				'title'        => 'List file backups',
				'group'        => 'files',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Backups MCPress took before files were changed or deleted (kept 30 days, up to 200).',
				'input_schema' => H::schema( array( 'path' => H::str( 'Only backups of this file.' ) ) ),
				'callback'     => array( __CLASS__, 'list_backups' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'restore_file_backup',
				'title'        => 'Restore a file backup',
				'group'        => 'files',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Put a backed-up version of a file back in place (the current version is backed up first).',
				'input_schema' => H::schema( array( 'backup_id' => H::str( 'ID from list_file_backups.' ) ), array( 'backup_id' ) ),
				'callback'     => array( __CLASS__, 'restore_backup' ),
				'guard'        => array( __CLASS__, 'guard_restore' ),
				'capability'   => 'manage_options',
			)
		);
	}

	/* ------------------------------------------------------------- Paths */

	public static function roots() {
		$roots = array( wp_normalize_path( untrailingslashit( realpath( ABSPATH ) ) ) );
		$wpc   = realpath( WP_CONTENT_DIR );
		if ( $wpc && ! Util::path_inside( $wpc, $roots[0] ) ) {
			$roots[] = wp_normalize_path( $wpc );
		}
		return $roots;
	}

	/**
	 * Absolute, normalised path inside the installation.
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve( $path, $must_exist = true ) {
		$path = str_replace( '\\', '/', trim( (string) $path ) );
		if ( false !== strpos( $path, "\0" ) ) {
			return H::error( 'invalid_path', 'Invalid path.' );
		}
		$roots = self::roots();
		$abs   = '';
		if ( '' !== $path && '/' === $path[0] ) {
			foreach ( $roots as $root ) {
				if ( Util::path_inside( $path, $root ) ) {
					$abs = $path;
				}
			}
		}
		if ( '' === $abs ) {
			$abs = $roots[0] . '/' . ltrim( '.' === $path ? '' : $path, '/' );
		}
		$abs = wp_normalize_path( $abs );

		if ( file_exists( $abs ) ) {
			$real = wp_normalize_path( realpath( $abs ) );
		} else {
			if ( $must_exist ) {
				return H::error( 'not_found', sprintf( 'No such file or directory: %s', $path ), 'Use list_files to check the path.' );
			}
			// Walk up to the first existing ancestor so symlinks cannot be used to escape.
			$parent = dirname( $abs );
			$rest   = basename( $abs );
			while ( ! file_exists( $parent ) && strlen( $parent ) > 1 ) {
				$rest   = basename( $parent ) . '/' . $rest;
				$parent = dirname( $parent );
			}
			$real_parent = realpath( $parent );
			if ( ! $real_parent ) {
				return H::error( 'invalid_path', 'Invalid path.' );
			}
			if ( preg_match( '#(^|/)\.\.(/|$)#', $rest ) ) {
				return H::error( 'invalid_path', 'Paths may not contain "..".' );
			}
			$real = wp_normalize_path( $real_parent ) . '/' . $rest;
		}
		foreach ( $roots as $root ) {
			if ( Util::path_inside( $real, $root ) ) {
				return $real;
			}
		}
		return H::error( 'outside_wordpress', 'Only files inside the WordPress installation are reachable.', null, false );
	}

	public static function rel( $abs ) {
		foreach ( self::roots() as $i => $root ) {
			if ( Util::path_inside( $abs, $root ) ) {
				$r = ltrim( substr( wp_normalize_path( $abs ), strlen( $root ) ), '/' );
				return 0 === $i ? $r : 'wp-content/' . $r;
			}
		}
		return $abs;
	}

	private static function in_backups( $abs ) {
		return Util::path_inside( $abs, wp_normalize_path( realpath( Backups::dir() ) ? realpath( Backups::dir() ) : Backups::dir() ) );
	}

	private static function in_mcpress( $abs ) {
		$dir = realpath( MCPRESS_DIR );
		return $dir && Util::path_inside( $abs, wp_normalize_path( $dir ) );
	}

	public static function is_sensitive( $abs ) {
		$base = strtolower( basename( $abs ) );
		if ( in_array( $base, array( 'wp-config.php', '.htpasswd', 'auth.json', 'id_rsa', 'id_ed25519', '.my.cnf', '.pgpass', '.npmrc', '.netrc' ), true ) ) {
			return true;
		}
		if ( 0 === strpos( $base, '.env' ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(pem|key|p12|pfx|crt|jks|keystore)$/', $base );
	}

	/** wp-config, server config, core files, mu-plugins and drop-ins. */
	public static function is_critical_path( $abs ) {
		$rel  = self::rel( $abs );
		$base = strtolower( basename( $abs ) );
		if ( in_array( $base, array( 'wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'web.config', 'nginx.conf' ), true ) ) {
			return true;
		}
		if ( in_array( $rel, array( 'index.php', 'wp-settings.php', 'wp-load.php', 'wp-blog-header.php', 'wp-login.php', 'xmlrpc.php', 'wp-cron.php' ), true ) ) {
			return true;
		}
		if ( 0 === strpos( $rel, 'wp-admin/' ) || 0 === strpos( $rel, 'wp-includes/' ) ) {
			return true;
		}
		$content = ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $abs ) ), '/' );
		if ( 0 === strpos( $content, 'mu-plugins/' ) || in_array( $content, array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'sunrise.php', 'fatal-error-handler.php', 'php-error.php' ), true ) ) {
			return true;
		}
		return false;
	}

	private static function is_php( $abs ) {
		return in_array( strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) ), self::PHP_EXT, true );
	}

	/* ------------------------------------------------------------- Risks & guards */

	public static function write_risk( array $args ) {
		$abs = self::resolve( isset( $args['path'] ) ? $args['path'] : '', false );
		if ( is_wp_error( $abs ) ) {
			return Policy::RISK_DESTRUCTIVE;
		}
		return self::is_critical_path( $abs ) ? Policy::RISK_CRITICAL : Policy::RISK_DESTRUCTIVE;
	}

	public static function guard_read( array $args ) {
		$abs = self::resolve( $args['path'], true );
		if ( is_wp_error( $abs ) ) {
			return null; // The callback reports "not found".
		}
		if ( self::in_backups( $abs ) ) {
			return H::error( 'self_protection', 'MCPress backups are only reachable through list_file_backups / restore_file_backup.' );
		}
		if ( Settings::get( 'protect_sensitive' ) && self::is_sensitive( $abs ) ) {
			return H::error( 'sensitive_file', sprintf( '%s holds credentials and is blocked while sensitive-data protection is on.', basename( $abs ) ), 'If you need a constant, ask the user, or use site_health with include_debug_info for non-secret values.' );
		}
		return null;
	}

	public static function guard_write( array $args ) {
		$err = H::file_edit_error();
		if ( $err ) {
			return $err;
		}
		$abs = self::resolve( $args['path'], false );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( self::in_mcpress( $abs ) || self::in_backups( $abs ) ) {
			return H::error( 'self_protection', 'MCPress files cannot be changed by an AI.', 'Updates arrive automatically from GitHub.' );
		}
		return null;
	}

	public static function guard_restore( array $args ) {
		$err = H::file_edit_error();
		if ( $err ) {
			return $err;
		}
		$entry = Backups::get( (string) $args['backup_id'] );
		if ( $entry ) {
			return self::guard_write( array( 'path' => $entry['path'] ) );
		}
		return null;
	}

	/* ------------------------------------------------------------- Callbacks */

	public static function list_files( array $args ) {
		$abs = self::resolve( $args['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_dir( $abs ) ) {
			return H::error( 'not_a_directory', 'That path is a file.', 'Use read_file.' );
		}
		$out   = array();
		$limit = 1000;
		$depth = ! empty( $args['recursive'] ) ? (int) $args['depth'] : 1;
		self::walk( $abs, $depth, isset( $args['pattern'] ) ? $args['pattern'] : '', $out, $limit );
		return array(
			'path'      => self::rel( $abs ),
			'entries'   => $out,
			'truncated' => count( $out ) >= $limit,
		);
	}

	private static function walk( $dir, $depth, $pattern, array &$out, $limit ) {
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $items ) {
			return;
		}
		$backups = wp_normalize_path( (string) realpath( Backups::dir() ) );
		foreach ( $items as $name ) {
			if ( '.' === $name || '..' === $name || count( $out ) >= $limit ) {
				continue;
			}
			$abs = wp_normalize_path( $dir . '/' . $name );
			if ( $abs === $backups ) {
				continue;
			}
			$is_dir = is_dir( $abs );
			if ( '' === $pattern || $is_dir || fnmatch( $pattern, $name ) ) {
				if ( '' === $pattern || ! $is_dir ) {
					$out[] = array(
						'path'     => self::rel( $abs ),
						'type'     => $is_dir ? 'dir' : 'file',
						'size'     => $is_dir ? null : (int) @filesize( $abs ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
						'modified' => gmdate( 'Y-m-d H:i:s', (int) @filemtime( $abs ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
					);
				}
			}
			if ( $is_dir && $depth > 1 && ! in_array( $name, self::SKIP_DIRS, true ) && ! is_link( $abs ) ) {
				self::walk( $abs, $depth - 1, $pattern, $out, $limit );
			}
		}
	}

	public static function read_file( array $args ) {
		$abs = self::resolve( $args['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( is_dir( $abs ) ) {
			return H::error( 'is_directory', 'That path is a directory.', 'Use list_files.' );
		}
		if ( ! is_readable( $abs ) ) {
			return H::error( 'unreadable', 'The web server cannot read this file.' );
		}
		$size = (int) filesize( $abs );
		$head = (string) file_get_contents( $abs, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$base = array(
			'path'     => self::rel( $abs ),
			'size'     => $size,
			'modified' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $abs ) ),
			'sha256'   => hash_file( 'sha256', $abs ),
		);
		if ( false !== strpos( $head, "\0" ) ) {
			$base['binary'] = true;
			$base['mime']   = function_exists( 'mime_content_type' ) ? mime_content_type( $abs ) : null;
			if ( ! empty( $args['as_base64'] ) ) {
				if ( $size > self::MAX_READ ) {
					return H::error( 'too_large', 'Binary file larger than 512 KB.' );
				}
				$base['base64'] = base64_encode( (string) file_get_contents( $abs ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			return $base;
		}
		$start = isset( $args['start_line'] ) ? (int) $args['start_line'] : 0;
		$end   = isset( $args['end_line'] ) ? (int) $args['end_line'] : 0;
		if ( $start || $end ) {
			$lines = array();
			$fh    = fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$n     = 0;
			$bytes = 0;
			while ( $fh && false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				++$n;
				if ( $n < max( 1, $start ) ) {
					continue;
				}
				if ( $end && $n > $end ) {
					break;
				}
				$lines[] = $line;
				$bytes  += strlen( $line );
				if ( $bytes > self::MAX_READ ) {
					break;
				}
			}
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$base['lines']   = array( max( 1, $start ), max( 1, $start ) + count( $lines ) - 1 );
			$base['content'] = implode( '', $lines );
			return $base;
		}
		$content         = (string) file_get_contents( $abs, false, null, 0, self::MAX_READ ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$base['content'] = $content;
		if ( $size > self::MAX_READ ) {
			$base['truncated'] = 'Only the first 512 KB are shown. Use start_line/end_line to read the rest.';
		}
		return $base;
	}

	public static function search_files( array $args ) {
		$abs = self::resolve( $args['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		$exts  = ! empty( $args['extensions'] ) ? array_map( 'strtolower', (array) $args['extensions'] ) : array( 'php', 'js', 'mjs', 'ts', 'jsx', 'tsx', 'css', 'scss', 'less', 'html', 'htm', 'twig', 'json', 'txt', 'md', 'xml', 'svg', 'yml', 'yaml', 'po', 'pot', 'htaccess', 'ini', 'tpl' );
		$regex = ! empty( $args['regex'] );
		$ci    = empty( $args['case_sensitive'] );
		if ( $regex ) {
			$pattern = '#' . str_replace( '#', '\#', $args['query'] ) . '#' . ( $ci ? 'i' : '' ) . 'u';
			if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return H::error( 'invalid_regex', 'The regular expression is not valid.' );
			}
		}
		$max     = (int) $args['max_results'];
		$results = array();
		$scanned = 0;
		$backups = wp_normalize_path( (string) realpath( Backups::dir() ) );
		$sens    = (bool) Settings::get( 'protect_sensitive' );
		$it      = is_dir( $abs ) ? new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ),
				function ( $file ) use ( $backups ) {
					if ( $file->isDir() ) {
						return ! in_array( $file->getFilename(), self::SKIP_DIRS, true ) && wp_normalize_path( $file->getPathname() ) !== $backups;
					}
					return true;
				}
			)
		) : array( new \SplFileInfo( $abs ) );
		foreach ( $it as $file ) {
			if ( count( $results ) >= $max || $scanned >= 8000 ) {
				break;
			}
			$name = $file->getFilename();
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$ext  = '' === $ext ? ltrim( strtolower( $name ), '.' ) : $ext;
			if ( ! $file->isFile() || ! in_array( $ext, $exts, true ) || $file->getSize() > 1048576 ) {
				continue;
			}
			if ( $sens && self::is_sensitive( $file->getPathname() ) ) {
				continue;
			}
			++$scanned;
			$fh = @fopen( $file->getPathname(), 'rb' ); // phpcs:ignore
			if ( ! $fh ) {
				continue;
			}
			$n = 0;
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				++$n;
				$hit = $regex ? preg_match( $pattern, $line ) : ( $ci ? false !== stripos( $line, $args['query'] ) : false !== strpos( $line, $args['query'] ) );
				if ( $hit ) {
					$results[] = array(
						'file' => self::rel( wp_normalize_path( $file->getPathname() ) ),
						'line' => $n,
						'text' => mb_substr( rtrim( $line ), 0, 300 ),
					);
					if ( count( $results ) >= $max ) {
						break;
					}
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return array(
			'matches'       => $results,
			'files_scanned' => $scanned,
			'truncated'     => count( $results ) >= $max || $scanned >= 8000,
		);
	}

	public static function write_file( array $args ) {
		$abs = self::resolve( $args['path'], false );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		$content = (string) $args['content'];
		if ( 'base64' === $args['encoding'] ) {
			$content = base64_decode( $content, true );
			if ( false === $content ) {
				return H::error( 'invalid_input', 'content is not valid base64.' );
			}
		}
		if ( strlen( $content ) > self::MAX_WRITE ) {
			return H::error( 'too_large', 'Files over 5 MB cannot be written through MCP.' );
		}
		if ( ! empty( $args['expected_sha256'] ) && is_file( $abs ) && ! hash_equals( strtolower( $args['expected_sha256'] ), hash_file( 'sha256', $abs ) ) ) {
			return H::error( 'conflict', 'The file changed since you read it (SHA-256 mismatch). Nothing was written.', 'Read it again and redo your change.', true );
		}
		if ( is_dir( $abs ) ) {
			return H::error( 'is_directory', 'That path is a directory.' );
		}
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			if ( empty( $args['create_dirs'] ) ) {
				return H::error( 'no_directory', 'The parent directory does not exist.', 'Set create_dirs to true.' );
			}
			if ( ! wp_mkdir_p( $dir ) ) {
				return H::error( 'filesystem', 'Could not create the directory.' );
			}
		}
		return self::commit( $abs, $content, is_file( $abs ) ? 'write' : 'create' );
	}

	public static function edit_file( array $args ) {
		$abs = self::resolve( $args['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return H::error( 'not_a_file', 'That path is not a file.' );
		}
		$current = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$res     = H::exact_replace( $current, (string) $args['old_text'], (string) $args['new_text'], ! empty( $args['replace_all'] ), 'file' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out                 = self::commit( $abs, $res[0], 'edit' );
		if ( is_array( $out ) ) {
			$out['replacements'] = $res[1];
		}
		return $out;
	}

	/**
	 * Back up, validate, write, then probe the site and roll back if it broke.
	 *
	 * @return array|\WP_Error
	 */
	private static function commit( $abs, $content, $reason ) {
		if ( self::is_php( $abs ) ) {
			$syntax = self::php_syntax_error( $content );
			if ( $syntax ) {
				return H::error( 'php_syntax', 'PHP syntax error, nothing was written: ' . $syntax, 'Fix the code and send it again.', true );
			}
		}
		if ( is_file( $abs ) && ! is_writable( $abs ) ) {
			return H::error( 'not_writable', 'The web server cannot write this file (permissions).' );
		}
		$backup  = Backups::take( $abs, $reason );
		$written = file_put_contents( $abs, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return H::error( 'filesystem', 'Could not write the file.' );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $abs, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$out = array(
			'path'      => self::rel( $abs ),
			'bytes'     => (int) $written,
			'sha256'    => hash( 'sha256', $content ),
			'backup_id' => $backup ? $backup['id'] : null,
		);

		if ( self::is_php( $abs ) || '.htaccess' === basename( $abs ) ) {
			$health = self::probe_site();
			$out['site_check'] = $health['status'];
			if ( 'broken' === $health['status'] ) {
				if ( $backup ) {
					file_put_contents( $abs, Backups::content( $backup['id'] ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				} else {
					@unlink( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				if ( function_exists( 'opcache_invalidate' ) ) {
					@opcache_invalidate( $abs, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				return H::error( 'site_broken_rolled_back', 'The change made the site fail (' . $health['detail'] . '). It was rolled back automatically.', 'Check the code (read_error_log shows the PHP error) and try again.', true );
			}
		}
		return $out;
	}

	/** Syntax check without exec(): the PHP tokenizer in parse mode. */
	public static function php_syntax_error( $code ) {
		if ( ! function_exists( 'token_get_all' ) || ! defined( 'TOKEN_PARSE' ) ) {
			return null;
		}
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return $e->getMessage() . ' on line ' . $e->getLine();
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
		return null;
	}

	/**
	 * Ask the site for its front page and the REST API.
	 *
	 * @return array{status:string,detail:string} ok | broken | unverified
	 */
	public static function probe_site() {
		$targets = array( home_url( '/' ), rest_url( 'mcpress/v1/oauth/metadata' ) );
		foreach ( $targets as $url ) {
			$resp = wp_remote_get(
				add_query_arg( 'mcpress_probe', wp_rand(), $url ),
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => false,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return array( 'status' => 'unverified', 'detail' => 'the site could not be reached from itself: ' . $resp->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = (string) wp_remote_retrieve_body( $resp );
			if ( $code >= 500 || preg_match( '/(Fatal error|Parse error|critical error on this website)/i', substr( $body, 0, 20000 ) ) ) {
				return array( 'status' => 'broken', 'detail' => 'HTTP ' . $code . ' on ' . wp_parse_url( $url, PHP_URL_PATH ) );
			}
		}
		return array( 'status' => 'ok', 'detail' => '' );
	}

	public static function delete_file( array $args ) {
		$abs = self::resolve( $args['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return H::error( 'not_a_file', 'Only files can be deleted here.' );
		}
		$backup = Backups::take( $abs, 'delete' );
		if ( ! $backup && filesize( $abs ) <= Backups::MAX_BYTES ) {
			return H::error( 'backup_failed', 'Could not back the file up, so it was not deleted.' );
		}
		if ( ! @unlink( $abs ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return H::error( 'filesystem', 'Could not delete the file (permissions).' );
		}
		return array( 'deleted' => self::rel( $abs ), 'backup_id' => $backup ? $backup['id'] : null );
	}

	public static function list_backups( array $args ) {
		return array( 'backups' => Backups::list_for( isset( $args['path'] ) ? $args['path'] : '' ) );
	}

	public static function restore_backup( array $args ) {
		$entry = Backups::get( (string) $args['backup_id'] );
		if ( ! $entry ) {
			return H::not_found( 'Backup', $args['backup_id'] );
		}
		$content = Backups::content( $entry['id'] );
		if ( false === $content ) {
			return H::error( 'backup_missing', 'The backup file is gone.' );
		}
		$abs = self::resolve( $entry['path'], false );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_dir( dirname( $abs ) ) ) {
			wp_mkdir_p( dirname( $abs ) );
		}
		$out = self::commit( $abs, $content, 'restore' );
		if ( is_array( $out ) ) {
			$out['restored_from'] = $entry['id'];
		}
		return $out;
	}

	/* ------------------------------------------------------------- Previews */

	public static function preview_write( array $args ) {
		$abs = self::resolve( $args['path'], false );
		if ( is_wp_error( $abs ) ) {
			return $abs->get_error_message();
		}
		$exists = is_file( $abs );
		$out    = array(
			'path'     => self::rel( $abs ),
			'exists'   => $exists,
			'size_now' => $exists ? filesize( $abs ) : 0,
			'critical' => self::is_critical_path( $abs ),
		);
		if ( isset( $args['content'] ) ) {
			$out['new_size']      = strlen( (string) $args['content'] );
			$out['new_content']   = Util::truncate( (string) $args['content'], 3000 );
		} else {
			$out['action'] = 'delete (a backup is kept)';
		}
		return $out;
	}

	public static function preview_edit( array $args ) {
		$abs = self::resolve( $args['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs->get_error_message();
		}
		$current = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'path'        => self::rel( $abs ),
			'matches'     => substr_count( $current, (string) $args['old_text'] ),
			'before'      => H::context_snippet( $current, (string) $args['old_text'] ),
			'replacement' => $args['new_text'],
		);
	}
}
