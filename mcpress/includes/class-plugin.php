<?php
/**
 * Wires every component together.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function boot() {
		Installer::maybe_upgrade();
		add_action( 'init', array( 'MCPress\\I18n', 'init' ), 1 );

		MCP\Transport::init();
		Auth\OAuth::init();
		Permissions\Approvals::init();
		Audit_Log::init();
		Updater::init();
		Admin\Admin_Bar::init();

		if ( is_admin() ) {
			Admin\Admin::init();
			Admin\Authorize_Page::init();
		}

		add_action( 'mcpress_daily', array( __CLASS__, 'daily_maintenance' ) );

		/**
		 * Fires when MCPress is loaded. Use mcpress_register_tool() from here on.
		 */
		do_action( 'mcpress_loaded' );
	}

	public static function daily_maintenance() {
		Audit_Log::purge( (int) Settings::get( 'log_retention_days' ) );
		Auth\Tokens::cleanup();
		Auth\OAuth::cleanup_clients();
		Permissions\Approvals::expire_old();
		Backups::cleanup();
	}
}
