<?php
/**
 * Ability for deleting a navigation menu.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Menus;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\MenuSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Delete Menu - Removes a menu and every item in it.
 */
final class DeleteMenuAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'delete-menu',
			array(
				'label'               => 'Delete Navigation Menu',
				'description'         => 'Delete a navigation menu and all of its items, and free any theme location it occupied. This cannot be undone: menu items are not sent to the trash. The pages and posts the menu linked to are untouched. Requires confirm to be true, so a menu is never removed on a misread instruction.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu'    => array(
							'type'        => 'string',
							'description' => 'Menu ID, slug or name.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true. Guards against deleting a menu by accident.',
						),
					),
					'required'   => array( 'menu', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'deleted'       => array( 'type' => 'boolean' ),
						'menu_id'       => array( 'type' => 'integer' ),
						'menu_name'     => array( 'type' => 'string' ),
						'items_deleted' => array( 'type' => 'integer' ),
						'error'         => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => false,
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
		$permitted = MenuSupport::require_can_manage();

		if ( is_wp_error( $permitted ) ) {
			return AbilityRegistrar::failure( $permitted );
		}

		if ( ! ContentSupport::to_bool( $input['confirm'] ?? null, false ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'confirmation_required', 'Set confirm to true to delete a menu and everything in it.' )
			);
		}

		$menu = MenuSupport::get_menu( $input['menu'] ?? null );

		if ( is_wp_error( $menu ) ) {
			return AbilityRegistrar::failure( $menu );
		}

		$menu_id   = (int) $menu->term_id;
		$menu_name = (string) $menu->name;
		$items     = count( MenuSupport::menu_items( $menu ) );

		$deleted = wp_delete_nav_menu( $menu_id );

		if ( is_wp_error( $deleted ) ) {
			return AbilityRegistrar::failure( $deleted );
		}

		if ( ! $deleted ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'delete_failed', sprintf( 'WordPress could not delete menu %d.', $menu_id ) )
			);
		}

		return array(
			'success'       => true,
			'deleted'       => true,
			'menu_id'       => $menu_id,
			'menu_name'     => $menu_name,
			'items_deleted' => $items,
		);
	}
}
