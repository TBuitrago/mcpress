<?php
/**
 * Shared helpers for tool providers: schema shorthands, errors, serializers.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\Context;

defined( 'ABSPATH' ) || exit;

class Helpers {

	/* ------------------------------------------------------------ Schema bits */

	public static function str( $description, array $extra = array() ) {
		return array_merge( array( 'type' => 'string', 'description' => $description ), $extra );
	}

	public static function int( $description, array $extra = array() ) {
		return array_merge( array( 'type' => 'integer', 'description' => $description ), $extra );
	}

	public static function bool( $description, $default = null ) {
		$s = array( 'type' => 'boolean', 'description' => $description );
		if ( null !== $default ) {
			$s['default'] = $default;
		}
		return $s;
	}

	public static function enum( array $values, $description, $default = null ) {
		$s = array( 'type' => 'string', 'enum' => $values, 'description' => $description );
		if ( null !== $default ) {
			$s['default'] = $default;
		}
		return $s;
	}

	public static function arr( $items, $description ) {
		return array( 'type' => 'array', 'items' => is_string( $items ) ? array( 'type' => $items ) : $items, 'description' => $description );
	}

	public static function obj( $description, array $properties = array() ) {
		$s = array( 'type' => 'object', 'description' => $description );
		if ( $properties ) {
			$s['properties'] = $properties;
		}
		return $s;
	}

	/** Any JSON value. */
	public static function any( $description ) {
		return array( 'description' => $description );
	}

	public static function schema( array $properties, array $required = array() ) {
		$s = array( 'type' => 'object', 'properties' => $properties );
		if ( $required ) {
			$s['required'] = $required;
		}
		return $s;
	}

	public static function paging() {
		return array(
			'per_page' => self::int( 'Items per page (1-100).', array( 'minimum' => 1, 'maximum' => 100, 'default' => 20 ) ),
			'page'     => self::int( 'Page number, from 1.', array( 'minimum' => 1, 'default' => 1 ) ),
		);
	}

	/* ----------------------------------------------------------------- Errors */

	public static function error( $code, $message, $hint = null, $retry_ok = false, $details = null ) {
		$data = array( 'retry_ok' => (bool) $retry_ok );
		if ( $hint ) {
			$data['hint'] = $hint;
		}
		if ( null !== $details ) {
			$data['details'] = $details;
		}
		return new \WP_Error( $code, $message, $data );
	}

	public static function not_found( $what, $id ) {
		return self::error( 'not_found', sprintf( '%s %s does not exist.', $what, is_scalar( $id ) ? (string) $id : wp_json_encode( $id ) ), 'Use the matching list_* tool to find valid IDs.' );
	}

	public static function forbidden( $message ) {
		return self::error( 'forbidden', $message, 'This is a permission of the connected WordPress user, not of MCPress. Do not retry.' );
	}

	/** File changes allowed by the server configuration? */
	public static function file_mods_error( $context = 'mcpress' ) {
		if ( ! wp_is_file_mod_allowed( $context ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return self::error( 'file_mods_disabled', 'DISALLOW_FILE_MODS is set in wp-config.php: installing, updating, deleting or editing code files is disabled on this server.', 'Tell the user. Only they can change wp-config.php; do not try to work around it.' );
		}
		return null;
	}

	public static function file_edit_error() {
		$mods = self::file_mods_error( 'mcpress_file_edit' );
		if ( $mods ) {
			return $mods;
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return self::error( 'file_edit_disabled', 'DISALLOW_FILE_EDIT is set in wp-config.php: editing files is disabled on this server.', 'Tell the user. Only they can change wp-config.php; do not try to work around it.' );
		}
		return null;
	}

	/* ------------------------------------------------------------- Includes */

	public static function admin_includes() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		foreach ( array( 'file', 'plugin', 'theme', 'update', 'misc', 'post', 'user', 'image', 'media', 'nav-menu', 'class-wp-upgrader', 'plugin-install', 'theme-install', 'translation-install' ) as $f ) {
			$path = ABSPATH . 'wp-admin/includes/' . $f . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/* ---------------------------------------------------------- Serializers */

	public static function post_summary( \WP_Post $p ) {
		return array(
			'id'       => (int) $p->ID,
			'type'     => $p->post_type,
			'status'   => $p->post_status,
			'title'    => $p->post_title,
			'slug'     => $p->post_name,
			'date'     => $p->post_date,
			'modified' => $p->post_modified,
			'author'   => (int) $p->post_author,
			'parent'   => (int) $p->post_parent,
			'link'     => get_permalink( $p ),
		);
	}

	public static function post_full( \WP_Post $p, array $opts = array() ) {
		$out = self::post_summary( $p );

		$out['excerpt']        = $p->post_excerpt;
		$out['menu_order']     = (int) $p->menu_order;
		$out['comment_status'] = $p->comment_status;
		$out['has_password']   = '' !== $p->post_password;
		$out['template']       = get_page_template_slug( $p );
		$out['featured_image'] = (int) get_post_thumbnail_id( $p );
		$out['sticky']         = 'post' === $p->post_type ? is_sticky( $p->ID ) : false;
		$out['edit_link']      = admin_url( 'post.php?post=' . $p->ID . '&action=edit' );
		$out['builder']        = self::builder_of( $p->ID );

		if ( ! empty( $opts['content'] ) ) {
			$content = (string) $p->post_content;
			$offset  = isset( $opts['offset'] ) ? max( 0, (int) $opts['offset'] ) : 0;
			$length  = isset( $opts['length'] ) && $opts['length'] > 0 ? (int) $opts['length'] : 200000;
			$total   = strlen( $content );
			$part    = substr( $content, $offset, $length );
			$out['content']        = false === $part ? '' : $part;
			$out['content_length'] = $total;
			if ( $offset > 0 || $offset + $length < $total ) {
				$out['content_range'] = array( 'offset' => $offset, 'length' => strlen( $out['content'] ), 'total' => $total, 'note' => 'Partial content. Call again with offset/length for the rest.' );
			}
			if ( ! empty( $opts['rendered'] ) ) {
				$GLOBALS['post'] = $p; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				setup_postdata( $p );
				$out['content_rendered'] = apply_filters( 'the_content', $p->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				wp_reset_postdata();
			}
		}
		if ( ! empty( $opts['terms'] ) ) {
			$out['terms'] = array();
			foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
				$terms = wp_get_object_terms( $p->ID, $tax, array( 'fields' => 'all' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					$out['terms'][ $tax ] = array_map(
						function ( $t ) {
							return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
						},
						$terms
					);
				}
			}
		}
		if ( ! empty( $opts['meta'] ) ) {
			$out['meta'] = self::all_meta( 'post', $p->ID, ! empty( $opts['meta_protected'] ) );
		}
		return $out;
	}

	/** All meta of an object, unserialized. Huge builder blobs are summarised. */
	public static function all_meta( $type, $id, $include_protected = true ) {
		$meta = get_metadata( $type, $id );
		$out  = array();
		foreach ( (array) $meta as $key => $values ) {
			if ( ! $include_protected && is_protected_meta( $key, $type ) ) {
				continue;
			}
			$values = array_map( 'maybe_unserialize', (array) $values );
			foreach ( $values as $i => $v ) {
				if ( is_string( $v ) && strlen( $v ) > 5000 ) {
					$values[ $i ] = substr( $v, 0, 300 ) . '… [' . strlen( $v ) . ' bytes; read it with get_post_meta and the key]';
				}
			}
			$out[ $key ] = 1 === count( $values ) ? $values[0] : $values;
		}
		return $out;
	}

	public static function builder_of( $post_id ) {
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}
		if ( 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true ) ) {
			return 'divi';
		}
		if ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
			return 'beaver-builder';
		}
		if ( get_post_meta( $post_id, '_bricks_page_content_2', true ) ) {
			return 'bricks';
		}
		if ( get_post_meta( $post_id, '_breakdance_data', true ) ) {
			return 'breakdance';
		}
		$post = get_post( $post_id );
		if ( $post && has_blocks( $post->post_content ) ) {
			return 'blocks';
		}
		return 'classic';
	}

	public static function user_summary( \WP_User $u ) {
		return array(
			'id'           => (int) $u->ID,
			'login'        => $u->user_login,
			'email'        => $u->user_email,
			'display_name' => $u->display_name,
			'roles'        => array_values( $u->roles ),
			'registered'   => $u->user_registered,
		);
	}

	public static function term_summary( \WP_Term $t ) {
		return array(
			'id'          => (int) $t->term_id,
			'taxonomy'    => $t->taxonomy,
			'name'        => $t->name,
			'slug'        => $t->slug,
			'description' => $t->description,
			'parent'      => (int) $t->parent,
			'count'       => (int) $t->count,
			'link'        => get_term_link( $t ),
		);
	}

	/** Resolve a list of term IDs or names into IDs, creating missing names when allowed. */
	public static function resolve_terms( $taxonomy, array $terms, $create = true ) {
		$ids = array();
		foreach ( $terms as $t ) {
			if ( is_numeric( $t ) ) {
				$ids[] = (int) $t;
				continue;
			}
			$found = term_exists( (string) $t, $taxonomy );
			if ( ! $found && $create ) {
				$found = wp_insert_term( (string) $t, $taxonomy );
			}
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( $found ) {
				$ids[] = (int) ( is_array( $found ) ? $found['term_id'] : $found );
			}
		}
		return $ids;
	}

	public static function paginate( array $args ) {
		$per = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$pg  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		return array( $per, $pg );
	}

	/** Exact find & replace with clear errors. Returns [new_text, count] or WP_Error. */
	public static function exact_replace( $haystack, $old, $new, $replace_all = false, $what = 'content' ) {
		if ( '' === (string) $old ) {
			return self::error( 'invalid_input', 'old_text must not be empty.' );
		}
		$count = substr_count( $haystack, $old );
		if ( 0 === $count ) {
			$hint = 'Copy the exact text (including spaces, quotes and HTML) from the current version and try again.';
			$norm = static function ( $s ) {
				return html_entity_decode( str_replace( array( "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D", "\r\n" ), array( "'", "'", '"', '"', "\n" ), $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			};
			if ( substr_count( $norm( $haystack ), $norm( $old ) ) > 0 ) {
				$hint = 'A match exists if quotes, line endings or HTML entities are ignored: the stored text uses different characters. Read it again and copy it exactly.';
			}
			return self::error( 'no_match', sprintf( 'old_text was not found in the %s.', $what ), $hint );
		}
		if ( $count > 1 && ! $replace_all ) {
			return self::error( 'multiple_matches', sprintf( 'old_text appears %d times in the %s.', $count, $what ), 'Include more surrounding text so it is unique, or set replace_all to true.' );
		}
		return array( str_replace( $old, $new, $haystack ), $count );
	}

	/** Short before/after context of the first match (for previews). */
	public static function context_snippet( $text, $needle, $radius = 120 ) {
		$pos = strpos( $text, $needle );
		if ( false === $pos ) {
			return null;
		}
		$start = max( 0, $pos - $radius );
		return ( $start > 0 ? '…' : '' ) . substr( $text, $start, strlen( $needle ) + 2 * $radius ) . '…';
	}

	public static function ctx_user_id( Context $ctx ) {
		return (int) $ctx->user_id;
	}
}
