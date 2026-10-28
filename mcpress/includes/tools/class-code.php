<?php
/**
 * run_php: arbitrary PHP inside WordPress. Always critical (asks in Automatic mode).
 *
 * Disabled with define( 'MCPRESS_DISABLE_PHP_EXECUTION', true ) or the mcpress_allow_php_execution filter.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Code {

	public static function php_allowed() {
		$allowed = ! ( defined( 'MCPRESS_DISABLE_PHP_EXECUTION' ) && MCPRESS_DISABLE_PHP_EXECUTION );
		return (bool) apply_filters( 'mcpress_allow_php_execution', $allowed );
	}

	public static function register( Tool_Registry $r ) {
		if ( ! self::php_allowed() ) {
			return;
		}
		$r->register(
			array(
				'name'         => 'run_php',
				'title'        => 'Run PHP',
				'group'        => 'code',
				'risk'         => Policy::RISK_CRITICAL,
				'description'  => 'Execute PHP inside WordPress (all functions and $wpdb available via global). Write the body of a function, without <?php; use "return" to send a value back. Echo output and PHP warnings are captured. Use it when no dedicated tool fits.',
				'input_schema' => H::schema(
					array(
						'code'    => H::str( 'PHP code, e.g. return get_option( \'blogname\' );' ),
						'timeout' => H::int( 'Seconds (5-120).', array( 'minimum' => 5, 'maximum' => 120, 'default' => 60 ) ),
					),
					array( 'code' )
				),
				'callback'     => array( __CLASS__, 'run_php' ),
				'guard'        => array( __CLASS__, 'guard' ),
				'preview'      => array( __CLASS__, 'preview' ),
				'capability'   => 'manage_options',
			)
		);
	}

	public static function guard( array $args ) {
		if ( ! self::php_allowed() ) {
			return H::error( 'php_disabled', 'PHP execution is disabled on this site.' );
		}
		if ( preg_match( '/mcpress/i', (string) $args['code'] ) ) {
			return H::error( 'self_protection', 'Code that touches MCPress (its settings, keys, tables or files) is refused.', 'Permissions are changed by the user in wp-admin → MCPress.' );
		}
		return null;
	}

	public static function preview( array $args ) {
		return array( 'php' => (string) $args['code'] );
	}

	public static function run_php( array $args ) {
		$code = trim( (string) $args['code'] );
		$code = preg_replace( '/^<\?php\s*/i', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', $code );

		$syntax = Files::php_syntax_error( '<?php ' . $code );
		if ( $syntax ) {
			return H::error( 'php_syntax', 'PHP syntax error: ' . $syntax, 'Fix the code; remember to end statements with ";".', true );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) $args['timeout'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			function ( $errno, $errstr, $errfile, $errline ) use ( &$warnings ) {
				if ( count( $warnings ) < 50 ) {
					$warnings[] = sprintf( '%s: %s (line %d)', self::level( $errno ), $errstr, $errline );
				}
				return true;
			}
		);
		ob_start();
		$start = microtime( true );
		$error = null;
		$value = null;
		try {
			// eval() is the purpose of this tool: it is critical risk (asks in Automatic mode), can be
			// switched off with MCPRESS_DISABLE_PHP_EXECUTION, and only runs for an authenticated admin connection.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			$fn    = eval( 'return static function () { ' . $code . "\n};" );
			$value = $fn();
		} catch ( \Throwable $e ) {
			$error = sprintf( '%s: %s (line %d)', get_class( $e ), $e->getMessage(), $e->getLine() );
		}
		$output = ob_get_clean();
		restore_error_handler();

		$out = array(
			'return'      => self::exportable( $value ),
			'output'      => Util::truncate( (string) $output, 100000 ),
			'warnings'    => $warnings,
			'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
		if ( $error ) {
			$out['exception'] = $error;
			return H::error( 'php_exception', $error, 'The code threw an exception. Output so far is in details.', true, $out );
		}
		return $out;
	}

	private static function level( $errno ) {
		$map = array(
			E_WARNING         => 'Warning',
			E_NOTICE          => 'Notice',
			E_USER_ERROR      => 'User error',
			E_USER_WARNING    => 'User warning',
			E_USER_NOTICE     => 'User notice',
			E_DEPRECATED      => 'Deprecated',
			E_USER_DEPRECATED => 'Deprecated',
		);
		return isset( $map[ $errno ] ) ? $map[ $errno ] : 'Error';
	}

	/** Make any PHP value JSON-friendly. */
	private static function exportable( $value, $depth = 0 ) {
		if ( $depth > 12 ) {
			return '…';
		}
		if ( is_resource( $value ) ) {
			return 'resource(' . get_resource_type( $value ) . ')';
		}
		if ( $value instanceof \WP_Error ) {
			return array( 'WP_Error' => $value->get_error_messages(), 'code' => $value->get_error_code() );
		}
		if ( $value instanceof \Closure ) {
			return 'Closure';
		}
		if ( is_object( $value ) ) {
			$arr = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
			return array_merge( array( '__class' => get_class( $value ) ), (array) self::exportable( $arr, $depth + 1 ) );
		}
		if ( is_array( $value ) ) {
			$out = array();
			$i   = 0;
			foreach ( $value as $k => $v ) {
				if ( ++$i > 2000 ) {
					$out['…'] = ( count( $value ) - 2000 ) . ' more items';
					break;
				}
				$out[ $k ] = self::exportable( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_float( $value ) && ( is_nan( $value ) || is_infinite( $value ) ) ) {
			return (string) $value;
		}
		return $value;
	}
}
