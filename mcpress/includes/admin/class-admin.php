<?php
/**
 * wp-admin screens and their form handlers.
 *
 * @package MCPress
 */

namespace MCPress\Admin;

use MCPress\Audit_Log;
use MCPress\Auth\Authenticator;
use MCPress\Auth\Connections;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Approvals;
use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Updater;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Admin {

	const PAGES = array(
		'mcpress'             => 'connect',
		'mcpress-approvals'   => 'approvals',
		'mcpress-activity'    => 'activity',
		'mcpress-settings'    => 'settings',
		'mcpress-diagnostics' => 'diagnostics',
	);

	const ACTIONS = array(
		'create_key',
		'update_connection',
		'revoke_connection',
		'approve',
		'reject',
		'save_settings',
		'check_updates',
		'revoke_all',
		'clear_log',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_filter( 'plugin_action_links_' . MCPRESS_BASENAME, array( __CLASS__, 'action_links' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_mcpress_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function capability() {
		return Authenticator::connect_capability();
	}

	public static function menu() {
		$cap     = self::capability();
		$pending = current_user_can( $cap ) ? Approvals::count_pending() : 0;
		$bubble  = $pending ? ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . number_format_i18n( $pending ) . '</span></span>' : '';
		$icon    = 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( MCPRESS_DIR . 'assets/img/menu-icon.svg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		add_menu_page( 'MCPress', 'MCPress' . $bubble, $cap, 'mcpress', array( __CLASS__, 'render' ), $icon, 81 );
		add_submenu_page( 'mcpress', __( 'Connect your AI', 'mcpress' ), __( 'Connect', 'mcpress' ), $cap, 'mcpress', array( __CLASS__, 'render' ) );
		add_submenu_page( 'mcpress', __( 'Approvals', 'mcpress' ), __( 'Approvals', 'mcpress' ) . $bubble, $cap, 'mcpress-approvals', array( __CLASS__, 'render' ) );
		add_submenu_page( 'mcpress', __( 'Activity', 'mcpress' ), __( 'Activity', 'mcpress' ), $cap, 'mcpress-activity', array( __CLASS__, 'render' ) );
		add_submenu_page( 'mcpress', __( 'Settings', 'mcpress' ), __( 'Settings', 'mcpress' ), $cap, 'mcpress-settings', array( __CLASS__, 'render' ) );
		add_submenu_page( 'mcpress', __( 'Diagnostics', 'mcpress' ), __( 'Diagnostics', 'mcpress' ), $cap, 'mcpress-diagnostics', array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::PAGES[ $page ] ) ) {
			return;
		}
		wp_enqueue_style( 'mcpress-admin', MCPRESS_URL . 'assets/css/admin.css', array(), MCPRESS_VERSION );
		wp_enqueue_script( 'mcpress-admin', MCPRESS_URL . 'assets/js/admin.js', array(), MCPRESS_VERSION, true );
		wp_localize_script(
			'mcpress-admin',
			'mcpressAdmin',
			array(
				'copied'        => __( 'Copied', 'mcpress' ),
				'copy'          => __( 'Copy', 'mcpress' ),
				'confirmRevoke' => __( 'Disconnect this AI? It will stop working immediately.', 'mcpress' ),
				'confirmAll'    => __( 'Disconnect every AI from this site?', 'mcpress' ),
				'confirmClear'  => __( 'Delete the whole activity log?', 'mcpress' ),
				'confirmFull'   => __( 'Full access lets the AI do anything without asking, including running PHP. Continue?', 'mcpress' ),
			)
		);
	}

	public static function activation_redirect() {
		if ( ! get_transient( 'mcpress_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'mcpress_activation_redirect' );
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( self::capability() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mcpress' ) );
		exit;
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=mcpress' ) ) . '">' . esc_html__( 'Connect', 'mcpress' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=mcpress-settings' ) ) . '">' . esc_html__( 'Settings', 'mcpress' ) . '</a>'
		);
		return $links;
	}

	/* ----------------------------------------------------------------- Render */

	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage MCPress.', 'mcpress' ) );
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'mcpress'; // phpcs:ignore WordPress.Security.NonceVerification
		$view = isset( self::PAGES[ $page ] ) ? self::PAGES[ $page ] : 'connect';

		echo '<div class="wrap mcp-wrap">';
		self::header( $page );
		self::notices();
		include MCPRESS_DIR . 'includes/admin/views/' . $view . '.php';
		echo '</div>';
	}

	private static function header( $current ) {
		$tabs = array(
			'mcpress'             => __( 'Connect', 'mcpress' ),
			'mcpress-approvals'   => __( 'Approvals', 'mcpress' ),
			'mcpress-activity'    => __( 'Activity', 'mcpress' ),
			'mcpress-settings'    => __( 'Settings', 'mcpress' ),
			'mcpress-diagnostics' => __( 'Diagnostics', 'mcpress' ),
		);
		$pending = Approvals::count_pending();
		?>
		<header class="mcp-header">
			<div class="mcp-header__brand">
				<img src="<?php echo esc_url( MCPRESS_URL . 'assets/img/logo-light.svg' ); ?>" alt="MCPress" width="150" height="33">
				<span class="mcp-header__tagline"><?php esc_html_e( 'Your AI runs WordPress. You set the rules.', 'mcpress' ); ?></span>
			</div>
			<nav class="mcp-tabs" aria-label="MCPress">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="mcp-tab<?php echo $slug === $current ? ' is-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
						<?php if ( 'mcpress-approvals' === $slug && $pending ) : ?>
							<span class="mcp-count"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</header>
		<h1 class="screen-reader-text">MCPress</h1>
		<?php
	}

	private static function notices() {
		$notice = get_transient( 'mcpress_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'mcpress_notice_' . get_current_user_id() );
		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
	}

	/* --------------------------------------------------------------- Helpers */

	public static function notice( $message, $type = 'success' ) {
		set_transient(
			'mcpress_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage MCPress.', 'mcpress' ), 403 );
		}
		check_admin_referer( 'mcpress_' . $action );
	}

	private static function back( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	public static function action_url( $action ) {
		return admin_url( 'admin-post.php' );
	}

	public static function form_open( $action, $class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="mcpress_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'mcpress_' . $action );
	}

	public static function mode_badge( $mode ) {
		return '<span class="mcp-badge mcp-badge--mode-' . esc_attr( $mode ) . '">' . esc_html( Policy::mode_label( $mode ) ) . '</span>';
	}

	public static function risk_badge( $risk ) {
		return '<span class="mcp-badge mcp-badge--risk-' . esc_attr( $risk ) . '">' . esc_html( Policy::risk_label( $risk ) ) . '</span>';
	}

	public static function status_badge( $status ) {
		$labels = array(
			'pending'  => __( 'Pending', 'mcpress' ),
			'approved' => __( 'Approved', 'mcpress' ),
			'executed' => __( 'Approved and run', 'mcpress' ),
			'failed'   => __( 'Approved, failed', 'mcpress' ),
			'rejected' => __( 'Rejected', 'mcpress' ),
			'expired'  => __( 'Expired', 'mcpress' ),
			'ok'       => __( 'Done', 'mcpress' ),
			'error'    => __( 'Error', 'mcpress' ),
			'allowed'  => __( 'Ran directly', 'mcpress' ),
			'denied'   => __( 'Refused', 'mcpress' ),
			'blocked'  => __( 'Blocked by a guard', 'mcpress' ),
		);
		$icons  = array(
			'pending'  => '◷',
			'approved' => '✓',
			'executed' => '✓',
			'ok'       => '✓',
			'allowed'  => '✓',
			'failed'   => '!',
			'error'    => '!',
			'rejected' => '✕',
			'denied'   => '✕',
			'blocked'  => '✕',
			'expired'  => '–',
		);
		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		$icon  = isset( $icons[ $status ] ) ? $icons[ $status ] : '';
		return '<span class="mcp-status mcp-status--' . esc_attr( $status ) . '"><span aria-hidden="true">' . esc_html( $icon ) . '</span> ' . esc_html( $label ) . '</span>';
	}

	public static function connection_label( $connection_id ) {
		static $cache = array();
		$connection_id = (int) $connection_id;
		if ( ! $connection_id ) {
			return __( 'Application password', 'mcpress' );
		}
		if ( ! isset( $cache[ $connection_id ] ) ) {
			$c                       = Connections::get( $connection_id );
			$cache[ $connection_id ] = $c ? $c['name'] . ( $c['revoked_at'] ? ' (' . __( 'disconnected', 'mcpress' ) . ')' : '' ) : '#' . $connection_id;
		}
		return $cache[ $connection_id ];
	}

	public static function pretty_json( $data ) {
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			$data    = null === $decoded ? $data : $decoded;
		}
		return is_string( $data ) ? $data : Util::json( $data, true );
	}

	/* --------------------------------------------------------------- Actions */

	public static function handle_create_key() {
		self::guard( 'create_key' );
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : Settings::get( 'default_mode' );
		$days    = isset( $_POST['expires_days'] ) ? absint( $_POST['expires_days'] ) : 0;
		$url_key = ! empty( $_POST['url_key'] ) && Settings::get( 'allow_url_keys' );

		$created = Connections::create_key( $name, get_current_user_id(), $mode, $days, $url_key );
		// Shown once on the next screen, then gone.
		set_transient(
			'mcpress_new_key_' . get_current_user_id(),
			array(
				'id'      => $created['id'],
				'key'     => $created['key'],
				'url_key' => $url_key,
			),
			MINUTE_IN_SECONDS * 10
		);
		self::notice( __( 'Key created. Copy it now: it will not be shown again.', 'mcpress' ) );
		self::back( 'mcpress', array( 'created' => $created['id'] ) );
	}

	public static function handle_update_connection() {
		self::guard( 'update_connection' );
		$id = isset( $_POST['connection_id'] ) ? absint( $_POST['connection_id'] ) : 0;
		if ( ! $id || ! Connections::get( $id ) ) {
			self::back( 'mcpress' );
		}
		$fields = array();
		if ( isset( $_POST['mode'] ) ) {
			$fields['mode'] = sanitize_key( wp_unslash( $_POST['mode'] ) );
		}
		if ( isset( $_POST['name'] ) ) {
			$fields['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ) );
		}
		if ( $fields ) {
			Connections::update( $id, $fields );
		}
		if ( ! empty( $_POST['disallow_tool'] ) ) {
			Connections::disallow_tool( $id, sanitize_key( wp_unslash( $_POST['disallow_tool'] ) ) );
		}
		self::notice( __( 'Connection updated.', 'mcpress' ) );
		self::back( 'mcpress' );
	}

	public static function handle_revoke_connection() {
		self::guard( 'revoke_connection' );
		$id = isset( $_POST['connection_id'] ) ? absint( $_POST['connection_id'] ) : 0;
		if ( $id ) {
			Connections::revoke( $id );
			self::notice( __( 'Disconnected. That AI can no longer reach this site.', 'mcpress' ) );
		}
		self::back( 'mcpress' );
	}

	public static function handle_revoke_all() {
		self::guard( 'revoke_all' );
		Connections::revoke_all();
		self::notice( __( 'Every AI was disconnected.', 'mcpress' ) );
		self::back( 'mcpress-settings' );
	}

	public static function handle_approve() {
		self::guard( 'approve' );
		$id     = isset( $_POST['approval'] ) ? sanitize_text_field( wp_unslash( $_POST['approval'] ) ) : '';
		$always = ! empty( $_POST['always'] );
		$result = Approvals::approve( $id, get_current_user_id(), $always );
		if ( is_wp_error( $result ) ) {
			self::notice( esc_html( $result->get_error_message() ), 'error' );
		} elseif ( 'executed' === $result['status'] ) {
			self::notice( $always ? __( 'Approved and run. This connection can now use that tool without asking.', 'mcpress' ) : __( 'Approved and run. Your AI will get the result when it checks.', 'mcpress' ) );
		} else {
			self::notice( __( 'Approved, but the action failed. The error was sent to your AI.', 'mcpress' ), 'warning' );
		}
		self::back( 'mcpress-approvals', array( 'approval' => rawurlencode( $id ) ) );
	}

	public static function handle_reject() {
		self::guard( 'reject' );
		$id     = isset( $_POST['approval'] ) ? sanitize_text_field( wp_unslash( $_POST['approval'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = Approvals::reject( $id, get_current_user_id(), $note );
		if ( is_wp_error( $result ) ) {
			self::notice( esc_html( $result->get_error_message() ), 'error' );
		} else {
			self::notice( __( 'Rejected. Your AI will be told when it checks.', 'mcpress' ) );
		}
		self::back( 'mcpress-approvals', array( 'approval' => rawurlencode( $id ) ) );
	}

	public static function handle_save_settings() {
		self::guard( 'save_settings' );
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$rules = array();
		if ( isset( $p['tool_rules'] ) && is_array( $p['tool_rules'] ) ) {
			foreach ( $p['tool_rules'] as $tool => $rule ) {
				if ( in_array( $rule, array( 'allow', 'ask', 'deny' ), true ) ) {
					$rules[ sanitize_key( $tool ) ] = $rule;
				}
			}
		}
		$enabled  = isset( $p['groups'] ) && is_array( $p['groups'] ) ? array_map( 'sanitize_key', $p['groups'] ) : array();
		$disabled = array_values( array_diff( array_keys( Tool_Registry::groups() ), $enabled, array( 'mcpress' ) ) );

		Settings::update(
			array(
				'default_mode'        => isset( $p['default_mode'] ) ? sanitize_key( $p['default_mode'] ) : Policy::MODE_APPROVAL,
				'approval_ttl_hours'  => isset( $p['approval_ttl_hours'] ) ? absint( $p['approval_ttl_hours'] ) : 24,
				'notify_email'        => ! empty( $p['notify_email'] ),
				'notify_email_to'     => isset( $p['notify_email_to'] ) ? sanitize_email( $p['notify_email_to'] ) : '',
				'protect_sensitive'   => ! empty( $p['protect_sensitive'] ),
				'allow_app_passwords' => ! empty( $p['allow_app_passwords'] ),
				'allow_url_keys'      => ! empty( $p['allow_url_keys'] ),
				'oauth_enabled'       => ! empty( $p['oauth_enabled'] ),
				'access_token_ttl'    => isset( $p['access_token_ttl_minutes'] ) ? absint( $p['access_token_ttl_minutes'] ) * MINUTE_IN_SECONDS : HOUR_IN_SECONDS,
				'refresh_token_days'  => isset( $p['refresh_token_days'] ) ? absint( $p['refresh_token_days'] ) : 90,
				'log_retention_days'  => isset( $p['log_retention_days'] ) ? absint( $p['log_retention_days'] ) : 90,
				'log_arguments'       => ! empty( $p['log_arguments'] ),
				'auto_update'         => ! empty( $p['auto_update'] ),
				'update_channel'      => isset( $p['update_channel'] ) ? sanitize_key( $p['update_channel'] ) : 'stable',
				'tool_rules'          => $rules,
				'disabled_groups'     => $disabled,
			)
		);
		delete_site_transient( Updater::CACHE_KEY );
		self::notice( __( 'Settings saved.', 'mcpress' ) );
		self::back( 'mcpress-settings' );
	}

	public static function handle_check_updates() {
		self::guard( 'check_updates' );
		$release = Updater::check_now();
		if ( ! $release ) {
			$status = Updater::status();
			/* translators: %s: error message. */
			self::notice( sprintf( esc_html__( 'Could not read the releases on GitHub: %s', 'mcpress' ), esc_html( isset( $status['error'] ) ? $status['error'] : '' ) ), 'error' );
		} elseif ( version_compare( $release['version'], MCPRESS_VERSION, '>' ) ) {
			/* translators: %s: version number. */
			self::notice( sprintf( esc_html__( 'Version %s is available. Update it from the Plugins screen, or wait for the automatic update.', 'mcpress' ), esc_html( $release['version'] ) ) . ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins', 'mcpress' ) . '</a>', 'info' );
		} else {
			self::notice( esc_html__( 'You have the latest version.', 'mcpress' ) );
		}
		self::back( 'mcpress-settings' );
	}

	public static function handle_clear_log() {
		self::guard( 'clear_log' );
		Audit_Log::clear();
		self::notice( __( 'Activity log cleared.', 'mcpress' ) );
		self::back( 'mcpress-activity' );
	}
}
