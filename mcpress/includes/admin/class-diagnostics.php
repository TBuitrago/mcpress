<?php
/**
 * Connectivity and environment checks for the Diagnostics screen.
 *
 * @package MCPress
 */

namespace MCPress\Admin;

use MCPress\Auth\OAuth;
use MCPress\Installer;
use MCPress\Settings;
use MCPress\Updater;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Diagnostics {

	/** @return array<int, array{label:string,status:string,message:string}> */
	public static function run() {
		global $wpdb;
		$checks = array();
		$mcp    = Util::mcp_url();

		$https    = 0 === strpos( $mcp, 'https://' );
		$checks[] = self::item(
			__( 'HTTPS', 'mcpress' ),
			$https ? 'ok' : 'error',
			$https ? __( 'The MCP URL uses HTTPS.', 'mcpress' ) : __( 'The site is not on HTTPS. Keys would travel in clear text, and Claude/ChatGPT refuse http:// connectors.', 'mcpress' )
		);

		$pretty   = (bool) get_option( 'permalink_structure' );
		$checks[] = self::item(
			__( 'Permalinks', 'mcpress' ),
			$pretty ? 'ok' : 'warn',
			$pretty ? __( 'Pretty permalinks are on.', 'mcpress' ) : __( 'Plain permalinks: API keys work, but OAuth discovery (Claude, ChatGPT) needs pretty permalinks.', 'mcpress' )
		);

		// Loopback 1: is the Authorization header reaching PHP?
		$probe = wp_remote_post(
			$mcp,
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array(
					'Authorization' => 'Bearer mcpk_' . str_repeat( '0', 48 ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json, text/event-stream',
				),
				'body'      => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
			)
		);
		if ( is_wp_error( $probe ) ) {
			$checks[] = self::item( __( 'MCP endpoint', 'mcpress' ), 'warn', sprintf( /* translators: %s: error. */ __( 'The site could not call itself to test the endpoint (%s). This is common on local or firewalled servers; test from your AI client instead.', 'mcpress' ), $probe->get_error_message() ) );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $probe );
			$body = json_decode( wp_remote_retrieve_body( $probe ), true );
			$err  = is_array( $body ) && isset( $body['error'] ) ? $body['error'] : '';
			if ( 401 === $code && 'invalid_token' === $err ) {
				$checks[] = self::item( __( 'MCP endpoint', 'mcpress' ), 'ok', __( 'The endpoint answers and the Authorization header reaches WordPress.', 'mcpress' ) );
			} elseif ( 401 === $code ) {
				$checks[] = self::item( __( 'Authorization header', 'mcpress' ), 'error', __( 'Your server removes the Authorization header. Add this line to .htaccess, above the WordPress block: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1 — or configure your client to send the key in the X-MCPress-Key header.', 'mcpress' ) );
			} else {
				$checks[] = self::item( __( 'MCP endpoint', 'mcpress' ), 'error', sprintf( /* translators: %d: HTTP status. */ __( 'Unexpected HTTP %d from the MCP endpoint. A security plugin or firewall may be blocking the REST API.', 'mcpress' ), $code ) );
			}
		}

		// Loopback 2: OAuth discovery.
		if ( Settings::get( 'oauth_enabled' ) ) {
			$prm = wp_remote_get( OAuth::protected_resource_url(), array( 'timeout' => 10, 'sslverify' => false ) );
			$as  = wp_remote_get( OAuth::issuer() . '/.well-known/openid-configuration', array( 'timeout' => 10, 'sslverify' => false ) );
			$wk  = wp_remote_get( home_url( '/.well-known/oauth-protected-resource' ), array( 'timeout' => 10, 'sslverify' => false ) );
			$ok1 = ! is_wp_error( $prm ) && 200 === (int) wp_remote_retrieve_response_code( $prm );
			$ok2 = ! is_wp_error( $as ) && 200 === (int) wp_remote_retrieve_response_code( $as );
			$ok3 = ! is_wp_error( $wk ) && 200 === (int) wp_remote_retrieve_response_code( $wk );
			if ( $ok1 && $ok2 ) {
				$checks[] = self::item(
					__( 'OAuth discovery', 'mcpress' ),
					'ok',
					$ok3 ? __( 'Metadata is published, including /.well-known/ at the site root.', 'mcpress' ) : __( 'Metadata is published through the REST API. /.well-known/ at the root is not reachable (fine for current clients; some older clients may need it).', 'mcpress' )
				);
			} elseif ( is_wp_error( $prm ) || is_wp_error( $as ) ) {
				$checks[] = self::item( __( 'OAuth discovery', 'mcpress' ), 'warn', __( 'Could not test (the site cannot call itself).', 'mcpress' ) );
			} else {
				$checks[] = self::item( __( 'OAuth discovery', 'mcpress' ), 'error', __( 'OAuth metadata is not reachable. Claude and ChatGPT connectors will fail; API keys still work.', 'mcpress' ) );
			}
		} else {
			$checks[] = self::item( __( 'OAuth', 'mcpress' ), 'info', __( 'OAuth is off. Only API keys can connect.', 'mcpress' ) );
		}

		$php      = PHP_VERSION;
		$checks[] = self::item( 'PHP', version_compare( $php, '8.1', '>=' ) ? 'ok' : 'warn', sprintf( /* translators: %s: version. */ __( 'PHP %s.', 'mcpress' ), $php ) . ( version_compare( $php, '8.1', '<' ) ? ' ' . __( 'PHP 8.1 or newer is recommended.', 'mcpress' ) : '' ) );

		$missing = array();
		foreach ( Installer::tables() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				$missing[] = $table;
			}
		}
		$checks[] = self::item( __( 'Database tables', 'mcpress' ), $missing ? 'error' : 'ok', $missing ? sprintf( /* translators: %s: table names. */ __( 'Missing: %s. Deactivate and activate MCPress.', 'mcpress' ), implode( ', ', $missing ) ) : __( 'All MCPress tables exist.', 'mcpress' ) );

		$mods     = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
		$edit     = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$checks[] = self::item(
			__( 'File changes', 'mcpress' ),
			$mods || $edit ? 'info' : 'ok',
			$mods ? __( 'DISALLOW_FILE_MODS is on: your AI cannot install, update or delete plugins/themes, nor edit files.', 'mcpress' ) : ( $edit ? __( 'DISALLOW_FILE_EDIT is on: your AI cannot edit plugin/theme files (installing and updating still work).', 'mcpress' ) : __( 'Your AI can manage plugins, themes and files (subject to its mode).', 'mcpress' ) )
		);

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$method   = get_filesystem_method();
		$checks[] = self::item( __( 'Filesystem access', 'mcpress' ), 'direct' === $method ? 'ok' : 'warn', 'direct' === $method ? __( 'WordPress can write files directly.', 'mcpress' ) : sprintf( /* translators: %s: method. */ __( 'WordPress needs "%s" credentials to write files. Installing plugins and editing files from your AI will fail unless FS_METHOD and credentials are defined in wp-config.php.', 'mcpress' ), $method ) );

		$status   = Updater::status();
		$checks[] = self::item(
			__( 'Updates from GitHub', 'mcpress' ),
			empty( $status ) ? 'info' : ( ! empty( $status['ok'] ) ? 'ok' : 'warn' ),
			empty( $status ) ? __( 'Not checked yet.', 'mcpress' ) : ( ! empty( $status['ok'] ) ? sprintf( /* translators: %s: version. */ __( 'GitHub reachable. Latest release: %s.', 'mcpress' ), $status['version'] ) : sprintf( /* translators: %s: error. */ __( 'Could not read GitHub releases: %s', 'mcpress' ), $status['error'] ) )
		);

		$auto     = ! ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! wp_next_scheduled( 'wp_version_check' ) );
		$checks[] = self::item(
			__( 'Automatic updates', 'mcpress' ),
			Settings::get( 'auto_update' ) ? ( $auto ? 'ok' : 'warn' ) : 'info',
			Settings::get( 'auto_update' ) ? ( $auto ? __( 'MCPress updates itself in the background.', 'mcpress' ) : __( 'Automatic updates are disabled on this server (AUTOMATIC_UPDATER_DISABLED or no cron). Update from the Plugins screen.', 'mcpress' ) ) : __( 'Automatic updates for MCPress are off in Settings.', 'mcpress' )
		);

		$checks[] = self::item(
			__( 'Abilities API', 'mcpress' ),
			function_exists( 'wp_get_abilities' ) ? 'ok' : 'info',
			function_exists( 'wp_get_abilities' ) ? __( 'Available: your AI can run abilities registered by other plugins.', 'mcpress' ) : __( 'Not available (WordPress 6.9+). The rest of MCPress works normally.', 'mcpress' )
		);

		if ( defined( 'MCPRESS_DISABLE_PHP_EXECUTION' ) && MCPRESS_DISABLE_PHP_EXECUTION ) {
			$checks[] = self::item( __( 'PHP execution', 'mcpress' ), 'info', __( 'MCPRESS_DISABLE_PHP_EXECUTION is set: run_php is unavailable.', 'mcpress' ) );
		}

		return $checks;
	}

	private static function item( $label, $status, $message ) {
		return compact( 'label', 'status', 'message' );
	}
}
