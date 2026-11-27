<?php
/**
 * Ability for moderating and editing a comment.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Comments;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Comment - Approves, holds, spams, trashes or edits a comment.
 */
final class UpdateCommentAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-comment',
			array(
				'label'               => 'Update Comment',
				'description'         => 'Moderate a comment by changing its status ("approve" publishes it, "hold" returns it to the moderation queue, "spam" marks it as spam, "trash" moves it to the trash; all are reversible), and/or edit its text. Returns the previous status so the change can be undone.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array(
							'type'        => 'integer',
							'description' => 'The comment to change.',
						),
						'status'     => array(
							'type'        => 'string',
							'enum'        => array_keys( CommentSupport::STATUSES ),
							'description' => 'New status.',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'New comment text. Filtered the same way as comments edited in wp-admin.',
						),
					),
					'required'   => array( 'comment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'previous_status' => array( 'type' => 'string' ),
						'changed'         => array( 'type' => 'array' ),
						'comment'         => array( 'type' => 'object' ),
						'error'           => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => true,
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
		$input   = is_array( $input ) ? $input : array();
		$comment = CommentSupport::require_can_edit( $input['comment_id'] ?? 0 );

		if ( is_wp_error( $comment ) ) {
			return AbilityRegistrar::failure( $comment );
		}

		$status  = isset( $input['status'] ) ? (string) $input['status'] : '';
		$has_txt = isset( $input['content'] );

		if ( '' === $status && ! $has_txt ) {
			return AbilityRegistrar::failure( new WP_Error( 'nothing_to_update', 'Pass status and/or content.' ) );
		}

		if ( '' !== $status && ! isset( CommentSupport::STATUSES[ $status ] ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'invalid_status', sprintf( 'status must be one of: %s.', implode( ', ', array_keys( CommentSupport::STATUSES ) ) ) ) );
		}

		if ( '' !== $status && ! current_user_can( 'moderate_comments' ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'insufficient_capability', 'User lacks the "moderate_comments" capability needed to change a comment\'s status.' ) );
		}

		$previous = CommentSupport::status( $comment );
		$changed  = array();

		if ( $has_txt ) {
			$content = trim( (string) $input['content'] );

			if ( '' === $content ) {
				return AbilityRegistrar::failure( new WP_Error( 'empty_content', 'content cannot be empty.' ) );
			}

			$updated = wp_update_comment(
				array(
					'comment_ID'      => (int) $comment->comment_ID,
					'comment_content' => $content,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return AbilityRegistrar::failure( $updated );
			}

			$changed[] = 'content';
		}

		if ( '' !== $status && $status !== $previous ) {
			// Leaving the trash or spam goes through the dedicated functions, which restore the pre-trash status metadata properly.
			if ( 'trash' === $previous && 'trash' !== $status ) {
				wp_untrash_comment( (int) $comment->comment_ID );
			} elseif ( 'spam' === $previous && 'spam' !== $status ) {
				wp_unspam_comment( (int) $comment->comment_ID );
			}

			$current = CommentSupport::status( get_comment( (int) $comment->comment_ID ) );

			if ( $current !== $status ) {
				$set = wp_set_comment_status( (int) $comment->comment_ID, CommentSupport::STATUSES[ $status ], true );

				if ( is_wp_error( $set ) || ! $set ) {
					return AbilityRegistrar::failure( is_wp_error( $set ) ? $set : new WP_Error( 'status_failed', sprintf( 'Could not set comment %d to "%s".', $comment->comment_ID, $status ) ) );
				}
			}

			$changed[] = 'status';
		}

		clean_comment_cache( (int) $comment->comment_ID );

		return array(
			'success'         => true,
			'previous_status' => $previous,
			'changed'         => $changed,
			'comment'         => CommentSupport::format_comment( get_comment( (int) $comment->comment_ID ) ),
		);
	}
}
