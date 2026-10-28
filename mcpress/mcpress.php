<?php
/**
 * Plugin Name:       MCPress
 * Plugin URI:        https://mcpress.tbadigitals.com
 * Description:       Connect your AI to WordPress over MCP. Your AI runs WordPress; you set the rules: prior approval or fully automatic.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            TBA Digitals
 * Author URI:        https://tbadigitals.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcpress
 * Domain Path:       /languages
 * Update URI:        https://github.com/TBuitrago/mcpress
 *
 * @package MCPress
 */

defined( 'ABSPATH' ) || exit;

define( 'MCPRESS_VERSION', '0.1.0' );
define( 'MCPRESS_DB_VERSION', 1 );
define( 'MCPRESS_FILE', __FILE__ );
define( 'MCPRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCPRESS_URL', plugin_dir_url( __FILE__ ) );
define( 'MCPRESS_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'MCPRESS_GITHUB_REPO' ) ) {
	define( 'MCPRESS_GITHUB_REPO', 'TBuitrago/mcpress' );
}

require_once MCPRESS_DIR . 'includes/class-autoloader.php';
MCPress\Autoloader::register();

register_activation_hook( __FILE__, array( 'MCPress\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MCPress\\Installer', 'deactivate' ) );

// The bearer token has to be recognised before anything asks for the current user.
MCPress\Auth\Authenticator::init();

add_action( 'plugins_loaded', array( 'MCPress\\Plugin', 'boot' ) );

/**
 * Public helper for other plugins: register extra MCP tools.
 *
 * @param array $tool Tool definition (see MCPress\MCP\Tool_Registry::register()).
 * @return bool
 */
function mcpress_register_tool( array $tool ) {
	return MCPress\MCP\Tool_Registry::instance()->register( $tool );
}
