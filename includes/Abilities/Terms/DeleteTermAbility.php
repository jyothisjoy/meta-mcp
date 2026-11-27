<?php
/**
 * Ability for deleting a taxonomy term.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Terms;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\TermSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Delete Term - Permanently deletes a term.
 */
final class DeleteTermAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'delete-term',
			array(
				'label'               => 'Delete Term',
				'description'         => 'Permanently delete a term. Posts keep existing but lose the term; child terms move up to the deleted term\'s parent. A taxonomy\'s default term (e.g. "Uncategorized") cannot be deleted. Terms have no trash, so this cannot be undone; confirm must be true. Its archive URL starts returning 404, so consider a redirect.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'  => array(
							'type'        => 'integer',
							'description' => 'The term to delete.',
						),
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'Optional check that the term belongs to this taxonomy.',
						),
						'confirm'  => array(
							'type'        => 'boolean',
							'description' => 'Must be true. Deleting a term cannot be undone.',
						),
					),
					'required'   => array( 'term_id', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'deleted'    => array( 'type' => 'boolean' ),
						'term'       => TermSupport::term_schema(),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => false,
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
		$input = is_array( $input ) ? $input : array();

		if ( ! ContentSupport::to_bool( $input['confirm'] ?? null, false ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'confirmation_required', 'Deleting a term cannot be undone. Pass confirm: true.' ) );
		}

		$term = TermSupport::get_term( $input['term_id'] ?? 0, isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '' );

		if ( is_wp_error( $term ) ) {
			return AbilityRegistrar::failure( $term );
		}

		// Checked before the capability: core denies "delete_term" on the default
		// term, which would otherwise surface as an unexplained permissions error.
		if ( TermSupport::default_term_id( $term->taxonomy ) === (int) $term->term_id ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'default_term', sprintf( 'Term %d is the default %s term and cannot be deleted. Choose another default first.', $term->term_id, $term->taxonomy ) )
			);
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		$can      = TermSupport::require_cap( $taxonomy, 'delete_terms' );

		if ( is_wp_error( $can ) || ! current_user_can( 'delete_term', $term->term_id ) ) {
			return AbilityRegistrar::failure( is_wp_error( $can ) ? $can : new WP_Error( 'cannot_delete_term', sprintf( 'User cannot delete term %d.', $term->term_id ) ) );
		}

		$summary = TermSupport::format_term( $term );
		$result  = wp_delete_term( (int) $term->term_id, $term->taxonomy );

		if ( is_wp_error( $result ) ) {
			return AbilityRegistrar::failure( $result );
		}

		if ( true !== $result ) {
			return AbilityRegistrar::failure( new WP_Error( 'delete_failed', sprintf( 'Could not delete term %d.', $term->term_id ) ) );
		}

		return array(
			'success' => true,
			'deleted' => true,
			'term'    => $summary,
		);
	}
}
