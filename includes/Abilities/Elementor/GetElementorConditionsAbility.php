<?php
/**
 * Ability for reading an Elementor template's display conditions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP\MCP\Abilities\Support\PolylangSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Elementor Conditions - Reads a Theme Builder template's display conditions.
 *
 * Elementor Pro picks the header, footer or archive template for a request purely
 * from these conditions. A template with none is never rendered, which on a
 * multilingual site shows up as the source language's header appearing on every
 * translated page, or as no header at all.
 */
final class GetElementorConditionsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-get-conditions',
			array(
				'label'               => 'Get Elementor Display Conditions',
				'description'         => 'Read the Elementor Pro Theme Builder display conditions of a header, footer, archive or single template, e.g. "include/general" or "include/singular/page/12". Elementor chooses which template to render purely from these, so a template with none is never served and whichever template does match renders in its place. Use elementor-set-conditions to write them.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The Elementor template to read.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'template_type' => array( 'type' => 'string' ),
						'conditions'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'language'      => array( 'type' => 'object' ),
						'error'         => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The conditions.
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

		$result = array(
			'success'       => true,
			'post_id'       => $post->ID,
			'template_type' => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
			'conditions'    => ElementorSupport::get_conditions( $post->ID ),
		);

		if ( PolylangSupport::is_active() ) {
			$result['language'] = PolylangSupport::get_post_language( $post->ID );
		}

		return $result;
	}
}
