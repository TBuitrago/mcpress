<?php
/**
 * Comments.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Comments {

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'list_comments',
				'title'        => 'List comments',
				'group'        => 'comments',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Comments by status (pending, approved, spam, trash), post or text.',
				'input_schema' => H::schema(
					array_merge(
						array(
							'status'  => H::enum( array( 'all', 'hold', 'approve', 'spam', 'trash' ), 'Status (hold = pending).', 'all' ),
							'post_id' => H::int( 'Only this post.' ),
							'search'  => H::str( 'Search text.' ),
							'type'    => H::str( 'comment, pingback, review…' ),
						),
						H::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_comments' ),
				'capability'   => 'moderate_comments',
			)
		);
		$r->register(
			array(
				'name'         => 'moderate_comment',
				'title'        => 'Moderate comments',
				'group'        => 'comments',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Approve, unapprove, mark as spam / not spam, trash or untrash one or many comments.',
				'input_schema' => H::schema(
					array(
						'ids'    => H::arr( 'integer', 'Comment IDs.' ),
						'action' => H::enum( array( 'approve', 'unapprove', 'spam', 'unspam', 'trash', 'untrash' ), 'Action.' ),
					),
					array( 'ids', 'action' )
				),
				'callback'     => array( __CLASS__, 'moderate_comment' ),
				'capability'   => 'moderate_comments',
			)
		);
		$r->register(
			array(
				'name'         => 'reply_comment',
				'title'        => 'Reply to a comment',
				'group'        => 'comments',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Publish a reply as the connected user (approved immediately).',
				'input_schema' => H::schema(
					array(
						'parent_id' => H::int( 'Comment to reply to.' ),
						'content'   => H::str( 'Reply text.' ),
					),
					array( 'parent_id', 'content' )
				),
				'callback'     => array( __CLASS__, 'reply_comment' ),
				'capability'   => 'moderate_comments',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_comment',
				'title'        => 'Delete comments',
				'group'        => 'comments',
				'risk'         => array( __CLASS__, 'delete_risk' ),
				'base_risk'    => Policy::RISK_WRITE,
				'description'  => 'Move comments to the trash, or delete them permanently with force=true (critical).',
				'input_schema' => H::schema(
					array(
						'ids'   => H::arr( 'integer', 'Comment IDs.' ),
						'force' => H::bool( 'Delete permanently.', false ),
					),
					array( 'ids' )
				),
				'callback'     => array( __CLASS__, 'delete_comment' ),
				'capability'   => 'moderate_comments',
			)
		);
	}

	private static function summary( \WP_Comment $c ) {
		return array(
			'id'      => (int) $c->comment_ID,
			'post_id' => (int) $c->comment_post_ID,
			'author'  => $c->comment_author,
			'email'   => $c->comment_author_email,
			'url'     => $c->comment_author_url,
			'date'    => $c->comment_date,
			'status'  => wp_get_comment_status( $c ),
			'type'    => $c->comment_type,
			'parent'  => (int) $c->comment_parent,
			'content' => $c->comment_content,
		);
	}

	public static function list_comments( array $args ) {
		list( $per, $page ) = H::paginate( $args );
		$q = array(
			'number' => $per,
			'offset' => ( $page - 1 ) * $per,
			'status' => $args['status'],
		);
		if ( ! empty( $args['post_id'] ) ) {
			$q['post_id'] = (int) $args['post_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$q['search'] = $args['search'];
		}
		if ( ! empty( $args['type'] ) ) {
			$q['type'] = $args['type'];
		}
		$items = get_comments( $q );
		$count = get_comments( array_merge( $q, array( 'count' => true, 'number' => 0, 'offset' => 0 ) ) );
		return array(
			'total' => (int) $count,
			'page'  => $page,
			'items' => array_map( array( __CLASS__, 'summary' ), $items ),
		);
	}

	public static function moderate_comment( array $args ) {
		$map  = array(
			'approve'   => 'wp_set_comment_status',
			'unapprove' => 'wp_set_comment_status',
			'spam'      => 'wp_spam_comment',
			'unspam'    => 'wp_unspam_comment',
			'trash'     => 'wp_trash_comment',
			'untrash'   => 'wp_untrash_comment',
		);
		$done = array();
		$fail = array();
		foreach ( (array) $args['ids'] as $id ) {
			$id = (int) $id;
			if ( ! get_comment( $id ) ) {
				$fail[ $id ] = 'not found';
				continue;
			}
			if ( 'approve' === $args['action'] ) {
				$ok = wp_set_comment_status( $id, 'approve' );
			} elseif ( 'unapprove' === $args['action'] ) {
				$ok = wp_set_comment_status( $id, 'hold' );
			} else {
				$ok = call_user_func( $map[ $args['action'] ], $id );
			}
			if ( $ok && ! is_wp_error( $ok ) ) {
				$done[] = $id;
			} else {
				$fail[ $id ] = 'failed';
			}
		}
		return array(
			'action' => $args['action'],
			'done'   => $done,
			'failed' => $fail ? $fail : new \stdClass(),
		);
	}

	public static function reply_comment( array $args ) {
		$parent = get_comment( (int) $args['parent_id'] );
		if ( ! $parent ) {
			return H::not_found( 'Comment', $args['parent_id'] );
		}
		$user = wp_get_current_user();
		$id   = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $parent->comment_post_ID,
					'comment_parent'       => (int) $parent->comment_ID,
					'comment_content'      => $args['content'],
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_approved'     => 1,
				)
			)
		);
		if ( ! $id ) {
			return H::error( 'failed', 'Could not save the reply.' );
		}
		return array( 'created' => self::summary( get_comment( $id ) ) );
	}

	public static function delete_risk( array $args ) {
		return empty( $args['force'] ) ? Policy::RISK_WRITE : Policy::RISK_CRITICAL;
	}

	public static function delete_comment( array $args ) {
		$done = array();
		foreach ( (array) $args['ids'] as $id ) {
			if ( wp_delete_comment( (int) $id, ! empty( $args['force'] ) ) ) {
				$done[] = (int) $id;
			}
		}
		return array(
			empty( $args['force'] ) ? 'trashed' : 'deleted_permanently' => $done,
		);
	}
}
