<?php
/**
 * Ability for reading the raw Elementor document.
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
 * Get Elementor Data - Returns the complete `_elementor_data` tree.
 */
final class GetElementorDataAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-get-data',
			array(
				'label'               => 'Get Elementor Data',
				'description'         => 'Read the complete Elementor element tree of a page, exactly as stored. Use this when you need to build a new layout from an existing one or make structural changes; for finding and editing a single widget, elementor-get-structure is smaller and easier to work with.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => 'The ID of the Elementor page or post.',
						),
						'as_json_string' => array(
							'type'        => 'boolean',
							'description' => 'Return the tree as a JSON string instead of a nested object. Default false.',
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
						'template_type'        => array( 'type' => 'string' ),
						'page_settings'        => array( 'type' => 'object' ),
						'elements'             => array( 'type' => array( 'array', 'string' ) ),
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
	 * @return array<string, mixed> The Elementor document.
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

		$page_settings = get_post_meta( $post->ID, '_elementor_page_settings', true );

		return array(
			'success'              => true,
			'post_id'              => $post->ID,
			'built_with_elementor' => ElementorSupport::is_built_with_elementor( $post->ID ),
			'template_type'        => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
			'page_settings'        => is_array( $page_settings ) ? $page_settings : array(),
			'elements'             => ContentSupport::to_bool( $input['as_json_string'] ?? null, false )
				? (string) wp_json_encode( $elements )
				: $elements,
		);
	}
}
