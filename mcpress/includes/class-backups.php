<?php
/**
 * Copies of files taken before the AI overwrites or deletes them.
 *
 * Stored in wp-content/mcpress-backups-<random>/ with an index of entries in an option.
 * The random suffix keeps the folder unguessable on servers that ignore .htaccess (nginx).
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Backups {

	const INDEX_OPTION = 'mcpress_file_backups';
	const DIR_OPTION   = 'mcpress_backup_dir';
	const MAX_ENTRIES  = 200;
	const MAX_DAYS     = 30;
	const MAX_BYTES    = 8388608; // 8 MB per file.

	public static function dir() {
		$name = get_option( self::DIR_OPTION );
		if ( ! $name || ! preg_match( '/^mcpress-backups-[a-z0-9]{20}$/', $name ) ) {
			$name = 'mcpress-backups-' . strtolower( wp_generate_password( 20, false, false ) );
			update_option( self::DIR_OPTION, $name, false );
		}
		$dir = trailingslashit( WP_CONTENT_DIR ) . $name;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:disable WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			// phpcs:enable
		}
		return $dir;
	}

	/**
	 * Back up a file before changing it.
	 *
	 * @param string $abs_path Absolute path.
	 * @param string $reason   write | edit | delete | restore
	 * @return array|null Entry, or null if the file does not exist (new file).
	 */
	public static function take( $abs_path, $reason ) {
		if ( ! is_file( $abs_path ) ) {
			return null;
		}
		$size = filesize( $abs_path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return null;
		}
		$id   = gmdate( 'Ymd-His' ) . '-' . Util::random_id( '', 4 );
		$dest = self::dir() . '/' . $id . '.bak';
		if ( ! @copy( $abs_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		$entry = array(
			'id'      => $id,
			'path'    => self::relative( $abs_path ),
			'size'    => (int) $size,
			'sha256'  => hash_file( 'sha256', $dest ),
			'reason'  => $reason,
			'created' => Util::now(),
			'user_id' => get_current_user_id(),
		);
		$index = self::index();
		array_unshift( $index, $entry );
		self::save_index( $index );
		return $entry;
	}

	public static function index() {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	public static function list_for( $relative_path = '' ) {
		$out = array();
		foreach ( self::index() as $entry ) {
			if ( '' === $relative_path || $entry['path'] === ltrim( $relative_path, '/' ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	public static function get( $id ) {
		foreach ( self::index() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	public static function content( $id ) {
		$file = self::dir() . '/' . basename( $id ) . '.bak';
		return is_file( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public static function cleanup() {
		$index  = self::index();
		$cutoff = time() - self::MAX_DAYS * DAY_IN_SECONDS;
		$keep   = array();
		foreach ( $index as $i => $entry ) {
			if ( $i < self::MAX_ENTRIES && strtotime( $entry['created'] . ' UTC' ) >= $cutoff ) {
				$keep[] = $entry;
			} else {
				@unlink( self::dir() . '/' . basename( $entry['id'] ) . '.bak' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		if ( count( $keep ) !== count( $index ) ) {
			self::save_index( $keep );
		}
	}

	private static function save_index( array $index ) {
		if ( count( $index ) > self::MAX_ENTRIES ) {
			foreach ( array_slice( $index, self::MAX_ENTRIES ) as $old ) {
				@unlink( self::dir() . '/' . basename( $old['id'] ) . '.bak' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$index = array_slice( $index, 0, self::MAX_ENTRIES );
		}
		update_option( self::INDEX_OPTION, $index, false );
	}

	public static function relative( $abs_path ) {
		return ltrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $abs_path ) ), '/' );
	}

	/** Remove the folder and index (uninstall). */
	public static function destroy() {
		$name = get_option( self::DIR_OPTION );
		if ( $name && preg_match( '/^mcpress-backups-[a-z0-9]{20}$/', $name ) ) {
			$dir = trailingslashit( WP_CONTENT_DIR ) . $name;
			foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( is_file( $dir . '/' . $name ) ) {
					@unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		delete_option( self::DIR_OPTION );
		delete_option( self::INDEX_OPTION );
	}
}
