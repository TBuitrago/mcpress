<?php
/**
 * Raw options (wp_options).
 *
 * MCPress' own options are invisible and read-only here, in every mode.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Redactor;
use MCPress\Settings;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Options {

	/** Changing these can take the site down or open it up. */
	const CRITICAL = array( 'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'upload_path', 'upload_url_path', 'cron', 'auto_core_update_disabled', 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'recovery_keys', 'wp_force_deactivated_plugins', 'auto_update_plugins', 'auto_update_themes' );

	/** Deleting these breaks WordPress. */
	const UNDELETABLE = array( 'siteurl', 'home', 'blogname', 'active_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'permalink_structure', 'db_version', 'initial_db_version' );

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'get_option',
				'title'        => 'Read options',
				'group'        => 'options',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Read one or more options by name (unserialized). Secret-looking values are masked while sensitive-data protection is on.',
				'input_schema' => H::schema( array( 'names' => H::arr( 'string', 'Option names.' ) ), array( 'names' ) ),
				'callback'     => array( __CLASS__, 'get_option' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'list_options',
				'title'        => 'Search options',
				'group'        => 'options',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Find options by name pattern, with size, autoload flag and a short preview. Useful to discover the settings of a plugin (e.g. search "woocommerce_").',
				'input_schema' => H::schema(
					array_merge(
						array(
							'search'        => H::str( 'Text contained in the option name.' ),
							'autoload_only' => H::bool( 'Only autoloaded options.', false ),
							'orderby'       => H::enum( array( 'name', 'size' ), 'Order.', 'name' ),
						),
						H::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_options' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'update_option',
				'title'        => 'Set an option',
				'group'        => 'options',
				'risk'         => array( __CLASS__, 'update_risk' ),
				'base_risk'    => Policy::RISK_WRITE,
				'description'  => 'Create or change any option. Values are stored like WordPress does (arrays serialized). Core options such as siteurl, home, active_plugins, users_can_register, default_role, and payment or secret settings are critical.',
				'input_schema' => H::schema(
					array(
						'name'     => H::str( 'Option name.' ),
						'value'    => H::any( 'New value (any JSON).' ),
						'autoload' => H::bool( 'Autoload (only applied when the option is created or this is sent).' ),
					),
					array( 'name' )
				),
				'callback'     => array( __CLASS__, 'update_option' ),
				'guard'        => array( __CLASS__, 'guard' ),
				'preview'      => array( __CLASS__, 'preview' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_option',
				'title'        => 'Delete an option',
				'group'        => 'options',
				'risk'         => array( __CLASS__, 'delete_risk' ),
				'base_risk'    => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Delete an option. Options WordPress needs to run cannot be deleted.',
				'input_schema' => H::schema( array( 'name' => H::str( 'Option name.' ) ), array( 'name' ) ),
				'callback'     => array( __CLASS__, 'delete_option' ),
				'guard'        => array( __CLASS__, 'guard_delete' ),
				'preview'      => array( __CLASS__, 'preview' ),
				'capability'   => 'manage_options',
			)
		);
	}

	public static function is_critical( $name ) {
		global $wpdb;
		$name = strtolower( (string) $name );
		if ( in_array( $name, self::CRITICAL, true ) || $wpdb->prefix . 'user_roles' === $name ) {
			return true;
		}
		return Redactor::name_is_secret( $name, true ) || (bool) preg_match( '/(payment|gateway|paypal|stripe|checkout|merchant|_api_|webhook)/', $name );
	}

	public static function update_risk( array $args ) {
		return self::is_critical( isset( $args['name'] ) ? $args['name'] : '' ) ? Policy::RISK_CRITICAL : Policy::RISK_WRITE;
	}

	public static function delete_risk( array $args ) {
		return self::is_critical( isset( $args['name'] ) ? $args['name'] : '' ) ? Policy::RISK_CRITICAL : Policy::RISK_DESTRUCTIVE;
	}

	public static function guard( array $args ) {
		if ( Settings::is_internal_option( $args['name'] ) ) {
			return H::error( 'self_protection', 'MCPress settings and keys cannot be read or changed by an AI.', 'If the user wants different permissions they change them in wp-admin → MCPress.' );
		}
		return null;
	}

	public static function guard_delete( array $args ) {
		$g = self::guard( $args );
		if ( $g ) {
			return $g;
		}
		if ( in_array( strtolower( $args['name'] ), self::UNDELETABLE, true ) ) {
			return H::error( 'core_option', sprintf( 'WordPress cannot run without the option "%s"; it cannot be deleted.', $args['name'] ), 'Use update_option to change its value instead.' );
		}
		return null;
	}

	public static function get_option( array $args ) {
		$out = array();
		foreach ( (array) $args['names'] as $name ) {
			$name = (string) $name;
			if ( Settings::is_internal_option( $name ) ) {
				$out[ $name ] = '[MCPress internal: not readable]';
				continue;
			}
			$sentinel = new \stdClass();
			$value    = get_option( $name, $sentinel );
			$out[ $name ] = $value === $sentinel ? array( '_exists' => false ) : $value;
		}
		return $out;
	}

	public static function list_options( array $args ) {
		global $wpdb;
		list( $per, $page ) = H::paginate( $args );
		$where  = array( 'option_name NOT LIKE %s', 'option_name NOT LIKE %s', 'option_name NOT LIKE %s' );
		$params = array( $wpdb->esc_like( 'mcpress_' ) . '%', $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' );
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}
		if ( ! empty( $args['autoload_only'] ) ) {
			$where[] = "autoload IN ('yes','on','auto-on','auto')";
		}
		$order = 'size' === $args['orderby'] ? 'size DESC' : 'option_name ASC';
		$sql   = "SELECT option_name, LENGTH(option_value) AS size, autoload, LEFT(option_value, 120) AS preview FROM {$wpdb->options} WHERE " . implode( ' AND ', $where ) . " ORDER BY $order LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$csql  = "SELECT COUNT(*) FROM {$wpdb->options} WHERE " . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( $csql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as &$row ) {
			$row['size'] = (int) $row['size'];
			if ( Redactor::name_is_secret( $row['option_name'], (bool) Settings::get( 'protect_sensitive' ) ) ) {
				$row['preview'] = Redactor::MASK;
			}
		}
		return array(
			'total' => $total,
			'page'  => $page,
			'items' => $rows,
		);
	}

	public static function update_option( array $args ) {
		$name     = (string) $args['name'];
		$value    = array_key_exists( 'value', $args ) ? $args['value'] : '';
		$sentinel = new \stdClass();
		$old      = get_option( $name, $sentinel );
		$autoload = array_key_exists( 'autoload', $args ) ? (bool) $args['autoload'] : null;
		$ok       = update_option( $name, $value, $autoload );
		return array(
			'name'     => $name,
			'created'  => $old === $sentinel,
			'changed'  => (bool) $ok,
			'previous' => $old === $sentinel ? null : $old,
			'value'    => get_option( $name ),
		);
	}

	public static function delete_option( array $args ) {
		$old = get_option( $args['name'], null );
		$ok  = delete_option( $args['name'] );
		return array(
			'name'     => $args['name'],
			'deleted'  => (bool) $ok,
			'previous' => $old,
		);
	}

	public static function preview( array $args ) {
		$sentinel = new \stdClass();
		$current  = get_option( $args['name'], $sentinel );
		return array(
			'option'  => $args['name'],
			'current' => $current === $sentinel ? '(does not exist)' : $current,
			'new'     => array_key_exists( 'value', $args ) ? $args['value'] : '(deleted)',
		);
	}
}
