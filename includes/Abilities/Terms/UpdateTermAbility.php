<?php
/**
 * Ability for renaming and editing a taxonomy term.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Terms;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\TermSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Term - Changes a term's name, slug, description or parent.
 */
final class UpdateTermAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-term',
			array(
				'label'               => 'Update Term',
				'description'         => 'Change a term\'s name, slug, description or parent. Only the fields you pass are changed. Changing the slug changes the term\'s archive URL, so add a redirect from the old one (redirects-create). Use polylang-set-term-language to change its language or translations.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'     => array(
							'type'        => 'integer',
							'description' => 'The term to change.',
						),
						'taxonomy'    => array(
							'type'        => 'string',
							'description' => 'Optional check that the term belongs to this taxonomy.',
						),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'New parent term ID; 0 makes it top level.',
						),
					),
					'required'   => array( 'term_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'changed'    => array( 'type' => 'array' ),
						'previous'   => array( 'type' => 'object' ),
						'term'       => TermSupport::term_schema(),
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
	 * @return array<string, mixed> The updated term.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();
		$term  = TermSupport::get_term( $input['term_id'] ?? 0, isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '' );

		if ( is_wp_error( $term ) ) {
			return AbilityRegistrar::failure( $term );
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		$can      = TermSupport::require_cap( $taxonomy, 'edit_terms' );

		if ( is_wp_error( $can ) || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return AbilityRegistrar::failure( is_wp_error( $can ) ? $can : new WP_Error( 'cannot_edit_term', sprintf( 'User cannot edit term %d.', $term->term_id ) ) );
		}

		$args     = array();
		$previous = array();

		if ( isset( $input['name'] ) ) {
			$name = trim( (string) $input['name'] );

			if ( '' === $name ) {
				return AbilityRegistrar::failure( new WP_Error( 'invalid_name', 'name cannot be empty.' ) );
			}

			$args['name']     = $name;
			$previous['name'] = $term->name;
		}

		if ( isset( $input['slug'] ) ) {
			$args['slug']     = sanitize_title( (string) $input['slug'] );
			$previous['slug'] = $term->slug;
		}

		if ( isset( $input['description'] ) ) {
			$args['description']     = wp_kses_post( (string) $input['description'] );
			$previous['description'] = $term->description;
		}

		if ( isset( $input['parent'] ) ) {
			$parent_id = max( 0, (int) $input['parent'] );

			if ( $parent_id > 0 ) {
				if ( ! $taxonomy->hierarchical ) {
					return AbilityRegistrar::failure( new WP_Error( 'not_hierarchical', sprintf( '"%s" is not hierarchical.', $taxonomy->name ) ) );
				}

				if ( $parent_id === (int) $term->term_id || in_array( (int) $term->term_id, get_ancestors( $parent_id, $taxonomy->name, 'taxonomy' ), true ) ) {
					return AbilityRegistrar::failure( new WP_Error( 'invalid_parent', 'A term cannot be its own parent or a child of one of its descendants.' ) );
				}

				$parent = TermSupport::get_term( $parent_id, $taxonomy->name );

				if ( is_wp_error( $parent ) ) {
					return AbilityRegistrar::failure( $parent );
				}
			}

			$args['parent']     = $parent_id;
			$previous['parent'] = (int) $term->parent;
		}

		if ( empty( $args ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'nothing_to_update', 'Pass at least one of name, slug, description or parent.' ) );
		}

		$updated = wp_update_term( (int) $term->term_id, $term->taxonomy, $args );

		if ( is_wp_error( $updated ) ) {
			return AbilityRegistrar::failure( $updated );
		}

		clean_term_cache( (int) $term->term_id, $term->taxonomy );
		$fresh = get_term( (int) $term->term_id );

		return array(
			'success'  => true,
			'changed'  => array_keys( $args ),
			'previous' => $previous,
			'term'     => $fresh instanceof \WP_Term ? TermSupport::format_term( $fresh ) : array(),
		);
	}
}
