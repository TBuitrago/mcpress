<?php
/**
 * MCPress itself: what this connection may do, and the approvals it is waiting for.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\Context;
use MCPress\MCP\Server;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Approvals;
use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Tools\Helpers as H;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Mcpress {

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'mcpress_status',
				'title'        => 'Connection permissions',
				'group'        => 'mcpress',
				'risk'         => Policy::RISK_READ,
				'description'  => 'This connection\'s permission mode, what runs directly / needs approval / is refused for each risk level, tools always allowed for it, site rules, and approvals still pending.',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'status' ),
			)
		);
		$r->register(
			array(
				'name'         => 'check_approval',
				'title'        => 'Check an approval',
				'group'        => 'mcpress',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Status of a request that needed the site owner\'s approval. When it was approved, the action has already run and its result is included. wait_seconds (≤ 25) waits for a decision before answering.',
				'input_schema' => H::schema(
					array(
						'approval_id'  => H::str( 'The approval_id you received (apr_…).' ),
						'wait_seconds' => H::int( 'Wait up to this many seconds for a decision (0-25).', array( 'minimum' => 0, 'maximum' => 25, 'default' => 0 ) ),
					),
					array( 'approval_id' )
				),
				'callback'     => array( __CLASS__, 'check_approval' ),
			)
		);
		$r->register(
			array(
				'name'         => 'list_my_approvals',
				'title'        => 'My approval requests',
				'group'        => 'mcpress',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Requests this connection sent for approval, newest first.',
				'input_schema' => H::schema( array( 'status' => H::enum( array( 'pending', 'executed', 'failed', 'rejected', 'expired', 'any' ), 'Filter.', 'any' ) ) ),
				'callback'     => array( __CLASS__, 'list_mine' ),
			)
		);
	}

	public static function status( array $args, Context $ctx ) {
		$by_risk = array();
		foreach ( Policy::risks() as $risk ) {
			$by_risk[ $risk ] = Policy::MATRIX[ $ctx->mode ][ $risk ];
		}
		$pending = Approvals::query( array( 'status' => 'pending', 'connection_id' => $ctx->connection_id, 'user_id' => $ctx->user_id ), 20 );
		return array(
			'connection'      => $ctx->name,
			'via'             => $ctx->via,
			'mode'            => $ctx->mode,
			'mode_explained'  => Server::mode_for_ai( $ctx->mode ),
			'by_risk'         => $by_risk,
			'always_allowed'  => $ctx->allowed_tools(),
			'site_rules'      => Settings::get( 'tool_rules' ) ? Settings::get( 'tool_rules' ) : new \stdClass(),
			'disabled_groups' => array_values( (array) Settings::get( 'disabled_groups' ) ),
			'approval_ttl'    => (int) Settings::get( 'approval_ttl_hours' ) . ' hours',
			'pending'         => array_map(
				function ( $row ) {
					return array(
						'approval_id' => $row['public_id'],
						'tool'        => $row['tool'],
						'summary'     => $row['summary'],
						'requested'   => Util::iso( $row['created_at'] ),
					);
				},
				$pending
			),
			'change_mode'     => 'Only the site owner can change the mode, in wp-admin → MCPress → Connect.',
		);
	}

	private static function own( $public_id, Context $ctx ) {
		$row = Approvals::get( $public_id );
		if ( ! $row || (int) $row['connection_id'] !== (int) $ctx->connection_id || (int) $row['user_id'] !== (int) $ctx->user_id ) {
			return null;
		}
		return $row;
	}

	public static function check_approval( array $args, Context $ctx ) {
		$row = self::own( (string) $args['approval_id'], $ctx );
		if ( ! $row ) {
			return H::not_found( 'Approval request (for this connection)', $args['approval_id'] );
		}
		$wait  = max( 0, min( 25, (int) $args['wait_seconds'] ) );
		$until = microtime( true ) + $wait;
		while ( 'pending' === $row['status'] && microtime( true ) < $until && strtotime( $row['expires_at'] . ' UTC' ) > time() ) {
			usleep( 1000000 );
			$row = Approvals::get( $row['public_id'] );
		}
		// An approval runs right after it is claimed; give a running one a moment.
		$tries = 0;
		while ( 'approved' === $row['status'] && $tries < 10 ) {
			usleep( 500000 );
			$row = Approvals::get( $row['public_id'] );
			++$tries;
		}
		return Approvals::status_payload( $row );
	}

	public static function list_mine( array $args, Context $ctx ) {
		$filters = array( 'connection_id' => $ctx->connection_id, 'user_id' => $ctx->user_id );
		if ( 'any' !== $args['status'] ) {
			$filters['status'] = $args['status'];
		}
		return array_map(
			function ( $row ) {
				return array(
					'approval_id' => $row['public_id'],
					'tool'        => $row['tool'],
					'status'      => $row['status'],
					'summary'     => $row['summary'],
					'requested'   => Util::iso( $row['created_at'] ),
					'decided'     => Util::iso( $row['decided_at'] ),
				);
			},
			Approvals::query( $filters, 50 )
		);
	}
}
