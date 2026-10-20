<?php
/**
 * Ability for editing media library details such as alt text.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Media - Sets alt text, title, caption and description on existing attachments.
 *
 * Alt text lives in the protected `_wp_attachment_image_alt` meta, which the
 * generic meta ability refuses, so this is the way to fix it after upload.
 */
final class UpdateMediaAbility {

	/**
	 * Largest batch accepted in one call.
	 */
	private const MAX_ITEMS = 100;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-media',
			array(
				'label'               => 'Update Media',
				'description'         => 'Set the alt text, title, caption or description of up to 100 existing media library items in one call. Identify each by attachment_id, or by url (the image\'s file URL, as found in an Elementor image widget or post content; resized variants such as -1024x683.jpg are resolved to the original). Only the fields you pass change; an empty string clears a field. Elementor image widgets and WordPress image blocks use the attachment\'s alt text unless they set their own.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'        => 'array',
							'description' => 'One entry per attachment.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'attachment_id' => array(
										'type'        => 'integer',
										'description' => 'The attachment ID.',
									),
									'url'           => array(
										'type'        => 'string',
										'description' => 'The file URL, instead of attachment_id.',
									),
									'alt_text'      => array(
										'type'        => 'string',
										'description' => 'Alternative text describing the image.',
									),
									'title'         => array(
										'type'        => 'string',
										'description' => 'Attachment title.',
									),
									'caption'       => array(
										'type'        => 'string',
										'description' => 'Caption.',
									),
									'description'   => array(
										'type'        => 'string',
										'description' => 'Long description.',
									),
								),
							),
						),
					),
					'required'   => array( 'items' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'results'    => array( 'type' => 'array' ),
						'updated'    => array( 'type' => 'integer' ),
						'failed'     => array( 'type' => 'integer' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The per-item outcome.
	 */
	public static function execute( $input = array() ): array {
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? array_values( $input['items'] ) : array();

		if ( empty( $items ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_items', 'items must list at least one attachment.' ) );
		}

		if ( count( $items ) > self::MAX_ITEMS ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'too_many_items', sprintf( 'At most %d items can be changed in one call; split the batch.', self::MAX_ITEMS ) )
			);
		}

		$results = array();
		$updated = 0;
		$failed  = 0;

		foreach ( $items as $item ) {
			$item   = is_array( $item ) ? $item : array();
			$result = self::update_one( $item );

			if ( is_wp_error( $result ) ) {
				++$failed;
				$results[] = array(
					'success'       => false,
					'attachment_id' => (int) ( $item['attachment_id'] ?? 0 ),
					'url'           => (string) ( $item['url'] ?? '' ),
					'error'         => $result->get_error_message(),
				);
				continue;
			}

			++$updated;
			$results[] = $result;
		}

		return array(
			'success' => true,
			'results' => $results,
			'updated' => $updated,
			'failed'  => $failed,
		);
	}

	/**
	 * Updates one attachment.
	 *
	 * @param array<string, mixed> $item The entry.
	 *
	 * @return array<string, mixed>|\WP_Error The attachment after the write.
	 */
	private static function update_one( array $item ) {
		$attachment = self::resolve( $item );

		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$fields = array_intersect_key( $item, array_flip( array( 'alt_text', 'title', 'caption', 'description' ) ) );

		if ( empty( $fields ) ) {
			return new WP_Error( 'nothing_to_change', 'Pass at least one of alt_text, title, caption, description.' );
		}

		$postarr = array( 'ID' => $attachment->ID );

		if ( array_key_exists( 'title', $fields ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $fields['title'] );
		}

		if ( array_key_exists( 'caption', $fields ) ) {
			$postarr['post_excerpt'] = wp_kses_post( (string) $fields['caption'] );
		}

		if ( array_key_exists( 'description', $fields ) ) {
			$postarr['post_content'] = wp_kses_post( (string) $fields['description'] );
		}

		if ( count( $postarr ) > 1 ) {
			$saved = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		if ( array_key_exists( 'alt_text', $fields ) ) {
			$alt = trim( wp_strip_all_tags( (string) $fields['alt_text'] ) );

			if ( '' === $alt ) {
				delete_post_meta( $attachment->ID, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $attachment->ID, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}
		}

		clean_post_cache( $attachment->ID );

		$fresh = get_post( $attachment->ID );
		$fresh = $fresh instanceof WP_Post ? $fresh : $attachment;

		return array(
			'success'       => true,
			'attachment_id' => $fresh->ID,
			'url'           => (string) wp_get_attachment_url( $fresh->ID ),
			'alt_text'      => (string) get_post_meta( $fresh->ID, '_wp_attachment_image_alt', true ),
			'title'         => $fresh->post_title,
			'caption'       => $fresh->post_excerpt,
			'changed'       => array_keys( $fields ),
		);
	}

	/**
	 * Finds the attachment an entry refers to and checks it may be edited.
	 *
	 * @param array<string, mixed> $item The entry.
	 *
	 * @return \WP_Post|\WP_Error The attachment.
	 */
	private static function resolve( array $item ) {
		$id = (int) ( $item['attachment_id'] ?? 0 );

		if ( $id <= 0 && ! empty( $item['url'] ) ) {
			$url = (string) $item['url'];

			// Resized variants (photo-1024x683.jpg) are not stored as their own
			// attachment, so also try the original, and the "-scaled" copy
			// WordPress keeps of large uploads.
			$original   = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url );
			$candidates = array_unique(
				array(
					$url,
					$original,
					(string) preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $original ),
				)
			);

			foreach ( $candidates as $candidate ) {
				$id = (int) attachment_url_to_postid( $candidate );

				if ( $id > 0 ) {
					break;
				}
			}

			if ( $id <= 0 ) {
				return new WP_Error( 'attachment_not_found', sprintf( 'No media library item found for URL "%s".', $url ) );
			}
		}

		if ( $id <= 0 ) {
			return new WP_Error( 'missing_attachment', 'Each item needs attachment_id or url.' );
		}

		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'attachment_not_found', sprintf( 'Post %d is not a media library item.', $id ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'cannot_edit_post', sprintf( 'User cannot edit media item %d.', $post->ID ) );
		}

		return $post;
	}
}
