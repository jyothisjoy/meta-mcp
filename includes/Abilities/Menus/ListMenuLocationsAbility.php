<?php
/**
 * Ability for listing the theme menu locations.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Menus;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\MenuSupport;
use WP\MCP\Abilities\Support\PolylangSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Menu Locations - Where the theme can display a menu, and what is in each slot.
 */
final class ListMenuLocationsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-menu-locations',
			array(
				'label'               => 'List Menu Locations',
				'description'         => 'List the menu locations the active theme registers (primary, footer and so on), with the menu currently assigned to each and whether the slot is empty. On a Polylang site it also reports the per-language assignment, which is where a location can appear empty in the theme settings while still showing a menu on the front end. Call this before set-menu-locations to learn the valid slugs.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'               => array( 'type' => 'boolean' ),
						'theme'                 => array( 'type' => 'string' ),
						'block_theme'           => array( 'type' => 'boolean' ),
						'locations'             => array( 'type' => 'array' ),
						'unassigned'            => array( 'type' => 'array' ),
						'polylang_per_language' => array( 'type' => 'object' ),
						'error'                 => array( 'type' => 'string' ),
						'error_code'            => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The theme locations.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the ability callback.
	public static function execute( $input = array() ): array {
		$registered = MenuSupport::registered_locations();
		$stored     = MenuSupport::stored_locations();
		$effective  = MenuSupport::effective_locations();
		$per_lang   = MenuSupport::polylang_locations();

		$locations  = array();
		$unassigned = array();

		foreach ( $registered as $slug => $description ) {
			$menu_id = isset( $stored[ $slug ] ) ? (int) $stored[ $slug ] : 0;
			$menu    = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;

			if ( ! $menu ) {
				$unassigned[] = $slug;
			}

			$location = array(
				'slug'              => (string) $slug,
				'description'       => (string) $description,
				'menu_id'           => $menu ? (int) $menu->term_id : 0,
				'menu_name'         => $menu ? (string) $menu->name : '',
				'effective_menu_id' => isset( $effective[ $slug ] ) ? (int) $effective[ $slug ] : 0,
			);

			if ( isset( $per_lang[ $slug ] ) ) {
				$location['by_language'] = $per_lang[ $slug ];
			}

			$locations[] = $location;
		}

		return array(
			'success'               => true,
			'theme'                 => (string) get_stylesheet(),
			'block_theme'           => MenuSupport::is_block_theme(),
			'locations'             => $locations,
			'unassigned'            => $unassigned,
			'polylang_per_language' => PolylangSupport::is_active() ? $per_lang : array(),
		);
	}
}
