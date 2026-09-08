<?php
/**
 * Ability for listing the navigation menus on a site.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Menus;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\MenuSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Menus - Every classic navigation menu, with where each one is used.
 */
final class ListMenusAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-menus',
			array(
				'label'               => 'List Navigation Menus',
				'description'         => 'List the WordPress navigation menus on this site (Appearance → Menus), with the ID, name, slug, item count and theme locations of each. Start here before editing a menu; call get-menu for the items inside one. On a block theme this also reports the Navigation block menus, which are wp_navigation posts the ordinary content tools can read.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'include_items' => array(
							'type'        => 'boolean',
							'description' => 'Include the full item tree of every menu. Off by default because it can be large; use get-menu for one menu.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'menus'            => array( 'type' => 'array' ),
						'count'            => array( 'type' => 'integer' ),
						'locations'        => array( 'type' => 'object' ),
						'block_theme'      => array( 'type' => 'boolean' ),
						'navigation_posts' => array( 'type' => 'array' ),
						'error'            => array( 'type' => 'string' ),
						'error_code'       => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The menus on this site.
	 */
	public static function execute( $input = array() ): array {
		$supported = MenuSupport::require_readable();

		if ( is_wp_error( $supported ) ) {
			return AbilityRegistrar::failure( $supported );
		}

		$include_items = ContentSupport::to_bool( $input['include_items'] ?? null, false );

		$menus = array_map(
			static fn( $menu ): array => MenuSupport::format_menu( $menu, array( 'include_items' => $include_items ) ),
			MenuSupport::all_menus()
		);

		return array(
			'success'          => true,
			'menus'            => $menus,
			'count'            => count( $menus ),
			'locations'        => MenuSupport::registered_locations(),
			'block_theme'      => MenuSupport::is_block_theme(),
			'navigation_posts' => MenuSupport::navigation_posts(),
		);
	}
}
