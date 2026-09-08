<?php
/**
 * Ability for adding an item to a navigation menu.
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
 * Add Menu Item - Appends or inserts a single entry in a menu.
 */
final class AddMenuItemAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'add-menu-item',
			array(
				'label'               => 'Add Navigation Menu Item',
				'description'         => 'Add one item to a navigation menu: a link to a page or post (type post_type with object_id), to a category or tag (type taxonomy with object_id), to a post type archive, or a custom URL (type custom with url). The item is appended unless you give a position, and nested when you give a parent item id. The target is checked before the item is written, so a link to a post that does not exist is refused rather than saved as a dead entry.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu'        => array(
							'type'        => 'string',
							'description' => 'Menu ID, slug or name.',
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'Label shown in the menu. Required for a custom link; for a page, post or term it defaults to that object\'s own title.',
						),
						'type'        => array(
							'type'        => 'string',
							'enum'        => MenuSupport::ITEM_TYPES,
							'description' => 'What the item points at. Inferred when omitted: object_id present means post_type or taxonomy, otherwise custom.',
						),
						'object'      => array(
							'type'        => 'string',
							'description' => 'Post type slug for post_type and post_type_archive items, taxonomy slug for taxonomy items, e.g. "page" or "category".',
						),
						'object_id'   => array(
							'type'        => 'integer',
							'description' => 'ID of the post or term the item links to.',
						),
						'url'         => array(
							'type'        => 'string',
							'description' => 'Destination for a custom link, including the scheme.',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'Menu item id to nest this item under. Omit or use 0 for a top level item.',
						),
						'position'    => array(
							'type'        => 'integer',
							'description' => '1-based position among its siblings. Omit to append to the end.',
						),
						'target'      => array(
							'type'        => 'string',
							'enum'        => MenuSupport::ITEM_TARGETS,
							'description' => 'Use "_blank" to open in a new tab, or leave empty for the same tab.',
						),
						'classes'     => array(
							'type'        => 'array',
							'description' => 'CSS classes to add to the menu item.',
							'items'       => array( 'type' => 'string' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Item description. Only some themes display it.',
						),
						'attr_title'  => array(
							'type'        => 'string',
							'description' => 'The link title attribute.',
						),
						'xfn'         => array(
							'type'        => 'string',
							'description' => 'XFN relationship value, e.g. "nofollow".',
						),
					),
					'required'   => array( 'menu' ),
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
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The created item and the resulting menu.
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

		$menu_id = (int) $menu->term_id;

		if ( count( MenuSupport::menu_items( $menu ) ) >= MenuSupport::MAX_ITEMS ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'menu_too_large',
					sprintf( 'This menu already holds the maximum of %d items.', MenuSupport::MAX_ITEMS )
				)
			);
		}

		$parent = MenuSupport::validate_parent( $input['parent'] ?? 0, $menu_id );

		if ( is_wp_error( $parent ) ) {
			return AbilityRegistrar::failure( $parent );
		}

		$args = MenuSupport::build_item_args( is_array( $input ) ? $input : array() );

		if ( is_wp_error( $args ) ) {
			return AbilityRegistrar::failure( $args );
		}

		$item_id = MenuSupport::write_item( $menu_id, 0, $args, $parent );

		if ( is_wp_error( $item_id ) ) {
			return AbilityRegistrar::failure( $item_id );
		}

		$position = isset( $input['position'] ) && is_numeric( $input['position'] ) ? (int) $input['position'] : null;

		MenuSupport::place_item( $menu, (int) $item_id, $parent, $position );

		$menu    = MenuSupport::get_menu( $menu_id );
		$written = MenuSupport::get_item( (int) $item_id );

		return array(
			'success' => true,
			'item'    => is_wp_error( $written ) ? array( 'id' => (int) $item_id ) : MenuSupport::format_item( wp_setup_nav_menu_item( $written ) ),
			'menu'    => is_wp_error( $menu ) ? array() : MenuSupport::format_menu( $menu, array( 'include_items' => true ) ),
		);
	}
}
