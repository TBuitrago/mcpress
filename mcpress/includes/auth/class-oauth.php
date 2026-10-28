<?php
/**
 * OAuth 2.1 authorization server for MCP clients that only take a URL
 * (claude.ai / Claude Desktop connectors, ChatGPT, …).
 *
 * - Protected resource metadata (RFC 9728), advertised in WWW-Authenticate.
 * - Authorization server metadata (RFC 8414) and OpenID-style discovery.
 * - Dynamic client registration (RFC 7591) and Client ID Metadata Documents.
 * - Authorization code + PKCE (S256 only), refresh token rotation, revocation (RFC 7009).
 *
 * The consent screen lives in wp-admin (Admin\Authorize_Page): the person logs in to WordPress,
 * picks the permission mode and approves.
 *
 * @package MCPress
 */

namespace MCPress\Auth;

use MCPress\Settings;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class OAuth {

	const NS                 = 'mcpress/v1';
	const MAX_CLIENTS        = 1000;
	const REGISTER_PER_HOUR  = 20;
	const CODE_TTL           = 600;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'init', array( __CLASS__, 'maybe_serve_well_known' ), 0 );
		add_filter( 'application_password_is_api_request', array( __CLASS__, 'skip_app_passwords_on_token_endpoint' ) );
	}

	/* ------------------------------------------------------------------ URLs */

	public static function issuer() {
		return untrailingslashit( rest_url( self::NS . '/oauth' ) );
	}

	public static function protected_resource_url() {
		return rest_url( self::NS . '/oauth/protected-resource' );
	}

	public static function endpoint( $name ) {
		return rest_url( self::NS . '/oauth/' . $name );
	}

	/* --------------------------------------------------------------- Metadata */

	public static function protected_resource_metadata() {
		return array(
			'resource'                 => Util::mcp_url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'mcp' ),
			'resource_name'            => 'MCPress · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'resource_documentation'   => 'https://mcpress.tbadigitals.com',
		);
	}

	public static function server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::endpoint( 'authorize' ),
			'token_endpoint'                        => self::endpoint( 'token' ),
			'registration_endpoint'                 => self::endpoint( 'register' ),
			'revocation_endpoint'                   => self::endpoint( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'scopes_supported'                      => array( 'mcp' ),
			'client_id_metadata_document_supported' => true,
			'authorization_response_iss_parameter_supported' => true,
			'service_documentation'                 => 'https://mcpress.tbadigitals.com',
		);
	}

	/* ----------------------------------------------------------------- Routes */

	public static function routes() {
		$public = '__return_true';
		register_rest_route( self::NS, '/oauth/protected-resource', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_protected_resource' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/oauth/metadata', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_server_metadata' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/oauth/\.well-known/(?:openid-configuration|oauth-authorization-server)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_server_metadata' ), 'permission_callback' => $public, 'show_in_index' => false ) );
		register_rest_route( self::NS, '/oauth/register', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_register' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/oauth/authorize', array( 'methods' => 'GET, POST', 'callback' => array( __CLASS__, 'rest_authorize' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/oauth/token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_token' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/oauth/revoke', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_revoke' ), 'permission_callback' => $public ) );
	}

	public static function rest_protected_resource() {
		if ( ! Settings::get( 'oauth_enabled' ) ) {
			return self::oauth_error( 'oauth_disabled', 'OAuth is disabled on this site. Use an MCPress API key.', 404 );
		}
		return self::json( self::protected_resource_metadata() );
	}

	public static function rest_server_metadata() {
		if ( ! Settings::get( 'oauth_enabled' ) ) {
			return self::oauth_error( 'oauth_disabled', 'OAuth is disabled on this site. Use an MCPress API key.', 404 );
		}
		return self::json( self::server_metadata() );
	}

	/** /.well-known/* at the site root (and under the home path for sub-folder installs). */
	public static function maybe_serve_well_known() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || ! Settings::get( 'oauth_enabled' ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$pos  = strpos( $path, '/.well-known/' );
		if ( false === $pos ) {
			return;
		}
		$name = strtok( substr( $path, $pos + 13 ), '/' );
		if ( 'oauth-protected-resource' === $name ) {
			$data = self::protected_resource_metadata();
		} elseif ( 'oauth-authorization-server' === $name || 'openid-configuration' === $name ) {
			$data = self::server_metadata();
		} else {
			return;
		}
		if ( 'OPTIONS' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::cors_headers();
			status_header( 204 );
			exit;
		}
		status_header( 200 );
		self::cors_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		echo Util::json( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Dynamic client registration. */
	public static function rest_register( \WP_REST_Request $request ) {
		global $wpdb;
		if ( ! Settings::get( 'oauth_enabled' ) ) {
			return self::oauth_error( 'access_denied', 'OAuth is disabled on this site.', 403 );
		}

		$ip       = Util::client_ip();
		$rate_key = 'mcpress_dcr_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= self::REGISTER_PER_HOUR ) {
			return self::oauth_error( 'invalid_client_metadata', 'Too many registrations from this address. Try again later.', 429 );
		}
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Util::table( 'clients' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $total >= self::MAX_CLIENTS ) {
			self::cleanup_clients();
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Util::table( 'clients' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $total >= self::MAX_CLIENTS ) {
				return self::oauth_error( 'invalid_client_metadata', 'Client registry is full.', 400 );
			}
		}

		$meta      = self::request_params( $request );
		$redirects = isset( $meta['redirect_uris'] ) ? (array) $meta['redirect_uris'] : array();
		if ( ! $redirects ) {
			return self::oauth_error( 'invalid_redirect_uri', 'redirect_uris is required.', 400 );
		}
		foreach ( $redirects as $uri ) {
			if ( ! self::valid_redirect_uri( $uri ) ) {
				return self::oauth_error( 'invalid_redirect_uri', 'Invalid redirect URI: ' . (string) $uri, 400 );
			}
		}

		$auth_method = isset( $meta['token_endpoint_auth_method'] ) ? (string) $meta['token_endpoint_auth_method'] : 'none';
		if ( ! in_array( $auth_method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			$auth_method = 'none';
		}

		$client_id = Util::random_id( 'mcpress-client-', 12 );
		$secret    = 'none' === $auth_method ? null : Util::random_token( 'mcps_' );
		$name      = isset( $meta['client_name'] ) ? sanitize_text_field( (string) $meta['client_name'] ) : '';
		$stored    = array(
			'token_endpoint_auth_method' => $auth_method,
			'client_uri'                 => isset( $meta['client_uri'] ) ? esc_url_raw( (string) $meta['client_uri'] ) : '',
			'logo_uri'                   => isset( $meta['logo_uri'] ) ? esc_url_raw( (string) $meta['logo_uri'] ) : '',
			'software_id'                => isset( $meta['software_id'] ) ? sanitize_text_field( (string) $meta['software_id'] ) : '',
			'software_version'           => isset( $meta['software_version'] ) ? sanitize_text_field( (string) $meta['software_version'] ) : '',
			'secret_hash'                => $secret ? Util::hash_token( $secret ) : '',
		);

		$wpdb->insert(
			Util::table( 'clients' ),
			array(
				'client_id'     => $client_id,
				'client_name'   => mb_substr( $name, 0, 120 ),
				'redirect_uris' => Util::json( array_values( $redirects ) ),
				'metadata'      => Util::json( $stored ),
				'created_ip'    => substr( $ip, 0, 64 ),
				'created_at'    => Util::now(),
			)
		);

		$out = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => array_values( $redirects ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $auth_method,
			'scope'                      => 'mcp',
		);
		if ( $secret ) {
			$out['client_secret']            = $secret;
			$out['client_secret_expires_at'] = 0;
		}
		return self::json( $out, 201 );
	}

	/** Hand the browser over to the consent screen in wp-admin (WordPress handles the login). */
	public static function rest_authorize( \WP_REST_Request $request ) {
		$keys   = array( 'response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource' );
		$params = array( 'page' => 'mcpress-authorize' );
		foreach ( $keys as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v && is_scalar( $v ) ) {
				$params[ $k ] = (string) $v;
			}
		}
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', add_query_arg( array_map( 'rawurlencode', $params ), admin_url( 'admin.php' ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Validate an authorization request (used by the consent screen).
	 *
	 * @return array|\WP_Error ['client' => array, 'params' => array]. A WP_Error with data['redirect'] = true
	 *                         may be sent back to the client; otherwise it must be shown to the user.
	 */
	public static function validate_authorization_request( array $q ) {
		if ( ! Settings::get( 'oauth_enabled' ) ) {
			return new \WP_Error( 'oauth_disabled', __( 'OAuth connections are disabled on this site. Create an API key in MCPress → Connect instead.', 'mcpress' ) );
		}
		$client_id    = isset( $q['client_id'] ) ? (string) $q['client_id'] : '';
		$redirect_uri = isset( $q['redirect_uri'] ) ? (string) $q['redirect_uri'] : '';
		$client       = self::get_client( $client_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( ! $client ) {
			return new \WP_Error( 'invalid_client', __( 'This AI client is not registered with this site. Try connecting again from your AI app.', 'mcpress' ) );
		}
		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			return new \WP_Error( 'invalid_redirect_uri', __( 'The return address of this request does not match the one the AI client registered.', 'mcpress' ) );
		}

		$params = array(
			'client_id'      => $client_id,
			'redirect_uri'   => $redirect_uri,
			'state'          => isset( $q['state'] ) ? (string) $q['state'] : '',
			'scope'          => 'mcp',
			'code_challenge' => isset( $q['code_challenge'] ) ? (string) $q['code_challenge'] : '',
			'resource'       => isset( $q['resource'] ) ? (string) $q['resource'] : '',
		);

		if ( 'code' !== ( isset( $q['response_type'] ) ? $q['response_type'] : '' ) ) {
			return new \WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.', array( 'redirect' => true, 'params' => $params ) );
		}
		$method = isset( $q['code_challenge_method'] ) ? (string) $q['code_challenge_method'] : '';
		if ( 'S256' !== $method || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $params['code_challenge'] ) ) {
			return new \WP_Error( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.', array( 'redirect' => true, 'params' => $params ) );
		}
		if ( '' !== $params['resource'] && ! self::resource_matches( $params['resource'] ) ) {
			return new \WP_Error( 'invalid_target', 'The requested resource is not this MCP server.', array( 'redirect' => true, 'params' => $params ) );
		}
		return array(
			'client' => $client,
			'params' => $params,
		);
	}

	/** Issue the authorization code after the person approved. */
	public static function issue_code( array $params, $user_id, $mode ) {
		return Tokens::issue(
			'code',
			null,
			self::CODE_TTL,
			array(
				'client_id'      => $params['client_id'],
				'redirect_uri'   => $params['redirect_uri'],
				'code_challenge' => $params['code_challenge'],
				'resource'       => $params['resource'],
				'user_id'        => (int) $user_id,
				'mode'           => $mode,
			)
		);
	}

	public static function redirect_url( $redirect_uri, array $query ) {
		$query['iss'] = self::issuer();
		$sep          = false === strpos( $redirect_uri, '?' ) ? '?' : '&';
		return $redirect_uri . $sep . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/** Token endpoint: authorization_code and refresh_token grants. */
	public static function rest_token( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'oauth_enabled' ) ) {
			return self::oauth_error( 'access_denied', 'OAuth is disabled on this site.', 400 );
		}
		$p          = self::request_params( $request );
		$grant_type = isset( $p['grant_type'] ) ? (string) $p['grant_type'] : '';
		list( $client_id, $client_secret ) = self::client_credentials( $request, $p );

		if ( 'authorization_code' === $grant_type ) {
			return self::grant_authorization_code( $p, $client_id, $client_secret );
		}
		if ( 'refresh_token' === $grant_type ) {
			return self::grant_refresh_token( $p, $client_id, $client_secret );
		}
		return self::oauth_error( 'unsupported_grant_type', 'Supported grants: authorization_code, refresh_token.', 400 );
	}

	private static function grant_authorization_code( array $p, $client_id, $client_secret ) {
		$code = isset( $p['code'] ) ? (string) $p['code'] : '';
		$row  = Tokens::find( $code, array( 'code' ) );
		if ( ! $row || ! Tokens::delete_by_token( $code ) ) {
			return self::oauth_error( 'invalid_grant', 'The authorization code is invalid, expired or already used.', 400 );
		}
		$data = $row['data'];

		if ( '' === $client_id ) {
			$client_id = $data['client_id'];
		}
		if ( ! hash_equals( (string) $data['client_id'], (string) $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'The code was issued to another client.', 400 );
		}
		$client = self::get_client( $client_id );
		if ( ! $client || is_wp_error( $client ) ) {
			return self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}
		$auth = self::authenticate_client( $client, $client_secret );
		if ( is_wp_error( $auth ) ) {
			return self::oauth_error( 'invalid_client', $auth->get_error_message(), 401 );
		}
		if ( isset( $p['redirect_uri'] ) && (string) $p['redirect_uri'] !== (string) $data['redirect_uri'] ) {
			return self::oauth_error( 'invalid_grant', 'redirect_uri does not match the authorization request.', 400 );
		}
		$verifier = isset( $p['code_verifier'] ) ? (string) $p['code_verifier'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) || ! hash_equals( (string) $data['code_challenge'], self::pkce_challenge( $verifier ) ) ) {
			return self::oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		$user = get_userdata( (int) $data['user_id'] );
		if ( ! $user || ! user_can( $user, Authenticator::connect_capability() ) ) {
			return self::oauth_error( 'invalid_grant', 'The approving user can no longer connect an AI.', 400 );
		}

		$connection_id = self::connection_for( (int) $data['user_id'], $client_id, $client['client_name'], $data['mode'] );
		self::touch_client( $client_id );
		return self::json( self::issue_tokens( $connection_id, $client_id ) );
	}

	private static function grant_refresh_token( array $p, $client_id, $client_secret ) {
		$token = isset( $p['refresh_token'] ) ? (string) $p['refresh_token'] : '';
		$row   = Tokens::find( $token, array( 'refresh' ) );
		if ( ! $row ) {
			return self::oauth_error( 'invalid_grant', 'The refresh token is invalid, expired or was revoked.', 400 );
		}
		$token_client = isset( $row['data']['client_id'] ) ? (string) $row['data']['client_id'] : '';
		if ( '' === $client_id ) {
			$client_id = $token_client;
		}
		if ( ! hash_equals( $token_client, (string) $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'The refresh token belongs to another client.', 400 );
		}
		$client = self::get_client( $client_id );
		if ( $client && ! is_wp_error( $client ) ) {
			$auth = self::authenticate_client( $client, $client_secret );
			if ( is_wp_error( $auth ) ) {
				return self::oauth_error( 'invalid_client', $auth->get_error_message(), 401 );
			}
		}
		$connection = Connections::get( $row['connection_id'] );
		if ( ! Connections::is_active( $connection ) ) {
			return self::oauth_error( 'invalid_grant', 'This connection was revoked in wp-admin.', 400 );
		}
		if ( ! Tokens::delete_by_token( $token ) ) {
			return self::oauth_error( 'invalid_grant', 'The refresh token was already used.', 400 );
		}
		Tokens::delete_for_connection( $connection['id'], array( 'access' ) );
		self::touch_client( $client_id );
		return self::json( self::issue_tokens( (int) $connection['id'], $client_id ) );
	}

	private static function issue_tokens( $connection_id, $client_id ) {
		$ttl     = (int) Settings::get( 'access_token_ttl' );
		$access  = Tokens::issue( 'access', $connection_id, $ttl, array( 'client_id' => $client_id ) );
		$refresh = Tokens::issue( 'refresh', $connection_id, (int) Settings::get( 'refresh_token_days' ) * DAY_IN_SECONDS, array( 'client_id' => $client_id ) );
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => $ttl,
			'refresh_token' => $refresh,
			'scope'         => 'mcp',
		);
	}

	/** Reuse the active connection of this user + client (re-authorizing updates its mode). */
	private static function connection_for( $user_id, $client_id, $client_name, $mode ) {
		global $wpdb;
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Util::table( 'connections' ) . " WHERE type = 'oauth' AND user_id = %d AND client_id = %s AND revoked_at IS NULL ORDER BY id DESC LIMIT 1",
				$user_id,
				$client_id
			)
		);
		if ( $id ) {
			Connections::set_mode( $id, $mode );
			return $id;
		}
		return Connections::create_oauth( $user_id, $mode, $client_id, $client_name ? $client_name : __( 'OAuth client', 'mcpress' ) );
	}

	public static function rest_revoke( \WP_REST_Request $request ) {
		$p     = self::request_params( $request );
		$token = isset( $p['token'] ) ? (string) $p['token'] : '';
		$row   = Tokens::find( $token, array( 'access', 'refresh' ) );
		if ( $row ) {
			if ( 'refresh' === $row['kind'] && $row['connection_id'] ) {
				Connections::revoke( $row['connection_id'] );
			} else {
				Tokens::delete( $row['id'] );
			}
		}
		return self::json( new \stdClass() ); // RFC 7009: 200 even for unknown tokens.
	}

	/* ---------------------------------------------------------------- Clients */

	/**
	 * Registered client, or a Client ID Metadata Document when client_id is an https URL.
	 *
	 * @return array|null|\WP_Error client_id, client_name, redirect_uris, metadata
	 */
	public static function get_client( $client_id ) {
		global $wpdb;
		if ( '' === (string) $client_id ) {
			return null;
		}
		if ( 0 === strpos( $client_id, 'https://' ) ) {
			return self::metadata_document_client( $client_id );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Util::table( 'clients' ) . ' WHERE client_id = %s', $client_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['redirect_uris'] = Util::json_decode_array( $row['redirect_uris'] );
		$row['metadata']      = Util::json_decode_array( $row['metadata'] );
		return $row;
	}

	private static function metadata_document_client( $url ) {
		if ( strlen( $url ) > 512 || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_client', __( 'The client ID URL is not valid.', 'mcpress' ) );
		}
		$cache_key = 'mcpress_cimd_' . md5( $url );
		$doc       = get_transient( $cache_key );
		if ( ! is_array( $doc ) ) {
			$resp = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 8,
					'redirection'         => 0,
					'limit_response_size' => 32768,
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new \WP_Error( 'invalid_client', __( 'Could not fetch the AI client\'s metadata document.', 'mcpress' ) );
			}
			$doc = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $doc ) || ! isset( $doc['client_id'] ) || $doc['client_id'] !== $url || empty( $doc['redirect_uris'] ) ) {
				return new \WP_Error( 'invalid_client', __( 'The AI client\'s metadata document is invalid.', 'mcpress' ) );
			}
			set_transient( $cache_key, $doc, DAY_IN_SECONDS );
		}
		$redirects = array_values( array_filter( (array) $doc['redirect_uris'], array( __CLASS__, 'valid_redirect_uri' ) ) );
		return array(
			'client_id'     => $url,
			'client_name'   => isset( $doc['client_name'] ) ? sanitize_text_field( (string) $doc['client_name'] ) : wp_parse_url( $url, PHP_URL_HOST ),
			'redirect_uris' => $redirects,
			'metadata'      => array(
				'token_endpoint_auth_method' => 'none',
				'client_uri'                 => isset( $doc['client_uri'] ) ? esc_url_raw( (string) $doc['client_uri'] ) : '',
				'logo_uri'                   => isset( $doc['logo_uri'] ) ? esc_url_raw( (string) $doc['logo_uri'] ) : '',
				'secret_hash'                => '',
			),
		);
	}

	private static function authenticate_client( array $client, $secret ) {
		$hash = isset( $client['metadata']['secret_hash'] ) ? (string) $client['metadata']['secret_hash'] : '';
		if ( '' === $hash ) {
			return true; // Public client: PKCE is the proof.
		}
		if ( '' === (string) $secret || ! hash_equals( $hash, Util::hash_token( $secret ) ) ) {
			return new \WP_Error( 'invalid_client', 'Client authentication failed.' );
		}
		return true;
	}

	private static function client_credentials( \WP_REST_Request $request, array $p ) {
		$id     = isset( $p['client_id'] ) ? (string) $p['client_id'] : '';
		$secret = isset( $p['client_secret'] ) ? (string) $p['client_secret'] : '';
		$header = $request->get_header( 'authorization' );
		if ( $header && preg_match( '/^Basic\s+(.+)$/i', $header, $m ) ) {
			$decoded = base64_decode( $m[1], true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $bid, $bsecret ) = explode( ':', $decoded, 2 );
				$id     = '' === $id ? rawurldecode( $bid ) : $id;
				$secret = '' === $secret ? rawurldecode( $bsecret ) : $secret;
			}
		}
		return array( $id, $secret );
	}

	private static function touch_client( $client_id ) {
		global $wpdb;
		$wpdb->update( Util::table( 'clients' ), array( 'last_used_at' => Util::now() ), array( 'client_id' => $client_id ) );
	}

	/** Delete registrations nobody used. */
	public static function cleanup_clients() {
		global $wpdb;
		$clients     = Util::table( 'clients' );
		$connections = Util::table( 'connections' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE c FROM $clients c LEFT JOIN $connections k ON k.client_id = c.client_id AND k.revoked_at IS NULL
				WHERE k.id IS NULL AND ( ( c.last_used_at IS NULL AND c.created_at < %s ) OR c.last_used_at < %s )",
				Util::now( -7 * DAY_IN_SECONDS ),
				Util::now( -180 * DAY_IN_SECONDS )
			)
		);
		// phpcs:enable
	}

	/* ---------------------------------------------------------------- Helpers */

	public static function valid_redirect_uri( $uri ) {
		if ( ! is_string( $uri ) || strlen( $uri ) > 2000 || false !== strpos( $uri, '#' ) ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( ! $parts || empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( in_array( $scheme, array( 'javascript', 'data', 'vbscript', 'file', 'blob', 'about' ), true ) ) {
			return false;
		}
		if ( 'https' === $scheme ) {
			return ! empty( $parts['host'] );
		}
		if ( 'http' === $scheme ) {
			$host = isset( $parts['host'] ) ? strtolower( trim( $parts['host'], '[]' ) ) : '';
			return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || ( '' !== $host && '.localhost' === substr( $host, -10 ) );
		}
		// Private-use schemes of native apps (cursor://, vscode://, com.example.app:/…).
		return (bool) preg_match( '/^[a-z][a-z0-9+.-]*$/', $scheme );
	}

	private static function resource_matches( $resource ) {
		$want = untrailingslashit( strtolower( Util::mcp_url() ) );
		$got  = untrailingslashit( strtolower( (string) $resource ) );
		return $got === $want || untrailingslashit( strtolower( home_url() ) ) === $got || 0 === strpos( $want, $got . '/' );
	}

	public static function pkce_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/** JSON body, form body or query, whatever the client used. */
	private static function request_params( \WP_REST_Request $request ) {
		$json = $request->get_json_params();
		$p    = is_array( $json ) ? $json : array();
		foreach ( (array) $request->get_body_params() as $k => $v ) {
			$p[ $k ] = $v;
		}
		foreach ( (array) $request->get_query_params() as $k => $v ) {
			if ( ! isset( $p[ $k ] ) ) {
				$p[ $k ] = $v;
			}
		}
		return $p;
	}

	public static function skip_app_passwords_on_token_endpoint( $is_api ) {
		$route = Authenticator::requested_route();
		if ( $route && 0 === strpos( $route, '/mcpress/v1/oauth/' ) ) {
			return false; // Basic auth there carries client_id:client_secret, not a WordPress login.
		}
		return $is_api;
	}

	private static function cors_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version' );
	}

	private static function json( $data, $status = 200 ) {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		return $response;
	}

	private static function oauth_error( $code, $description, $status ) {
		return self::json(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status
		);
	}
}
