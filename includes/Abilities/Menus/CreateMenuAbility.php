<?php
/**
 * Ability for creating a navigation menu.
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
 * Create Menu - Adds a navigation menu, optionally filled and assigned in one call.
 */
final class CreateMenuAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'create-menu',
			array(
				'label'               => 'Create Navigation Menu',
				'description'         => 'Create a new WordPress navigation menu. Optionally fill it with items and assign it to theme locations in the same call, which is the quickest way to build a working menu from nothing. See set-menu-items for the shape of the items array.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'name'      => array(
							'type'        => 'string',
							'description' => 'Name of the menu, as it appears under Appearance → Menus.',
						),
						'items'     => array(
							'type'        => 'array',
							'description' => 'Optional items to add, in order. Each item takes title, type (custom, post_type, taxonomy, post_type_archive), url or object/object_id, and an optional children array. See set-menu-items.',
							'items'       => array( 'type' => 'object' ),
						),
						'locations' => array(
							'type'        => 'array',
							'description' => 'Optional theme location slugs to assign the new menu to, e.g. ["primary"]. Call list-menu-locations to see what the theme registers.',
							'items'       => array( 'type' => 'string' ),
						),
					),
					'required'   => array( 'name' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'menu'       => array( 'type' => 'object' ),
						'created'    => array( 'type' => 'integer' ),
						'warnings'   => array( 'type' => 'array' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The created menu.
	 */
	public static function execute( $input = array() ): array {
		$permitted = MenuSupport::require_can_manage();

		if ( is_wp_error( $permitted ) ) {
			return AbilityRegistrar::failure( $permitted );
		}

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';

		if ( '' === $name ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_name', 'A menu name is required.' ) );
		}

		if ( wp_get_nav_menu_object( $name ) ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'menu_exists',
					sprintf( 'A menu called "%s" already exists. Use update-menu or set-menu-items on it instead.', $name )
				)
			);
		}

		$menu_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $menu_id ) ) {
			return AbilityRegistrar::failure( $menu_id );
		}

		$menu = MenuSupport::get_menu( (int) $menu_id );

		if ( is_wp_error( $menu ) ) {
			return AbilityRegistrar::failure( $menu );
		}

		$created  = 0;
		$warnings = array();

		if ( ! empty( $input['items'] ) && is_array( $input['items'] ) ) {
			$written = SetMenuItemsAbility::write_tree( $menu, $input['items'] );

			if ( is_wp_error( $written ) ) {
				// The menu itself exists, so report the item failure rather than
				// leaving the client to guess why it is empty.
				return array(
					'success'    => false,
					'menu'       => MenuSupport::format_menu( $menu, array( 'include_items' => true ) ),
					'error'      => $written->get_error_message(),
					'error_code' => (string) $written->get_error_code(),
				);
			}

			$created = count( $written );
		}

		if ( ! empty( $input['locations'] ) && is_array( $input['locations'] ) ) {
			$assignments = array();

			foreach ( $input['locations'] as $location ) {
				$assignments[ (string) $location ] = (int) $menu->term_id;
			}

			$assigned = MenuSupport::assign_locations( $assignments );

			if ( is_wp_error( $assigned ) ) {
				$warnings[] = $assigned->get_error_message();
			}
		}

		$menu = MenuSupport::get_menu( (int) $menu_id );

		return array(
			'success'  => true,
			'menu'     => is_wp_error( $menu ) ? array( 'id' => (int) $menu_id ) : MenuSupport::format_menu( $menu, array( 'include_items' => true ) ),
			'created'  => $created,
			'warnings' => $warnings,
		);
	}
}
