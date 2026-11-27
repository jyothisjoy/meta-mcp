<?php
/**
 * Ability for replying to a comment as the current user.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Comments;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reply Comment - Posts a reply to a comment under the current user's name.
 */
final class ReplyCommentAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'reply-comment',
			array(
				'label'               => 'Reply to Comment',
				'description'         => 'Post a reply to a comment, published under the name of the WordPress user the MCP connection authenticates as (like replying from the Comments screen). The reply is public as soon as it is approved, which is the default; set approve false to hold it for review. Replying also approves a held parent comment when approve is true, as wp-admin does.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => 'The comment to reply to.',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Reply text.',
						),
						'approve'    => array(
							'type'        => 'boolean',
							'description' => 'Publish the reply straight away. Default true.',
						),
					),
					'required'   => array( 'comment_id', 'content' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'reply'           => array( 'type' => 'object' ),
						'parent_approved' => array( 'type' => 'boolean' ),
						'error'           => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The reply.
	 */
	public static function execute( $input = array() ): array {
		$input  = is_array( $input ) ? $input : array();
		$parent = CommentSupport::require_can_edit( $input['comment_id'] ?? 0 );

		if ( is_wp_error( $parent ) ) {
			return AbilityRegistrar::failure( $parent );
		}

		if ( ! current_user_can( 'moderate_comments' ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'insufficient_capability', 'User lacks the "moderate_comments" capability needed to reply from the dashboard.' ) );
		}

		$content = isset( $input['content'] ) ? trim( (string) $input['content'] ) : '';

		if ( '' === $content ) {
			return AbilityRegistrar::failure( new WP_Error( 'empty_content', 'content is required.' ) );
		}

		if ( in_array( CommentSupport::status( $parent ), array( 'spam', 'trash' ), true ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'parent_not_visible', sprintf( 'Comment %d is in %s; restore it before replying.', $parent->comment_ID, CommentSupport::status( $parent ) ) ) );
		}

		$user    = wp_get_current_user();
		$approve = ContentSupport::to_bool( $input['approve'] ?? null, true );

		$reply_id = wp_insert_comment(
			wp_filter_comment(
				array(
					'comment_post_ID'      => (int) $parent->comment_post_ID,
					'comment_parent'       => (int) $parent->comment_ID,
					'comment_content'      => $content,
					'comment_type'         => 'comment',
					'user_id'              => (int) $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_author_IP'    => '',
					'comment_agent'        => 'MCP',
				)
			) + array( 'comment_approved' => $approve ? 1 : 0 )
		);

		if ( ! $reply_id ) {
			return AbilityRegistrar::failure( new WP_Error( 'reply_failed', 'Could not save the reply.' ) );
		}

		$parent_approved = false;

		if ( $approve && 'hold' === CommentSupport::status( $parent ) ) {
			$parent_approved = (bool) wp_set_comment_status( (int) $parent->comment_ID, 'approve' );
		}

		return array(
			'success'         => true,
			'reply'           => CommentSupport::format_comment( get_comment( (int) $reply_id ) ),
			'parent_approved' => $parent_approved,
		);
	}
}
