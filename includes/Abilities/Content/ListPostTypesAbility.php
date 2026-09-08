<?php
/**
 * Ability for discovering the post types available for editing.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP\MCP\Abilities\Support\PolylangSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Post Types - Describes the content types this site exposes to MCP.
 *
 * This is the entry point a client should call first: it reports which post
 * types exist, what each one supports, which taxonomies apply, and whether
 * Elementor and Polylang are available for it. Without this, a client has to
 * guess at post type slugs.
 */
final class ListPostTypesAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-post-types',
			array(
				'label'               => 'List Post Types',
				'description'         => 'List every post type that can be read or edited through MCP, including its supported features, taxonomies, available statuses, and whether Elementor and Polylang are enabled for it. Call this before creating or querying content so you use valid post type slugs.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'editable_only' => array(
							'type'        => 'boolean',
							'description' => 'Only return post types the current user can create or edit. Default false.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_types' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_read' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => true,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The list of post types.
	 */
	public static function execute( $input = array() ): array {
		$editable_only = ContentSupport::to_bool( $input['editable_only'] ?? null, false );

		$elementor_types = ElementorSupport::is_active() ? ElementorSupport::supported_post_types() : array();
		$result          = array();

		foreach ( ContentSupport::allowed_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( ! $object ) {
				continue;
			}

			$can_edit   = current_user_can( $object->cap->edit_posts ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- From the post type object.
			$can_create = current_user_can( $object->cap->create_posts ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- From the post type object.

			if ( $editable_only && ! $can_edit && ! $can_create ) {
				continue;
			}

			$result[] = array(
				'slug'              => $post_type,
				'label'             => $object->labels->name ?? $post_type,
				'singular_label'    => $object->labels->singular_name ?? $post_type,
				'description'       => $object->description,
				'hierarchical'      => (bool) $object->hierarchical,
				'public'            => (bool) $object->public,
				'has_archive'       => (bool) $object->has_archive,
				'rest_base'         => $object->rest_base ? (string) $object->rest_base : $post_type,
				'supports'          => array_keys( get_all_post_type_supports( $post_type ) ),
				'taxonomies'        => self::describe_taxonomies( $post_type ),
				'statuses'          => ContentSupport::WRITABLE_STATUSES,
				'can_edit'          => $can_edit,
				'can_create'        => $can_create,
				// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- From the post type object.
				'can_publish'       => current_user_can( $object->cap->publish_posts ),
				'elementor_enabled' => in_array( $post_type, $elementor_types, true ),
				'translatable'      => PolylangSupport::is_translated_post_type( $post_type ),
			);
		}

		return array(
			'success'    => true,
			'post_types' => $result,
		);
	}

	/**
	 * Describes the taxonomies attached to a post type.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return array<int, array<string, mixed>> Taxonomy descriptors.
	 */
	private static function describe_taxonomies( string $post_type ): array {
		$result = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public && ! $taxonomy->show_in_rest ) {
				continue;
			}

			$result[] = array(
				'slug'         => $taxonomy->name,
				'label'        => $taxonomy->labels->name ?? $taxonomy->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'translatable' => PolylangSupport::is_translated_taxonomy( $taxonomy->name ),
			);
		}

		return $result;
	}
}
