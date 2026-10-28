<?php
/**
 * Masks secrets in anything sent to the AI or written to the log.
 *
 * Works on the data, not on the query that produced it: keys whose name means "secret"
 * and values that look like password hashes are replaced, wherever they come from.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Redactor {

	const MASK = '[redacted]';

	/** Field names that are always secret, whatever the setting. */
	const ALWAYS = array( 'user_pass', 'user_activation_key', 'session_tokens', '_application_passwords', 'token_hash' );

	public static function enabled() {
		return (bool) Settings::get( 'protect_sensitive' );
	}

	/**
	 * @param mixed $data
	 * @param bool  $force Redact even when the setting is off (used for the log).
	 */
	public static function redact( $data, $force = false ) {
		$on = $force || self::enabled();
		return self::walk( $data, $on, 0 );
	}

	private static function walk( $data, $on, $depth ) {
		if ( $depth > 40 ) {
			return $data;
		}
		if ( is_object( $data ) ) {
			if ( $data instanceof \JsonSerializable ) {
				$data = $data->jsonSerialize();
			} elseif ( $data instanceof \stdClass ) {
				$copy = new \stdClass();
				foreach ( get_object_vars( $data ) as $k => $v ) {
					$copy->$k = self::value_for( $k, $v, $on, $depth );
				}
				return $copy;
			} else {
				$data = get_object_vars( $data );
			}
		}
		if ( is_array( $data ) ) {
			$name_hint = null;
			// Rows like {option_name: "stripe_secret", option_value: "…"} or {meta_key, meta_value}.
			foreach ( array( 'option_name', 'meta_key', 'id', 'name', 'key' ) as $hint ) {
				if ( isset( $data[ $hint ] ) && is_string( $data[ $hint ] ) ) {
					$name_hint = $data[ $hint ];
					break;
				}
			}
			foreach ( $data as $k => $v ) {
				if ( null !== $name_hint && in_array( $k, array( 'option_value', 'meta_value', 'value' ), true ) && self::name_is_secret( $name_hint, $on ) ) {
					$data[ $k ] = self::mask_value( $v );
					continue;
				}
				$data[ $k ] = self::value_for( $k, $v, $on, $depth );
			}
			return $data;
		}
		if ( is_string( $data ) && $on && self::looks_like_hash( $data ) ) {
			return self::MASK;
		}
		return $data;
	}

	private static function value_for( $key, $value, $on, $depth ) {
		if ( is_string( $key ) && self::name_is_secret( $key, $on ) && ( is_scalar( $value ) || is_array( $value ) ) ) {
			return self::mask_value( $value );
		}
		return self::walk( $value, $on, $depth + 1 );
	}

	private static function mask_value( $value ) {
		if ( '' === $value || null === $value || false === $value || array() === $value ) {
			return $value;
		}
		return self::MASK;
	}

	/** Does a field / option / meta name hold a secret? */
	public static function name_is_secret( $name, $on = true ) {
		$name = strtolower( (string) $name );
		if ( in_array( $name, self::ALWAYS, true ) || Settings::is_internal_option( $name ) ) {
			return true;
		}
		if ( ! $on ) {
			return false;
		}
		if ( in_array( $name, array( 'post_password', 'password_protected', 'has_password', 'password_reset' ), true ) ) {
			return false;
		}
		return (bool) preg_match( '/(passw(or)?d|passwd|pwd$|^pwd|secret|api[_-]?key|apikey|access[_-]?key|private[_-]?key|secret[_-]?key|license[_-]?key|licence[_-]?key|auth[_-]?key|_salt$|^salt|nonce_key|logged_in_key|client[_-]?secret|(^|_)token(s)?($|_)|bearer|credential|webhook[_-]?secret|signing[_-]?key|encryption[_-]?key)/', $name );
	}

	public static function looks_like_hash( $value ) {
		return (bool) preg_match( '/^(\$P\$|\$H\$|\$wp\$|\$2[abxy]\$|\$argon2(id|i|d)\$|\$generic\$)[^\s]{20,}$/', $value );
	}

	/** Redact secrets that appear inline in free text (e.g. "api_key=abc123" in a log line). */
	public static function redact_text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/(\$P\$|\$wp\$|\$2[abxy]\$|\$argon2id\$)\S{20,}/', self::MASK, $text );
		return preg_replace( '/\b(mcp[kacr]_[a-f0-9]{48})\b/', self::MASK, $text );
	}
}
