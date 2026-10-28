<?php
/**
 * Admin bar badge with the number of requests waiting for approval.
 *
 * @package MCPress
 */

namespace MCPress\Admin;

use MCPress\Auth\Authenticator;
use MCPress\Permissions\Approvals;

defined( 'ABSPATH' ) || exit;

class Admin_Bar {

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'node' ), 90 );
		add_action( 'wp_head', array( __CLASS__, 'style' ) );
		add_action( 'admin_head', array( __CLASS__, 'style' ) );
	}

	public static function node( \WP_Admin_Bar $bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( Authenticator::connect_capability() ) ) {
			return;
		}
		$pending = Approvals::count_pending();
		if ( ! $pending ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'mcpress-approvals',
				'title' => '<span class="mcp-ab-dot" aria-hidden="true"></span>' . esc_html(
					sprintf(
						/* translators: %d: number of pending requests. */
						_n( '%d AI request', '%d AI requests', $pending, 'mcpress' ),
						$pending
					)
				),
				'href'  => Approvals::admin_url(),
				'meta'  => array( 'title' => __( 'Your AI is waiting for your approval', 'mcpress' ) ),
			)
		);
	}

	public static function style() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		echo '<style>#wpadminbar #wp-admin-bar-mcpress-approvals>.ab-item{background:#415BF7;color:#fff}#wpadminbar #wp-admin-bar-mcpress-approvals .mcp-ab-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#F4F1EA;margin-right:6px;vertical-align:middle;animation:mcpab 1.6s ease-in-out infinite}@keyframes mcpab{50%{opacity:.35}}@media (prefers-reduced-motion:reduce){#wpadminbar .mcp-ab-dot{animation:none}}</style>';
	}
}
