<?php
/**
 * Taxonomies and terms.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Taxonomies {

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'list_taxonomies',
				'title'        => 'List taxonomies',
				'group'        => 'taxonomies',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Categories, tags, product categories and every other taxonomy, with the post types they apply to.',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'list_taxonomies' ),
			)
		);
		$r->register(
			array(
				'name'         => 'list_terms',
				'title'        => 'List terms',
				'group'        => 'taxonomies',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Terms of a taxonomy (e.g. all categories), with counts.',
				'input_schema' => H::schema(
					array_merge(
						array(
							'taxonomy' => H::str( 'Taxonomy name.', array( 'default' => 'category' ) ),
							'search'   => H::str( 'Search text.' ),
							'parent'   => H::int( 'Only children of this term.' ),
							'orderby'  => H::enum( array( 'name', 'count', 'term_id', 'slug' ), 'Order by.', 'name' ),
						),
						H::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_terms' ),
			)
		);
		$term_fields = array(
			'taxonomy'    => H::str( 'Taxonomy name.' ),
			'name'        => H::str( 'Name.' ),
			'slug'        => H::str( 'Slug.' ),
			'description' => H::str( 'Description.' ),
			'parent'      => H::int( 'Parent term ID (hierarchical taxonomies).' ),
		);
		$r->register(
			array(
				'name'         => 'create_term',
				'title'        => 'Create a term',
				'group'        => 'taxonomies',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Create a category, tag or term of any taxonomy.',
				'input_schema' => H::schema( $term_fields, array( 'taxonomy', 'name' ) ),
				'callback'     => array( __CLASS__, 'create_term' ),
			)
		);
		$r->register(
			array(
				'name'         => 'update_term',
				'title'        => 'Update a term',
				'group'        => 'taxonomies',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Rename a term or change its slug, description or parent.',
				'input_schema' => H::schema( array_merge( array( 'id' => H::int( 'Term ID.' ) ), $term_fields ), array( 'id', 'taxonomy' ) ),
				'callback'     => array( __CLASS__, 'update_term' ),
			)
		);
		$r->register(
			array(
				'name'         => 'delete_term',
				'title'        => 'Delete a term',
				'group'        => 'taxonomies',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Delete a term. Posts keep existing; posts left without a category get the default category.',
				'input_schema' => H::schema(
					array(
						'id'       => H::int( 'Term ID.' ),
						'taxonomy' => H::str( 'Taxonomy name.' ),
					),
					array( 'id', 'taxonomy' )
				),
				'callback'     => array( __CLASS__, 'delete_term' ),
				'preview'      => array( __CLASS__, 'preview_term' ),
			)
		);
		$r->register(
			array(
				'name'         => 'set_post_terms',
				'title'        => 'Set the terms of a post',
				'group'        => 'taxonomies',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Assign terms (IDs or names; missing names are created) to a post, replacing or appending.',
				'input_schema' => H::schema(
					array(
						'post_id'  => H::int( 'Post ID.' ),
						'taxonomy' => H::str( 'Taxonomy name.' ),
						'terms'    => H::arr( array( 'type' => array( 'string', 'integer' ) ), 'Term IDs or names.' ),
						'append'   => H::bool( 'Add to the existing terms instead of replacing them.', false ),
					),
					array( 'post_id', 'taxonomy', 'terms' )
				),
				'callback'     => array( __CLASS__, 'set_post_terms' ),
			)
		);
	}

	public static function list_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $t ) {
			if ( ! $t->show_ui && ! $t->public ) {
				continue;
			}
			$out[] = array(
				'name'         => $t->name,
				'label'        => $t->label,
				'hierarchical' => (bool) $t->hierarchical,
				'public'       => (bool) $t->public,
				'post_types'   => array_values( (array) $t->object_type ),
				'count'        => (int) wp_count_terms( array( 'taxonomy' => $t->name, 'hide_empty' => false ) ),
			);
		}
		return $out;
	}

	public static function list_terms( array $args ) {
		if ( ! taxonomy_exists( $args['taxonomy'] ) ) {
			return H::error( 'invalid_taxonomy', 'Unknown taxonomy.', 'Call list_taxonomies.' );
		}
		list( $per, $page ) = H::paginate( $args );
		$q = array(
			'taxonomy'   => $args['taxonomy'],
			'hide_empty' => false,
			'number'     => $per,
			'offset'     => ( $page - 1 ) * $per,
			'orderby'    => $args['orderby'],
		);
		if ( ! empty( $args['search'] ) ) {
			$q['search'] = $args['search'];
		}
		if ( isset( $args['parent'] ) ) {
			$q['parent'] = (int) $args['parent'];
		}
		$terms = get_terms( $q );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		unset( $q['number'], $q['offset'] );
		return array(
			'total' => (int) wp_count_terms( $q ),
			'page'  => $page,
			'items' => array_map( array( 'MCPress\\Tools\\Helpers', 'term_summary' ), $terms ),
		);
	}

	private static function check_tax( $taxonomy, $cap ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return H::error( 'invalid_taxonomy', 'Unknown taxonomy.', 'Call list_taxonomies.' );
		}
		if ( ! current_user_can( $tax->cap->$cap ) ) {
			return H::forbidden( 'The connected user cannot change terms of this taxonomy.' );
		}
		return null;
	}

	public static function create_term( array $args ) {
		$err = self::check_tax( $args['taxonomy'], 'edit_terms' );
		if ( $err ) {
			return $err;
		}
		$res = wp_insert_term(
			$args['name'],
			$args['taxonomy'],
			array_filter(
				array(
					'slug'        => isset( $args['slug'] ) ? $args['slug'] : null,
					'description' => isset( $args['description'] ) ? $args['description'] : null,
					'parent'      => isset( $args['parent'] ) ? (int) $args['parent'] : null,
				),
				function ( $v ) {
					return null !== $v;
				}
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'created' => H::term_summary( get_term( $res['term_id'], $args['taxonomy'] ) ) );
	}

	public static function update_term( array $args ) {
		$err = self::check_tax( $args['taxonomy'], 'edit_terms' );
		if ( $err ) {
			return $err;
		}
		if ( ! get_term( (int) $args['id'], $args['taxonomy'] ) ) {
			return H::not_found( 'Term', $args['id'] );
		}
		$fields = array_intersect_key( $args, array_flip( array( 'name', 'slug', 'description', 'parent' ) ) );
		$res    = wp_update_term( (int) $args['id'], $args['taxonomy'], $fields );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'updated' => H::term_summary( get_term( (int) $args['id'], $args['taxonomy'] ) ) );
	}

	public static function delete_term( array $args ) {
		$err = self::check_tax( $args['taxonomy'], 'delete_terms' );
		if ( $err ) {
			return $err;
		}
		$term = get_term( (int) $args['id'], $args['taxonomy'] );
		if ( ! $term || is_wp_error( $term ) ) {
			return H::not_found( 'Term', $args['id'] );
		}
		if ( 'category' === $args['taxonomy'] && (int) get_option( 'default_category' ) === (int) $term->term_id ) {
			return H::error( 'default_term', 'This is the default category and cannot be deleted.', 'Change default_category with update_option first.' );
		}
		$res = wp_delete_term( $term->term_id, $args['taxonomy'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'deleted' => H::term_summary( $term ) );
	}

	public static function preview_term( array $args ) {
		$term = get_term( (int) $args['id'], $args['taxonomy'] );
		return $term && ! is_wp_error( $term ) ? array( 'term' => H::term_summary( $term ), 'posts_using_it' => (int) $term->count ) : 'Term not found.';
	}

	public static function set_post_terms( array $args ) {
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post ) {
			return H::not_found( 'Post', $args['post_id'] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		if ( ! taxonomy_exists( $args['taxonomy'] ) || ! is_object_in_taxonomy( $post->post_type, $args['taxonomy'] ) ) {
			return H::error( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not apply to %s.', $args['taxonomy'], $post->post_type ) );
		}
		$ids = H::resolve_terms( $args['taxonomy'], (array) $args['terms'], true );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$res = wp_set_object_terms( $post->ID, $ids, $args['taxonomy'], ! empty( $args['append'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$terms = wp_get_object_terms( $post->ID, $args['taxonomy'] );
		return array(
			'post_id' => $post->ID,
			'terms'   => array_map( array( 'MCPress\\Tools\\Helpers', 'term_summary' ), is_wp_error( $terms ) ? array() : $terms ),
		);
	}
}
