<?php
/**
 * Connections: one per AI client (an API key or an OAuth grant), each with its own mode.
 *
 * @package MCPress
 */

namespace MCPress\Auth;

use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Connections {

	/**
	 * Create an API-key connection.
	 *
	 * @return array{id:int,key:string}
	 */
	public static function create_key( $name, $user_id, $mode, $expires_days = 0, $url_key = false ) {
		global $wpdb;
		$wpdb->insert(
			Util::table( 'connections' ),
			array(
				'name'       => self::clean_name( $name, __( 'API key', 'mcpress' ) ),
				'user_id'    => (int) $user_id,
				'type'       => 'key',
				'mode'       => Policy::is_mode( $mode ) ? $mode : Settings::get( 'default_mode' ),
				'url_key'    => $url_key ? 1 : 0,
				'created_at' => Util::now(),
				'expires_at' => $expires_days > 0 ? Util::now( (int) $expires_days * DAY_IN_SECONDS ) : null,
			)
		);
		$id  = (int) $wpdb->insert_id;
		$key = Tokens::issue( 'key', $id, $expires_days > 0 ? (int) $expires_days * DAY_IN_SECONDS : null );
		$wpdb->update( Util::table( 'connections' ), array( 'key_prefix' => substr( $key, 0, 12 ) ), array( 'id' => $id ) );
		do_action( 'mcpress_connection_created', $id );
		return array(
			'id'  => $id,
			'key' => $key,
		);
	}

	/** Create an OAuth connection (called when an authorization code is exchanged). */
	public static function create_oauth( $user_id, $mode, $client_id, $client_name ) {
		global $wpdb;
		$wpdb->insert(
			Util::table( 'connections' ),
			array(
				'name'       => self::clean_name( $client_name, __( 'OAuth client', 'mcpress' ) ),
				'user_id'    => (int) $user_id,
				'type'       => 'oauth',
				'mode'       => Policy::is_mode( $mode ) ? $mode : Settings::get( 'default_mode' ),
				'client_id'  => substr( (string) $client_id, 0, 191 ),
				'created_at' => Util::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		do_action( 'mcpress_connection_created', $id );
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Util::table( 'connections' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	/** Active (not revoked) connections, newest first. */
	public static function all( $include_revoked = false ) {
		global $wpdb;
		$table = Util::table( 'connections' );
		$where = $include_revoked ? '' : 'WHERE revoked_at IS NULL';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM $table $where ORDER BY id DESC LIMIT 500", ARRAY_A );
	}

	public static function is_active( $connection ) {
		if ( ! $connection || ! empty( $connection['revoked_at'] ) ) {
			return false;
		}
		if ( ! empty( $connection['expires_at'] ) && strtotime( $connection['expires_at'] . ' UTC' ) < time() ) {
			return false;
		}
		return true;
	}

	public static function update( $id, array $fields ) {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'name', 'mode', 'url_key', 'allowed_tools', 'expires_at' ) ) );
		if ( isset( $allowed['mode'] ) && ! Policy::is_mode( $allowed['mode'] ) ) {
			unset( $allowed['mode'] );
		}
		if ( isset( $allowed['name'] ) ) {
			$allowed['name'] = self::clean_name( $allowed['name'], '' );
		}
		if ( ! $allowed ) {
			return false;
		}
		$ok = false !== $wpdb->update( Util::table( 'connections' ), $allowed, array( 'id' => (int) $id ) );
		do_action( 'mcpress_connection_updated', (int) $id, $allowed );
		return $ok;
	}

	public static function set_mode( $id, $mode ) {
		return self::update( $id, array( 'mode' => $mode ) );
	}

	public static function allow_tool( $id, $tool ) {
		$c = self::get( $id );
		if ( ! $c ) {
			return false;
		}
		$list   = Util::json_decode_array( $c['allowed_tools'] );
		$list[] = sanitize_key( $tool );
		return self::update( $id, array( 'allowed_tools' => Util::json( array_values( array_unique( $list ) ) ) ) );
	}

	public static function disallow_tool( $id, $tool ) {
		$c = self::get( $id );
		if ( ! $c ) {
			return false;
		}
		$list = array_values( array_diff( Util::json_decode_array( $c['allowed_tools'] ), array( $tool ) ) );
		return self::update( $id, array( 'allowed_tools' => Util::json( $list ) ) );
	}

	/** Revoke: kills every token at once. The row stays for the activity log. */
	public static function revoke( $id ) {
		global $wpdb;
		Tokens::delete_for_connection( $id );
		$wpdb->update( Util::table( 'connections' ), array( 'revoked_at' => Util::now() ), array( 'id' => (int) $id ) );
		do_action( 'mcpress_connection_revoked', (int) $id );
		return true;
	}

	public static function revoke_all() {
		foreach ( self::all() as $c ) {
			self::revoke( $c['id'] );
		}
	}

	/** Record use (throttled to one write per minute). */
	public static function touch( array $connection ) {
		global $wpdb;
		if ( $connection['last_used_at'] && strtotime( $connection['last_used_at'] . ' UTC' ) > time() - MINUTE_IN_SECONDS ) {
			return;
		}
		$wpdb->update(
			Util::table( 'connections' ),
			array(
				'last_used_at' => Util::now(),
				'last_ip'      => substr( Util::client_ip(), 0, 64 ),
				'last_agent'   => Util::user_agent(),
			),
			array( 'id' => (int) $connection['id'] )
		);
	}

	/** Revoke connections of a user that is deleted or loses admin rights. */
	public static function revoke_for_user( $user_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Util::table( 'connections' ) . ' WHERE user_id = %d AND revoked_at IS NULL', (int) $user_id ) );
		foreach ( $ids as $id ) {
			self::revoke( $id );
		}
	}

	private static function clean_name( $name, $fallback ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		return '' === $name ? $fallback : mb_substr( $name, 0, 120 );
	}
}
