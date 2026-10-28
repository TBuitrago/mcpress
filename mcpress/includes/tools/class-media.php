<?php
/**
 * Media library.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Media {

	const MAX_BASE64_BYTES = 20971520; // 20 MB decoded.

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'list_media',
				'title'        => 'List media',
				'group'        => 'media',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Find attachments in the media library by type, text or the post they are attached to.',
				'input_schema' => H::schema(
					array_merge(
						array(
							'mime_type' => H::str( 'e.g. image, image/png, application/pdf, video.' ),
							'search'    => H::str( 'Search title, caption, filename.' ),
							'parent'    => H::int( 'Attached to this post ID (0 = unattached).' ),
						),
						H::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_media' ),
			)
		);
		$r->register(
			array(
				'name'         => 'get_media',
				'title'        => 'Read an attachment',
				'group'        => 'media',
				'risk'         => Policy::RISK_READ,
				'description'  => 'URL, sizes, dimensions, alt text, caption and description of an attachment.',
				'input_schema' => H::schema( array( 'id' => H::int( 'Attachment ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'get_media' ),
			)
		);
		$r->register(
			array(
				'name'         => 'upload_media',
				'title'        => 'Upload media',
				'group'        => 'media',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Add a file to the media library from a public URL (downloaded by the site, private network addresses refused) or from base64 data. File types follow WordPress rules (no SVG unless a plugin allows it).',
				'input_schema' => H::schema(
					array(
						'url'           => H::str( 'Public http(s) URL of the file.' ),
						'base64'        => H::str( 'File contents in base64 (alternative to url).' ),
						'filename'      => H::str( 'File name with extension (required with base64).' ),
						'title'         => H::str( 'Title.' ),
						'alt'           => H::str( 'Alt text (images).' ),
						'caption'       => H::str( 'Caption.' ),
						'description'   => H::str( 'Description.' ),
						'post_id'       => H::int( 'Attach to this post.' ),
						'set_featured'  => H::bool( 'Make it the featured image of post_id.', false ),
					)
				),
				'callback'     => array( __CLASS__, 'upload_media' ),
				'capability'   => 'upload_files',
			)
		);
		$r->register(
			array(
				'name'         => 'update_media',
				'title'        => 'Update an attachment',
				'group'        => 'media',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Change title, alt text, caption or description of an attachment.',
				'input_schema' => H::schema(
					array(
						'id'          => H::int( 'Attachment ID.' ),
						'title'       => H::str( 'Title.' ),
						'alt'         => H::str( 'Alt text.' ),
						'caption'     => H::str( 'Caption.' ),
						'description' => H::str( 'Description.' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'update_media' ),
			)
		);
		$r->register(
			array(
				'name'         => 'delete_media',
				'title'        => 'Delete an attachment',
				'group'        => 'media',
				'risk'         => Policy::RISK_CRITICAL,
				'description'  => 'Permanently delete an attachment and its files from the server. Images used in content will break.',
				'input_schema' => H::schema( array( 'id' => H::int( 'Attachment ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'delete_media' ),
				'preview'      => array( __CLASS__, 'preview_delete' ),
			)
		);
	}

	public static function summary( \WP_Post $a, $full = false ) {
		$meta = wp_get_attachment_metadata( $a->ID );
		$out  = array(
			'id'        => (int) $a->ID,
			'title'     => $a->post_title,
			'mime_type' => $a->post_mime_type,
			'url'       => wp_get_attachment_url( $a->ID ),
			'alt'       => (string) get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
			'parent'    => (int) $a->post_parent,
			'date'      => $a->post_date,
			'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		);
		if ( $full ) {
			$out['caption']     = $a->post_excerpt;
			$out['description'] = $a->post_content;
			$out['file']        = get_post_meta( $a->ID, '_wp_attached_file', true );
			$out['filesize']    = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : null;
			$sizes              = array();
			if ( ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $name => $s ) {
					$src            = wp_get_attachment_image_src( $a->ID, $name );
					$sizes[ $name ] = array(
						'url'    => $src ? $src[0] : null,
						'width'  => (int) $s['width'],
						'height' => (int) $s['height'],
					);
				}
			}
			$out['sizes'] = $sizes;
		}
		return $out;
	}

	public static function list_media( array $args ) {
		list( $per, $page ) = H::paginate( $args );
		$q = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per,
			'paged'          => $page,
		);
		if ( ! empty( $args['mime_type'] ) ) {
			$q['post_mime_type'] = $args['mime_type'];
		}
		if ( ! empty( $args['search'] ) ) {
			$q['s'] = $args['search'];
		}
		if ( isset( $args['parent'] ) ) {
			$q['post_parent'] = (int) $args['parent'];
		}
		$query = new \WP_Query( $q );
		return array(
			'total' => (int) $query->found_posts,
			'page'  => $page,
			'items' => array_map( array( __CLASS__, 'summary' ), $query->posts ),
		);
	}

	public static function get_media( array $args ) {
		$a = get_post( (int) $args['id'] );
		if ( ! $a || 'attachment' !== $a->post_type ) {
			return H::not_found( 'Attachment', $args['id'] );
		}
		return self::summary( $a, true );
	}

	public static function upload_media( array $args ) {
		H::admin_includes();
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return H::forbidden( 'The connected user cannot edit the post to attach to.' );
		}

		if ( ! empty( $args['url'] ) ) {
			$url = esc_url_raw( $args['url'] );
			if ( ! wp_http_validate_url( $url ) ) {
				return H::error( 'invalid_url', 'The URL is not public or not valid. Private and local network addresses are refused.' );
			}
			$tmp = download_url( $url, 60 ); // Uses wp_safe_remote_get: blocks internal hosts.
			if ( is_wp_error( $tmp ) ) {
				return H::error( 'download_failed', 'Could not download the file: ' . $tmp->get_error_message(), null, true );
			}
			$name = ! empty( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			if ( '' === $name || false === strpos( $name, '.' ) ) {
				$name = self::name_from_mime( $tmp, $name ? $name : 'file' );
			}
		} elseif ( ! empty( $args['base64'] ) ) {
			if ( empty( $args['filename'] ) ) {
				return H::error( 'invalid_input', 'filename is required with base64.' );
			}
			$data = preg_replace( '#^data:[^;]+;base64,#', '', (string) $args['base64'] );
			$bin  = base64_decode( $data, true );
			if ( false === $bin ) {
				return H::error( 'invalid_input', 'base64 is not valid.' );
			}
			if ( strlen( $bin ) > min( self::MAX_BASE64_BYTES, wp_max_upload_size() ) ) {
				return H::error( 'too_large', 'File is larger than the upload limit of this site.' );
			}
			$tmp = wp_tempnam( 'mcpress-upload' );
			file_put_contents( $tmp, $bin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$name = sanitize_file_name( $args['filename'] );
		} else {
			return H::error( 'invalid_input', 'Send url or base64.' );
		}

		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$id   = media_handle_sideload( $file, $post_id, isset( $args['title'] ) ? $args['title'] : null );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return H::error( 'upload_failed', $id->get_error_message(), 'Check the file type is allowed by WordPress.' );
		}
		self::apply_fields( $id, $args );
		if ( $post_id && ! empty( $args['set_featured'] ) ) {
			set_post_thumbnail( $post_id, $id );
		}
		return array( 'uploaded' => self::summary( get_post( $id ), true ) );
	}

	private static function name_from_mime( $path, $base ) {
		$type = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : '';
		$ext  = array(
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/gif'       => 'gif',
			'image/webp'      => 'webp',
			'image/avif'      => 'avif',
			'application/pdf' => 'pdf',
			'video/mp4'       => 'mp4',
		);
		return $base . '.' . ( isset( $ext[ $type ] ) ? $ext[ $type ] : 'bin' );
	}

	private static function apply_fields( $id, array $args ) {
		$post = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$post['post_title'] = $args['title'];
		}
		if ( isset( $args['caption'] ) ) {
			$post['post_excerpt'] = $args['caption'];
		}
		if ( isset( $args['description'] ) ) {
			$post['post_content'] = $args['description'];
		}
		if ( count( $post ) > 1 ) {
			wp_update_post( wp_slash( $post ) );
		}
		if ( isset( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $args['alt'] ) ) );
		}
	}

	public static function update_media( array $args ) {
		$a = get_post( (int) $args['id'] );
		if ( ! $a || 'attachment' !== $a->post_type ) {
			return H::not_found( 'Attachment', $args['id'] );
		}
		if ( ! current_user_can( 'edit_post', $a->ID ) ) {
			return H::forbidden( 'The connected user cannot edit this attachment.' );
		}
		self::apply_fields( $a->ID, $args );
		return array( 'updated' => self::summary( get_post( $a->ID ), true ) );
	}

	public static function delete_media( array $args ) {
		$a = get_post( (int) $args['id'] );
		if ( ! $a || 'attachment' !== $a->post_type ) {
			return H::not_found( 'Attachment', $args['id'] );
		}
		if ( ! current_user_can( 'delete_post', $a->ID ) ) {
			return H::forbidden( 'The connected user cannot delete this attachment.' );
		}
		$summary = self::summary( $a );
		return wp_delete_attachment( $a->ID, true ) ? array( 'deleted' => $summary ) : H::error( 'failed', 'Could not delete the attachment.' );
	}

	public static function preview_delete( array $args ) {
		$a = get_post( (int) $args['id'] );
		return $a && 'attachment' === $a->post_type ? array( 'attachment' => self::summary( $a ), 'action' => 'Delete the file and all its sizes permanently' ) : 'Attachment not found.';
	}
}
