<?php
/**
 * Streamable HTTP transport for MCP, mounted on the REST API:
 *   POST /wp-json/mcpress/v1/mcp            (Authorization: Bearer …)
 *   POST /wp-json/mcpress/v1/mcp/k/<key>    (key in the URL, opt-in)
 *
 * Stateless: no Mcp-Session-Id, JSON responses only, GET answers 405.
 *
 * @package MCPress
 */

namespace MCPress\MCP;

use MCPress\Auth\Authenticator;
use MCPress\Auth\OAuth;
use MCPress\Context;
use MCPress\Settings;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Transport {

	const RAW = '__mcpress_raw';

	/** @var bool Whether our response has been produced (for the fatal-error handler). */
	private static $answered = false;

	/** @var int Output-buffer level opened by us (0 = none). */
	private static $ob_level = 0;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw' ), 20, 4 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'let_us_report_bad_json' ), 10, 3 );
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_allowed_headers' ) );
		add_filter( 'rest_exposed_cors_headers', array( __CLASS__, 'cors_exposed_headers' ) );
	}

	public static function routes() {
		$endpoints = array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_post' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'GET, DELETE',
				'callback'            => array( __CLASS__, 'handle_other' ),
				'permission_callback' => '__return_true',
			),
		);
		register_rest_route( 'mcpress/v1', '/mcp', $endpoints );
		register_rest_route(
			'mcpress/v1',
			'/mcp/k/(?P<key>mcpk_[a-f0-9]{48})',
			array_map(
				function ( $e ) {
					$e['show_in_index'] = false;
					return $e;
				},
				$endpoints
			)
		);
	}

	/** @return \WP_REST_Response */
	public static function handle_post( \WP_REST_Request $request ) {
		$ctx = Authenticator::authenticate();
		if ( ! $ctx ) {
			$ctx = Authenticator::from_application_password();
		}
		if ( ! $ctx ) {
			return self::unauthorized();
		}

		Context::set( $ctx );
		wp_set_current_user( $ctx->user_id );
		self::harden_runtime();

		$body = $request->get_body();
		$msg  = json_decode( $body, true );
		if ( null === $msg && JSON_ERROR_NONE !== json_last_error() ) {
			return self::json( 400, Server::error( null, Server::PARSE_ERROR, 'Parse error: ' . json_last_error_msg() ) );
		}

		$server = new Server( $ctx );

		if ( is_array( $msg ) && Schema::is_list( $msg ) && array() !== $msg ) {
			$responses = array();
			foreach ( $msg as $item ) {
				$response = $server->handle( $item );
				if ( null !== $response ) {
					$responses[] = $response;
				}
			}
			self::$answered = true;
			return $responses ? self::json( 200, $responses ) : self::raw( 202, null );
		}

		$response       = $server->handle( $msg );
		self::$answered = true;
		return null === $response ? self::raw( 202, null ) : self::json( 200, $response );
	}

	/**
	 * Core rejects a malformed JSON body before our callback runs; on the MCP route the client must get
	 * a JSON-RPC parse error (-32700) instead, which handle_post() produces.
	 */
	public static function let_us_report_bad_json( $response, $handler, $request ) {
		if ( is_wp_error( $response ) && 'rest_invalid_json' === $response->get_error_code() && 0 === strpos( $request->get_route(), '/mcpress/v1/mcp' ) ) {
			return null;
		}
		return $response;
	}

	/** GET (server-sent stream) and DELETE (session end) are not offered by a stateless server. */
	public static function handle_other( \WP_REST_Request $request ) {
		return self::raw(
			405,
			Util::json( array( 'error' => 'method_not_allowed', 'error_description' => 'MCPress uses stateless Streamable HTTP: send JSON-RPC messages with POST.' ) ),
			array( 'Allow' => 'POST' )
		);
	}

	private static function unauthorized() {
		$failure = Authenticator::failure();
		$params  = array( 'realm="MCPress"' );
		if ( Settings::get( 'oauth_enabled' ) ) {
			$params[] = 'resource_metadata="' . OAuth::protected_resource_url() . '"';
		}
		if ( 'missing' !== $failure ) {
			$params[] = 'error="invalid_token"';
		}
		$messages = array(
			'missing'          => 'Authentication required. Send "Authorization: Bearer <MCPress key>" or connect with OAuth.',
			'invalid'          => 'The token is invalid, expired or was revoked. Create a new key or reconnect in wp-admin → MCPress.',
			'url_key_disabled' => 'Keys in the URL are disabled for this connection. Use the Authorization header.',
			'user_missing'     => 'The WordPress user behind this connection no longer exists.',
			'user_capability'  => 'The WordPress user behind this connection is no longer allowed to connect an AI.',
		);
		return self::raw(
			401,
			Util::json(
				array(
					'error'             => 'missing' === $failure ? 'unauthorized' : 'invalid_token',
					'error_description' => isset( $messages[ $failure ] ) ? $messages[ $failure ] : $messages['missing'],
				)
			),
			array( 'WWW-Authenticate' => 'Bearer ' . implode( ', ', $params ) )
		);
	}

	/** Keep the JSON clean and answer even if a tool kills PHP. */
	private static function harden_runtime() {
		if ( ! headers_sent() ) {
			@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		}
		add_filter( 'wp_should_handle_php_error', array( __CLASS__, 'skip_core_fatal_handler' ), 10, 2 );
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		ob_start();
		self::$ob_level = ob_get_level();
	}

	/** Discard whatever other code printed inside our buffer. */
	private static function drop_buffer() {
		while ( self::$ob_level > 0 && ob_get_level() >= self::$ob_level ) {
			ob_end_clean();
		}
		self::$ob_level = 0;
	}

	public static function skip_core_fatal_handler( $should, $error ) {
		return Executor::$running ? false : $should;
	}

	public static function on_shutdown() {
		if ( self::$answered || ! Executor::$running ) {
			return;
		}
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}
		self::drop_buffer();
		$response = Server::result( Executor::$current_request_id, Executor::fatal_result( $error ) );
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo Util::json( $response ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function json( $status, $data ) {
		return self::raw( $status, Util::json( $data ) );
	}

	private static function raw( $status, $body, array $headers = array() ) {
		$response = new \WP_REST_Response( array( self::RAW => $body ), $status );
		$headers  = array_merge(
			array(
				'Cache-Control' => 'no-store',
				'Content-Type'  => 'application/json; charset=utf-8',
			),
			$headers
		);
		$response->set_headers( $headers );
		return $response;
	}

	/** Print our body as-is instead of letting the REST server re-encode it. */
	public static function serve_raw( $served, $result, $request, $server ) {
		if ( $served || ! ( $result instanceof \WP_HTTP_Response ) ) {
			return $served;
		}
		$data = $result->get_data();
		if ( ! is_array( $data ) || ! array_key_exists( self::RAW, $data ) ) {
			return $served;
		}
		self::drop_buffer();
		if ( null !== $data[ self::RAW ] ) {
			echo $data[ self::RAW ]; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return true;
	}

	public static function cors_allowed_headers( $headers ) {
		return array_unique( array_merge( (array) $headers, array( 'Mcp-Session-Id', 'MCP-Protocol-Version', 'X-MCPress-Key', 'Last-Event-ID', 'Accept' ) ) );
	}

	public static function cors_exposed_headers( $headers ) {
		return array_unique( array_merge( (array) $headers, array( 'WWW-Authenticate', 'Mcp-Session-Id' ) ) );
	}
}
