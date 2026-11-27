<?php
/**
 * Ability for listing comments.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Comments;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP_Comment;
use WP_Comment_Query;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Comments - Lists comments by status, post or text, for moderation.
 */
final class ListCommentsAbility {

	/**
	 * Largest page size accepted.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-comments',
			array(
				'label'               => 'List Comments',
				'description'         => 'List comments for moderation, newest first: author name, date, status, content and the post they are on. Filter by status ("hold" is the moderation queue; also "approve", "spam", "trash" or "all"), post, text or type. Commenter IP addresses and browser details are never returned; email addresses only to users who can moderate. Counts per status are included.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
							'description' => 'Which comments to list. Default "all" (approved and held, as on the Comments screen).',
						),
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'Only comments on this post.',
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Only comments whose text, author name or URL contains this.',
						),
						'type'     => array(
							'type'        => 'string',
							'description' => 'Comment type, e.g. "comment", "pingback", "trackback" or "review". Default: every type.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Comments per page. Default 20, maximum 100.',
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'comments'    => array( 'type' => 'array' ),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'counts'      => array( 'type' => 'object' ),
						'error'       => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( self::class, 'permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => true,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Only users who can moderate (or edit posts, to see comments on their own) may list.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function permission() {
		$base = AbilityRegistrar::allow_read();

		if ( true !== $base ) {
			return $base;
		}

		if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'insufficient_capability', 'User lacks the "moderate_comments" or "edit_posts" capability needed to list comments.' );
		}

		return true;
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The comments.
	 */
	public static function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'all';
		$per_page = isset( $input['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		if ( ! in_array( $status, array( 'approve', 'hold', 'spam', 'trash', 'all' ), true ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'invalid_status', 'status must be approve, hold, spam, trash or all.' ) );
		}

		if ( ! current_user_can( 'moderate_comments' ) && in_array( $status, array( 'spam', 'trash' ), true ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'insufficient_capability', 'Only users who can moderate comments can list spam or trashed comments.' ) );
		}

		$args = array(
			'status'  => $status,
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);

		if ( ! empty( $input['post_id'] ) ) {
			$post = ContentSupport::require_can_read( $input['post_id'] );

			if ( is_wp_error( $post ) ) {
				return AbilityRegistrar::failure( $post );
			}

			$args['post_id'] = $post->ID;
		}

		if ( isset( $input['search'] ) && '' !== (string) $input['search'] ) {
			$args['search'] = (string) $input['search'];
		}

		if ( ! empty( $input['type'] ) ) {
			$args['type'] = 'comment' === $input['type'] ? 'comment' : sanitize_key( (string) $input['type'] );
		}

		$moderator = current_user_can( 'moderate_comments' );

		// Non-moderators see comments on their own posts only; narrowing the query
		// (rather than filtering afterwards) keeps total and paging right.
		if ( ! $moderator ) {
			$args['post_author'] = get_current_user_id();
		}

		$total    = (int) ( new WP_Comment_Query() )->query( array_merge( $args, array( 'count' => true, 'number' => 0, 'offset' => 0 ) ) );
		$query    = new WP_Comment_Query( $args );
		$comments = array();

		foreach ( (array) $query->comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			// Non-moderators only see comments on posts they can edit.
			if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
				continue;
			}

			$comments[] = CommentSupport::format_comment( $comment );
		}

		// Site-wide counts are moderator information.
		$counts = $moderator || isset( $args['post_id'] ) ? wp_count_comments( isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 ) : (object) array( 'approved' => 0, 'moderated' => 0, 'spam' => 0, 'trash' => 0, 'total_comments' => 0 );

		return array(
			'success'     => true,
			'comments'    => $comments,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'counts'      => array(
				'approve' => (int) $counts->approved,
				'hold'    => (int) $counts->moderated,
				'spam'    => (int) $counts->spam,
				'trash'   => (int) $counts->trash,
			),
		);
	}
}
