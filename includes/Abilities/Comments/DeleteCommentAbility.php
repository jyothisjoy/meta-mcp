<?php
/**
 * Ability for trashing or permanently deleting a comment.
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
 * Delete Comment - Trashes a comment, or deletes it permanently with force.
 */
final class DeleteCommentAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'delete-comment',
			array(
				'label'               => 'Delete Comment',
				'description'         => 'Move a comment to the trash (reversible with update-comment status "approve" or "hold"), or delete it permanently with force true, which cannot be undone and also removes its replies\' link to it. confirm must be true either way. To hide spam, prefer update-comment with status "spam", which trains spam filters.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => 'The comment to delete.',
						),
						'force'      => array(
							'type'        => 'boolean',
							'description' => 'Delete permanently instead of trashing. Cannot be undone. Default false.',
						),
						'confirm'    => array(
							'type'        => 'boolean',
							'description' => 'Must be true.',
						),
					),
					'required'   => array( 'comment_id', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'deleted'    => array( 'type' => 'boolean' ),
						'trashed'    => array( 'type' => 'boolean' ),
						'comment'    => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		if ( ! ContentSupport::to_bool( $input['confirm'] ?? null, false ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'confirmation_required', 'Pass confirm: true to delete a comment.' ) );
		}

		$comment = CommentSupport::require_can_edit( $input['comment_id'] ?? 0 );

		if ( is_wp_error( $comment ) ) {
			return AbilityRegistrar::failure( $comment );
		}

		if ( ! current_user_can( 'moderate_comments' ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'insufficient_capability', 'User lacks the "moderate_comments" capability needed to delete comments.' ) );
		}

		$force   = ContentSupport::to_bool( $input['force'] ?? null, false ) || ! EMPTY_TRASH_DAYS;
		$summary = CommentSupport::format_comment( $comment );

		if ( ! $force && 'trash' === CommentSupport::status( $comment ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'already_trashed', sprintf( 'Comment %d is already in the trash. Pass force true to delete it permanently.', $comment->comment_ID ) ) );
		}

		$result = $force ? wp_delete_comment( (int) $comment->comment_ID, true ) : wp_trash_comment( (int) $comment->comment_ID );

		if ( ! $result ) {
			return AbilityRegistrar::failure( new WP_Error( 'delete_failed', sprintf( 'Could not delete comment %d.', $comment->comment_ID ) ) );
		}

		if ( ! $force ) {
			$summary = CommentSupport::format_comment( get_comment( (int) $comment->comment_ID ) );
		}

		return array(
			'success' => true,
			'deleted' => $force,
			'trashed' => ! $force,
			'comment' => $summary,
		);
	}
}
