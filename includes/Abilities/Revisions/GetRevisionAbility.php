<?php
/**
 * Ability for reading one revision and comparing it with the current post.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Revisions;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Revision - Reads a revision, optionally compared with the current post.
 */
final class GetRevisionAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'get-revision',
			array(
				'label'               => 'Get Revision',
				'description'         => 'Read one revision of a post. With compare_to_current (default true) it reports, field by field, whether the title, content and excerpt differ from the current post (with previews and the first point of difference) and whether the stored Elementor layout differs. include_content returns the revision\'s full title, content and excerpt.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'revision_id'        => array(
							'type'        => 'integer',
							'description' => 'The revision ID, from list-revisions.',
						),
						'include_content'    => array(
							'type'        => 'boolean',
							'description' => 'Include the revision\'s full content and excerpt. Default false.',
						),
						'compare_to_current' => array(
							'type'        => 'boolean',
							'description' => 'Compare with the current post. Default true.',
						),
					),
					'required'   => array( 'revision_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'revision'   => array( 'type' => 'object' ),
						'comparison' => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The revision.
	 */
	public static function execute( $input = array() ): array {
		$input  = is_array( $input ) ? $input : array();
		$loaded = RevisionSupport::require_revision( $input['revision_id'] ?? 0 );

		if ( is_wp_error( $loaded ) ) {
			return AbilityRegistrar::failure( $loaded );
		}

		list( $revision, $post ) = $loaded;

		$data = RevisionSupport::format_revision( $revision );

		if ( ContentSupport::to_bool( $input['include_content'] ?? null, false ) ) {
			$data['content'] = $revision->post_content;
			$data['excerpt'] = $revision->post_excerpt;
		}

		$result = array(
			'success'  => true,
			'revision' => $data,
		);

		if ( ContentSupport::to_bool( $input['compare_to_current'] ?? null, true ) ) {
			$result['comparison'] = RevisionSupport::compare( $revision, $post );
		}

		return $result;
	}
}
