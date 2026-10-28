<?php
/**
 * Who is calling: the connection, the WordPress user it acts as, and its permission mode.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Context {

	/** @var array|null Connection row. Null for application-password logins. */
	public $connection;

	public $connection_id = 0;
	public $user_id       = 0;
	public $mode;
	/** key | oauth | url_key | app_password */
	public $via;
	public $name = '';

	/** @var Context|null */
	private static $current;

	public function __construct( $connection, $user_id, $mode, $via ) {
		$this->connection    = $connection;
		$this->connection_id = $connection ? (int) $connection['id'] : 0;
		$this->user_id       = (int) $user_id;
		$this->mode          = Permissions\Policy::is_mode( $mode ) ? $mode : Settings::get( 'default_mode' );
		$this->via           = $via;
		$this->name          = $connection ? (string) $connection['name'] : __( 'Application password', 'mcpress' );
	}

	public static function from_connection( array $connection, $via ) {
		return new self( $connection, (int) $connection['user_id'], $connection['mode'], $via );
	}

	public static function set( ?Context $context = null ) {
		self::$current = $context;
	}

	/** @return Context|null */
	public static function current() {
		return self::$current;
	}

	/** Tools this connection may always run without asking (set from the approval screen). */
	public function allowed_tools() {
		if ( ! $this->connection ) {
			return array();
		}
		return array_values( array_filter( (array) Util::json_decode_array( $this->connection['allowed_tools'] ) ) );
	}

	public function user() {
		return get_userdata( $this->user_id );
	}
}
