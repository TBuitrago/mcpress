<?php
/**
 * OAuth consent screen: wp-admin/admin.php?page=mcpress-authorize&client_id=…
 *
 * Rendered as a standalone page (no admin menu) right after WordPress verified the login.
 *
 * @package MCPress
 */

namespace MCPress\Admin;

use MCPress\Auth\Authenticator;
use MCPress\Auth\OAuth;
use MCPress\Permissions\Policy;
use MCPress\Settings;

defined( 'ABSPATH' ) || exit;

class Authorize_Page {

	const SLUG = 'mcpress-authorize';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_render' ), 1 );
	}

	/** Hidden page so that admin.php?page=mcpress-authorize is a valid screen. */
	public static function register() {
		add_submenu_page( '', __( 'Connect an AI', 'mcpress' ), '', 'read', self::SLUG, '__return_null' );
	}

	public static function maybe_render() {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );

		$query = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && is_scalar( $_REQUEST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$query[ $k ] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}
		// URLs must not be mangled by sanitize_text_field.
		foreach ( array( 'redirect_uri', 'client_id', 'resource' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && is_scalar( $_REQUEST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$query[ $k ] = trim( (string) wp_unslash( $_REQUEST[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			}
		}

		$valid = OAuth::validate_authorization_request( $query );
		if ( is_wp_error( $valid ) ) {
			$data = $valid->get_error_data();
			if ( is_array( $data ) && ! empty( $data['redirect'] ) ) {
				self::redirect_back( $data['params']['redirect_uri'], array( 'error' => $valid->get_error_code(), 'error_description' => $valid->get_error_message(), 'state' => $data['params']['state'] ) );
			}
			self::render_message( __( 'This connection cannot continue', 'mcpress' ), $valid->get_error_message() );
		}

		$client = $valid['client'];
		$params = $valid['params'];

		if ( ! current_user_can( Authenticator::connect_capability() ) ) {
			self::render_message(
				__( 'Your account cannot connect an AI', 'mcpress' ),
				__( 'Only administrators can connect an AI to this site. Log in with an administrator account and try again.', 'mcpress' ),
				wp_logout_url( add_query_arg( array_map( 'rawurlencode', array_merge( array( 'page' => self::SLUG ), $query ) ), admin_url( 'admin.php' ) ) ),
				__( 'Log in with another account', 'mcpress' )
			);
		}

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			check_admin_referer( 'mcpress_authorize_' . md5( $params['client_id'] . '|' . $params['redirect_uri'] ) );
			$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
			if ( 'approve' !== $decision ) {
				self::redirect_back( $params['redirect_uri'], array( 'error' => 'access_denied', 'error_description' => 'The site owner declined the connection.', 'state' => $params['state'] ) );
			}
			$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
			$mode = Policy::is_mode( $mode ) ? $mode : Settings::get( 'default_mode' );
			$code = OAuth::issue_code( $params, get_current_user_id(), $mode );
			self::redirect_back( $params['redirect_uri'], array( 'code' => $code, 'state' => $params['state'] ) );
		}

		self::render_consent( $client, $params, $query );
	}

	private static function redirect_back( $redirect_uri, array $query ) {
		if ( '' === $query['state'] ) {
			unset( $query['state'] );
		}
		$url = OAuth::redirect_url( $redirect_uri, $query );
		header( 'Location: ' . $url, true, 302 );
		exit;
	}

	private static function head( $title ) {
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?> · MCPress</title>
<link rel="stylesheet" href="<?php echo esc_url( MCPRESS_URL . 'assets/css/admin.css?ver=' . MCPRESS_VERSION ); ?>">
</head>
<body class="mcp-standalone">
<main class="mcp-consent">
<div class="mcp-consent__brand"><img src="<?php echo esc_url( MCPRESS_URL . 'assets/img/logo-light.svg' ); ?>" alt="MCPress" width="180" height="40"></div>
		<?php
	}

	private static function foot() {
		echo '</main></body></html>';
		exit;
	}

	private static function render_message( $title, $message, $link = '', $link_label = '' ) {
		self::head( $title );
		echo '<section class="mcp-card"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>';
		if ( $link ) {
			echo '<p><a class="mcp-key-btn" href="' . esc_url( $link ) . '">' . esc_html( $link_label ) . '</a></p>';
		}
		echo '</section>';
		self::foot();
	}

	private static function render_consent( array $client, array $params, array $query ) {
		$user         = wp_get_current_user();
		$client_name  = $client['client_name'] ? $client['client_name'] : __( 'An AI client', 'mcpress' );
		$redirect     = wp_parse_url( $params['redirect_uri'] );
		$return_label = isset( $redirect['host'] ) ? $redirect['host'] : $redirect['scheme'] . '://';
		$site         = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$default      = Settings::get( 'default_mode' );
		$action       = add_query_arg( array_map( 'rawurlencode', array_merge( array( 'page' => self::SLUG ), $query ) ), admin_url( 'admin.php' ) );

		/* translators: 1: AI client name, 2: site name. */
		self::head( sprintf( __( 'Connect %1$s to %2$s', 'mcpress' ), $client_name, $site ) );
		?>
<section class="mcp-card">
	<p class="mcp-eyebrow">■ <?php esc_html_e( 'New AI connection', 'mcpress' ); ?></p>
	<h1>
		<?php
		/* translators: 1: AI client name, 2: site name. */
		printf( esc_html__( '%1$s wants to work on %2$s', 'mcpress' ), '<strong>' . esc_html( $client_name ) . '</strong>', '<strong>' . esc_html( $site ) . '</strong>' );
		?>
	</h1>
	<p class="mcp-muted">
		<?php
		/* translators: 1: user login, 2: return host. */
		printf( esc_html__( 'It will act as %1$s, with that account\'s permissions. After you decide, you go back to %2$s.', 'mcpress' ), '<strong>' . esc_html( $user->user_login ) . '</strong>', '<code>' . esc_html( $return_label ) . '</code>' );
		?>
	</p>

	<form method="post" action="<?php echo esc_url( $action ); ?>">
		<?php wp_nonce_field( 'mcpress_authorize_' . md5( $params['client_id'] . '|' . $params['redirect_uri'] ) ); ?>
		<fieldset class="mcp-modes">
			<legend><?php esc_html_e( 'How much control do you give it?', 'mcpress' ); ?></legend>
			<?php foreach ( Policy::modes() as $mode ) : ?>
				<label class="mcp-mode mcp-mode--<?php echo esc_attr( $mode ); ?>">
					<input type="radio" name="mode" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $default, $mode ); ?>>
					<span class="mcp-mode__title"><?php echo esc_html( Policy::mode_label( $mode ) ); ?></span>
					<span class="mcp-mode__text"><?php echo esc_html( Policy::mode_description( $mode ) ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="mcp-muted"><?php esc_html_e( 'You can change the mode or disconnect at any time in wp-admin → MCPress. Everything the AI does is logged.', 'mcpress' ); ?></p>
		<div class="mcp-actions">
			<button type="submit" name="decision" value="approve" class="mcp-key-btn"><?php esc_html_e( 'Connect AI', 'mcpress' ); ?></button>
			<button type="submit" name="decision" value="deny" class="mcp-btn-ghost"><?php esc_html_e( 'Cancel', 'mcpress' ); ?></button>
		</div>
	</form>
</section>
		<?php
		self::foot();
	}
}
