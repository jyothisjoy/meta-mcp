<?php
/**
 * Ability for reading and writing post custom fields.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\MetaSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Post Meta - Reads and writes the custom fields of a post.
 */
final class SetPostMetaAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'set-post-meta',
			array(
				'label'               => 'Set Post Meta',
				'description'         => 'Read and write the custom fields of a post. Pass meta as key/value pairs to write them, with a null value to delete a key. Protected keys starting with an underscore are rejected unless the site allowlists them; Elementor keys are managed by the elementor-* abilities instead.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The ID of the post.',
						),
						'meta'    => array(
							'type'        => 'object',
							'description' => 'Custom fields to write, as key/value pairs. A null value deletes the key. Omit to read the current values without changing anything.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'result'     => array( 'type' => 'object' ),
						'meta'       => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The write report and the resulting meta.
	 */
	public static function execute( $input = array() ): array {
		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$result = array(
			'updated' => array(),
			'deleted' => array(),
			'errors'  => array(),
		);

		if ( isset( $input['meta'] ) ) {
			if ( ! is_array( $input['meta'] ) ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'invalid_meta', 'meta must be an object of key/value pairs.' )
				);
			}

			$result = MetaSupport::write_meta( $post->ID, $input['meta'], $post->post_type );
		}

		return array(
			'success' => true,
			'result'  => $result,
			'meta'    => MetaSupport::read_meta( $post->ID ),
		);
	}
}
