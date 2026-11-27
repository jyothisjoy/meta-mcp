<?php
/**
 * Ability for reading one taxonomy term.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Terms;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\TermSupport;
use WP_Error;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Term - Reads one term by ID, or by slug within a taxonomy.
 */
final class GetTermAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'get-term',
			array(
				'label'               => 'Get Term',
				'description'         => 'Read one taxonomy term: name, slug, description, parent, post count, link and, on a Polylang site, its language and translations. Pass term_id, or slug together with taxonomy.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'  => array(
							'type'        => 'integer',
							'description' => 'The term ID.',
						),
						'slug'     => array(
							'type'        => 'string',
							'description' => 'The term slug, instead of term_id. Requires taxonomy.',
						),
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'Taxonomy slug. Required with slug; with term_id it is checked against the term.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'term'       => TermSupport::term_schema(),
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
	 * @return array<string, mixed> The term.
	 */
	public static function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';

		if ( ! empty( $input['term_id'] ) ) {
			$term = TermSupport::get_term( $input['term_id'], $taxonomy );
		} elseif ( ! empty( $input['slug'] ) ) {
			$tax = TermSupport::require_taxonomy( $taxonomy );

			if ( is_wp_error( $tax ) ) {
				return AbilityRegistrar::failure( $tax );
			}

			$found = get_terms(
				array(
					'taxonomy'   => $tax->name,
					'slug'       => sanitize_title( (string) $input['slug'] ),
					'hide_empty' => false,
					'lang'       => '',
				)
			);

			$term = is_array( $found ) && isset( $found[0] ) && $found[0] instanceof WP_Term
				? $found[0]
				: new WP_Error( 'term_not_found', sprintf( 'No %s term with slug "%s".', $tax->name, (string) $input['slug'] ) );
		} else {
			$term = new WP_Error( 'missing_term', 'Pass term_id, or slug with taxonomy.' );
		}

		if ( is_wp_error( $term ) ) {
			return AbilityRegistrar::failure( $term );
		}

		// Same visibility gate as list-terms, applied after either lookup path.
		$can = TermSupport::require_can_view( get_taxonomy( $term->taxonomy ) );

		if ( is_wp_error( $can ) ) {
			return AbilityRegistrar::failure( $can );
		}

		return array(
			'success' => true,
			'term'    => TermSupport::format_term( $term ),
		);
	}
}
