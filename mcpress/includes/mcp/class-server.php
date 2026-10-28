<?php
/**
 * MCP protocol (JSON-RPC 2.0): initialize, tools, resources, prompts.
 *
 * @package MCPress
 */

namespace MCPress\MCP;

use MCPress\Context;
use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Server {

	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	const PARSE_ERROR      = -32700;
	const INVALID_REQUEST  = -32600;
	const METHOD_NOT_FOUND = -32601;
	const INVALID_PARAMS   = -32602;
	const INTERNAL_ERROR   = -32603;

	/** @var Context */
	private $ctx;

	public function __construct( Context $ctx ) {
		$this->ctx = $ctx;
	}

	/**
	 * Handle one JSON-RPC message.
	 *
	 * @param mixed $msg Decoded message.
	 * @return array|null Response, or null for notifications and client responses.
	 */
	public function handle( $msg ) {
		if ( ! is_array( $msg ) || ! isset( $msg['jsonrpc'] ) || '2.0' !== $msg['jsonrpc'] ) {
			return self::error( null, self::INVALID_REQUEST, 'Invalid Request: expected a JSON-RPC 2.0 object.' );
		}
		$has_id = array_key_exists( 'id', $msg );
		$id     = $has_id ? $msg['id'] : null;

		if ( ! isset( $msg['method'] ) ) {
			return null; // A response to a server request; we never send any, so nothing to do.
		}
		if ( ! is_string( $msg['method'] ) ) {
			return self::error( $id, self::INVALID_REQUEST, 'Invalid Request: method must be a string.' );
		}
		if ( ! $has_id ) {
			return null; // Notification (notifications/initialized, notifications/cancelled…).
		}

		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		try {
			switch ( $msg['method'] ) {
				case 'initialize':
					return self::result( $id, $this->initialize( $params ) );
				case 'ping':
					return self::result( $id, new \stdClass() );
				case 'tools/list':
					return self::result( $id, array( 'tools' => $this->list_tools() ) );
				case 'tools/call':
					if ( empty( $params['name'] ) || ! is_string( $params['name'] ) ) {
						return self::error( $id, self::INVALID_PARAMS, 'tools/call requires params.name.' );
					}
					$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
					Executor::$current_request_id = $id;
					return self::result( $id, Executor::call( $params['name'], $args, $this->ctx ) );
				case 'resources/list':
					return self::result( $id, array( 'resources' => Resources::all() ) );
				case 'resources/templates/list':
					return self::result( $id, array( 'resourceTemplates' => array() ) );
				case 'resources/read':
					$uri  = isset( $params['uri'] ) ? (string) $params['uri'] : '';
					$read = Resources::read( $uri, $this->ctx );
					if ( null === $read ) {
						return self::error( $id, -32002, 'Resource not found: ' . $uri );
					}
					return self::result( $id, array( 'contents' => array( $read ) ) );
				case 'prompts/list':
					return self::result( $id, array( 'prompts' => Prompts::all() ) );
				case 'prompts/get':
					$prompt = Prompts::get( isset( $params['name'] ) ? (string) $params['name'] : '', isset( $params['arguments'] ) ? (array) $params['arguments'] : array() );
					if ( null === $prompt ) {
						return self::error( $id, self::INVALID_PARAMS, 'Unknown prompt.' );
					}
					return self::result( $id, $prompt );
				case 'logging/setLevel':
					return self::result( $id, new \stdClass() );
				case 'completion/complete':
					return self::result(
						$id,
						array(
							'completion' => array(
								'values'  => array(),
								'total'   => 0,
								'hasMore' => false,
							),
						)
					);
			}
		} catch ( \Throwable $e ) {
			return self::error( $id, self::INTERNAL_ERROR, 'Internal error: ' . $e->getMessage() );
		}

		return self::error( $id, self::METHOD_NOT_FOUND, 'Method not found: ' . $msg['method'] );
	}

	private function initialize( array $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => false,
				),
				'prompts'   => array( 'listChanged' => false ),
				'logging'   => new \stdClass(),
			),
			'serverInfo'      => array(
				'name'        => 'mcpress',
				'title'       => 'MCPress · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'version'     => MCPRESS_VERSION,
				'websiteUrl'  => 'https://mcpress.tbadigitals.com',
				'description' => 'Full control of a WordPress site over MCP, with the permission mode chosen by the site owner.',
				'icons'       => array(
					array(
						'src'      => MCPRESS_URL . 'assets/img/symbol-light.svg',
						'mimeType' => 'image/svg+xml',
					),
				),
			),
			'instructions'    => self::instructions( $this->ctx ),
		);
	}

	/** Guidance the client gives the model. Kept short; the full guide is the mcpress://guide resource. */
	public static function instructions( Context $ctx ) {
		$mode  = $ctx->mode;
		$lines = array();

		$lines[] = sprintf( 'You are connected to the WordPress site "%s" (%s) through MCPress, acting as the user "%s".', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url(), $ctx->user() ? $ctx->user()->user_login : '?' );
		$lines[] = sprintf( 'Permission mode of this connection: %s.', self::mode_for_ai( $mode ) );
		if ( Policy::MODE_READONLY !== $mode && Policy::MODE_FULL !== $mode ) {
			$lines[] = 'When a tool answers with status "approval_required", nothing has run yet: tell the user what you asked for and the approval link, then call check_approval with the approval_id (wait_seconds up to 25). Never repeat the original call to get around it.';
		}
		$lines[] = 'Start with site_info to learn the WordPress version, theme, active plugins and what this connection may do. Prefer small, targeted edits (edit_post_content, edit_file) over rewriting whole documents. Use rest_request to reach any plugin REST API (WooCommerce, ACF, SEO plugins…). Errors explain what to do next: follow the hint instead of retrying blindly.';
		$lines[] = 'MCPress settings, keys and its own files are off-limits by design; if the user wants a different mode they change it in wp-admin → MCPress.';

		return implode( "\n", $lines );
	}

	public static function mode_for_ai( $mode ) {
		$map = array(
			Policy::MODE_READONLY => 'READ ONLY (you can read; every change is refused)',
			Policy::MODE_APPROVAL => 'PRIOR APPROVAL (reads run immediately; every change waits for the site owner to approve it)',
			Policy::MODE_AUTO     => 'AUTOMATIC (changes run immediately; critical actions wait for approval)',
			Policy::MODE_FULL     => 'FULL ACCESS (everything runs immediately, nothing is confirmed: be careful, double-check destructive actions with the user when in doubt)',
		);
		return isset( $map[ $mode ] ) ? $map[ $mode ] : $mode;
	}

	private function list_tools() {
		$out = array();
		$mode = $this->ctx->mode;
		foreach ( Tool_Registry::instance()->all() as $tool ) {
			// Hidden when it can never run for this connection.
			if ( ! Policy::group_enabled( $tool['group'] ) || Policy::DENY === Policy::tool_rule( $tool['name'] ) ) {
				continue;
			}
			$dynamic = is_callable( $tool['risk'] );
			if ( Policy::MODE_READONLY === $mode && Policy::RISK_READ !== $tool['base_risk'] && ! $dynamic ) {
				continue;
			}
			$description = $tool['description'];
			if ( $dynamic && Policy::MODE_READONLY === $mode ) {
				$description .= ' [Read-only connection: only read operations are accepted.]';
			} elseif ( $dynamic && Policy::MODE_APPROVAL === $mode ) {
				$description .= ' [Write operations need the site owner\'s approval in this connection.]';
			} elseif ( $dynamic && Policy::MODE_AUTO === $mode ) {
				$description .= ' [Critical operations need the site owner\'s approval in this connection.]';
			} elseif ( ! $dynamic && Policy::ASK === Policy::expected( $tool, $this->ctx ) ) {
				$description .= ' [Needs the site owner\'s approval in this connection.]';
			}
			$out[] = array(
				'name'        => $tool['name'],
				'title'       => $tool['title'],
				'description' => $description,
				'inputSchema' => $tool['input_schema'],
				'annotations' => Tool_Registry::annotations( $tool ),
			);
		}
		return $out;
	}

	public static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	public static function error( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => (int) $code,
			'message' => (string) $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}
}
