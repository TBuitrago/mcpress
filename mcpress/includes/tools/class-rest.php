<?php
/**
 * Universal bridge: call any REST route of this site internally, as the connected user.
 *
 * Risk from the method and route: GET is a read; POST/PUT/PATCH a write; DELETE destructive
 * (critical with force). Routes that manage users, plugins, themes, settings, application
 * passwords or payments are critical for any write. MCPress' own routes are refused.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Rest {

	const CRITICAL_ROUTES = array(
		'#^/wp/v2/users#',
		'#^/wp/v2/plugins#',
		'#^/wp/v2/themes#',
		'#^/wp/v2/settings#',
		'#application-passwords#',
		'#^/wp-site-health/#',
		'#^/wc/v\d+/(payment_gateways|settings|system_status/tools|webhooks|customers)#',
		'#^/wc/store/#',
		'#^/wp-abilities/#',
	);

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'list_rest_routes',
				'title'        => 'List REST routes',
				'group'        => 'rest',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Without a namespace: every REST namespace (wp/v2, wc/v3, acf/v3, yoast/v1, rankmath/v1…) with its number of routes. With a namespace: its routes, methods and argument names.',
				'input_schema' => H::schema(
					array(
						'namespace' => H::str( 'e.g. wc/v3.' ),
						'search'    => H::str( 'Only routes containing this text.' ),
					)
				),
				'callback'     => array( __CLASS__, 'list_routes' ),
			)
		);
		$r->register(
			array(
				'name'         => 'rest_request',
				'title'        => 'Call a REST route',
				'group'        => 'rest',
				'risk'         => array( __CLASS__, 'risk' ),
				'base_risk'    => Policy::RISK_READ,
				'description'  => 'Call any REST API route of this site internally (no HTTP), as the connected user, with the plugin\'s own validation and hooks. Reaches WooCommerce, ACF, SEO plugins, forms, etc. GET = read; POST/PUT/PATCH = write; DELETE = destructive. Writes to users, plugins, themes, settings or payment routes are critical.',
				'input_schema' => H::schema(
					array(
						'method' => H::enum( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), 'HTTP method.', 'GET' ),
						'route'  => H::str( 'Route with leading slash, e.g. /wc/v3/products or /wp/v2/posts/12. A query string is allowed.' ),
						'params' => H::obj( 'Query parameters (GET) or body parameters (other methods).' ),
						'body'   => H::obj( 'JSON body (merged with params for writes).' ),
					),
					array( 'route' )
				),
				'callback'     => array( __CLASS__, 'request' ),
				'guard'        => array( __CLASS__, 'guard' ),
				'preview'      => array( __CLASS__, 'preview' ),
			)
		);
	}

	private static function normalize_route( $route ) {
		$route = '/' . ltrim( (string) $route, '/' );
		$route = preg_replace( '#^/wp-json#', '', $route );
		return $route;
	}

	public static function risk( array $args ) {
		$method = strtoupper( isset( $args['method'] ) ? $args['method'] : 'GET' );
		$route  = (string) strtok( self::normalize_route( isset( $args['route'] ) ? $args['route'] : '' ), '?' );
		if ( in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return Policy::RISK_READ;
		}
		foreach ( self::CRITICAL_ROUTES as $re ) {
			if ( preg_match( $re, $route ) ) {
				return Policy::RISK_CRITICAL;
			}
		}
		/** Lets plugins mark more routes as critical. */
		if ( apply_filters( 'mcpress_rest_route_is_critical', false, $route, $method ) ) {
			return Policy::RISK_CRITICAL;
		}
		if ( 'DELETE' === $method ) {
			$force = isset( $args['params']['force'] ) ? $args['params']['force'] : ( isset( $args['body']['force'] ) ? $args['body']['force'] : false );
			if ( false !== strpos( (string) $args['route'], 'force=true' ) || false !== strpos( (string) $args['route'], 'force=1' ) || \MCPress\Util::bool( $force ) ) {
				return Policy::RISK_CRITICAL;
			}
			return Policy::RISK_DESTRUCTIVE;
		}
		return Policy::RISK_WRITE;
	}

	public static function guard( array $args ) {
		$route = (string) strtok( self::normalize_route( $args['route'] ), '?' );
		if ( 0 === stripos( $route, '/mcpress' ) ) {
			return H::error( 'self_protection', 'MCPress routes cannot be called through the bridge.' );
		}
		if ( 0 === stripos( $route, '/batch/' ) ) {
			return H::error( 'unsupported', 'Batch requests are not supported through the bridge.', 'Send each request with its own rest_request call.' );
		}
		return null;
	}

	public static function list_routes( array $args ) {
		$server = rest_get_server();
		$routes = $server->get_routes();
		if ( empty( $args['namespace'] ) ) {
			$ns = array();
			foreach ( $server->get_namespaces() as $n ) {
				if ( 0 === strpos( $n, 'mcpress' ) ) {
					continue;
				}
				$ns[ $n ] = 0;
			}
			foreach ( array_keys( $routes ) as $route ) {
				foreach ( array_keys( $ns ) as $n ) {
					if ( 0 === strpos( $route, '/' . $n . '/' ) || '/' . $n === $route ) {
						++$ns[ $n ];
					}
				}
			}
			return array( 'namespaces' => $ns, 'hint' => 'Call again with a namespace to see its routes.' );
		}
		$prefix = '/' . trim( $args['namespace'], '/' );
		$out    = array();
		foreach ( $routes as $route => $handlers ) {
			if ( 0 !== strpos( $route, $prefix ) || 0 === strpos( $route, '/mcpress' ) ) {
				continue;
			}
			if ( ! empty( $args['search'] ) && false === stripos( $route, $args['search'] ) ) {
				continue;
			}
			$methods = array();
			$params  = array();
			foreach ( $handlers as $h ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) $h['methods'] ) ) );
				if ( ! empty( $h['args'] ) ) {
					$params = array_merge( $params, array_keys( $h['args'] ) );
				}
			}
			$out[] = array(
				'route'   => $route,
				'methods' => array_values( array_unique( $methods ) ),
				'args'    => array_slice( array_values( array_unique( $params ) ), 0, 40 ),
			);
			if ( count( $out ) >= 300 ) {
				break;
			}
		}
		return array( 'namespace' => trim( $args['namespace'], '/' ), 'routes' => $out );
	}

	public static function request( array $args ) {
		$method = strtoupper( $args['method'] );
		$route  = self::normalize_route( $args['route'] );
		$query  = array();
		if ( false !== strpos( $route, '?' ) ) {
			list( $route, $qs ) = explode( '?', $route, 2 );
			parse_str( $qs, $query );
		}
		$request = new \WP_REST_Request( $method, $route );
		$params  = isset( $args['params'] ) && is_array( $args['params'] ) ? $args['params'] : array();
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$request->set_query_params( array_merge( $query, $params ) );
		} else {
			$request->set_query_params( $query );
			$body = array_merge( $params, isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array() );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
			$request->set_body_params( $body );
		}
		$response = rest_do_request( $request );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, false );
		$status   = $response->get_status();
		$headers  = array_intersect_key( $response->get_headers(), array_flip( array( 'X-WP-Total', 'X-WP-TotalPages', 'Allow', 'Location' ) ) );
		$out      = array(
			'status'  => $status,
			'headers' => $headers ? $headers : new \stdClass(),
			'data'    => self::strip_links( $data ),
		);
		if ( $status >= 400 ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'Request failed.';
			return H::error( is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'rest_error', sprintf( 'HTTP %d: %s', $status, $msg ), $status >= 500 ? null : 'Check the route and arguments with list_rest_routes.', $status >= 500, $out );
		}
		return $out;
	}

	/** _links blocks are noise for the model. */
	private static function strip_links( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['_links'] );
			foreach ( $data as $k => $v ) {
				if ( is_array( $v ) ) {
					$data[ $k ] = self::strip_links( $v );
				}
			}
		}
		return $data;
	}

	public static function preview( array $args ) {
		return array(
			'request' => strtoupper( $args['method'] ) . ' ' . self::normalize_route( $args['route'] ),
			'params'  => isset( $args['params'] ) ? $args['params'] : new \stdClass(),
			'body'    => isset( $args['body'] ) ? $args['body'] : new \stdClass(),
		);
	}
}
