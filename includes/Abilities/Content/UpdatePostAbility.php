<?php
/**
 * Ability for updating an existing post.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\PostWriter;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Post - Edits an existing post of any post type.
 */
final class UpdatePostAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$properties = array_merge(
			array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The ID of the post to update.',
				),
			),
			PostWriter::shared_schema_properties()
		);

		AbilityRegistrar::register(
			'update-post',
			array(
				'label'               => 'Update Post',
				'description'         => 'Edit an existing post of any post type. Only the fields you pass are changed; everything else is left alone. Can update content, status, taxonomy terms, custom fields, featured image, page template, Polylang language and Elementor layout in one call.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'post'       => array( 'type' => 'object' ),
						'applied'    => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The updated post and a report of what was applied.
	 */
	public static function execute( $input = array() ): array {
		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$postarr = PostWriter::build_postarr( $input, $post->post_type, $post );

		if ( is_wp_error( $postarr ) ) {
			return AbilityRegistrar::failure( $postarr );
		}

		// Only touch the posts table when a column actually changed; ID and
		// post_type are always present in the array.
		if ( count( $postarr ) > 2 ) {
			// wp_update_post expects slashed data.
			$updated = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $updated ) ) {
				return AbilityRegistrar::failure( $updated );
			}
		}

		$applied = PostWriter::apply_side_effects( $post, $input );

		$fresh = ContentSupport::refresh( $post );

		return array(
			'success' => true,
			'post'    => ContentSupport::format_post(
				$fresh,
				array(
					'include_content' => false,
					'include_terms'   => true,
				)
			),
			'applied' => $applied,
		);
	}
}
