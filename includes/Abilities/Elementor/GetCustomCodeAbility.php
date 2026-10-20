<?php
/**
 * Ability for reading one Elementor Pro Custom Code snippet.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\CustomCodeSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Custom Code - Reads one Elementor → Custom Code snippet, including its code.
 */
final class GetCustomCodeAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-get-custom-code',
			array(
				'label'               => 'Get Elementor Custom Code',
				'description'         => 'Read one Elementor → Custom Code snippet: its code, location, priority, "always load jQuery" option, status and display conditions.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The snippet ID.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippet'    => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The snippet.
	 */
	public static function execute( $input = array() ): array {
		$available = CustomCodeSupport::require_available();

		if ( is_wp_error( $available ) ) {
			return AbilityRegistrar::failure( $available );
		}

		$post = CustomCodeSupport::get( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		return array(
			'success' => true,
			'snippet' => CustomCodeSupport::format( $post, true ),
		);
	}
}
