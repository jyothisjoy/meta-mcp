<?php
/**
 * Ability for trashing, restoring and permanently deleting posts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Delete Post - Trashes, restores or permanently deletes a post.
 */
final class DeletePostAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'delete-post',
			array(
				'label'               => 'Delete Post',
				'description'         => 'Move a post to the trash, restore it from the trash, or delete it permanently. Trashing is reversible and is the default; permanent deletion requires force to be set explicitly and cannot be undone.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The ID of the post to act on.',
						),
						'action'  => array(
							'type'        => 'string',
							'enum'        => array( 'trash', 'restore' ),
							'description' => 'Whether to trash the post or restore it from the trash. Default "trash".',
						),
						'force'   => array(
							'type'        => 'boolean',
							'description' => 'Permanently delete the post instead of trashing it. This cannot be undone. Default false.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'deleted'    => array( 'type' => 'boolean' ),
						'post'       => array( 'type' => 'object' ),
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
		$post = ContentSupport::get_post( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'cannot_delete_post', sprintf( 'User cannot delete post %d.', $post->ID ) )
			);
		}

		$force  = ContentSupport::to_bool( $input['force'] ?? null, false );
		$action = isset( $input['action'] ) ? (string) $input['action'] : 'trash';

		if ( 'restore' === $action ) {
			if ( 'trash' !== $post->post_status ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'not_trashed', sprintf( 'Post %d is not in the trash.', $post->ID ) )
				);
			}

			$restored = wp_untrash_post( $post->ID );

			if ( ! $restored ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'restore_failed', sprintf( 'Could not restore post %d.', $post->ID ) )
				);
			}

			return array(
				'success' => true,
				'deleted' => false,
				'post'    => ContentSupport::format_post( ContentSupport::refresh( $post ), array( 'include_content' => false ) ),
			);
		}

		// Keep a copy of the summary before the row disappears.
		$summary = ContentSupport::format_post( $post, array( 'include_content' => false ) );

		if ( $force || ! EMPTY_TRASH_DAYS ) {
			$result = wp_delete_post( $post->ID, true );

			if ( ! $result ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'delete_failed', sprintf( 'Could not delete post %d.', $post->ID ) )
				);
			}

			return array(
				'success' => true,
				'deleted' => true,
				'post'    => $summary,
			);
		}

		$result = wp_trash_post( $post->ID );

		if ( ! $result ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'trash_failed', sprintf( 'Could not trash post %d.', $post->ID ) )
			);
		}

		return array(
			'success' => true,
			'deleted' => false,
			'post'    => ContentSupport::format_post( ContentSupport::refresh( $post ), array( 'include_content' => false ) ),
		);
	}
}
