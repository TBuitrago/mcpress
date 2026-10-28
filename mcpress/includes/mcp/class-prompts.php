<?php
/**
 * MCP prompts: ready-made starting points the user can pick in their AI client.
 *
 * @package MCPress
 */

namespace MCPress\MCP;

defined( 'ABSPATH' ) || exit;

class Prompts {

	private static function definitions() {
		return array(
			'site_checkup'  => array(
				'title'       => 'Site check-up',
				'description' => 'Review health, updates, security basics and performance of this WordPress site.',
				'arguments'   => array(),
				'text'        => 'Do a check-up of this WordPress site. Use site_info, site_health and check_updates. Report, in order of importance: pending updates (core, plugins, themes), Site Health problems, inactive plugins and themes that could be removed, and anything that looks insecure. Do not change anything yet: end with a short, prioritised list of proposed actions.',
			),
			'content_audit' => array(
				'title'       => 'Content audit',
				'description' => 'Find thin, outdated or orphaned content.',
				'arguments'   => array(
					array(
						'name'        => 'post_type',
						'description' => 'Post type to audit (default: post).',
						'required'    => false,
					),
				),
				'text'        => 'Audit the published %s content of this site. List items with very little text, items not updated in over a year, items without a featured image or excerpt, and draft/pending items forgotten for months. Do not change anything: give me a table and your recommendations.',
			),
		);
	}

	public static function all() {
		$out = array();
		foreach ( self::definitions() as $name => $p ) {
			$out[] = array(
				'name'        => $name,
				'title'       => $p['title'],
				'description' => $p['description'],
				'arguments'   => $p['arguments'],
			);
		}
		return $out;
	}

	public static function get( $name, array $args ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $name ] ) ) {
			return null;
		}
		$p    = $defs[ $name ];
		$text = 'content_audit' === $name ? sprintf( $p['text'], sanitize_key( isset( $args['post_type'] ) ? $args['post_type'] : 'post' ) ) : $p['text'];
		return array(
			'description' => $p['description'],
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
