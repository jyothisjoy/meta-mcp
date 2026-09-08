<?php
/**
 * Ability for removing an item from a navigation menu.
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
 * Delete Menu Item - Removes one entry, and decides what happens to its children.
 */
final class DeleteMenuItemAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'delete-menu-item',
			array(
				'label'               => 'Delete Navigation Menu Item',
				'description'         => 'Remove one item from a navigation menu. Anything nested under it is moved up to take its place, unless delete_children is true, in which case the whole branch goes. The page or post the item linked to is not touched.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'item_id'         => array(
							'type'        => 'integer',
							'description' => 'The menu item id, as returned by get-menu.',
						),
						'menu'            => array(
							'type'        => 'string',
							'description' => 'Optional menu ID, slug or name to check the item belongs to.',
						),
						'delete_children' => array(
							'type'        => 'boolean',
							'description' => 'Delete everything nested under this item too. Default false, which moves the children up one level instead.',
						),
					),
					'required'   => array( 'item_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'deleted'        => array( 'type' => 'array' ),
						'children_moved' => array( 'type' => 'integer' ),
						'menu'           => array( 'type' => 'object' ),
						'error'          => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The outcome and the resulting menu.
	 */
	public static function execute( $input = array() ): array {
		$permitted = MenuSupport::require_can_manage();

		if ( is_wp_error( $permitted ) ) {
			return AbilityRegistrar::failure( $permitted );
		}

		$item_id = isset( $input['item_id'] ) ? (int) $input['item_id'] : 0;
		$menu_id = 0;

		if ( isset( $input['menu'] ) && '' !== $input['menu'] ) {
			$menu = MenuSupport::get_menu( $input['menu'] );

			if ( is_wp_error( $menu ) ) {
				return AbilityRegistrar::failure( $menu );
			}

			$menu_id = (int) $menu->term_id;
		}

		$item = MenuSupport::get_item( $item_id, $menu_id );

		if ( is_wp_error( $item ) ) {
			return AbilityRegistrar::failure( $item );
		}

		if ( 0 === $menu_id ) {
			$menu_id = MenuSupport::menu_id_for_item( $item_id );
		}

		$menu = MenuSupport::get_menu( $menu_id );

		if ( is_wp_error( $menu ) ) {
			return AbilityRegistrar::failure( $menu );
		}

		$items   = MenuSupport::menu_items( $menu );
		$targets = array( $item_id );
		$moved   = 0;

		if ( ContentSupport::to_bool( $input['delete_children'] ?? null, false ) ) {
			$targets = MenuSupport::descendant_ids( $item_id, $items );
		} else {
			$parent = (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true );
			$moved  = MenuSupport::reparent_children( $item_id, $parent );
		}

		$deleted = array();

		foreach ( $targets as $target ) {
			if ( wp_delete_post( (int) $target, true ) ) {
				$deleted[] = (int) $target;
			}
		}

		if ( empty( $deleted ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'delete_failed', sprintf( 'WordPress could not delete menu item %d.', $item_id ) )
			);
		}

		$menu = MenuSupport::get_menu( $menu_id );

		if ( ! is_wp_error( $menu ) ) {
			// Close the gap the deletion left, so positions stay contiguous.
			MenuSupport::apply_order( MenuSupport::tree_order( MenuSupport::to_tree( MenuSupport::menu_items( $menu ) ) ) );

			$menu = MenuSupport::get_menu( $menu_id );
		}

		return array(
			'success'        => true,
			'deleted'        => $deleted,
			'children_moved' => $moved,
			'menu'           => is_wp_error( $menu ) ? array() : MenuSupport::format_menu( $menu, array( 'include_items' => true ) ),
		);
	}
}
