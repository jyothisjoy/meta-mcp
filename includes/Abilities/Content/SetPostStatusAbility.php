<?php
/**
 * Ability for publishing, scheduling and unpublishing a post.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\PostWriter;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Post Status - Moves a post between draft, pending, published, scheduled and private.
 *
 * Publishing is separated from update-post so a client can review a draft and
 * then take the single, explicit step that puts it on the front end.
 */
final class SetPostStatusAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'set-post-status',
			array(
				'label'               => 'Set Post Status',
				'description'         => 'Publish, schedule, unpublish or privatise a post. Pass status "publish" to publish immediately, or "future" with a date to schedule it. Requires the publish capability for the post type when moving to publish, future or private.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The ID of the post to change.',
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => ContentSupport::WRITABLE_STATUSES,
							'description' => 'The target status.',
						),
						'date'    => array(
							'type'        => 'string',
							'description' => 'Publish date as YYYY-MM-DD HH:MM:SS in site time. Required when status is "future"; ignored otherwise unless you want to backdate a publish.',
						),
					),
					'required'   => array( 'post_id', 'status' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'post'            => array( 'type' => 'object' ),
						'previous_status' => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The updated post.
	 */
	public static function execute( $input = array() ): array {
		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$status = isset( $input['status'] ) ? (string) $input['status'] : '';
		$allowed = ContentSupport::require_can_set_status( $status, $post->post_type, $post->ID );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$previous = $post->post_status;
		$postarr  = array(
			'ID'          => $post->ID,
			'post_status' => $status,
		);

		if ( ! empty( $input['date'] ) ) {
			$date = PostWriter::parse_date( (string) $input['date'] );

			if ( is_wp_error( $date ) ) {
				return AbilityRegistrar::failure( $date );
			}

			if ( 'future' === $status && $date['timestamp'] <= time() ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'date_not_future', 'Status "future" requires a date that is still in the future.' )
				);
			}

			$postarr['post_date']     = $date['local'];
			$postarr['post_date_gmt'] = $date['gmt'];
			$postarr['edit_date']     = true;
		} elseif ( 'future' === $status ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'missing_date', 'Scheduling with status "future" requires a date in the future.' )
			);
		}

		// A post moving out of draft for the first time has no publish date yet.
		if ( 'publish' === $status && empty( $input['date'] ) && in_array( $post->post_date_gmt, array( '0000-00-00 00:00:00', '' ), true ) ) {
			$postarr['post_date']     = current_time( 'mysql' );
			$postarr['post_date_gmt'] = current_time( 'mysql', 1 );
		}

		$updated = wp_update_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $updated ) ) {
			return AbilityRegistrar::failure( $updated );
		}

		$fresh = ContentSupport::refresh( $post );

		return array(
			'success'         => true,
			'previous_status' => $previous,
			'post'            => ContentSupport::format_post( $fresh, array( 'include_content' => false ) ),
		);
	}
}
