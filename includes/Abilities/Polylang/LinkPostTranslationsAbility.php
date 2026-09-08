<?php
/**
 * Ability for linking existing posts together as translations.
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
 * Link Post Translations - Associates existing posts as translations of each other.
 */
final class LinkPostTranslationsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-link-post-translations',
			array(
				'label'               => 'Link Post Translations',
				'description'         => 'Link posts that already exist as translations of each other, so Polylang shows them as the same content in different languages. Pass a map of language slug to post ID covering every language in the set. Each post is assigned the language it is keyed under. To create a translation that does not exist yet, use polylang-create-post-translation instead.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'translations' => array(
							'type'        => 'object',
							'description' => 'Map of language slug to post ID, e.g. {"en": 12, "fr": 34, "de": 56}. At least two entries are required. Any language omitted here is removed from the set.',
						),
					),
					'required'   => array( 'translations' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
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
	 * @return array<string, mixed> The resulting translation set.
	 */
	public static function execute( $input = array() ): array {
		$active = PolylangSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$translations = $input['translations'] ?? null;

		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'missing_translations', 'translations must be an object of language slug to post ID.' )
			);
		}

		$saved = PolylangSupport::save_post_translations( $translations );

		if ( is_wp_error( $saved ) ) {
			return AbilityRegistrar::failure( $saved );
		}

		$first_id = (int) reset( $translations );

		return array(
			'success'      => true,
			'translations' => PolylangSupport::get_post_translations( $first_id ),
		);
	}
}
