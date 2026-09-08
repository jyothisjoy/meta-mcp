<?php
/**
 * Ability for managing the language and translations of taxonomy terms.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Polylang;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Term Language - Assigns and links the languages of taxonomy terms.
 *
 * Term translations matter more than they look: when a post is translated, its
 * categories and tags are carried across by mapping each term to its
 * counterpart in the target language. A term with no translation is dropped
 * rather than assigned in the wrong language, so an untranslated taxonomy
 * quietly produces translations with no categories.
 */
final class SetTermLanguageAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-set-term-language',
			array(
				'label'               => 'Set Term Language',
				'description'         => 'Assign a taxonomy term to a language, and optionally link it to its counterparts in other languages. Do this for categories and tags before translating posts that use them: when a post is translated, only terms that have a translation in the target language are carried over.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'      => array(
							'type'        => 'integer',
							'description' => 'The ID of the term.',
						),
						'taxonomy'     => array(
							'type'        => 'string',
							'description' => 'The taxonomy the term belongs to, e.g. "category".',
						),
						'language'     => array(
							'type'        => 'string',
							'description' => 'Language slug to assign to the term.',
						),
						'translations' => array(
							'type'        => 'object',
							'description' => 'Optional map of language slug to term ID, linking this term to its translations, e.g. {"en": 5, "fr": 9}. Every term listed is assigned the language it is keyed under.',
						),
					),
					'required'   => array( 'term_id', 'taxonomy' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'term_id'      => array( 'type' => 'integer' ),
						'language'     => array( 'type' => 'string' ),
						'translations' => array( 'type' => 'object' ),
						'error'        => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The outcome.
	 */
	public static function execute( $input = array() ): array {
		$active = PolylangSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		$term_id  = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;

		$checked = self::check_term( $term_id, $taxonomy );

		if ( is_wp_error( $checked ) ) {
			return AbilityRegistrar::failure( $checked );
		}

		if ( ! PolylangSupport::is_translated_taxonomy( $taxonomy ) ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'taxonomy_not_translatable',
					sprintf( 'Taxonomy "%s" is not enabled for translation in the Polylang settings.', $taxonomy )
				)
			);
		}

		$language = isset( $input['language'] ) ? (string) $input['language'] : '';

		if ( '' !== $language ) {
			$valid = PolylangSupport::validate_language( $language );

			if ( is_wp_error( $valid ) ) {
				return AbilityRegistrar::failure( $valid );
			}

			pll_set_term_language( $term_id, $language );
		}

		if ( ! empty( $input['translations'] ) && is_array( $input['translations'] ) ) {
			$linked = self::link_translations( $input['translations'], $taxonomy );

			if ( is_wp_error( $linked ) ) {
				return AbilityRegistrar::failure( $linked );
			}
		}

		return array(
			'success'      => true,
			'term_id'      => $term_id,
			'language'     => function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $term_id, 'slug' ) : '',
			'translations' => function_exists( 'pll_get_term_translations' ) ? (array) pll_get_term_translations( $term_id ) : array(),
		);
	}

	/**
	 * Validates a term and the caller's permission to edit it.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return true|\WP_Error True when usable, WP_Error otherwise.
	 */
	private static function check_term( int $term_id, string $taxonomy ) {
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" is not registered.', $taxonomy ) );
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'term_not_found', sprintf( 'No term with ID %d in "%s".', $term_id, $taxonomy ) );
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return new WP_Error( 'cannot_edit_term', sprintf( 'User cannot edit term %d.', $term_id ) );
		}

		return true;
	}

	/**
	 * Links a set of terms as translations of each other.
	 *
	 * @param array  $translations Map of language slug to term ID.
	 * @param string $taxonomy     Taxonomy slug.
	 *
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	private static function link_translations( array $translations, string $taxonomy ) {
		if ( ! function_exists( 'pll_save_term_translations' ) ) {
			return new WP_Error( 'polylang_not_active', 'Polylang term translation functions are unavailable.' );
		}

		$clean = array();

		foreach ( $translations as $language => $term_id ) {
			$language = (string) $language;
			$valid    = PolylangSupport::validate_language( $language );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$term_id = (int) $term_id;
			$checked = self::check_term( $term_id, $taxonomy );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			pll_set_term_language( $term_id, $language );

			$clean[ $language ] = $term_id;
		}

		if ( count( $clean ) < 2 ) {
			return new WP_Error(
				'insufficient_translations',
				'At least two terms in different languages are required to link translations.'
			);
		}

		pll_save_term_translations( $clean );

		return true;
	}
}
