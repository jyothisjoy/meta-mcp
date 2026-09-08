<?php
/**
 * Ability for editing or moving a navigation menu item.
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
 * Update Menu Item - Edits one entry in place, including where it sits.
 */
final class UpdateMenuItemAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-menu-item',
			array(
				'label'               => 'Update Navigation Menu Item',
				'description'         => 'Change one menu item: its label, what it links to, its CSS classes, or where it sits in the menu. Only the fields you send are changed, everything else on the item is kept. Send parent and position together to move an item, for example parent 0 with position 1 to make it the first top level entry.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'item_id'     => array(
							'type'        => 'integer',
							'description' => 'The menu item id, as returned by get-menu. This is not the id of the page it links to.',
						),
						'menu'        => array(
							'type'        => 'string',
							'description' => 'Optional menu ID, slug or name to check the item belongs to.',
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'New label for the item.',
						),
						'type'        => array(
							'type'        => 'string',
							'enum'        => MenuSupport::ITEM_TYPES,
							'description' => 'Change what kind of thing the item points at.',
						),
						'object'      => array(
							'type'        => 'string',
							'description' => 'Post type or taxonomy slug of the new target.',
						),
						'object_id'   => array(
							'type'        => 'integer',
							'description' => 'ID of the post or term the item should link to.',
						),
						'url'         => array(
							'type'        => 'string',
							'description' => 'New destination for a custom link.',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'Menu item id to nest this item under, or 0 to move it to the top level.',
						),
						'position'    => array(
							'type'        => 'integer',
							'description' => 'New 1-based position among its siblings.',
						),
						'target'      => array(
							'type'        => 'string',
							'enum'        => MenuSupport::ITEM_TARGETS,
							'description' => 'Use "_blank" to open in a new tab, or an empty string for the same tab.',
						),
						'classes'     => array(
							'type'        => 'array',
							'description' => 'Replaces the item\'s CSS classes.',
							'items'       => array( 'type' => 'string' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'New item description.',
						),
						'attr_title'  => array(
							'type'        => 'string',
							'description' => 'New link title attribute.',
						),
						'xfn'         => array(
							'type'        => 'string',
							'description' => 'New XFN relationship value.',
						),
					),
					'required'   => array( 'item_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'item'       => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The updated item and menu.
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

			if ( $menu_id <= 0 ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'item_not_in_menu', sprintf( 'Menu item %d does not belong to any menu.', $item_id ) )
				);
			}
		}

		$menu = MenuSupport::get_menu( $menu_id );

		if ( is_wp_error( $menu ) ) {
			return AbilityRegistrar::failure( $menu );
		}

		$existing = MenuSupport::format_item( wp_setup_nav_menu_item( $item ) );

		$parent = array_key_exists( 'parent', $input )
			? MenuSupport::validate_parent( $input['parent'], $menu_id, $item_id )
			: (int) $existing['parent'];

		if ( is_wp_error( $parent ) ) {
			return AbilityRegistrar::failure( $parent );
		}

		$args = MenuSupport::build_item_args( $input, $existing );

		if ( is_wp_error( $args ) ) {
			return AbilityRegistrar::failure( $args );
		}

		$written = MenuSupport::write_item( $menu_id, $item_id, $args, $parent );

		if ( is_wp_error( $written ) ) {
			return AbilityRegistrar::failure( $written );
		}

		$moved = array_key_exists( 'parent', $input ) || array_key_exists( 'position', $input );

		if ( $moved ) {
			$position = isset( $input['position'] ) && is_numeric( $input['position'] ) ? (int) $input['position'] : null;

			MenuSupport::place_item( $menu, $item_id, $parent, $position );
		}

		$menu    = MenuSupport::get_menu( $menu_id );
		$refresh = MenuSupport::get_item( $item_id );

		return array(
			'success' => true,
			'item'    => is_wp_error( $refresh ) ? array() : MenuSupport::format_item( wp_setup_nav_menu_item( $refresh ) ),
			'menu'    => is_wp_error( $menu ) ? array() : MenuSupport::format_menu( $menu, array( 'include_items' => true ) ),
		);
	}
}
