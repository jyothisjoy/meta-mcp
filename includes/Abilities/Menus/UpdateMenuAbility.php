<?php
/**
 * Ability for renaming a navigation menu and changing where it is used.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Menus;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\MenuSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Menu - Renames a menu or changes its theme locations.
 */
final class UpdateMenuAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-menu',
			array(
				'label'               => 'Update Navigation Menu',
				'description'         => 'Rename a navigation menu, change its description, or replace the set of theme locations it is assigned to. Only the fields you send are changed. To edit the items inside the menu use add-menu-item, update-menu-item or set-menu-items.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu'        => array(
							'type'        => 'string',
							'description' => 'Menu ID, slug or name.',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'New name for the menu.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'New description for the menu.',
						),
						'locations'   => array(
							'type'        => 'array',
							'description' => 'The complete list of theme location slugs this menu should occupy. Locations it currently holds and that are absent from this list are cleared. Send an empty array to unassign it everywhere.',
							'items'       => array( 'type' => 'string' ),
						),
					),
					'required'   => array( 'menu' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'menu'       => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The updated menu.
	 */
	public static function execute( $input = array() ): array {
		$permitted = MenuSupport::require_can_manage();

		if ( is_wp_error( $permitted ) ) {
			return AbilityRegistrar::failure( $permitted );
		}

		$menu = MenuSupport::get_menu( $input['menu'] ?? null );

		if ( is_wp_error( $menu ) ) {
			return AbilityRegistrar::failure( $menu );
		}

		$menu_data = array();

		if ( isset( $input['name'] ) ) {
			$name = trim( (string) $input['name'] );

			if ( '' === $name ) {
				return AbilityRegistrar::failure( new WP_Error( 'missing_name', 'A menu name cannot be empty.' ) );
			}

			$existing = wp_get_nav_menu_object( $name );

			if ( $existing && (int) $existing->term_id !== (int) $menu->term_id ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'menu_exists', sprintf( 'Another menu is already called "%s".', $name ) )
				);
			}

			$menu_data['menu-name'] = $name;
		}

		if ( isset( $input['description'] ) ) {
			$menu_data['description'] = (string) $input['description'];
		}

		if ( ! empty( $menu_data ) ) {
			// wp_update_nav_menu_object() reads a missing key as an empty value
			// and writes it, so renaming a menu without resending its
			// description would silently clear the description.
			$menu_data['menu-name']   = $menu_data['menu-name'] ?? $menu->name;
			$menu_data['description'] = $menu_data['description'] ?? $menu->description;

			$updated = wp_update_nav_menu_object( (int) $menu->term_id, $menu_data );

			if ( is_wp_error( $updated ) ) {
				return AbilityRegistrar::failure( $updated );
			}
		}

		if ( isset( $input['locations'] ) && is_array( $input['locations'] ) ) {
			$assigned = self::sync_locations( (int) $menu->term_id, $input['locations'] );

			if ( is_wp_error( $assigned ) ) {
				return AbilityRegistrar::failure( $assigned );
			}
		}

		$menu = MenuSupport::get_menu( (int) $menu->term_id );

		return array(
			'success' => true,
			'menu'    => is_wp_error( $menu ) ? array() : MenuSupport::format_menu( $menu ),
		);
	}

	/**
	 * Makes the stored locations for a menu match the requested list exactly.
	 *
	 * @param int   $menu_id   Menu term ID.
	 * @param array $locations Requested location slugs.
	 *
	 * @return array<string, int>|\WP_Error The stored map, or WP_Error.
	 */
	private static function sync_locations( int $menu_id, array $locations ) {
		$wanted      = array_map( 'strval', $locations );
		$assignments = array();

		// Clear the ones it holds today and is not asked to keep.
		foreach ( MenuSupport::locations_for_menu( $menu_id ) as $current ) {
			if ( ! in_array( $current, $wanted, true ) ) {
				$assignments[ $current ] = 0;
			}
		}

		foreach ( $wanted as $location ) {
			$assignments[ $location ] = $menu_id;
		}

		if ( empty( $assignments ) ) {
			return array();
		}

		return MenuSupport::assign_locations( $assignments );
	}
}
