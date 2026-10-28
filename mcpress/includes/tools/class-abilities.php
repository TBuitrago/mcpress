<?php
/**
 * WordPress Abilities API (6.9+): abilities registered by core and plugins.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Abilities {

	public static function register( Tool_Registry $r ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}
		$r->register(
			array(
				'name'         => 'list_abilities',
				'title'        => 'List abilities',
				'group'        => 'abilities',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Abilities registered with the WordPress Abilities API by core and plugins: name, description, category, input schema and read-only/destructive annotations.',
				'input_schema' => H::schema(
					array(
						'search'   => H::str( 'Only abilities whose name or description contains this text.' ),
						'category' => H::str( 'Only this category.' ),
						'schemas'  => H::bool( 'Include input/output schemas.', true ),
					)
				),
				'callback'     => array( __CLASS__, 'list_abilities' ),
			)
		);
		$r->register(
			array(
				'name'         => 'run_ability',
				'title'        => 'Run an ability',
				'group'        => 'abilities',
				'risk'         => array( __CLASS__, 'risk' ),
				'base_risk'    => Policy::RISK_READ,
				'description'  => 'Execute an ability with its input. Risk follows its annotations: read-only = read, destructive = critical, otherwise write. The ability checks its own permissions.',
				'input_schema' => H::schema(
					array(
						'name'  => H::str( 'Ability name, e.g. core/get-site-info.' ),
						'input' => H::any( 'Input matching the ability input schema.' ),
					),
					array( 'name' )
				),
				'callback'     => array( __CLASS__, 'run_ability' ),
				'guard'        => array( __CLASS__, 'guard' ),
				'preview'      => array( __CLASS__, 'preview' ),
			)
		);
	}

	private static function annotations( $ability ) {
		$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
		return isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
	}

	public static function risk( array $args ) {
		$ability = function_exists( 'wp_get_ability' ) && ! empty( $args['name'] ) ? wp_get_ability( $args['name'] ) : null;
		if ( ! $ability ) {
			return Policy::RISK_WRITE;
		}
		$a = self::annotations( $ability );
		if ( ! empty( $a['readonly'] ) ) {
			return Policy::RISK_READ;
		}
		if ( ! empty( $a['destructive'] ) ) {
			return Policy::RISK_CRITICAL;
		}
		return Policy::RISK_WRITE;
	}

	public static function guard( array $args ) {
		if ( 0 === stripos( (string) $args['name'], 'mcpress' ) ) {
			return H::error( 'self_protection', 'MCPress abilities cannot be run through MCP.' );
		}
		return null;
	}

	public static function list_abilities( array $args ) {
		$out = array();
		foreach ( (array) wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			$desc = (string) $ability->get_description();
			$cat  = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
			if ( ! empty( $args['search'] ) && false === stripos( $name . ' ' . $desc, $args['search'] ) ) {
				continue;
			}
			if ( ! empty( $args['category'] ) && $cat !== $args['category'] ) {
				continue;
			}
			$item = array(
				'name'        => $name,
				'label'       => (string) $ability->get_label(),
				'description' => $desc,
				'category'    => $cat,
				'annotations' => self::annotations( $ability ) ? self::annotations( $ability ) : new \stdClass(),
			);
			if ( ! empty( $args['schemas'] ) ) {
				$item['input_schema']  = $ability->get_input_schema() ? $ability->get_input_schema() : new \stdClass();
				$item['output_schema'] = $ability->get_output_schema() ? $ability->get_output_schema() : new \stdClass();
			}
			$out[] = $item;
		}
		return array( 'abilities' => $out );
	}

	public static function run_ability( array $args ) {
		$ability = wp_get_ability( $args['name'] );
		if ( ! $ability ) {
			return H::not_found( 'Ability', $args['name'] );
		}
		$input  = array_key_exists( 'input', $args ) ? $args['input'] : null;
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'ability' => $args['name'], 'result' => $result );
	}

	public static function preview( array $args ) {
		$ability = wp_get_ability( $args['name'] );
		return array(
			'ability'     => $args['name'],
			'description' => $ability ? $ability->get_description() : 'not registered',
			'input'       => array_key_exists( 'input', $args ) ? $args['input'] : null,
		);
	}
}
