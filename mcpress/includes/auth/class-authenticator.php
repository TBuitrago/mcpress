<?php
/**
 * Resolves the MCP caller from the request: Bearer key / OAuth token, key in the URL,
 * or (if enabled) a WordPress application password.
 *
 * The WordPress session cookie is never accepted on the MCP endpoint.
 *
 * @package MCPress
 */

namespace MCPress\Auth;

use MCPress\Context;
use MCPress\Settings;

defined( 'ABSPATH' ) || exit;

class Authenticator {

	/** @var Context|null|false false = not resolved yet */
	private static $resolved = false;

	/** @var string|null Why authentication failed (for the 401 response). */
	private static $failure = null;

	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_current_user' ), 30 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_authentication_errors' ), 99 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'clear_foreign_token_errors' ), 9999, 3 );
	}

	/** Is this HTTP request aimed at the MCP endpoint? Works before the REST API is loaded. */
	public static function is_mcp_request() {
		$route = self::requested_route();
		return null !== $route && 0 === strpos( $route, '/mcpress/v1/mcp' );
	}

	/** The REST route of the current request, or null if it is not a REST request. */
	public static function requested_route() {
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$prefix = '/' . trim( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json', '/' ) . '/';
		$pos    = strpos( $uri, $prefix );
		if ( false === $pos ) {
			return null;
		}
		return '/' . ltrim( substr( $uri, $pos + strlen( $prefix ) - 1 ), '/' );
	}

	public static function determine_current_user( $user_id ) {
		if ( ! self::is_mcp_request() ) {
			return $user_id;
		}
		$context = self::authenticate();
		return $context ? $context->user_id : $user_id;
	}

	/** Make sure a valid MCPress token is not undone by the cookie/nonce check of core. */
	public static function rest_authentication_errors( $result ) {
		if ( self::is_mcp_request() && self::authenticate() && ( null === $result || true === $result ) ) {
			return true;
		}
		return $result;
	}

	/**
	 * Some JWT plugins reject every Bearer token they cannot parse, even on routes that are not theirs.
	 * On our own route, once our token was verified, that error does not apply.
	 */
	public static function clear_foreign_token_errors( $result, $server, $request ) {
		if ( is_wp_error( $result ) && 0 === strpos( $request->get_route(), '/mcpress/v1/mcp' ) && self::authenticate() ) {
			if ( preg_match( '/jwt|token/i', $result->get_error_code() ) ) {
				return null;
			}
		}
		return $result;
	}

	/**
	 * Resolve the caller once per request.
	 *
	 * @return Context|null
	 */
	public static function authenticate() {
		if ( false !== self::$resolved ) {
			return self::$resolved;
		}
		self::$resolved = null;

		$token = self::token_from_request( $via );
		if ( null === $token ) {
			self::$failure = 'missing';
			return null;
		}

		$row = Tokens::find( $token, array( 'key', 'access' ) );
		if ( ! $row || empty( $row['connection_id'] ) ) {
			self::$failure = 'invalid';
			return null;
		}

		$connection = Connections::get( $row['connection_id'] );
		if ( ! Connections::is_active( $connection ) ) {
			self::$failure = 'invalid';
			return null;
		}

		if ( 'url_key' === $via && ( empty( $connection['url_key'] ) || ! Settings::get( 'allow_url_keys' ) ) ) {
			self::$failure = 'url_key_disabled';
			return null;
		}

		$user = get_userdata( (int) $connection['user_id'] );
		if ( ! $user ) {
			self::$failure = 'user_missing';
			return null;
		}
		if ( ! user_can( $user, self::connect_capability() ) ) {
			self::$failure = 'user_capability';
			return null;
		}

		Connections::touch( $connection );
		self::$resolved = Context::from_connection( $connection, 'oauth' === $connection['type'] ? 'oauth' : $via );
		return self::$resolved;
	}

	/**
	 * Late fallback used by the transport: a request authenticated by WordPress itself
	 * with an application password (only if the site owner enabled it).
	 *
	 * @return Context|null
	 */
	public static function from_application_password() {
		if ( ! Settings::get( 'allow_app_passwords' ) || ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return null;
		}
		if ( ! is_user_logged_in() || null === rest_get_authenticated_app_password() ) {
			return null;
		}
		$user_id = get_current_user_id();
		if ( ! user_can( $user_id, self::connect_capability() ) ) {
			return null;
		}
		return new Context( null, $user_id, Settings::get( 'default_mode' ), 'app_password' );
	}

	public static function failure() {
		return self::$failure;
	}

	/** Capability required to connect an AI (and that the connected user must keep). */
	public static function connect_capability() {
		return (string) apply_filters( 'mcpress_connect_capability', 'manage_options' );
	}

	/**
	 * Read the secret from the request.
	 *
	 * @param string|null $via Set to 'key' (header) or 'url_key'.
	 * @return string|null
	 */
	private static function token_from_request( &$via = null ) {
		$via    = 'key';
		$values = array();
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $server_key ) {
			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				$values[] = (string) wp_unslash( $_SERVER[ $server_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}
		if ( ! $values && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					$values[] = (string) $value;
				}
			}
		}
		foreach ( $values as $value ) {
			if ( preg_match( '/^\s*Bearer\s+([A-Za-z0-9_\-\.]+)\s*$/i', $value, $m ) ) {
				return $m[1];
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_MCPRESS_KEY'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MCPRESS_KEY'] ) ) );
		}
		$route = self::requested_route();
		if ( $route && preg_match( '#^/mcpress/v1/mcp/k/(mcpk_[a-f0-9]{48})/?$#', $route, $m ) ) {
			$via = 'url_key';
			return $m[1];
		}
		return null;
	}

	/** For tests and for the approval runner. */
	public static function reset() {
		self::$resolved = false;
		self::$failure  = null;
	}
}
