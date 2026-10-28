<?php
/**
 * Plugin settings (one option, array).
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'mcpress_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'default_mode'        => Permissions\Policy::MODE_APPROVAL,
			'tool_rules'          => array(),
			'disabled_groups'     => array(),
			'approval_ttl_hours'  => 24,
			'notify_email'        => false,
			'notify_email_to'     => '',
			'protect_sensitive'   => true,
			'allow_app_passwords' => false,
			'allow_url_keys'      => false,
			'oauth_enabled'       => true,
			'access_token_ttl'    => HOUR_IN_SECONDS,
			'refresh_token_days'  => 90,
			'log_retention_days'  => 90,
			'log_arguments'       => true,
			'auto_update'         => true,
			'update_channel'      => 'stable',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$current = self::all();
		$clean   = self::sanitize( array_merge( $current, $values ) );
		update_option( self::OPTION, $clean, false );
		self::$cache = null;
		return self::all();
	}

	public static function sanitize( array $s ) {
		$d   = self::defaults();
		$out = array();

		$out['default_mode'] = Permissions\Policy::is_mode( $s['default_mode'] ) ? $s['default_mode'] : $d['default_mode'];

		$out['tool_rules'] = array();
		if ( is_array( $s['tool_rules'] ) ) {
			foreach ( $s['tool_rules'] as $tool => $rule ) {
				$tool = sanitize_key( $tool );
				if ( $tool && in_array( $rule, array( 'allow', 'ask', 'deny' ), true ) ) {
					$out['tool_rules'][ $tool ] = $rule;
				}
			}
		}

		$out['disabled_groups'] = array_values( array_filter( array_map( 'sanitize_key', (array) $s['disabled_groups'] ) ) );

		$out['approval_ttl_hours'] = max( 1, min( 24 * 30, (int) $s['approval_ttl_hours'] ) );
		$out['notify_email']       = Util::bool( $s['notify_email'] );
		$out['notify_email_to']    = sanitize_email( (string) $s['notify_email_to'] );
		$out['protect_sensitive']  = Util::bool( $s['protect_sensitive'] );
		$out['allow_app_passwords'] = Util::bool( $s['allow_app_passwords'] );
		$out['allow_url_keys']     = Util::bool( $s['allow_url_keys'] );
		$out['oauth_enabled']      = Util::bool( $s['oauth_enabled'] );
		$out['access_token_ttl']   = max( 300, min( DAY_IN_SECONDS * 30, (int) $s['access_token_ttl'] ) );
		$out['refresh_token_days'] = max( 1, min( 3650, (int) $s['refresh_token_days'] ) );
		$out['log_retention_days'] = max( 1, min( 3650, (int) $s['log_retention_days'] ) );
		$out['log_arguments']      = Util::bool( $s['log_arguments'] );
		$out['auto_update']        = Util::bool( $s['auto_update'] );
		$out['update_channel']     = in_array( $s['update_channel'], array( 'stable', 'beta' ), true ) ? $s['update_channel'] : 'stable';

		return $out;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/** Is the given option name one of MCPress' own (never exposed to the AI)? */
	public static function is_internal_option( $name ) {
		$name = strtolower( (string) $name );
		return 0 === strpos( $name, 'mcpress_' ) || 0 === strpos( $name, '_transient_mcpress_' ) || 0 === strpos( $name, '_transient_timeout_mcpress_' ) || 0 === strpos( $name, '_site_transient_mcpress_' ) || 0 === strpos( $name, '_site_transient_timeout_mcpress_' );
	}
}
