<?php
/**
 * Approval queue. A request stores the exact tool and arguments; approving it runs
 * that exact call (as the connection's user) and keeps the result for the AI.
 *
 * Status: pending → approved → executed | failed
 *         pending → rejected | expired
 *
 * @package MCPress
 */

namespace MCPress\Permissions;

use MCPress\Auth\Connections;
use MCPress\Context;
use MCPress\MCP\Executor;
use MCPress\MCP\Tool_Registry;
use MCPress\Redactor;
use MCPress\Settings;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Approvals {

	const MAX_PENDING_PER_CONNECTION = 50;

	public static function init() {
		// Nothing to hook yet; maintenance runs from Plugin::daily_maintenance().
	}

	/**
	 * Queue a call. Identical pending requests from the same connection are reused.
	 *
	 * @return array|\WP_Error Approval row.
	 */
	public static function create( array $tool, array $args, Context $ctx, $risk ) {
		global $wpdb;
		$table     = Util::table( 'approvals' );
		$args_json = Util::json( $args );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE connection_id = %d AND user_id = %d AND tool = %s AND status = 'pending' AND expires_at > %s AND arguments = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ctx->connection_id,
				$ctx->user_id,
				$tool['name'],
				Util::now(),
				$args_json
			),
			ARRAY_A
		);
		if ( $existing ) {
			return $existing;
		}

		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE connection_id = %d AND user_id = %d AND status = 'pending'", $ctx->connection_id, $ctx->user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $pending >= self::MAX_PENDING_PER_CONNECTION ) {
			return new \WP_Error( 'too_many_pending', sprintf( 'There are already %d requests waiting for approval on this connection. Ask the user to review them before sending more.', $pending ), array( 'retry_ok' => false ) );
		}

		$row = array(
			'public_id'     => Util::random_id( 'apr_' ),
			'connection_id' => $ctx->connection_id,
			'user_id'       => $ctx->user_id,
			'tool'          => $tool['name'],
			'arguments'     => $args_json,
			'risk'          => $risk,
			'summary'       => self::summary( $tool, $args ),
			'status'        => 'pending',
			'created_at'    => Util::now(),
			'expires_at'    => Util::now( (int) Settings::get( 'approval_ttl_hours' ) * HOUR_IN_SECONDS ),
		);
		$wpdb->insert( $table, $row );
		$row['id'] = (int) $wpdb->insert_id;

		self::notify( $row, $ctx );
		do_action( 'mcpress_approval_requested', $row, $ctx );
		return $row;
	}

	/** What the AI receives when its call is queued. */
	public static function pending_payload( array $approval, Context $ctx ) {
		return array(
			'status'      => 'approval_required',
			'approval_id' => $approval['public_id'],
			'tool'        => $approval['tool'],
			'risk'        => $approval['risk'],
			'summary'     => $approval['summary'],
			'approve_url' => self::admin_url( $approval['public_id'] ),
			'expires_at'  => Util::iso( $approval['expires_at'] ),
			'message'     => sprintf(
				'Nothing has run yet. This connection is in "%s" mode, so the site owner must approve this %s action. Tell the user what you want to do and give them the approve_url. Then call check_approval with approval_id "%s" (wait_seconds up to 25; call again while it is still pending). Do not repeat the original call.',
				$ctx->mode,
				$approval['risk'],
				$approval['public_id']
			),
		);
	}

	public static function admin_url( $public_id = '' ) {
		$url = admin_url( 'admin.php?page=mcpress-approvals' );
		return $public_id ? add_query_arg( 'approval', rawurlencode( $public_id ), $url ) : $url;
	}

	public static function get( $public_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Util::table( 'approvals' ) . ' WHERE public_id = %s', (string) $public_id ), ARRAY_A );
	}

	/**
	 * @param array $filters status, connection_id, user_id
	 */
	public static function query( array $filters = array(), $limit = 50, $offset = 0 ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( isset( $filters['connection_id'] ) ) {
			$where[]  = 'connection_id = %d';
			$params[] = (int) $filters['connection_id'];
		}
		if ( isset( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		$params[] = (int) $limit;
		$params[] = (int) $offset;
		$sql      = 'SELECT * FROM ' . Util::table( 'approvals' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_pending() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Util::table( 'approvals' ) . " WHERE status = 'pending' AND expires_at > %s", Util::now() ) );
	}

	/**
	 * Approve and run.
	 *
	 * @param bool $always Also allow this tool for the connection from now on.
	 * @return array|\WP_Error Updated row.
	 */
	public static function approve( $public_id, $approver_id, $always = false ) {
		global $wpdb;
		$row = self::get( $public_id );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'That request does not exist.', 'mcpress' ) );
		}
		if ( 'pending' !== $row['status'] ) {
			return new \WP_Error( 'not_pending', __( 'That request was already decided.', 'mcpress' ) );
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			self::set_status( $row['id'], 'expired' );
			return new \WP_Error( 'expired', __( 'That request expired. Ask your AI to send it again.', 'mcpress' ) );
		}

		$ctx = self::context_for( $row );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$tool = Tool_Registry::instance()->get( $row['tool'] );
		if ( ! $tool ) {
			return new \WP_Error( 'tool_missing', __( 'The tool of this request is no longer available.', 'mcpress' ) );
		}

		// Claim it atomically so a double click cannot run it twice.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Util::table( 'approvals' ) . " SET status = 'approved', decided_at = %s, decided_by = %d WHERE id = %d AND status = 'pending'",
				Util::now(),
				(int) $approver_id,
				(int) $row['id']
			)
		);
		if ( 1 !== (int) $claimed ) {
			return new \WP_Error( 'not_pending', __( 'That request was already decided.', 'mcpress' ) );
		}

		if ( $always && $ctx->connection_id ) {
			Connections::allow_tool( $ctx->connection_id, $row['tool'] );
		}

		$args   = Util::json_decode_array( $row['arguments'] );
		$result = Executor::execute( $tool, $args, $ctx, $row['risk'], 'approved', (int) $row['id'] );

		$wpdb->update(
			Util::table( 'approvals' ),
			array(
				'status'      => empty( $result['isError'] ) ? 'executed' : 'failed',
				'result'      => Util::truncate( Util::json( $result ), 200000 ),
				'executed_at' => Util::now(),
			),
			array( 'id' => (int) $row['id'] )
		);

		do_action( 'mcpress_approval_decided', self::get( $public_id ), 'approved' );
		return self::get( $public_id );
	}

	public static function reject( $public_id, $approver_id, $note = '' ) {
		global $wpdb;
		$row = self::get( $public_id );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return new \WP_Error( 'not_pending', __( 'That request was already decided.', 'mcpress' ) );
		}
		$wpdb->update(
			Util::table( 'approvals' ),
			array(
				'status'     => 'rejected',
				'note'       => sanitize_textarea_field( (string) $note ),
				'decided_at' => Util::now(),
				'decided_by' => (int) $approver_id,
			),
			array( 'id' => (int) $row['id'] )
		);
		\MCPress\Audit_Log::record(
			array(
				'connection_id' => (int) $row['connection_id'],
				'user_id'       => (int) $row['user_id'],
				'tool'          => $row['tool'],
				'risk'          => $row['risk'],
				'decision'      => 'rejected',
				'status'        => 'rejected',
				'arguments'     => Util::json_decode_array( $row['arguments'] ),
				'result'        => $note,
				'approval_id'   => (int) $row['id'],
			)
		);
		do_action( 'mcpress_approval_decided', self::get( $public_id ), 'rejected' );
		return self::get( $public_id );
	}

	/** What check_approval returns to the AI. */
	public static function status_payload( array $row ) {
		$out = array(
			'approval_id' => $row['public_id'],
			'tool'        => $row['tool'],
			'status'      => $row['status'],
			'requested'   => Util::iso( $row['created_at'] ),
		);
		switch ( $row['status'] ) {
			case 'pending':
				if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
					$out['status']  = 'expired';
					$out['message'] = 'The request expired without a decision. Ask the user whether to send it again.';
				} else {
					$out['message']     = 'Still waiting for the site owner. Remind the user of the approve_url, then check again.';
					$out['approve_url'] = self::admin_url( $row['public_id'] );
					$out['expires_at']  = Util::iso( $row['expires_at'] );
				}
				break;
			case 'approved':
				$out['message'] = 'Approved; it is running right now. Check again in a few seconds.';
				break;
			case 'executed':
			case 'failed':
				$out['decided']  = Util::iso( $row['decided_at'] );
				$out['executed'] = Util::iso( $row['executed_at'] );
				$result          = Util::json_decode_array( $row['result'] );
				$text            = isset( $result['content'][0]['text'] ) ? $result['content'][0]['text'] : '';
				$decoded         = json_decode( $text, true );
				$out['result']   = null === $decoded ? $text : $decoded;
				$out['message']  = 'executed' === $row['status'] ? 'The owner approved it and it ran. The result is included.' : 'The owner approved it but the tool failed. The error is included.';
				break;
			case 'rejected':
				$out['decided'] = Util::iso( $row['decided_at'] );
				$out['note']    = (string) $row['note'];
				$out['message'] = 'The site owner rejected this request. Do not try to achieve the same result another way; ask the user how they want to proceed.';
				break;
			case 'expired':
				$out['message'] = 'The request expired without a decision.';
				break;
		}
		return $out;
	}

	/** Preview for the approval screen, computed now (state may have changed since the request). */
	public static function preview( array $row ) {
		$tool = Tool_Registry::instance()->get( $row['tool'] );
		if ( ! $tool || ! is_callable( $tool['preview'] ) ) {
			return null;
		}
		$ctx = self::context_for( $row );
		if ( is_wp_error( $ctx ) ) {
			return null;
		}
		$previous = get_current_user_id();
		wp_set_current_user( $ctx->user_id );
		try {
			$preview = call_user_func( $tool['preview'], Util::json_decode_array( $row['arguments'] ), $ctx );
		} catch ( \Throwable $e ) {
			$preview = 'Preview failed: ' . $e->getMessage();
		}
		wp_set_current_user( $previous );
		return is_wp_error( $preview ) ? $preview->get_error_message() : Redactor::redact( $preview );
	}

	/** @return Context|\WP_Error */
	public static function context_for( array $row ) {
		if ( (int) $row['connection_id'] > 0 ) {
			$connection = Connections::get( $row['connection_id'] );
			if ( ! Connections::is_active( $connection ) ) {
				return new \WP_Error( 'connection_revoked', __( 'The connection that sent this request was revoked or expired.', 'mcpress' ) );
			}
			return Context::from_connection( $connection, 'approval' );
		}
		if ( ! get_userdata( (int) $row['user_id'] ) ) {
			return new \WP_Error( 'user_missing', __( 'The user of this request no longer exists.', 'mcpress' ) );
		}
		return new Context( null, (int) $row['user_id'], Settings::get( 'default_mode' ), 'app_password' );
	}

	public static function expire_old() {
		global $wpdb;
		$table = Util::table( 'approvals' );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'expired' WHERE status = 'pending' AND expires_at < %s", Util::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$days = max( 30, (int) Settings::get( 'log_retention_days' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE status <> 'pending' AND created_at < %s", Util::now( -$days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( Util::table( 'approvals' ), array( 'status' => $status ), array( 'id' => (int) $id ) );
	}

	/** One line that tells a human what is being asked. */
	private static function summary( array $tool, array $args ) {
		$bits = array();
		foreach ( $args as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$v      = (string) $v;
				$bits[] = $k . '=' . ( mb_strlen( $v ) > 60 ? mb_substr( $v, 0, 60 ) . '…' : $v );
			} else {
				$bits[] = $k . '=[…]';
			}
			if ( count( $bits ) >= 4 ) {
				break;
			}
		}
		$line = $tool['title'] . ( $bits ? ' (' . implode( ', ', $bits ) . ')' : '' );
		return Redactor::enabled() ? Redactor::redact_text( $line ) : $line;
	}

	private static function notify( array $row, Context $ctx ) {
		if ( ! Settings::get( 'notify_email' ) ) {
			return;
		}
		$to = Settings::get( 'notify_email_to' );
		$to = $to ? $to : get_option( 'admin_email' );
		/* translators: 1: site name, 2: action summary. */
		$subject = sprintf( __( '[%1$s] Your AI is waiting for approval: %2$s', 'mcpress' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $row['summary'] );
		$body    = sprintf(
			/* translators: 1: connection name, 2: summary, 3: risk, 4: URL, 5: expiry. */
			__( "The connection \"%1\$s\" asked to run:\n\n%2\$s\nRisk: %3\$s\n\nReview it here:\n%4\$s\n\nIt expires on %5\$s.", 'mcpress' ),
			$ctx->name,
			$row['summary'],
			Policy::risk_label( $row['risk'] ),
			self::admin_url( $row['public_id'] ),
			Util::local( $row['expires_at'] )
		);
		wp_mail( $to, $subject, $body );
	}
}
