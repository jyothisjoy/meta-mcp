<?php
/**
 * Ability for assigning taxonomy terms to a post.
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
 * Set Post Terms - Assigns categories, tags and custom taxonomy terms.
 */
final class SetPostTermsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'set-post-terms',
			array(
				'label'               => 'Set Post Terms',
				'description'         => 'Assign categories, tags or custom taxonomy terms to a post. Terms can be given as IDs, slugs or names. By default the given terms replace the existing ones in each taxonomy you list; taxonomies you do not list are untouched.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'              => array(
							'type'        => 'integer',
							'description' => 'The ID of the post to update.',
						),
						'terms'                => array(
							'type'        => 'object',
							'description' => 'Object of taxonomy slug to an array of term IDs, slugs or names, e.g. {"category": ["news"], "post_tag": [12, "launch"]}.',
						),
						'append'               => array(
							'type'        => 'boolean',
							'description' => 'Add to the existing terms instead of replacing them. Default false.',
						),
						'create_missing_terms' => array(
							'type'        => 'boolean',
							'description' => 'Create terms that do not exist yet. Requires the capability to manage that taxonomy. Default false.',
						),
					),
					'required'   => array( 'post_id', 'terms' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'result'     => array( 'type' => 'object' ),
						'terms'      => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The outcome and the resulting term assignments.
	 */
	public static function execute( $input = array() ): array {
		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		if ( empty( $input['terms'] ) || ! is_array( $input['terms'] ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'missing_terms', 'terms must be an object of taxonomy slug to an array of terms.' )
			);
		}

		$result = PostWriter::apply_terms(
			$post,
			$input['terms'],
			ContentSupport::to_bool( $input['create_missing_terms'] ?? null, false ),
			ContentSupport::to_bool( $input['append'] ?? null, false )
		);

		return array(
			'success' => true,
			'result'  => $result,
			'terms'   => ContentSupport::get_post_terms( ContentSupport::refresh( $post ) ),
		);
	}
}
