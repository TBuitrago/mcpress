<?php
/**
 * Minimal JSON Schema validation for tool arguments.
 *
 * Lenient on purpose: AIs often send "5" for 5 or "true" for true, so scalar values are
 * coerced to the declared type when that is unambiguous. Unknown properties are kept.
 *
 * @package MCPress
 */

namespace MCPress\MCP;

defined( 'ABSPATH' ) || exit;

class Schema {

	/**
	 * Validate and coerce.
	 *
	 * @param mixed $value
	 * @param array $schema
	 * @return array{0:mixed,1:string[]} [coerced value, errors]
	 */
	public static function validate( $value, $schema, $path = 'arguments' ) {
		$schema = (array) $schema;
		$errors = array();

		if ( isset( $schema['type'] ) ) {
			$types = (array) $schema['type'];
			$ok    = false;
			foreach ( $types as $type ) {
				list( $coerced, $matched ) = self::coerce( $value, $type );
				if ( $matched ) {
					$value = $coerced;
					$ok    = true;
					break;
				}
			}
			if ( ! $ok ) {
				return array( $value, array( sprintf( '%s must be of type %s.', $path, implode( '|', $types ) ) ) );
			}
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, (array) $schema['enum'], true ) ) {
			$errors[] = sprintf( '%s must be one of: %s.', $path, implode( ', ', array_map( array( __CLASS__, 'scalar_label' ), (array) $schema['enum'] ) ) );
		}

		if ( is_string( $value ) ) {
			if ( isset( $schema['minLength'] ) && mb_strlen( $value ) < $schema['minLength'] ) {
				$errors[] = sprintf( '%s must be at least %d characters.', $path, $schema['minLength'] );
			}
			if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > $schema['maxLength'] ) {
				$errors[] = sprintf( '%s must be at most %d characters.', $path, $schema['maxLength'] );
			}
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				$errors[] = sprintf( '%s must be ≥ %s.', $path, $schema['minimum'] );
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				$errors[] = sprintf( '%s must be ≤ %s.', $path, $schema['maximum'] );
			}
		}

		if ( is_array( $value ) && self::is_list( $value ) && isset( $schema['items'] ) && ( ! isset( $schema['type'] ) || in_array( 'array', (array) $schema['type'], true ) ) ) {
			foreach ( $value as $i => $item ) {
				list( $value[ $i ], $item_errors ) = self::validate( $item, $schema['items'], $path . '[' . $i . ']' );
				$errors = array_merge( $errors, $item_errors );
			}
		}

		$is_object_schema = isset( $schema['properties'] ) || isset( $schema['required'] );
		if ( is_array( $value ) && $is_object_schema && ( ! self::is_list( $value ) || array() === $value ) ) {
			$props = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
			foreach ( (array) ( isset( $schema['required'] ) ? $schema['required'] : array() ) as $req ) {
				if ( ! array_key_exists( $req, $value ) || null === $value[ $req ] || '' === $value[ $req ] ) {
					$errors[] = sprintf( '%s.%s is required.', $path, $req );
				}
			}
			foreach ( $props as $key => $prop ) {
				$prop = (array) $prop;
				if ( array_key_exists( $key, $value ) ) {
					if ( null === $value[ $key ] && ! in_array( 'null', (array) ( isset( $prop['type'] ) ? $prop['type'] : array() ), true ) ) {
						unset( $value[ $key ] );
						continue;
					}
					list( $value[ $key ], $prop_errors ) = self::validate( $value[ $key ], $prop, $path . '.' . $key );
					$errors = array_merge( $errors, $prop_errors );
				} elseif ( array_key_exists( 'default', $prop ) ) {
					$value[ $key ] = $prop['default'];
				}
			}
		}

		return array( $value, $errors );
	}

	/** @return array{0:mixed,1:bool} */
	private static function coerce( $value, $type ) {
		switch ( $type ) {
			case 'string':
				if ( is_string( $value ) ) {
					return array( $value, true );
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					return array( (string) $value, true );
				}
				return array( $value, false );
			case 'integer':
				if ( is_int( $value ) ) {
					return array( $value, true );
				}
				if ( is_float( $value ) && floor( $value ) === $value ) {
					return array( (int) $value, true );
				}
				if ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
					return array( (int) $value, true );
				}
				return array( $value, false );
			case 'number':
				if ( is_int( $value ) || is_float( $value ) ) {
					return array( $value, true );
				}
				if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
					return array( 0 + trim( $value ), true );
				}
				return array( $value, false );
			case 'boolean':
				if ( is_bool( $value ) ) {
					return array( $value, true );
				}
				if ( in_array( $value, array( 'true', '1', 1, 'yes' ), true ) ) {
					return array( true, true );
				}
				if ( in_array( $value, array( 'false', '0', 0, 'no', '' ), true ) ) {
					return array( false, true );
				}
				return array( $value, false );
			case 'array':
				if ( is_array( $value ) && self::is_list( $value ) ) {
					return array( $value, true );
				}
				if ( is_string( $value ) && '' !== $value && '[' === $value[0] ) {
					$decoded = json_decode( $value, true );
					if ( is_array( $decoded ) ) {
						return array( $decoded, true );
					}
				}
				if ( is_string( $value ) || is_int( $value ) ) {
					return array( array( $value ), true ); // A single value where a list is expected.
				}
				return array( $value, false );
			case 'object':
				if ( is_array( $value ) && ( ! self::is_list( $value ) || array() === $value ) ) {
					return array( $value, true );
				}
				if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
					$decoded = json_decode( $value, true );
					if ( is_array( $decoded ) ) {
						return array( $decoded, true );
					}
				}
				return array( $value, false );
			case 'null':
				return array( $value, null === $value );
		}
		return array( $value, true );
	}

	public static function is_list( array $value ) {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function scalar_label( $v ) {
		return is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
	}
}
