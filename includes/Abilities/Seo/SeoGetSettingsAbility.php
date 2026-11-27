<?php
/**
 * Ability for reading the SEO plugin's (Rank Math or Yoast SEO) site-wide settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\SeoBackend;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Get Settings - Reads Rank Math's or Yoast's site-wide settings for one group.
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
				'label'               => 'Get SEO Settings',
				'description'         => 'Read the SEO plugin\'s site-wide settings for one group. Rank Math: "titles" (title_separator, knowledgegraph_*, homepage_title, pt_{type}_title/_description/_robots/_custom_robots/_default_rich_snippet, tax_{tax}_robots, robots_global, local SEO...), "sitemap" (pt_{type}_sitemap, items_per_page...), "general" (breadcrumbs, links, images, 404 monitor, redirections, llms...); switches are "on"/"off". Yoast: "titles" (wpseo_titles: separator, title-{type}, metadesc-{type}, noindex-{type}...), "general" (wpseo feature switches), "social" (wpseo_social); switches are true/false. search filters keys by substring; keys picks exact keys.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group'  => array(
							'type'        => 'string',
							'enum'        => SeoBackend::all_groups(),
							'description' => 'Which settings group to read. Rank Math: general, titles, sitemap. Yoast SEO: general, titles, social.',
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
						'provider' => SeoBackend::provider_schema(),
					),
					'required'   => array( 'group' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'provider'     => array( 'type' => 'string' ),
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
		$input   = is_array( $input ) ? $input : array();
		$backend = SeoBackend::resolve( $input );

		if ( is_wp_error( $backend ) ) {
			return AbilityRegistrar::failure( $backend );
		}

		$group = $backend::validate_group( $input['group'] ?? '' );

		if ( is_wp_error( $group ) ) {
			return AbilityRegistrar::failure( $group );
		}

		$allowed = $backend::require_can_manage_settings( $group );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$settings = SeoBackend::redact( $backend::get_settings( $group ) );
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
			'provider'     => SeoBackend::name_of( $backend ),
			'group'        => $group,
			'option'       => $backend::OPTION_GROUPS[ $group ],
			'settings'     => (object) $settings,
			'missing_keys' => $missing,
		);
	}
}
