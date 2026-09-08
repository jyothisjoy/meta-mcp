<?php
/**
 * Ability for listing the languages configured in Polylang.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Polylang;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\PolylangSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Languages - Reports the Polylang language configuration.
 */
final class ListLanguagesAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-list-languages',
			array(
				'label'               => 'List Languages',
				'description'         => 'List the languages configured in Polylang, with their slugs, locales and which one is the default, plus which post types and taxonomies are translatable and whether Polylang Pro is active. Call this before working with translations so you use valid language slugs.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                 => array( 'type' => 'boolean' ),
						'polylang_active'         => array( 'type' => 'boolean' ),
						'polylang_pro'            => array( 'type' => 'boolean' ),
						'version'                 => array( 'type' => 'string' ),
						'default_language'        => array( 'type' => 'string' ),
						'languages'               => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'translated_post_types'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'translated_taxonomies'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'synchronised_fields'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'error'                   => array( 'type' => 'string' ),
						'error_code'              => array( 'type' => 'string' ),
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
	 * @param array $input Input parameters (unused).
	 *
	 * @return array<string, mixed> The language configuration.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the ability callback.
	public static function execute( $input = array() ): array {
		if ( ! PolylangSupport::is_active() ) {
			return array(
				'success'         => true,
				'polylang_active' => false,
				'polylang_pro'    => false,
				'version'         => '',
				'languages'       => array(),
			);
		}

		$post_types = array();

		foreach ( get_post_types( array(), 'names' ) as $post_type ) {
			if ( PolylangSupport::is_translated_post_type( (string) $post_type ) ) {
				$post_types[] = (string) $post_type;
			}
		}

		$taxonomies = array();

		foreach ( get_taxonomies( array(), 'names' ) as $taxonomy ) {
			if ( PolylangSupport::is_translated_taxonomy( (string) $taxonomy ) ) {
				$taxonomies[] = (string) $taxonomy;
			}
		}

		return array(
			'success'               => true,
			'polylang_active'       => true,
			'polylang_pro'          => PolylangSupport::is_pro(),
			'version'               => PolylangSupport::version(),
			'default_language'      => function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '',
			'languages'             => PolylangSupport::languages(),
			'translated_post_types' => $post_types,
			'translated_taxonomies' => $taxonomies,
			'synchronised_fields'   => PolylangSupport::sync_settings(),
		);
	}
}
