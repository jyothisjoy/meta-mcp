<?php
/**
 * Ability for reading Rank Math's site-wide settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\RankMathSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Get Settings - Reads Rank Math's General, Titles & Meta or Sitemap settings.
 */
final class SeoGetSettingsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'seo-get-settings',
			array(
				'label'               => 'Get Rank Math Settings',
				'description'         => 'Read Rank Math\'s site-wide settings for one screen: "titles" (Titles & Meta: title_separator, knowledgegraph_type, knowledgegraph_name, website_name, homepage_title, pt_{post_type}_title / _description / _robots / _custom_robots / _default_rich_snippet, tax_{taxonomy}_robots, robots_global, noindex_empty_taxonomies ...), "sitemap" (pt_{post_type}_sitemap, tax_{taxonomy}_sitemap, authors_sitemap, items_per_page ...) or "general" (breadcrumbs, links, images, robots.txt ...). Switches are stored as "on"/"off". Use search to return only keys containing a word, e.g. "facility". Write them with seo-update-settings.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group'  => array(
							'type'        => 'string',
							'enum'        => array_keys( RankMathSupport::OPTION_GROUPS ),
							'description' => 'Which settings screen to read.',
						),
						'keys'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Only return these keys.',
						),
						'search' => array(
							'type'        => 'string',
							'description' => 'Only return keys containing this text.',
						),
					),
					'required'   => array( 'group' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'group'        => array( 'type' => 'string' ),
						'option'       => array( 'type' => 'string' ),
						'settings'     => array( 'type' => 'object' ),
						'missing_keys' => array( 'type' => 'array' ),
						'error'        => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The settings.
	 */
	public static function execute( $input = array() ): array {
		$active = RankMathSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$group = RankMathSupport::validate_group( $input['group'] ?? '' );

		if ( is_wp_error( $group ) ) {
			return AbilityRegistrar::failure( $group );
		}

		$allowed = RankMathSupport::require_can_manage_settings( $group );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$settings = RankMathSupport::get_settings( $group );
		$missing  = array();

		if ( ! empty( $input['keys'] ) && is_array( $input['keys'] ) ) {
			$keys     = array_map( 'strval', $input['keys'] );
			$missing  = array_values( array_diff( $keys, array_keys( $settings ) ) );
			$settings = array_intersect_key( $settings, array_flip( $keys ) );
		}

		$search = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';

		if ( '' !== $search ) {
			$settings = array_filter(
				$settings,
				static fn( $key ): bool => false !== strpos( strtolower( (string) $key ), $search ),
				ARRAY_FILTER_USE_KEY
			);
		}

		ksort( $settings );

		return array(
			'success'      => true,
			'group'        => $group,
			'option'       => RankMathSupport::OPTION_GROUPS[ $group ],
			'settings'     => (object) $settings,
			'missing_keys' => $missing,
		);
	}
}
