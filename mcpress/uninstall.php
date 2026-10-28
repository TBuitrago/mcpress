<?php
/**
 * Removes every MCPress table, option and file. Keys stop working the moment the tables are gone.
 * Keep the data with define( 'MCPRESS_KEEP_DATA_ON_UNINSTALL', true ) in wp-config.php.
 *
 * @package MCPress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'MCPRESS_KEEP_DATA_ON_UNINSTALL' ) && MCPRESS_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

function mcpress_uninstall_site() {
	global $wpdb;
	foreach ( array( 'connections', 'tokens', 'clients', 'approvals', 'log' ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'mcpress_' . $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	$dir = get_option( 'mcpress_backup_dir' );
	if ( $dir && preg_match( '/^mcpress-backups-[a-z0-9]{20}$/', $dir ) ) {
		$path = trailingslashit( WP_CONTENT_DIR ) . $dir;
		// scandir() rather than glob( GLOB_BRACE ): that flag does not exist on musl-based PHP (Alpine).
		foreach ( (array) @scandir( $path ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_file( $path . '/' . $name ) ) {
				@unlink( $path . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	foreach ( array( 'mcpress_settings', 'mcpress_db_version', 'mcpress_update_status', 'mcpress_file_backups', 'mcpress_backup_dir' ) as $option ) {
		delete_option( $option );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mcpress\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mcpress\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_clear_scheduled_hook( 'mcpress_daily' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mcpress_blog ) {
		switch_to_blog( $mcpress_blog );
		mcpress_uninstall_site();
		restore_current_blog();
	}
} else {
	mcpress_uninstall_site();
}
delete_site_transient( 'mcpress_update_release' );
