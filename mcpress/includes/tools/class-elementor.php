<?php
/**
 * Elementor pages and templates, saved through Elementor's own document API so the editor,
 * revisions and generated CSS stay consistent.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Elementor {

	public static function register( Tool_Registry $r ) {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}
		$r->register(
			array(
				'name'         => 'elementor_get_page',
				'title'        => 'Read an Elementor page',
				'group'        => 'elementor',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Elementor data of a page, post or template: the element tree (sections/containers, columns, widgets with settings) and page settings. outline=true returns a compact tree (id, type, widget, first text) to save tokens; then read the full data to edit.',
				'input_schema' => H::schema(
					array(
						'id'      => H::int( 'Post ID.' ),
						'outline' => H::bool( 'Compact tree instead of full data.', false ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'get_page' ),
			)
		);
		$r->register(
			array(
				'name'         => 'elementor_update_page',
				'title'        => 'Save an Elementor page',
				'group'        => 'elementor',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Save the full Elementor element tree (and optionally page settings) of a page/post/template through Elementor\'s document API: the page opens normally in the editor, a revision is kept and CSS is regenerated. Send the complete "elements" array as returned by elementor_get_page, with your changes. New element ids must be unique 7-character hex strings.',
				'input_schema' => H::schema(
					array(
						'id'        => H::int( 'Post ID.' ),
						'elements'  => H::arr( new \stdClass(), 'Complete Elementor element tree.' ),
						'settings'  => H::obj( 'Page settings to merge (optional).' ),
						'status'    => H::enum( array( 'draft', 'publish', 'pending', 'private' ), 'Post status (default: keep current).' ),
					),
					array( 'id', 'elements' )
				),
				'callback'     => array( __CLASS__, 'update_page' ),
				'preview'      => array( __CLASS__, 'preview' ),
			)
		);
	}

	private static function document( $id ) {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) ) {
			return null;
		}
		return \Elementor\Plugin::$instance->documents->get( (int) $id, false );
	}

	public static function get_page( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return H::not_found( 'Post', $args['id'] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		$raw      = get_post_meta( $post->ID, '_elementor_data', true );
		$elements = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
		$out      = array(
			'post'          => H::post_summary( $post ),
			'built_with'    => 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ? 'elementor' : 'not elementor (yet)',
			'template_type' => get_post_meta( $post->ID, '_elementor_template_type', true ),
			'version'       => get_post_meta( $post->ID, '_elementor_version', true ),
			'settings'      => get_post_meta( $post->ID, '_elementor_page_settings', true ) ? get_post_meta( $post->ID, '_elementor_page_settings', true ) : new \stdClass(),
			'edit_url'      => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
		);
		if ( ! empty( $args['outline'] ) ) {
			$out['outline'] = self::outline( (array) $elements );
		} else {
			$out['elements'] = $elements ? $elements : array();
		}
		return $out;
	}

	private static function outline( array $elements, $depth = 0 ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$item = array(
				'id'   => isset( $el['id'] ) ? $el['id'] : '',
				'type' => isset( $el['elType'] ) ? $el['elType'] : '',
			);
			if ( ! empty( $el['widgetType'] ) ) {
				$item['widget'] = $el['widgetType'];
			}
			$text = self::first_text( isset( $el['settings'] ) ? (array) $el['settings'] : array() );
			if ( $text ) {
				$item['text'] = $text;
			}
			if ( ! empty( $el['elements'] ) && $depth < 12 ) {
				$item['children'] = self::outline( (array) $el['elements'], $depth + 1 );
			}
			$out[] = $item;
		}
		return $out;
	}

	private static function first_text( array $settings ) {
		foreach ( array( 'title', 'editor', 'text', 'heading', 'description', 'button_text', 'caption' ) as $k ) {
			if ( ! empty( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
				return mb_substr( trim( wp_strip_all_tags( $settings[ $k ] ) ), 0, 80 );
			}
		}
		return '';
	}

	public static function update_page( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return H::not_found( 'Post', $args['id'] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		$elements = $args['elements'];
		$ids      = array();
		$problem  = self::validate( $elements, $ids );
		if ( $problem ) {
			return H::error( 'invalid_elements', $problem, 'Every element needs a unique "id" and an "elType" (container, section, column or widget); widgets need "widgetType". Read the page with elementor_get_page and keep its structure.' );
		}

		$doc = self::document( $post->ID );
		if ( $doc && method_exists( $doc, 'save' ) ) {
			update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
			$data = array( 'elements' => $elements );
			// Elementor replaces the page settings with what it receives, so always merge into the current ones.
			if ( ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) || ! empty( $args['status'] ) ) {
				$data['settings'] = array_merge(
					self::current_settings( $post->ID ),
					isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array()
				);
				if ( ! empty( $args['status'] ) ) {
					$data['settings']['post_status'] = $args['status'];
				}
			}
			$ok = $doc->save( $data );
			if ( ! $ok ) {
				return H::error( 'elementor_save_failed', 'Elementor refused to save the document.', 'Check the element tree; read_error_log may show more.' );
			}
		} else {
			// Fallback: write the meta the way Elementor does and clear its CSS.
			update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
			update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $post->ID, '_elementor_version', ELEMENTOR_VERSION );
			}
			if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
				update_post_meta( $post->ID, '_elementor_page_settings', wp_slash( array_merge( self::current_settings( $post->ID ), $args['settings'] ) ) );
			}
			if ( ! empty( $args['status'] ) ) {
				wp_update_post( array( 'ID' => $post->ID, 'post_status' => $args['status'] ) );
			}
		}
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		return array(
			'saved'    => H::post_summary( get_post( $post->ID ) ),
			'elements' => count( $ids ),
			'edit_url' => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
		);
	}

	private static function current_settings( $post_id ) {
		$current = get_post_meta( $post_id, '_elementor_page_settings', true );
		return is_array( $current ) ? $current : array();
	}

	private static function validate( $elements, array &$ids, $depth = 0 ) {
		if ( ! is_array( $elements ) ) {
			return 'elements must be an array.';
		}
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) || empty( $el['elType'] ) ) {
				return sprintf( 'Element %d at depth %d has no elType.', $i, $depth );
			}
			if ( empty( $el['id'] ) || ! is_string( $el['id'] ) ) {
				return sprintf( 'Element %d at depth %d has no id.', $i, $depth );
			}
			if ( isset( $ids[ $el['id'] ] ) ) {
				return sprintf( 'Duplicate element id "%s".', $el['id'] );
			}
			$ids[ $el['id'] ] = true;
			if ( 'widget' === $el['elType'] && empty( $el['widgetType'] ) ) {
				return sprintf( 'Widget "%s" has no widgetType.', $el['id'] );
			}
			if ( ! empty( $el['elements'] ) ) {
				$p = self::validate( $el['elements'], $ids, $depth + 1 );
				if ( $p ) {
					return $p;
				}
			}
		}
		return null;
	}

	public static function preview( array $args ) {
		$post = get_post( (int) $args['id'] );
		return array(
			'post'         => $post ? H::post_summary( $post ) : 'not found',
			'new_outline'  => self::outline( (array) $args['elements'] ),
		);
	}
}
