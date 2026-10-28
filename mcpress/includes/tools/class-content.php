<?php
/**
 * Posts, pages and custom post types; revisions; post meta.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\Context;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Content {

	public static function register( Tool_Registry $r ) {
		$post_fields = array(
			'title'          => H::str( 'Title.' ),
			'content'        => H::str( 'Content. Block markup (<!-- wp:paragraph -->…) for the block editor, or HTML.' ),
			'excerpt'        => H::str( 'Excerpt.' ),
			'status'         => H::enum( array( 'draft', 'publish', 'pending', 'private', 'future' ), 'Status.' ),
			'slug'           => H::str( 'URL slug.' ),
			'author'         => H::int( 'Author user ID.' ),
			'parent'         => H::int( 'Parent post ID (hierarchical types).' ),
			'menu_order'     => H::int( 'Order.' ),
			'date'           => H::str( 'Publish date, site time, "YYYY-MM-DD HH:MM:SS". Required for status=future.' ),
			'template'       => H::str( 'Page template file or block template slug ("" for default).' ),
			'comment_status' => H::enum( array( 'open', 'closed' ), 'Comments.' ),
			'password'       => H::str( 'Password-protect the post ("" to remove).' ),
			'sticky'         => H::bool( 'Stick to the top of the blog (posts only).' ),
			'featured_image' => H::int( 'Attachment ID of the featured image (0 to remove).' ),
			'terms'          => H::obj( 'Taxonomy → list of term IDs or names (names are created if missing). Replaces the post terms of each taxonomy given, e.g. {"category": ["News"], "post_tag": ["a","b"]}.' ),
			'meta'           => H::obj( 'Meta key → value to set. Use null to delete a key.' ),
		);

		$r->register(
			array(
				'name'         => 'list_post_types',
				'title'        => 'List post types',
				'group'        => 'content',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Post types (posts, pages, products, templates, custom types) with labels, supports, taxonomies and counts by status.',
				'input_schema' => H::schema( array( 'include_internal' => H::bool( 'Include non-public types (wp_template, wp_block, wp_navigation, etc.).', false ) ) ),
				'callback'     => array( __CLASS__, 'list_post_types' ),
			)
		);
		$r->register(
			array(
				'name'         => 'list_posts',
				'title'        => 'List posts',
				'group'        => 'content',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Find posts, pages or any post type. Filters: status, search, author, parent, taxonomy term, meta, dates. Returns summaries; use get_post for the content.',
				'input_schema' => H::schema(
					array_merge(
						array(
							'post_type'   => H::str( 'Post type, or "any".', array( 'default' => 'post' ) ),
							'status'      => H::str( 'publish, draft, pending, private, future, trash, or "any" (all but trash).', array( 'default' => 'any' ) ),
							'search'      => H::str( 'Search text.' ),
							'author'      => H::int( 'Author ID.' ),
							'parent'      => H::int( 'Parent ID.' ),
							'include'     => H::arr( 'integer', 'Only these IDs.' ),
							'taxonomy'    => H::str( 'Filter by taxonomy (with terms).' ),
							'terms'       => H::arr( 'string', 'Term IDs or slugs for taxonomy.' ),
							'meta_key'    => H::str( 'Filter by meta key.' ),
							'meta_value'  => H::str( 'Meta value (with meta_key).' ),
							'after'       => H::str( 'Published after (YYYY-MM-DD).' ),
							'before'      => H::str( 'Published before (YYYY-MM-DD).' ),
							'orderby'     => H::enum( array( 'date', 'modified', 'title', 'menu_order', 'ID', 'rand' ), 'Order by.', 'date' ),
							'order'       => H::enum( array( 'ASC', 'DESC' ), 'Direction.', 'DESC' ),
						),
						H::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_posts' ),
			)
		);
		$r->register(
			array(
				'name'         => 'get_post',
				'title'        => 'Read a post',
				'group'        => 'content',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Full post: raw content (block markup), excerpt, status, template, featured image, terms and all meta. Long content can be read in ranges with offset/length.',
				'input_schema' => H::schema(
					array(
						'id'       => H::int( 'Post ID.' ),
						'slug'     => H::str( 'Or find by slug (with post_type).' ),
						'post_type' => H::str( 'Post type for slug lookups.', array( 'default' => 'any' ) ),
						'content'  => H::bool( 'Include content.', true ),
						'rendered' => H::bool( 'Also include the rendered HTML (the_content filters).', false ),
						'meta'     => H::bool( 'Include meta.', true ),
						'terms'    => H::bool( 'Include terms.', true ),
						'offset'   => H::int( 'Content byte offset.', array( 'minimum' => 0 ) ),
						'length'   => H::int( 'Content bytes to return.', array( 'minimum' => 1 ) ),
					)
				),
				'callback'     => array( __CLASS__, 'get_post' ),
			)
		);
		$r->register(
			array(
				'name'         => 'create_post',
				'title'        => 'Create a post',
				'group'        => 'content',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Create a post, page or any post type. Default status is draft.',
				'input_schema' => H::schema( array_merge( array( 'post_type' => H::str( 'Post type.', array( 'default' => 'post' ) ) ), $post_fields ), array( 'title' ) ),
				'callback'     => array( __CLASS__, 'create_post' ),
				'preview'      => array( __CLASS__, 'preview_create' ),
				'capability'   => 'edit_posts',
			)
		);
		$r->register(
			array(
				'name'         => 'update_post',
				'title'        => 'Update a post',
				'group'        => 'content',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Change any field of an existing post. Only the fields you send change. A revision is kept. For a small text change prefer edit_post_content.',
				'input_schema' => H::schema( array_merge( array( 'id' => H::int( 'Post ID.' ) ), $post_fields ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'update_post' ),
				'preview'      => array( __CLASS__, 'preview_update' ),
			)
		);
		$r->register(
			array(
				'name'         => 'delete_post',
				'title'        => 'Delete a post',
				'group'        => 'content',
				'risk'         => array( __CLASS__, 'delete_risk' ),
				'base_risk'    => Policy::RISK_WRITE,
				'description'  => 'Move a post to the trash (reversible), or delete it permanently with force=true (critical). Use delete_media for attachments.',
				'input_schema' => H::schema(
					array(
						'id'    => H::int( 'Post ID.' ),
						'force' => H::bool( 'Delete permanently, skipping the trash.', false ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'delete_post' ),
				'preview'      => array( __CLASS__, 'preview_delete' ),
			)
		);
		$r->register(
			array(
				'name'         => 'restore_post',
				'title'        => 'Restore from trash',
				'group'        => 'content',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Take a post out of the trash.',
				'input_schema' => H::schema( array( 'id' => H::int( 'Post ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'restore_post' ),
			)
		);
		$r->register(
			array(
				'name'         => 'edit_post_content',
				'title'        => 'Find and replace in a post',
				'group'        => 'content',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Surgical edit: replace an exact piece of text in the content, title or excerpt of a post without resending the whole document. old_text must match exactly and be unique unless replace_all is true. A revision is kept.',
				'input_schema' => H::schema(
					array(
						'id'          => H::int( 'Post ID.' ),
						'field'       => H::enum( array( 'content', 'title', 'excerpt' ), 'Field to edit.', 'content' ),
						'old_text'    => H::str( 'Exact text to find.' ),
						'new_text'    => H::str( 'Replacement (may be empty to delete).' ),
						'replace_all' => H::bool( 'Replace every occurrence.', false ),
					),
					array( 'id', 'old_text', 'new_text' )
				),
				'callback'     => array( __CLASS__, 'edit_post_content' ),
				'preview'      => array( __CLASS__, 'preview_edit' ),
			)
		);
		$r->register(
			array(
				'name'         => 'list_revisions',
				'title'        => 'List revisions',
				'group'        => 'content',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Revisions of a post (newest first).',
				'input_schema' => H::schema( array( 'id' => H::int( 'Post ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'list_revisions' ),
			)
		);
		$r->register(
			array(
				'name'         => 'restore_revision',
				'title'        => 'Restore a revision',
				'group'        => 'content',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Bring a post back to one of its revisions (the current version is kept as a new revision).',
				'input_schema' => H::schema( array( 'revision_id' => H::int( 'Revision ID from list_revisions.' ) ), array( 'revision_id' ) ),
				'callback'     => array( __CLASS__, 'restore_revision' ),
			)
		);
		$r->register(
			array(
				'name'         => 'get_post_meta',
				'title'        => 'Read post meta',
				'group'        => 'content',
				'risk'         => Policy::RISK_READ,
				'description'  => 'All meta of a post, or one key in full (values are unserialized).',
				'input_schema' => H::schema(
					array(
						'id'  => H::int( 'Post ID.' ),
						'key' => H::str( 'Meta key (omit for all).' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'get_post_meta' ),
			)
		);
		$r->register(
			array(
				'name'         => 'update_post_meta',
				'title'        => 'Set post meta',
				'group'        => 'content',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Set a meta value (any JSON value; arrays/objects are stored serialized like WordPress does). For Elementor data use elementor_update_page.',
				'input_schema' => H::schema(
					array(
						'id'    => H::int( 'Post ID.' ),
						'key'   => H::str( 'Meta key.' ),
						'value' => H::any( 'New value.' ),
					),
					array( 'id', 'key' )
				),
				'callback'     => array( __CLASS__, 'update_post_meta' ),
				'preview'      => array( __CLASS__, 'preview_meta' ),
			)
		);
		$r->register(
			array(
				'name'         => 'delete_post_meta',
				'title'        => 'Delete post meta',
				'group'        => 'content',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Delete a meta key from a post (optionally only rows with a given value).',
				'input_schema' => H::schema(
					array(
						'id'    => H::int( 'Post ID.' ),
						'key'   => H::str( 'Meta key.' ),
						'value' => H::any( 'Only delete rows with this value.' ),
					),
					array( 'id', 'key' )
				),
				'callback'     => array( __CLASS__, 'delete_post_meta' ),
				'preview'      => array( __CLASS__, 'preview_meta' ),
			)
		);
	}

	/* ----------------------------------------------------------- Callbacks */

	public static function list_post_types( array $args ) {
		$types = get_post_types( empty( $args['include_internal'] ) ? array( 'show_ui' => true ) : array(), 'objects' );
		$out   = array();
		foreach ( $types as $t ) {
			$counts = (array) wp_count_posts( $t->name );
			$out[]  = array(
				'name'         => $t->name,
				'label'        => $t->label,
				'public'       => (bool) $t->public,
				'hierarchical' => (bool) $t->hierarchical,
				'supports'     => array_keys( (array) get_all_post_type_supports( $t->name ) ),
				'taxonomies'   => get_object_taxonomies( $t->name ),
				'rest_base'    => $t->show_in_rest ? ( $t->rest_base ? $t->rest_base : $t->name ) : null,
				'counts'       => array_filter( array_map( 'intval', $counts ) ),
			);
		}
		return $out;
	}

	public static function list_posts( array $args ) {
		list( $per, $page ) = H::paginate( $args );
		$q = array(
			'post_type'        => 'any' === $args['post_type'] ? 'any' : sanitize_key( $args['post_type'] ),
			'post_status'      => 'any' === $args['status'] ? array_values( array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft', 'inherit' ) ) ) : array_map( 'sanitize_key', explode( ',', $args['status'] ) ),
			'posts_per_page'   => $per,
			'paged'            => $page,
			'orderby'          => $args['orderby'],
			'order'            => $args['order'],
			'suppress_filters' => false,
			'ignore_sticky_posts' => true,
		);
		if ( ! empty( $args['search'] ) ) {
			$q['s'] = $args['search'];
		}
		if ( ! empty( $args['author'] ) ) {
			$q['author'] = (int) $args['author'];
		}
		if ( isset( $args['parent'] ) ) {
			$q['post_parent'] = (int) $args['parent'];
		}
		if ( ! empty( $args['include'] ) ) {
			$q['post__in'] = array_map( 'intval', $args['include'] );
		}
		if ( ! empty( $args['taxonomy'] ) && ! empty( $args['terms'] ) ) {
			$numeric        = count( array_filter( $args['terms'], 'is_numeric' ) ) === count( $args['terms'] );
			$q['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => sanitize_key( $args['taxonomy'] ),
					'field'    => $numeric ? 'term_id' : 'slug',
					'terms'    => $numeric ? array_map( 'intval', $args['terms'] ) : $args['terms'],
				),
			);
		}
		if ( ! empty( $args['meta_key'] ) ) {
			$q['meta_key'] = $args['meta_key']; // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( isset( $args['meta_value'] ) ) {
				$q['meta_value'] = $args['meta_value']; // phpcs:ignore WordPress.DB.SlowDBQuery
			}
		}
		$date = array();
		if ( ! empty( $args['after'] ) ) {
			$date['after'] = $args['after'];
		}
		if ( ! empty( $args['before'] ) ) {
			$date['before'] = $args['before'];
		}
		if ( $date ) {
			$date['inclusive'] = true;
			$q['date_query']   = array( $date );
		}
		$query = new \WP_Query( $q );
		$items = array();
		foreach ( $query->posts as $p ) {
			if ( ! current_user_can( 'read_post', $p->ID ) ) {
				continue;
			}
			$items[] = H::post_summary( $p );
		}
		return array(
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
			'items' => $items,
		);
	}

	public static function find_post( array $args ) {
		if ( ! empty( $args['id'] ) ) {
			$post = get_post( (int) $args['id'] );
		} elseif ( ! empty( $args['slug'] ) ) {
			$found = get_posts(
				array(
					'name'           => sanitize_title( $args['slug'] ),
					'post_type'      => isset( $args['post_type'] ) ? $args['post_type'] : 'any',
					'post_status'    => 'any',
					'posts_per_page' => 1,
				)
			);
			$post  = $found ? $found[0] : null;
		} else {
			return H::error( 'invalid_input', 'Send id or slug.' );
		}
		if ( ! $post ) {
			return H::not_found( 'Post', ! empty( $args['id'] ) ? $args['id'] : $args['slug'] );
		}
		return $post;
	}

	public static function get_post( array $args ) {
		$post = self::find_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'read_post', $post->ID ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot read this post.' );
		}
		return H::post_full(
			$post,
			array(
				'content'        => $args['content'],
				'rendered'       => $args['rendered'],
				'meta'           => $args['meta'],
				'meta_protected' => true,
				'terms'          => $args['terms'],
				'offset'         => isset( $args['offset'] ) ? $args['offset'] : 0,
				'length'         => isset( $args['length'] ) ? $args['length'] : 0,
			)
		);
	}

	public static function create_post( array $args ) {
		$type = sanitize_key( $args['post_type'] );
		if ( ! post_type_exists( $type ) ) {
			return H::error( 'invalid_post_type', sprintf( 'Post type "%s" does not exist.', $type ), 'Call list_post_types.' );
		}
		$pto = get_post_type_object( $type );
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return H::forbidden( sprintf( 'The connected user cannot create %s.', $pto->label ) );
		}
		$data = self::postarr( $args );
		$data['post_type'] = $type;
		if ( empty( $data['post_status'] ) ) {
			$data['post_status'] = 'draft';
		}
		if ( 'publish' === $data['post_status'] && ! current_user_can( $pto->cap->publish_posts ) ) {
			return H::forbidden( 'The connected user cannot publish this type; create it as pending or draft.' );
		}
		$id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$extra = self::apply_extras( $id, $args );
		return array_merge( array( 'created' => H::post_summary( get_post( $id ) ) ), $extra );
	}

	public static function update_post( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return H::not_found( 'Post', $args['id'] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		$data = self::postarr( $args );
		if ( isset( $data['post_status'] ) && 'trash' === $data['post_status'] ) {
			return H::error( 'invalid_input', 'Use delete_post to trash a post.' );
		}
		if ( $data ) {
			$data['ID'] = $post->ID;
			$res        = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$extra = self::apply_extras( $post->ID, $args );
		return array_merge( array( 'updated' => H::post_summary( get_post( $post->ID ) ) ), $extra );
	}

	/** Map tool arguments to wp_insert_post fields. */
	private static function postarr( array $a ) {
		$map  = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'author'         => 'post_author',
			'parent'         => 'post_parent',
			'menu_order'     => 'menu_order',
			'date'           => 'post_date',
			'comment_status' => 'comment_status',
			'password'       => 'post_password',
			'template'       => 'page_template',
		);
		$data = array();
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $a ) ) {
				$data[ $to ] = $a[ $from ];
			}
		}
		if ( isset( $data['post_date'] ) ) {
			$data['post_date_gmt'] = get_gmt_from_date( $data['post_date'] );
			$data['edit_date']     = true;
		}
		return $data;
	}

	/** Terms, meta, featured image and sticky after insert/update. */
	private static function apply_extras( $id, array $args ) {
		$report = array();
		if ( isset( $args['terms'] ) && is_array( $args['terms'] ) ) {
			foreach ( $args['terms'] as $tax => $terms ) {
				if ( ! taxonomy_exists( $tax ) ) {
					$report['term_errors'][ $tax ] = 'Unknown taxonomy.';
					continue;
				}
				$ids = H::resolve_terms( $tax, (array) $terms, true );
				if ( is_wp_error( $ids ) ) {
					$report['term_errors'][ $tax ] = $ids->get_error_message();
					continue;
				}
				wp_set_object_terms( $id, $ids, $tax, false );
			}
		}
		if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $key => $value ) {
				if ( '_elementor_data' === $key ) {
					$report['meta_errors'][ $key ] = 'Use elementor_update_page for Elementor data.';
					continue;
				}
				if ( null === $value ) {
					delete_post_meta( $id, $key );
				} else {
					update_post_meta( $id, $key, wp_slash( $value ) );
				}
			}
		}
		if ( isset( $args['featured_image'] ) ) {
			if ( (int) $args['featured_image'] > 0 ) {
				if ( ! set_post_thumbnail( $id, (int) $args['featured_image'] ) && (int) get_post_thumbnail_id( $id ) !== (int) $args['featured_image'] ) {
					$report['featured_image_error'] = 'Not an image attachment.';
				}
			} else {
				delete_post_thumbnail( $id );
			}
		}
		if ( isset( $args['sticky'] ) && 'post' === get_post_type( $id ) ) {
			if ( $args['sticky'] ) {
				stick_post( $id );
			} else {
				unstick_post( $id );
			}
		}
		return $report;
	}

	public static function delete_risk( array $args ) {
		return empty( $args['force'] ) ? Policy::RISK_WRITE : Policy::RISK_CRITICAL;
	}

	public static function delete_post( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return H::not_found( 'Post', $args['id'] );
		}
		if ( 'attachment' === $post->post_type ) {
			return H::error( 'invalid_input', 'This is an attachment.', 'Use delete_media.' );
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot delete this post.' );
		}
		$summary = H::post_summary( $post );
		if ( empty( $args['force'] ) && EMPTY_TRASH_DAYS ) {
			$res = wp_trash_post( $post->ID );
			return $res ? array( 'trashed' => $summary, 'undo' => 'restore_post with this id' ) : H::error( 'failed', 'Could not move the post to the trash.' );
		}
		$res = wp_delete_post( $post->ID, true );
		return $res ? array( 'deleted_permanently' => $summary ) : H::error( 'failed', 'Could not delete the post.' );
	}

	public static function restore_post( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post || 'trash' !== $post->post_status ) {
			return H::error( 'invalid_input', 'That post is not in the trash.' );
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot restore this post.' );
		}
		$res = wp_untrash_post( $post->ID );
		return $res ? array(
			'restored' => H::post_summary( get_post( $post->ID ) ),
			'note'     => 'WordPress brings trashed posts back as drafts. Publish it again with update_post (status: publish) if it was public.',
		) : H::error( 'failed', 'Could not restore the post.' );
	}

	public static function edit_post_content( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return H::not_found( 'Post', $args['id'] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		$field = array(
			'content' => 'post_content',
			'title'   => 'post_title',
			'excerpt' => 'post_excerpt',
		)[ $args['field'] ];
		$res = H::exact_replace( (string) $post->$field, (string) $args['old_text'], (string) $args['new_text'], ! empty( $args['replace_all'] ), $args['field'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		list( $new, $count ) = $res;
		$up = wp_update_post(
			wp_slash(
				array(
					'ID'   => $post->ID,
					$field => $new,
				)
			),
			true
		);
		if ( is_wp_error( $up ) ) {
			return $up;
		}
		$saved = get_post( $post->ID )->$field;
		$out   = array(
			'id'           => $post->ID,
			'field'        => $args['field'],
			'replacements' => $count,
			'context'      => '' !== (string) $args['new_text'] ? H::context_snippet( $saved, (string) $args['new_text'], 80 ) : null,
		);
		if ( $saved !== $new ) {
			$out['warning'] = 'WordPress filtered the saved text (e.g. kses removed some HTML). Read the post again to see the stored version.';
		}
		return $out;
	}

	public static function list_revisions( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return H::not_found( 'Post', $args['id'] );
		}
		$out = array();
		foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 50 ) ) as $rev ) {
			$out[] = array(
				'revision_id' => (int) $rev->ID,
				'date'        => $rev->post_modified,
				'author'      => (int) $rev->post_author,
				'title'       => $rev->post_title,
				'autosave'    => wp_is_post_autosave( $rev ) ? true : false,
				'length'      => strlen( $rev->post_content ),
			);
		}
		return array(
			'post_id'   => $post->ID,
			'revisions' => $out,
		);
	}

	public static function restore_revision( array $args ) {
		$rev = wp_get_post_revision( (int) $args['revision_id'] );
		if ( ! $rev ) {
			return H::not_found( 'Revision', $args['revision_id'] );
		}
		if ( ! current_user_can( 'edit_post', $rev->post_parent ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		$res = wp_restore_post_revision( $rev->ID );
		return $res ? array( 'restored' => H::post_summary( get_post( $rev->post_parent ) ), 'from_revision' => $rev->ID ) : H::error( 'failed', 'Could not restore the revision.' );
	}

	public static function get_post_meta( array $args ) {
		if ( ! get_post( (int) $args['id'] ) ) {
			return H::not_found( 'Post', $args['id'] );
		}
		if ( ! empty( $args['key'] ) ) {
			$values = get_post_meta( (int) $args['id'], $args['key'], false );
			return array(
				'key'    => $args['key'],
				'exists' => metadata_exists( 'post', (int) $args['id'], $args['key'] ),
				'value'  => 1 === count( $values ) ? $values[0] : $values,
			);
		}
		return H::all_meta( 'post', (int) $args['id'], true );
	}

	public static function update_post_meta( array $args ) {
		$id = (int) $args['id'];
		if ( ! get_post( $id ) ) {
			return H::not_found( 'Post', $id );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		if ( '_elementor_data' === $args['key'] ) {
			return H::error( 'use_elementor_tool', 'Elementor data must be saved through Elementor.', 'Use elementor_update_page: it validates the JSON and regenerates the CSS.' );
		}
		$value = array_key_exists( 'value', $args ) ? $args['value'] : '';
		$old   = get_post_meta( $id, $args['key'], true );
		update_post_meta( $id, $args['key'], wp_slash( $value ) );
		return array(
			'id'       => $id,
			'key'      => $args['key'],
			'previous' => $old,
			'value'    => get_post_meta( $id, $args['key'], true ),
		);
	}

	public static function delete_post_meta( array $args ) {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return H::forbidden( 'The connected user cannot edit this post.' );
		}
		$ok = array_key_exists( 'value', $args ) ? delete_post_meta( $id, $args['key'], wp_slash( $args['value'] ) ) : delete_post_meta( $id, $args['key'] );
		return array(
			'id'      => $id,
			'key'     => $args['key'],
			'deleted' => (bool) $ok,
		);
	}

	/* ------------------------------------------------------------ Previews */

	public static function preview_create( array $args ) {
		return array(
			'will_create' => isset( $args['post_type'] ) ? $args['post_type'] : 'post',
			'title'       => isset( $args['title'] ) ? $args['title'] : '',
			'status'      => isset( $args['status'] ) ? $args['status'] : 'draft',
			'content'     => isset( $args['content'] ) ? \MCPress\Util::truncate( $args['content'], 1500 ) : '',
		);
	}

	public static function preview_update( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return 'Post not found.';
		}
		$changes = array();
		foreach ( self::postarr( $args ) as $field => $value ) {
			$current           = isset( $post->$field ) ? $post->$field : '';
			$changes[ $field ] = array(
				'current' => \MCPress\Util::truncate( (string) $current, 800 ),
				'new'     => \MCPress\Util::truncate( (string) $value, 800 ),
			);
		}
		return array(
			'post'    => H::post_summary( $post ),
			'changes' => $changes,
			'also'    => array_values( array_intersect( array_keys( $args ), array( 'terms', 'meta', 'featured_image', 'sticky' ) ) ),
		);
	}

	public static function preview_delete( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return 'Post not found.';
		}
		return array(
			'post'   => H::post_summary( $post ),
			'action' => empty( $args['force'] ) ? 'Move to trash (can be restored)' : 'DELETE PERMANENTLY (cannot be undone)',
			'revisions_and_meta' => empty( $args['force'] ) ? 'kept' : 'deleted too',
		);
	}

	public static function preview_edit( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return 'Post not found.';
		}
		$field   = array(
			'content' => 'post_content',
			'title'   => 'post_title',
			'excerpt' => 'post_excerpt',
		)[ isset( $args['field'] ) ? $args['field'] : 'content' ];
		$current = (string) $post->$field;
		return array(
			'post'        => H::post_summary( $post ),
			'matches'     => substr_count( $current, (string) $args['old_text'] ),
			'before'      => H::context_snippet( $current, (string) $args['old_text'] ),
			'replacement' => $args['new_text'],
		);
	}

	public static function preview_meta( array $args ) {
		return array(
			'post'    => get_post( (int) $args['id'] ) ? H::post_summary( get_post( (int) $args['id'] ) ) : 'not found',
			'key'     => $args['key'],
			'current' => get_post_meta( (int) $args['id'], $args['key'], true ),
			'new'     => array_key_exists( 'value', $args ) ? $args['value'] : '(deleted)',
		);
	}
}
