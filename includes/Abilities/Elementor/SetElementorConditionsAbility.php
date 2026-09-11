<?php
/**
 * Ability for writing an Elementor template's display conditions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Elementor Conditions - Writes a Theme Builder template's display conditions.
 *
 * The common multilingual repair is one call: point `copy_from_post_id` at the
 * source-language template and the conditions are copied onto the translation,
 * with any post or term ids they name rewritten to that language.
 */
final class SetElementorConditionsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-set-conditions',
			array(
				'label'               => 'Set Elementor Display Conditions',
				'description'         => 'Write the Elementor Pro Theme Builder display conditions of a header, footer, archive or single template, e.g. ["include/general"] or ["include/singular/hotel_room"]. Pass copy_from_post_id instead of conditions to copy them from another template, with any post or term ids they name remapped to the target language - the usual fix for a translated header that never renders because it has no conditions of its own. Passing an empty conditions array clears them, which stops the template rendering anywhere.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'            => array(
							'type'        => 'integer',
							'description' => 'The Elementor template to write.',
						),
						'conditions'         => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Condition strings, each "include/..." or "exclude/...". Replaces whatever the template had. An empty array clears them.',
						),
						'copy_from_post_id'  => array(
							'type'        => 'integer',
							'description' => 'Copy the conditions from this template instead of passing them explicitly.',
						),
						'remap_ids'          => array(
							'type'        => 'boolean',
							'description' => 'When copying, rewrite post and term ids in the conditions to the language of the target template. Default true. Ids with no translation are left as they are and reported in untranslated_ids.',
						),
						'overwrite_existing' => array(
							'type'        => 'boolean',
							'description' => 'Write even when the template already has conditions. Default true. Set false to leave a deliberately narrowed template alone.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'post_id'          => array( 'type' => 'integer' ),
						'conditions'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'previous'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'written'          => array( 'type' => 'boolean' ),
						'untranslated_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'error'            => array( 'type' => 'string' ),
						'error_code'       => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The write outcome.
	 */
	public static function execute( $input = array() ): array {
		$active = ElementorSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$has_conditions = isset( $input['conditions'] ) && is_array( $input['conditions'] );
		$copy_from      = isset( $input['copy_from_post_id'] ) ? (int) $input['copy_from_post_id'] : 0;

		if ( ! $has_conditions && $copy_from <= 0 ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'missing_conditions', 'Pass either conditions or copy_from_post_id.' )
			);
		}

		$previous     = ElementorSupport::get_conditions( $post->ID );
		$untranslated = array();
		$remap        = ContentSupport::to_bool( $input['remap_ids'] ?? null, true );
		$overwrite    = ContentSupport::to_bool( $input['overwrite_existing'] ?? null, true );

		if ( ! $overwrite && ! empty( $previous ) ) {
			return array(
				'success'          => true,
				'post_id'          => $post->ID,
				'conditions'       => $previous,
				'previous'         => $previous,
				'written'          => false,
				'untranslated_ids' => array(),
			);
		}

		if ( $has_conditions ) {
			$conditions = array_map( 'strval', $input['conditions'] );
		} else {
			$source = ContentSupport::require_can_read( $copy_from );

			if ( is_wp_error( $source ) ) {
				return AbilityRegistrar::failure( $source );
			}

			$conditions = ElementorSupport::get_conditions( $source->ID );

			if ( empty( $conditions ) ) {
				return AbilityRegistrar::failure(
					new WP_Error(
						'source_has_no_conditions',
						sprintf( 'Template %d has no display conditions to copy.', $source->ID )
					)
				);
			}

			if ( $remap ) {
				$language = PolylangSupport::is_active() ? PolylangSupport::get_post_language( $post->ID ) : null;

				if ( ! $language ) {
					return AbilityRegistrar::failure(
						new WP_Error(
							'target_has_no_language',
							sprintf( 'Template %d has no language assigned, so ids in the copied conditions cannot be remapped. Assign one with polylang-set-post-language, or pass remap_ids false.', $post->ID )
						)
					);
				}

				$before     = $conditions;
				$conditions = ElementorSupport::remap_conditions( $conditions, (string) $language['slug'] );

				foreach ( $before as $index => $condition ) {
					$separator = strrchr( $condition, '/' );
					$tail      = false === $separator ? '' : substr( $separator, 1 );

					if ( '' !== $tail && ctype_digit( $tail ) && $condition === ( $conditions[ $index ] ?? '' ) ) {
						$untranslated[] = $condition;
					}
				}
			}
		}

		$written = ElementorSupport::set_conditions( $post->ID, $conditions );

		if ( is_wp_error( $written ) ) {
			return AbilityRegistrar::failure( $written );
		}

		return array(
			'success'          => true,
			'post_id'          => $post->ID,
			'conditions'       => ElementorSupport::get_conditions( $post->ID ),
			'previous'         => $previous,
			'written'          => true,
			'untranslated_ids' => $untranslated,
		);
	}
}
