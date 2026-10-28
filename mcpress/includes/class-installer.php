<?php
/**
 * Database tables, activation and upgrades.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Installer {

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::install();
				restore_current_blog();
			}
		} else {
			self::install();
		}
		set_transient( 'mcpress_activation_redirect', 1, 60 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'mcpress_daily' );
	}

	/** Runs on every load; installs or upgrades the schema for the current site when needed. */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'mcpress_db_version' ) !== MCPRESS_DB_VERSION ) {
			self::install();
		}
		if ( ! wp_next_scheduled( 'mcpress_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mcpress_daily' );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = array(
			'connections' => Util::table( 'connections' ),
			'tokens'      => Util::table( 'tokens' ),
			'clients'     => Util::table( 'clients' ),
			'approvals'   => Util::table( 'approvals' ),
			'log'         => Util::table( 'log' ),
		);

		dbDelta(
			"CREATE TABLE {$t['connections']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(20) NOT NULL DEFAULT 'key',
				mode varchar(20) NOT NULL DEFAULT 'approval',
				client_id varchar(191) DEFAULT NULL,
				key_prefix varchar(32) NOT NULL DEFAULT '',
				url_key tinyint(1) NOT NULL DEFAULT 0,
				allowed_tools longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				last_ip varchar(64) NOT NULL DEFAULT '',
				last_agent varchar(255) NOT NULL DEFAULT '',
				expires_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY client_id (client_id)
			) $charset;
			CREATE TABLE {$t['tokens']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connection_id bigint(20) unsigned DEFAULT NULL,
				kind varchar(20) NOT NULL,
				token_hash char(64) NOT NULL,
				data longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				expires_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY connection_id (connection_id),
				KEY expires_at (expires_at)
			) $charset;
			CREATE TABLE {$t['clients']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(191) NOT NULL,
				client_name varchar(191) NOT NULL DEFAULT '',
				redirect_uris longtext NOT NULL,
				metadata longtext DEFAULT NULL,
				created_ip varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id)
			) $charset;
			CREATE TABLE {$t['approvals']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(40) NOT NULL,
				connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tool varchar(100) NOT NULL,
				arguments longtext DEFAULT NULL,
				risk varchar(20) NOT NULL DEFAULT 'write',
				summary text DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				note text DEFAULT NULL,
				result longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				decided_at datetime DEFAULT NULL,
				decided_by bigint(20) unsigned DEFAULT NULL,
				executed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY status (status),
				KEY connection_id (connection_id)
			) $charset;
			CREATE TABLE {$t['log']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				connection_id bigint(20) unsigned DEFAULT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tool varchar(100) NOT NULL DEFAULT '',
				risk varchar(20) NOT NULL DEFAULT '',
				decision varchar(20) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT '',
				arguments longtext DEFAULT NULL,
				result longtext DEFAULT NULL,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				ip varchar(64) NOT NULL DEFAULT '',
				approval_id bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY connection_id (connection_id),
				KEY tool (tool)
			) $charset;"
		);

		update_option( 'mcpress_db_version', MCPRESS_DB_VERSION, false );
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
	}

	/** Every MCPress table name (used by guards and by uninstall). */
	public static function tables() {
		return array(
			Util::table( 'connections' ),
			Util::table( 'tokens' ),
			Util::table( 'clients' ),
			Util::table( 'approvals' ),
			Util::table( 'log' ),
		);
	}
}
