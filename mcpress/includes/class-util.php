<?php
/**
 * Small shared helpers.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Util {

	/** Current time in UTC, MySQL format. */
	public static function now( $offset_seconds = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset_seconds );
	}

	/** Prefixed random secret, e.g. mcpk_3f9a… */
	public static function random_token( $prefix, $bytes = 24 ) {
		return $prefix . bin2hex( random_bytes( $bytes ) );
	}

	public static function random_id( $prefix = '', $bytes = 8 ) {
		return $prefix . bin2hex( random_bytes( $bytes ) );
	}

	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'mcpress_' . $name;
	}

	public static function json( $data, $pretty = false ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$out = wp_json_encode( $data, $flags );
		return false === $out ? '{}' : $out;
	}

	public static function json_decode_array( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	public static function truncate( $text, $max = 2000 ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return substr( $text, 0, $max ) . '… [' . ( strlen( $text ) - $max ) . ' more bytes]';
	}

	/** Public URL of the MCP endpoint. */
	public static function mcp_url() {
		return rest_url( 'mcpress/v1/mcp' );
	}

	/** OAuth issuer: the site home URL without trailing slash. */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the client IP recorded in the activity log (for sites behind a trusted proxy).
		 */
		return (string) apply_filters( 'mcpress_client_ip', $ip );
	}

	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
	}

	/** MySQL UTC datetime → ISO 8601. */
	public static function iso( $mysql ) {
		if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
			return null;
		}
		return gmdate( 'c', strtotime( $mysql . ' UTC' ) );
	}

	/** MySQL UTC datetime → site-local human string. */
	public static function local( $mysql, $format = null ) {
		if ( empty( $mysql ) ) {
			return '—';
		}
		$format = $format ? $format : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, strtotime( $mysql . ' UTC' ) );
	}

	public static function human_diff( $mysql ) {
		if ( empty( $mysql ) ) {
			return '—';
		}
		/* translators: %s: human time difference, e.g. "5 minutes". */
		return sprintf( __( '%s ago', 'mcpress' ), human_time_diff( strtotime( $mysql . ' UTC' ), time() ) );
	}

	/** Is $path inside $base (both must exist or $path's parent must exist)? */
	public static function path_inside( $path, $base ) {
		$base = wp_normalize_path( untrailingslashit( $base ) ) . '/';
		$path = wp_normalize_path( $path );
		return 0 === strpos( rtrim( $path, '/' ) . '/', $base );
	}

	public static function bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}
}
