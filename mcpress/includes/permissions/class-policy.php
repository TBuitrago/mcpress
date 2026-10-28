<?php
/**
 * Decides whether a tool call runs, waits for approval, or is refused.
 *
 * Precedence (first match wins):
 *  1. Tool disabled (group switched off or rule "deny").
 *  2. Read-only connection and the call is not a read.
 *  3. Site rule "ask".
 *  4. Site rule "allow".
 *  5. "Always allow" for this connection.
 *  6. The mode matrix.
 * Hard guards (lockout / self-protection) run before this, inside each tool.
 *
 * @package MCPress
 */

namespace MCPress\Permissions;

use MCPress\Context;
use MCPress\Settings;

defined( 'ABSPATH' ) || exit;

class Policy {

	const MODE_READONLY = 'readonly';
	const MODE_APPROVAL = 'approval';
	const MODE_AUTO     = 'auto';
	const MODE_FULL     = 'full';

	const RISK_READ        = 'read';
	const RISK_WRITE       = 'write';
	const RISK_DESTRUCTIVE = 'destructive';
	const RISK_CRITICAL    = 'critical';

	const ALLOW = 'allow';
	const ASK   = 'ask';
	const DENY  = 'deny';

	/** mode => risk => decision */
	const MATRIX = array(
		'readonly' => array(
			'read'        => 'allow',
			'write'       => 'deny',
			'destructive' => 'deny',
			'critical'    => 'deny',
		),
		'approval' => array(
			'read'        => 'allow',
			'write'       => 'ask',
			'destructive' => 'ask',
			'critical'    => 'ask',
		),
		'auto'     => array(
			'read'        => 'allow',
			'write'       => 'allow',
			'destructive' => 'allow',
			'critical'    => 'ask',
		),
		'full'     => array(
			'read'        => 'allow',
			'write'       => 'allow',
			'destructive' => 'allow',
			'critical'    => 'allow',
		),
	);

	public static function modes() {
		return array_keys( self::MATRIX );
	}

	public static function is_mode( $mode ) {
		return is_string( $mode ) && isset( self::MATRIX[ $mode ] );
	}

	public static function risks() {
		return array( self::RISK_READ, self::RISK_WRITE, self::RISK_DESTRUCTIVE, self::RISK_CRITICAL );
	}

	public static function risk_rank( $risk ) {
		$rank = array_search( $risk, self::risks(), true );
		return false === $rank ? 1 : (int) $rank;
	}

	public static function mode_label( $mode ) {
		$labels = array(
			self::MODE_READONLY => __( 'Read only', 'mcpress' ),
			self::MODE_APPROVAL => __( 'Prior approval', 'mcpress' ),
			self::MODE_AUTO     => __( 'Automatic', 'mcpress' ),
			self::MODE_FULL     => __( 'Full access', 'mcpress' ),
		);
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	public static function mode_description( $mode ) {
		$text = array(
			self::MODE_READONLY => __( 'Your AI can look at everything it is allowed to see, but it cannot change anything.', 'mcpress' ),
			self::MODE_APPROVAL => __( 'Your AI reads freely. Every change waits for you to approve it in wp-admin.', 'mcpress' ),
			self::MODE_AUTO     => __( 'Your AI makes changes on its own. Only critical actions (running PHP, editing wp-config.php, SQL writes, creating administrators, core updates) wait for you.', 'mcpress' ),
			self::MODE_FULL     => __( 'Your AI does everything without asking. Like a developer with the keys. Every action is still logged.', 'mcpress' ),
		);
		return isset( $text[ $mode ] ) ? $text[ $mode ] : '';
	}

	public static function risk_label( $risk ) {
		$labels = array(
			self::RISK_READ        => __( 'Read', 'mcpress' ),
			self::RISK_WRITE       => __( 'Write', 'mcpress' ),
			self::RISK_DESTRUCTIVE => __( 'Destructive', 'mcpress' ),
			self::RISK_CRITICAL    => __( 'Critical', 'mcpress' ),
		);
		return isset( $labels[ $risk ] ) ? $labels[ $risk ] : $risk;
	}

	/** Site-wide rule for a tool: '' (follow the mode), allow, ask or deny. */
	public static function tool_rule( $tool_name ) {
		$rules = (array) Settings::get( 'tool_rules' );
		return isset( $rules[ $tool_name ] ) ? $rules[ $tool_name ] : '';
	}

	public static function group_enabled( $group ) {
		return ! in_array( $group, (array) Settings::get( 'disabled_groups' ), true );
	}

	/**
	 * @param array   $tool Tool definition.
	 * @param string  $risk Risk of this specific call.
	 * @param Context $ctx  Caller.
	 * @return array{decision:string,reason:string}
	 */
	public static function decide( array $tool, $risk, Context $ctx ) {
		$name = $tool['name'];
		$rule = self::tool_rule( $name );

		if ( ! self::group_enabled( $tool['group'] ) || self::DENY === $rule ) {
			return self::result( self::DENY, 'tool_disabled' );
		}
		if ( self::MODE_READONLY === $ctx->mode && self::RISK_READ !== $risk ) {
			return self::result( self::DENY, 'read_only_mode' );
		}
		if ( self::ASK === $rule && self::RISK_READ !== $risk ) {
			return self::result( self::ASK, 'site_rule' );
		}
		if ( self::ALLOW === $rule ) {
			return self::result( self::ALLOW, 'site_rule' );
		}
		if ( in_array( $name, $ctx->allowed_tools(), true ) ) {
			return self::result( self::ALLOW, 'always_allowed' );
		}
		$decision = self::MATRIX[ $ctx->mode ][ $risk ];

		/**
		 * Filters the final decision for a tool call.
		 *
		 * @param string  $decision allow|ask|deny
		 * @param array   $tool
		 * @param string  $risk
		 * @param Context $ctx
		 */
		$decision = apply_filters( 'mcpress_policy_decision', $decision, $tool, $risk, $ctx );
		if ( ! in_array( $decision, array( self::ALLOW, self::ASK, self::DENY ), true ) ) {
			$decision = self::ASK;
		}
		return self::result( $decision, 'mode' );
	}

	/** What a tool will do for a connection without knowing the arguments (for tools/list). */
	public static function expected( array $tool, Context $ctx ) {
		return self::decide( $tool, $tool['base_risk'], $ctx )['decision'];
	}

	private static function result( $decision, $reason ) {
		return array(
			'decision' => $decision,
			'reason'   => $reason,
		);
	}
}
