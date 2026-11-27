<?php
/**
 * Shared helpers for the comment abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Comments;

use WP_Comment;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - CommentSupport
 *
 * Formats comments without the commenter's IP address or browser user agent,
 * which the comment tools never return. The commenter's email address is only
 * included for users who can moderate comments, the same people who see it on
 * the Comments screen.
 */
final class CommentSupport {

	/**
	 * Status names accepted by the tools, mapped to wp_set_comment_status() values.
	 */
	public const STATUSES = array(
		'approve' => 'approve',
		'hold'    => 'hold',
		'spam'    => 'spam',
		'trash'   => 'trash',
	);

	/**
	 * Loads a comment the current user may edit.
	 *
	 * @param mixed $comment_id Comment ID.
	 *
	 * @return \WP_Comment|\WP_Error The comment.
	 */
	public static function require_can_edit( $comment_id ) {
		$comment_id = (int) $comment_id;
		$comment    = $comment_id > 0 ? get_comment( $comment_id ) : null;

		if ( ! $comment instanceof WP_Comment ) {
			return new WP_Error( 'comment_not_found', sprintf( 'Comment %d does not exist.', $comment_id ) );
		}

		if ( ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
			return new WP_Error( 'cannot_edit_comment', sprintf( 'User cannot edit comment %d.', $comment_id ) );
		}

		return $comment;
	}

	/**
	 * The status name of a comment, in the tools' vocabulary.
	 *
	 * @param \WP_Comment $comment The comment.
	 *
	 * @return string approve, hold, spam, trash or another stored value.
	 */
	public static function status( WP_Comment $comment ): string {
		$status = wp_get_comment_status( $comment );

		if ( 'approved' === $status ) {
			return 'approve';
		}

		if ( 'unapproved' === $status ) {
			return 'hold';
		}

		return (string) $status;
	}

	/**
	 * Formats a comment for a client.
	 *
	 * @param \WP_Comment $comment The comment.
	 *
	 * @return array<string, mixed> The comment.
	 */
	public static function format_comment( WP_Comment $comment ): array {
		$post = get_post( (int) $comment->comment_post_ID );

		$data = array(
			'id'           => (int) $comment->comment_ID,
			'post_id'      => (int) $comment->comment_post_ID,
			'post_title'   => $post ? $post->post_title : '',
			'parent'       => (int) $comment->comment_parent,
			'type'         => '' === (string) $comment->comment_type ? 'comment' : (string) $comment->comment_type,
			'status'       => self::status( $comment ),
			'author_name'  => $comment->comment_author,
			'author_url'   => $comment->comment_author_url,
			'user_id'      => (int) $comment->user_id,
			'date'         => $comment->comment_date,
			'date_gmt'     => $comment->comment_date_gmt,
			'content'      => $comment->comment_content,
			'link'         => (string) get_comment_link( $comment ),
			'edit_link'    => (string) admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID ),
		);

		// Only moderators see email addresses on the Comments screen; never the IP or user agent.
		if ( current_user_can( 'moderate_comments' ) ) {
			$data['author_email'] = $comment->comment_author_email;
		}

		return $data;
	}
}
