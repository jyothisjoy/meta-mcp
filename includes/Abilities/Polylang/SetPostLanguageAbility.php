<?php
/**
 * Ability for assigning the Polylang language of a post.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Polylang;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Post Language - Assigns a post to a language.
 */
final class SetPostLanguageAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-set-post-language',
			array(
				'label'               => 'Set Post Language',
				'description'         => 'Assign a post to a Polylang language. A post must have a language before it can be linked to translations. Changing the language of a post that is already part of a translation set will detach it from that set.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'The ID of the post.',
						),
						'language' => array(
							'type'        => 'string',
							'description' => 'Language slug, e.g. "en" or "fr". Call polylang-list-languages for valid values.',
						),
					),
					'required'   => array( 'post_id', 'language' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'post_id'      => array( 'type' => 'integer' ),
						'language'     => array( 'type' => array( 'object', 'null' ) ),
						'translations' => array( 'type' => 'object' ),
						'warning'      => array( 'type' => 'string' ),
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

		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$language = isset( $input['language'] ) ? (string) $input['language'] : '';

		if ( '' === $language ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_language', 'language is required.' ) );
		}

		$warning = '';

		if ( ! PolylangSupport::is_translated_post_type( $post->post_type ) ) {
			$warning = sprintf(
				'Post type "%s" is not marked translatable in the Polylang settings; the language may not take effect.',
				$post->post_type
			);
		}

		$existing = PolylangSupport::get_post_translations( $post->ID );

		if ( count( $existing ) > 1 ) {
			$warning = trim( $warning . ' This post is part of a translation set; changing its language detaches it from the other translations.' );
		}

		$applied = PolylangSupport::set_post_language( $post->ID, $language );

		if ( is_wp_error( $applied ) ) {
			return AbilityRegistrar::failure( $applied );
		}

		$response = array(
			'success'      => true,
			'post_id'      => $post->ID,
			'language'     => PolylangSupport::get_post_language( $post->ID ),
			'translations' => PolylangSupport::get_post_translations( $post->ID ),
		);

		if ( '' !== $warning ) {
			$response['warning'] = $warning;
		}

		return $response;
	}
}
