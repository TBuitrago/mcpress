<?php
/**
 * Activity log: every MCP tool call, whatever the outcome.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Audit_Log {

	const MAX_ARGS   = 6000;
	const MAX_RESULT = 3000;

	public static function init() {
		add_action( 'deleted_user', array( 'MCPress\\Auth\\Connections', 'revoke_for_user' ) );
		add_action( 'remove_user_role', array( __CLASS__, 'on_role_removed' ), 10, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'on_role_set' ), 10, 3 );
	}

	public static function record( array $entry ) {
		global $wpdb;

		$args = isset( $entry['arguments'] ) ? $entry['arguments'] : array();
		if ( ! Settings::get( 'log_arguments' ) ) {
			$args = array( '_omitted' => true );
		}
		$result = isset( $entry['result'] ) ? $entry['result'] : '';

		$wpdb->insert(
			Util::table( 'log' ),
			array(
				'created_at'    => Util::now(),
				'connection_id' => empty( $entry['connection_id'] ) ? null : (int) $entry['connection_id'],
				'user_id'       => isset( $entry['user_id'] ) ? (int) $entry['user_id'] : get_current_user_id(),
				'tool'          => substr( (string) ( isset( $entry['tool'] ) ? $entry['tool'] : '' ), 0, 100 ),
				'risk'          => substr( (string) ( isset( $entry['risk'] ) ? $entry['risk'] : '' ), 0, 20 ),
				'decision'      => substr( (string) ( isset( $entry['decision'] ) ? $entry['decision'] : '' ), 0, 20 ),
				'status'        => substr( (string) ( isset( $entry['status'] ) ? $entry['status'] : '' ), 0, 20 ),
				'arguments'     => Util::truncate( Util::json( Redactor::redact( $args, true ) ), self::MAX_ARGS ),
				'result'        => Util::truncate( is_string( $result ) ? Redactor::redact_text( $result ) : Util::json( Redactor::redact( $result, true ) ), self::MAX_RESULT ),
				'duration_ms'   => isset( $entry['duration_ms'] ) ? max( 0, (int) $entry['duration_ms'] ) : 0,
				'ip'            => substr( Util::client_ip(), 0, 64 ),
				'approval_id'   => empty( $entry['approval_id'] ) ? null : (int) $entry['approval_id'],
			)
		);
	}

	/**
	 * @param array $filters connection_id, tool, decision, status, search
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $filters = array(), $per_page = 50, $page = 1 ) {
		global $wpdb;
		$table  = Util::table( 'log' );
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'tool', 'decision', 'status', 'risk' ) as $f ) {
			if ( ! empty( $filters[ $f ] ) ) {
				$where[]  = "$f = %s";
				$params[] = $filters[ $f ];
			}
		}
		if ( ! empty( $filters['connection_id'] ) ) {
			$where[]  = 'connection_id = %d';
			$params[] = (int) $filters['connection_id'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(arguments LIKE %s OR result LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		$sql    = "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( (int) $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	public static function distinct_tools() {
		global $wpdb;
		return (array) $wpdb->get_col( 'SELECT DISTINCT tool FROM ' . Util::table( 'log' ) . ' ORDER BY tool' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function purge( $days ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Util::table( 'log' ) . ' WHERE created_at < %s', Util::now( -max( 1, (int) $days ) * DAY_IN_SECONDS ) ) );
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Util::table( 'log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function on_role_removed( $user_id, $role ) {
		self::recheck_user( $user_id );
	}

	public static function on_role_set( $user_id, $role, $old_roles ) {
		self::recheck_user( $user_id );
	}

	/** A user who can no longer connect an AI loses their connections. */
	private static function recheck_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && ! user_can( $user, Auth\Authenticator::connect_capability() ) ) {
			Auth\Connections::revoke_for_user( $user_id );
		}
	}
}
