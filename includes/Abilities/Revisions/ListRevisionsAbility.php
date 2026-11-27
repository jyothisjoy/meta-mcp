<?php
/**
 * Ability for listing the revisions of a post.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Revisions;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Revisions - Lists the saved revisions of a post, newest first.
 */
final class ListRevisionsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-revisions',
			array(
				'label'               => 'List Revisions',
				'description'         => 'List the saved revisions of a post or page, newest first: revision id, date, author, title, content length and whether it carries an Elementor layout. Use it to find the version to go back to after an unwanted edit, then get-revision to compare and restore-revision to restore. Only works for post types with revisions enabled.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'The post whose revisions to list.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Number of revisions to return. Default 20, maximum 100.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'           => array( 'type' => 'boolean' ),
						'post_id'           => array( 'type' => 'integer' ),
						'revisions_enabled' => array( 'type' => 'boolean' ),
						'revisions'         => array( 'type' => 'array' ),
						'total'             => array( 'type' => 'integer' ),
						'error'             => array( 'type' => 'string' ),
						'error_code'        => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The revisions.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();
		$post  = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		if ( 'revision' === $post->post_type ) {
			return AbilityRegistrar::failure( new WP_Error( 'is_revision', sprintf( '%d is itself a revision; pass its parent post %d.', $post->ID, $post->post_parent ) ) );
		}

		$per_page  = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;
		// Only the requested page is loaded, without its meta: Elementor copies the
		// whole layout into every revision, and loading 100 of those exhausted memory.
		$all_ids   = wp_get_post_revisions( $post->ID, array( 'fields' => 'ids' ) );
		$page      = wp_get_post_revisions(
			$post->ID,
			array(
				'order'                  => 'DESC',
				'orderby'                => 'date ID',
				'posts_per_page'         => $per_page,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$elementor = RevisionSupport::ids_with_elementor( array_map( static fn( $r ): int => (int) $r->ID, array_values( $page ) ) );
		$revisions = array();

		foreach ( array_values( $page ) as $revision ) {
			$revisions[] = RevisionSupport::format_revision( $revision, in_array( (int) $revision->ID, $elementor, true ) );
		}

		return array(
			'success'           => true,
			'post_id'           => $post->ID,
			'revisions_enabled' => wp_revisions_enabled( $post ),
			'revisions'         => $revisions,
			'total'             => count( $all_ids ),
		);
	}
}
