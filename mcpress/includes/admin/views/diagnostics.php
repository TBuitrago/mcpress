<?php
/**
 * Diagnostics screen.
 *
 * @package MCPress
 */

use MCPress\Admin\Diagnostics;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

$mcpress_checks = Diagnostics::run();
$mcpress_icons  = array(
	'ok'    => '✓',
	'warn'  => '!',
	'error' => '✕',
	'info'  => 'i',
);
$mcpress_words  = array(
	'ok'    => __( 'OK', 'mcpress' ),
	'warn'  => __( 'Check', 'mcpress' ),
	'error' => __( 'Problem', 'mcpress' ),
	'info'  => __( 'Info', 'mcpress' ),
);
?>
<section class="mcp-card">
	<h2><?php esc_html_e( 'Diagnostics', 'mcpress' ); ?></h2>
	<p class="mcp-muted"><?php esc_html_e( 'If your AI cannot connect, start here.', 'mcpress' ); ?></p>
	<ul class="mcp-checks">
		<?php foreach ( $mcpress_checks as $mcpress_c ) : ?>
			<li class="mcp-check-item mcp-check-item--<?php echo esc_attr( $mcpress_c['status'] ); ?>">
				<span class="mcp-check-item__icon" aria-hidden="true"><?php echo esc_html( $mcpress_icons[ $mcpress_c['status'] ] ); ?></span>
				<div>
					<strong><?php echo esc_html( $mcpress_c['label'] ); ?></strong>
					<span class="screen-reader-text"><?php echo esc_html( $mcpress_words[ $mcpress_c['status'] ] ); ?></span>
					<p><?php echo esc_html( $mcpress_c['message'] ); ?></p>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Endpoints', 'mcpress' ); ?></h2>
	<dl class="mcp-facts mcp-facts--wide">
		<div><dt>MCP</dt><dd><code><?php echo esc_html( Util::mcp_url() ); ?></code></dd></div>
		<div><dt><?php esc_html_e( 'OAuth metadata', 'mcpress' ); ?></dt><dd><code><?php echo esc_html( \MCPress\Auth\OAuth::issuer() . '/.well-known/openid-configuration' ); ?></code></dd></div>
		<div><dt><?php esc_html_e( 'Protected resource', 'mcpress' ); ?></dt><dd><code><?php echo esc_html( \MCPress\Auth\OAuth::protected_resource_url() ); ?></code></dd></div>
		<div><dt><?php esc_html_e( 'Version', 'mcpress' ); ?></dt><dd><code>MCPress <?php echo esc_html( MCPRESS_VERSION ); ?> · WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?> · PHP <?php echo esc_html( PHP_VERSION ); ?></code></dd></div>
	</dl>
</section>
