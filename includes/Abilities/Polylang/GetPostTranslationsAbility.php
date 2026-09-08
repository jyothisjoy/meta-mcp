<?php
/**
 * Ability for reading the translation set of a post.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Polylang;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\PolylangSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Post Translations - Reports which languages a post exists in.
 */
final class GetPostTranslationsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-get-post-translations',
			array(
				'label'               => 'Get Post Translations',
				'description'         => 'Show which languages a post already exists in and which are still missing, with the post ID, title and status of each translation. Use this before creating a translation so you do not duplicate one that already exists.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The ID of any post in the translation set.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'           => array( 'type' => 'boolean' ),
						'post_id'           => array( 'type' => 'integer' ),
						'language'          => array( 'type' => array( 'object', 'null' ) ),
						'translations'      => array( 'type' => 'object' ),
						'missing_languages' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'translatable'      => array( 'type' => 'boolean' ),
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
	 * @return array<string, mixed> The translation set.
	 */
	public static function execute( $input = array() ): array {
		$active = PolylangSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$post = ContentSupport::require_can_read( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$translations = PolylangSupport::get_post_translations( $post->ID );
		$missing      = array_values( array_diff( PolylangSupport::language_slugs(), array_keys( $translations ) ) );

		return array(
			'success'           => true,
			'post_id'           => $post->ID,
			'language'          => PolylangSupport::get_post_language( $post->ID ),
			'translations'      => $translations,
			'missing_languages' => $missing,
			'translatable'      => PolylangSupport::is_translated_post_type( $post->post_type ),
		);
	}
}
