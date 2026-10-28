<?php
/**
 * Class autoloader.
 *
 * MCPress\Foo_Bar        → includes/class-foo-bar.php
 * MCPress\MCP\Tool_List  → includes/mcp/class-tool-list.php
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( $class ) {
		if ( 0 !== strpos( $class, 'MCPress\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, 8 ) );
		$name  = array_pop( $parts );
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file  = MCPRESS_DIR . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
