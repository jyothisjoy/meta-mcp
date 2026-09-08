<?php
/**
 * Ability for reading an Elementor layout as an addressable list.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Elementor Structure - Flattens an Elementor document into editable nodes.
 *
 * The raw `_elementor_data` tree is deeply nested and mostly styling. This
 * returns one row per element with its id, type and a text preview, which is
 * what a client needs to decide which element to change and to call
 * elementor-update-element with the right id.
 */
final class GetElementorStructureAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-get-structure',
			array(
				'label'               => 'Get Elementor Structure',
				'description'         => 'Read the Elementor layout of a page as a flat list of elements, each with its element id, type, nesting depth and a preview of its text. This is the recommended way to inspect an Elementor page before editing: find the element you want in this list, then pass its id to elementor-update-element.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => 'The ID of the Elementor page or post.',
						),
						'include_settings' => array(
							'type'        => 'boolean',
							'description' => 'Include the full settings object of every element. Verbose; leave off unless you need to inspect styling. Default false.',
						),
						'max_depth'        => array(
							'type'        => 'integer',
							'description' => 'Stop descending after this many levels. 0 means unlimited. Default 0.',
						),
						'search'           => array(
							'type'        => 'string',
							'description' => 'Only return elements whose text preview or type contains this string, case-insensitively.',
						),
						'widgets_only'     => array(
							'type'        => 'boolean',
							'description' => 'Only return widgets, skipping sections, columns and containers. Default false.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'              => array( 'type' => 'boolean' ),
						'post_id'              => array( 'type' => 'integer' ),
						'built_with_elementor' => array( 'type' => 'boolean' ),
						'element_count'        => array( 'type' => 'integer' ),
						'elements'             => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'error'                => array( 'type' => 'string' ),
						'error_code'           => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The flattened structure.
	 */
	public static function execute( $input = array() ): array {
		$active = ElementorSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$post = ContentSupport::require_can_read( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$elements = ElementorSupport::get_elements( $post->ID );

		if ( is_wp_error( $elements ) ) {
			return AbilityRegistrar::failure( $elements );
		}

		$flat = ElementorSupport::flatten(
			$elements,
			array(
				'include_settings' => ContentSupport::to_bool( $input['include_settings'] ?? null, false ),
				'max_depth'        => isset( $input['max_depth'] ) ? max( 0, (int) $input['max_depth'] ) : 0,
				'search'           => isset( $input['search'] ) ? (string) $input['search'] : '',
			)
		);

		if ( ContentSupport::to_bool( $input['widgets_only'] ?? null, false ) ) {
			$flat = array_values(
				array_filter( $flat, static fn( array $node ): bool => 'widget' === $node['el_type'] )
			);
		}

		return array(
			'success'              => true,
			'post_id'              => $post->ID,
			'built_with_elementor' => ElementorSupport::is_built_with_elementor( $post->ID ),
			'element_count'        => count( $flat ),
			'elements'             => $flat,
		);
	}
}
