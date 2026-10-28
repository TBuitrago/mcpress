<?php
/**
 * Secrets: API keys, OAuth access/refresh tokens and authorization codes.
 * Only the SHA-256 of each secret is stored.
 *
 * @package MCPress
 */

namespace MCPress\Auth;

use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Tokens {

	const PREFIXES = array(
		'key'     => 'mcpk_',
		'access'  => 'mcpa_',
		'refresh' => 'mcpr_',
		'code'    => 'mcpc_',
	);

	/**
	 * Create a secret and store its hash.
	 *
	 * @return string The plain secret (shown once, never stored).
	 */
	public static function issue( $kind, $connection_id, $ttl_seconds = null, array $data = array() ) {
		global $wpdb;
		$token = Util::random_token( self::PREFIXES[ $kind ] );
		$wpdb->insert(
			Util::table( 'tokens' ),
			array(
				'connection_id' => $connection_id ? (int) $connection_id : null,
				'kind'          => $kind,
				'token_hash'    => Util::hash_token( $token ),
				'data'          => $data ? Util::json( $data ) : null,
				'created_at'    => Util::now(),
				'expires_at'    => $ttl_seconds ? Util::now( $ttl_seconds ) : null,
			)
		);
		return $token;
	}

	/**
	 * Find a live (not expired) secret of one of the given kinds.
	 *
	 * @return array|null Row with decoded 'data'.
	 */
	public static function find( $token, array $kinds ) {
		global $wpdb;
		if ( ! is_string( $token ) || strlen( $token ) < 20 || strlen( $token ) > 200 ) {
			return null;
		}
		$kind = self::kind_of( $token );
		if ( ! $kind || ! in_array( $kind, $kinds, true ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Util::table( 'tokens' ) . ' WHERE token_hash = %s AND kind = %s LIMIT 1',
				Util::hash_token( $token ),
				$kind
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		if ( $row['expires_at'] && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		$row['data'] = Util::json_decode_array( $row['data'] );
		return $row;
	}

	public static function kind_of( $token ) {
		foreach ( self::PREFIXES as $kind => $prefix ) {
			if ( 0 === strpos( (string) $token, $prefix ) ) {
				return $kind;
			}
		}
		return null;
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( Util::table( 'tokens' ), array( 'id' => (int) $id ) );
	}

	/** Delete by secret. Returns true if a row was removed (used to make codes single-use). */
	public static function delete_by_token( $token ) {
		global $wpdb;
		return (bool) $wpdb->delete( Util::table( 'tokens' ), array( 'token_hash' => Util::hash_token( $token ) ) );
	}

	public static function delete_for_connection( $connection_id, $kinds = null ) {
		global $wpdb;
		$table = Util::table( 'tokens' );
		if ( $kinds ) {
			$in = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE connection_id = %d AND kind IN ($in)", array_merge( array( (int) $connection_id ), $kinds ) ) );
		} else {
			$wpdb->delete( $table, array( 'connection_id' => (int) $connection_id ) );
		}
	}

	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Util::table( 'tokens' ) . ' WHERE expires_at IS NOT NULL AND expires_at < %s', Util::now() ) );
	}
}
