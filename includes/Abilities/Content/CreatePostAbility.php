<?php
/**
 * Ability for creating posts of any post type.
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
 * Create Post - Creates content of any registered post type.
 */
final class CreatePostAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$properties = array_merge(
			array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type slug, e.g. "post", "page" or a custom type. Call list-post-types for valid values.',
				),
			),
			PostWriter::shared_schema_properties()
		);

		AbilityRegistrar::register(
			'create-post',
			array(
				'label'               => 'Create Post',
				'description'         => 'Create a post of any registered post type, in one call setting its content, status, taxonomy terms, custom fields, featured image, page template, Polylang language and Elementor layout. Defaults to draft status; publishing requires the publish capability for that post type.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'post_type' ),
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
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The created post and a report of what was applied.
	 */
	public static function execute( $input = array() ): array {
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		if ( '' === $post_type ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_post_type', 'post_type is required.' ) );
		}

		$valid = ContentSupport::validate_post_type( $post_type );

		if ( is_wp_error( $valid ) ) {
			return AbilityRegistrar::failure( $valid );
		}

		$can_create = ContentSupport::require_post_type_cap( $post_type, 'create_posts' );

		if ( is_wp_error( $can_create ) ) {
			return AbilityRegistrar::failure( $can_create );
		}

		$postarr = PostWriter::build_postarr( $input, $post_type );

		if ( is_wp_error( $postarr ) ) {
			return AbilityRegistrar::failure( $postarr );
		}

		// Draft unless the caller asked for something else, so an unfinished
		// generation never lands on the front end by accident.
		if ( ! isset( $postarr['post_status'] ) ) {
			$postarr['post_status'] = 'draft';
		}

		// wp_insert_post expects slashed data.
		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			return AbilityRegistrar::failure( $post_id );
		}

		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'post_creation_failed', 'The post was created but could not be read back.' )
			);
		}

		$applied = PostWriter::apply_side_effects( $post, $input );

		// Re-read so the response reflects the side effects.
		$post = ContentSupport::refresh( $post );

		return array(
			'success' => true,
			'post'    => ContentSupport::format_post(
				$post,
				array(
					'include_content' => false,
					'include_terms'   => true,
				)
			),
			'applied' => $applied,
		);
	}
}
