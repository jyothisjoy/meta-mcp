<?php
/**
 * Ability for editing a single element inside an Elementor document.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Elementor Element - Patches one element by id.
 *
 * The safe way to change an Elementor page: the rest of the layout is read,
 * modified in memory and written back untouched, so a bad edit can only affect
 * the one element it names.
 */
final class UpdateElementorElementAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-update-element',
			array(
				'label'               => 'Update Elementor Element',
				'description'         => 'Change the settings of a single element inside an Elementor page, located by its element id, or delete that element. Settings are merged into the existing ones by default, so you only pass what changes: for a heading widget that is usually {"title": "New text"}, for a text editor {"editor": "<p>New copy</p>"}, for a button {"text": "Buy now", "link": {"url": "https://example.com"}}. Call elementor-get-structure first to find the element id and its current settings. Everything else on the page is left exactly as it was.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => 'The ID of the Elementor page or post.',
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => 'The id of the element to change, as returned by elementor-get-structure.',
						),
						'settings'   => array(
							'type'        => 'object',
							'description' => 'Settings to apply. Required unless operation is "delete".',
						),
						'operation'  => array(
							'type'        => 'string',
							'enum'        => array( 'merge', 'replace', 'delete' ),
							'description' => 'How to apply the settings: "merge" keeps existing settings not mentioned (default), "replace" discards them, "delete" removes the element and its children entirely.',
						),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'operation'  => array( 'type' => 'string' ),
						'element'    => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The outcome.
	 */
	public static function execute( $input = array() ): array {
		$active = ElementorSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$element_id = isset( $input['element_id'] ) ? trim( (string) $input['element_id'] ) : '';

		if ( '' === $element_id ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_element_id', 'element_id is required.' ) );
		}

		$operation = isset( $input['operation'] ) ? (string) $input['operation'] : 'merge';

		if ( ! in_array( $operation, array( 'merge', 'replace', 'delete' ), true ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'invalid_operation', 'operation must be one of: merge, replace, delete.' )
			);
		}

		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( 'delete' !== $operation && empty( $settings ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'missing_settings', 'settings is required unless operation is "delete".' )
			);
		}

		$elements = ElementorSupport::get_elements( $post->ID );

		if ( is_wp_error( $elements ) ) {
			return AbilityRegistrar::failure( $elements );
		}

		if ( empty( $elements ) ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'no_elementor_data',
					sprintf( 'Post %d has no Elementor layout to edit.', $post->ID )
				)
			);
		}

		$applied = ElementorSupport::apply_to_element( $elements, $element_id, $settings, $operation );

		if ( ! $applied['found'] ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'element_not_found',
					sprintf( 'No element with id "%s" on post %d. Call elementor-get-structure to list the available ids.', $element_id, $post->ID )
				)
			);
		}

		$saved = ElementorSupport::save_elements( $post->ID, $applied['tree'] );

		if ( is_wp_error( $saved ) ) {
			return AbilityRegistrar::failure( $saved );
		}

		$response = array(
			'success'    => true,
			'post_id'    => $post->ID,
			'element_id' => $element_id,
			'operation'  => $operation,
		);

		if ( 'delete' !== $operation ) {
			$updated = ElementorSupport::flatten( $applied['tree'], array( 'include_settings' => true ) );

			foreach ( $updated as $node ) {
				if ( $node['id'] === $element_id ) {
					$response['element'] = $node;
					break;
				}
			}
		}

		return $response;
	}
}
