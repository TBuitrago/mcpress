<?php
/**
 * Runs a tool call: validation → hard guards → policy → run now or queue for approval.
 *
 * @package MCPress
 */

namespace MCPress\MCP;

use MCPress\Audit_Log;
use MCPress\Context;
use MCPress\Permissions\Approvals;
use MCPress\Permissions\Policy;
use MCPress\Redactor;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Executor {

	/** JSON-RPC id of the tools/call in progress (used to answer even after a fatal error). */
	public static $current_request_id = null;

	/** @var array|null Tool call in progress: name, args, ctx, start, risk, decision, approval_id. */
	public static $running = null;

	/**
	 * Entry point for tools/call.
	 *
	 * @return array MCP CallToolResult.
	 */
	public static function call( $name, array $args, Context $ctx ) {
		$tool = Tool_Registry::instance()->get( $name );
		if ( ! $tool || ! Policy::group_enabled( $tool['group'] ) ) {
			return self::error_result( 'unknown_tool', sprintf( 'Unknown or disabled tool "%s". Call tools/list to see what this connection can use.', $name ) );
		}

		list( $args, $errors ) = Schema::validate( $args, $tool['input_schema'] );
		if ( $errors ) {
			return self::error_result( 'invalid_arguments', implode( ' ', $errors ), array( 'hint' => 'Fix the arguments according to the tool input schema and call again.' ) );
		}

		$risk = Tool_Registry::risk_for( $tool, $args );

		$previous_user = get_current_user_id();
		wp_set_current_user( $ctx->user_id );
		$guard = is_callable( $tool['guard'] ) ? call_user_func( $tool['guard'], $args, $ctx ) : null;
		wp_set_current_user( $previous_user );

		if ( is_wp_error( $guard ) ) {
			self::log( $tool, $args, $ctx, $risk, 'blocked', 'error', $guard->get_error_message() );
			return self::wp_error_result( $guard );
		}

		$decision = Policy::decide( $tool, $risk, $ctx );

		if ( Policy::DENY === $decision['decision'] ) {
			$message = self::deny_message( $decision['reason'], $tool, $risk, $ctx );
			self::log( $tool, $args, $ctx, $risk, 'denied', 'error', $message );
			return self::error_result( 'permission_denied', $message, array( 'retry_ok' => false ) );
		}

		if ( Policy::ASK === $decision['decision'] ) {
			$approval = Approvals::create( $tool, $args, $ctx, $risk );
			if ( is_wp_error( $approval ) ) {
				return self::wp_error_result( $approval );
			}
			self::log( $tool, $args, $ctx, $risk, 'pending', 'pending', $approval['public_id'], (int) $approval['id'] );
			return self::text_result( Approvals::pending_payload( $approval, $ctx ) );
		}

		return self::execute( $tool, $args, $ctx, $risk, 'allowed', null );
	}

	/**
	 * Run the tool now, as the connection's user.
	 *
	 * @param string   $decision allowed | approved
	 * @param int|null $approval_id
	 * @return array MCP CallToolResult.
	 */
	public static function execute( array $tool, array $args, Context $ctx, $risk, $decision, $approval_id ) {
		$start         = microtime( true );
		$previous_user = get_current_user_id();
		$previous_ctx  = Context::current();
		Context::set( $ctx );
		wp_set_current_user( $ctx->user_id );

		self::$running = compact( 'tool', 'args', 'ctx', 'risk', 'decision', 'approval_id', 'start' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		ob_start();
		try {
			if ( $tool['capability'] && ! current_user_can( $tool['capability'] ) ) {
				$out = new \WP_Error( 'capability', sprintf( 'The connected user lacks the "%s" capability needed by %s. Reconnecting with an account that has it is the only fix.', $tool['capability'], $tool['name'] ), array( 'retry_ok' => false ) );
			} else {
				// Guards run again right before execution: state may have changed since an approval was requested.
				$guard = is_callable( $tool['guard'] ) ? call_user_func( $tool['guard'], $args, $ctx ) : null;
				$out   = is_wp_error( $guard ) ? $guard : call_user_func( $tool['callback'], $args, $ctx );
			}
		} catch ( \Throwable $e ) {
			$out = new \WP_Error( 'exception', sprintf( '%s: %s (in %s:%d)', get_class( $e ), $e->getMessage(), self::short_path( $e->getFile() ), $e->getLine() ) );
		}
		$stray = ob_get_clean();

		self::$running = null;
		wp_set_current_user( $previous_user );
		Context::set( $previous_ctx );

		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $out ) ) {
			self::log( $tool, $args, $ctx, $risk, $decision, 'error', $out->get_error_message(), $approval_id, $duration );
			return self::wp_error_result( $out );
		}

		$out = Redactor::redact( $out );
		if ( is_array( $out ) && '' !== trim( (string) $stray ) && strlen( $stray ) < 4000 ) {
			$out['_stray_output'] = wp_strip_all_tags( $stray );
		}

		self::log( $tool, $args, $ctx, $risk, $decision, 'ok', $out, $approval_id, $duration );
		do_action( 'mcpress_tool_executed', $tool['name'], $args, $out, $ctx );

		return self::text_result( $out );
	}

	/** Called from the shutdown handler when PHP died inside a tool. */
	public static function fatal_result( array $error ) {
		$running = self::$running;
		$message = sprintf( 'PHP fatal error while running the tool: %s (in %s:%d). Nothing after this point ran.', $error['message'], self::short_path( $error['file'] ), $error['line'] );
		if ( $running ) {
			self::log( $running['tool'], $running['args'], $running['ctx'], $running['risk'], $running['decision'], 'error', $message, $running['approval_id'], (int) round( ( microtime( true ) - $running['start'] ) * 1000 ) );
		}
		return self::error_result( 'php_fatal', $message, array( 'retry_ok' => false ) );
	}

	private static function deny_message( $reason, array $tool, $risk, Context $ctx ) {
		switch ( $reason ) {
			case 'read_only_mode':
				return sprintf( 'This connection is READ ONLY, so "%s" (%s) cannot run. If the user wants changes, they must switch the connection mode in wp-admin → MCPress → Connect. Do not try other tools to make the same change.', $tool['name'], $risk );
			case 'tool_disabled':
				return sprintf( 'The site owner disabled "%s" in MCPress settings. Do not look for workarounds; ask the user if they want to enable it.', $tool['name'] );
			default:
				return sprintf( 'MCPress policy refused "%s". Ask the user before trying anything else.', $tool['name'] );
		}
	}

	public static function text_result( $data, $is_error = false ) {
		$text = is_string( $data ) ? $data : Util::json( $data );
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => (bool) $is_error,
		);
	}

	public static function error_result( $code, $message, array $extra = array() ) {
		return self::text_result( array_merge( array( 'error' => $code, 'message' => $message ), $extra ), true );
	}

	public static function wp_error_result( \WP_Error $error ) {
		$data  = $error->get_error_data();
		$extra = array();
		if ( is_array( $data ) ) {
			foreach ( array( 'hint', 'retry_ok', 'details' ) as $k ) {
				if ( isset( $data[ $k ] ) ) {
					$extra[ $k ] = $data[ $k ];
				}
			}
			if ( isset( $data['status'] ) && empty( $extra ) ) {
				$extra['http_status'] = $data['status'];
			}
		}
		$messages = $error->get_error_messages();
		return self::error_result( $error->get_error_code(), implode( ' ', $messages ), $extra );
	}

	private static function log( array $tool, array $args, Context $ctx, $risk, $decision, $status, $result, $approval_id = null, $duration = 0 ) {
		Audit_Log::record(
			array(
				'connection_id' => $ctx->connection_id,
				'user_id'       => $ctx->user_id,
				'tool'          => $tool['name'],
				'risk'          => $risk,
				'decision'      => $decision,
				'status'        => $status,
				'arguments'     => $args,
				'result'        => $result,
				'duration_ms'   => $duration,
				'approval_id'   => $approval_id,
			)
		);
	}

	public static function short_path( $file ) {
		return str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $file ) );
	}
}
