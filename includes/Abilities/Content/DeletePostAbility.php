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

			// Read before untrashing: core deletes it once the post is restored.
			$desired_slug = (string) get_post_meta( $post->ID, '_wp_desired_post_slug', true );

			// Since WordPress 5.6 a restored post comes back as a draft; restore the
			// status it had when it was trashed, as the Trash screen's Restore does not.
			add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
			$restored = wp_untrash_post( $post->ID );
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );

			if ( ! $restored ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'restore_failed', sprintf( 'Could not restore post %d.', $post->ID ) )
				);
			}

			self::repair_trashed_slug( $post->ID, $desired_slug );

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

	/**
	 * Takes the "__trashed" suffix back off a restored post's slug.
	 *
	 * Core restores the slug from `_wp_desired_post_slug` only when that slug is
	 * non-empty, so a draft that never had a slug came back as "__trashed" and
	 * would have been published under that URL.
	 *
	 * @param int    $post_id      The restored post.
	 * @param string $desired_slug The slug saved when it was trashed.
	 *
	 * @return void
	 */
	private static function repair_trashed_slug( int $post_id, string $desired_slug ): void {
		$restored = get_post( $post_id );

		if ( ! $restored instanceof \WP_Post || ! preg_match( '/__trashed(-\d+)?$/', $restored->post_name ) ) {
			return;
		}

		$slug = $desired_slug;

		// A draft may legitimately have no slug; anything else needs one.
		if ( '' === $slug && ! in_array( $restored->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			$slug = sanitize_title( $restored->post_title );
		}

		if ( '' !== $slug ) {
			$slug = wp_unique_post_slug( $slug, $restored->ID, $restored->post_status, $restored->post_type, (int) $restored->post_parent );
		}

		global $wpdb;

		// A direct update: wp_update_post() would regenerate the slug from the title for a draft.
		$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => $restored->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $restored->ID );
	}
}
