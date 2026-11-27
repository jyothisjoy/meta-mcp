<?php
/**
 * Ability for creating a taxonomy term.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Terms;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP\MCP\Abilities\Support\TermSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Create Term - Adds a category, tag or custom taxonomy term.
 */
final class CreateTermAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'create-term',
			array(
				'label'               => 'Create Term',
				'description'         => 'Create a term in a taxonomy (a category, tag or custom taxonomy term). On a Polylang site, set language to file it under a language, and translation_of to link it as the translation of an existing term in another language. Fails if a term with the same name already exists at that level.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'       => array(
							'type'        => 'string',
							'description' => 'Taxonomy slug, e.g. "category".',
						),
						'name'           => array(
							'type'        => 'string',
							'description' => 'The term name.',
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => 'URL slug. Default: generated from the name.',
						),
						'description'    => array(
							'type'        => 'string',
							'description' => 'Term description.',
						),
						'parent'         => array(
							'type'        => 'integer',
							'description' => 'Parent term ID, for hierarchical taxonomies such as categories.',
						),
						'language'       => array(
							'type'        => 'string',
							'description' => 'Polylang language slug for the new term. Default: the default language.',
						),
						'translation_of' => array(
							'type'        => 'integer',
							'description' => 'ID of an existing term this one translates. Requires language, which must differ from that term\'s language.',
						),
					),
					'required'   => array( 'taxonomy', 'name' ),
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
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The created term.
	 */
	public static function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = TermSupport::require_taxonomy( $input['taxonomy'] ?? '' );

		if ( is_wp_error( $taxonomy ) ) {
			return AbilityRegistrar::failure( $taxonomy );
		}

		$can = TermSupport::require_cap( $taxonomy, 'edit_terms' );

		if ( is_wp_error( $can ) ) {
			return AbilityRegistrar::failure( $can );
		}

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';

		if ( '' === $name ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_name', 'name is required.' ) );
		}

		$args = array();

		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}

		if ( isset( $input['description'] ) ) {
			$args['description'] = wp_kses_post( (string) $input['description'] );
		}

		if ( ! empty( $input['parent'] ) ) {
			if ( ! $taxonomy->hierarchical ) {
				return AbilityRegistrar::failure( new WP_Error( 'not_hierarchical', sprintf( '"%s" is not hierarchical, so a term cannot have a parent.', $taxonomy->name ) ) );
			}

			$parent = TermSupport::get_term( $input['parent'], $taxonomy->name );

			if ( is_wp_error( $parent ) ) {
				return AbilityRegistrar::failure( $parent );
			}

			$args['parent'] = (int) $parent->term_id;
		}

		$language    = isset( $input['language'] ) ? (string) $input['language'] : '';
		$translation = isset( $input['translation_of'] ) ? (int) $input['translation_of'] : 0;
		$source      = null;

		if ( ( '' !== $language || $translation > 0 ) ) {
			$active = PolylangSupport::require_active();

			if ( is_wp_error( $active ) ) {
				return AbilityRegistrar::failure( $active );
			}

			if ( ! PolylangSupport::is_translated_taxonomy( $taxonomy->name ) ) {
				return AbilityRegistrar::failure( new WP_Error( 'taxonomy_not_translated', sprintf( 'Polylang does not translate the "%s" taxonomy.', $taxonomy->name ) ) );
			}

			if ( '' !== $language ) {
				$valid = PolylangSupport::validate_language( $language );

				if ( is_wp_error( $valid ) ) {
					return AbilityRegistrar::failure( $valid );
				}
			}

			if ( $translation > 0 ) {
				if ( '' === $language ) {
					return AbilityRegistrar::failure( new WP_Error( 'missing_language', 'translation_of needs language, the language of the new term.' ) );
				}

				$source = TermSupport::get_term( $translation, $taxonomy->name );

				if ( is_wp_error( $source ) ) {
					return AbilityRegistrar::failure( $source );
				}

				$source_language = TermSupport::term_language( (int) $source->term_id );

				if ( $source_language && $source_language['slug'] === $language ) {
					return AbilityRegistrar::failure( new WP_Error( 'same_language', sprintf( 'Term %d is already in "%s".', $source->term_id, $language ) ) );
				}

				if ( PolylangSupport::get_translated_term( (int) $source->term_id, $language ) > 0 ) {
					return AbilityRegistrar::failure( new WP_Error( 'translation_exists', sprintf( 'Term %d already has a "%s" translation.', $source->term_id, $language ) ) );
				}
			}
		}

		$created = wp_insert_term( $name, $taxonomy->name, $args );

		if ( is_wp_error( $created ) ) {
			return AbilityRegistrar::failure( $created );
		}

		$term_id = (int) $created['term_id'];

		if ( '' !== $language && function_exists( 'pll_set_term_language' ) ) {
			pll_set_term_language( $term_id, $language );

			if ( $source && function_exists( 'pll_save_term_translations' ) ) {
				$group              = TermSupport::term_translations( (int) $source->term_id );
				$map                = array_map( static fn( array $t ): int => $t['id'], $group );
				$source_language    = TermSupport::term_language( (int) $source->term_id );
				$map[ $language ]   = $term_id;

				if ( $source_language ) {
					$map[ $source_language['slug'] ] = (int) $source->term_id;
				}

				pll_save_term_translations( $map );
			}
		}

		$term = get_term( $term_id );

		return array(
			'success' => true,
			'term'    => $term instanceof \WP_Term ? TermSupport::format_term( $term ) : array( 'id' => $term_id ),
		);
	}
}
