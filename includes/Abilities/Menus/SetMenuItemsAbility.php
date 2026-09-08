<?php
/**
 * Ability for writing the whole structure of a navigation menu at once.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Menus;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\MenuSupport;
use WP_Error;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Menu Items - Replaces or extends a menu from a nested item tree.
 *
 * Building a menu one `add-menu-item` call at a time means a round trip per
 * link, and the client has to track the id of every parent it just created to
 * nest anything under it. One tree, written in a single call, is both cheaper
 * and easier to get right: parents are created before their children and the
 * ids are resolved here.
 */
final class SetMenuItemsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'set-menu-items',
			array(
				'label'               => 'Set Navigation Menu Items',
				'description'         => 'Write the structure of a navigation menu in one call from a nested array of items. By default this replaces everything in the menu; set mode to "append" to add to what is already there. Each item takes title, type (custom, post_type, taxonomy, post_type_archive), url or object plus object_id, optional target, classes, description, attr_title and xfn, and an optional children array nested to any depth. Every target is validated before anything is written, so a bad reference anywhere in the tree leaves the existing menu untouched.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu'  => array(
							'type'        => 'string',
							'description' => 'Menu ID, slug or name.',
						),
						'items' => array(
							'type'        => 'array',
							'description' => 'The items, in the order they should appear. Example: [{"title":"Home","type":"post_type","object":"page","object_id":12},{"title":"Docs","type":"custom","url":"https://example.com/docs","children":[{"title":"API","type":"post_type","object":"page","object_id":44}]}]',
							'items'       => array( 'type' => 'object' ),
						),
						'mode'  => array(
							'type'        => 'string',
							'enum'        => array( 'replace', 'append' ),
							'description' => 'How to apply the items. "replace" (default) deletes the existing items and writes these instead; "append" adds them after what is already in the menu.',
						),
					),
					'required'   => array( 'menu', 'items' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'created'    => array( 'type' => 'integer' ),
						'removed'    => array( 'type' => 'integer' ),
						'menu'       => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => true,
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

		$menu = MenuSupport::get_menu( $input['menu'] ?? null );

		if ( is_wp_error( $menu ) ) {
			return AbilityRegistrar::failure( $menu );
		}

		if ( ! isset( $input['items'] ) || ! is_array( $input['items'] ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'missing_items', 'items must be an array of menu items, even if it is empty.' )
			);
		}

		$mode    = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
		$mode    = in_array( $mode, array( 'replace', 'append' ), true ) ? $mode : 'replace';
		$replace = 'replace' === $mode;

		$existing = MenuSupport::menu_items( $menu );

		$created = self::write_tree( $menu, $input['items'], $replace ? array() : $existing );

		if ( is_wp_error( $created ) ) {
			return AbilityRegistrar::failure( $created );
		}

		$removed = 0;

		if ( $replace ) {
			foreach ( $existing as $item ) {
				if ( wp_delete_post( (int) $item['id'], true ) ) {
					++$removed;
				}
			}
		}

		$menu = MenuSupport::get_menu( (int) $menu->term_id );

		if ( ! is_wp_error( $menu ) ) {
			MenuSupport::apply_order( MenuSupport::tree_order( MenuSupport::to_tree( MenuSupport::menu_items( $menu ) ) ) );

			$menu = MenuSupport::get_menu( (int) $menu->term_id );
		}

		return array(
			'success' => true,
			'created' => count( $created ),
			'removed' => $removed,
			'menu'    => is_wp_error( $menu ) ? array() : MenuSupport::format_menu( $menu, array( 'include_items' => true ) ),
		);
	}

	/**
	 * Validates a submitted tree, then writes it into a menu.
	 *
	 * Validation is a separate pass on purpose. Writing menu items is not
	 * transactional, so the only way to keep a typo in the fifth item from
	 * leaving four orphans behind is to refuse the whole tree before the first
	 * item is created.
	 *
	 * @param \WP_Term $menu  The menu to write into.
	 * @param array    $items The submitted item tree.
	 * @param array    $kept  Items already in the menu that will survive, used for the size check.
	 *
	 * @return int[]|\WP_Error IDs of the created items, or WP_Error.
	 */
	public static function write_tree( WP_Term $menu, array $items, array $kept = array() ) {
		$counted = self::count_nodes( $items );

		if ( is_wp_error( $counted ) ) {
			return $counted;
		}

		if ( ( $counted + count( $kept ) ) > MenuSupport::MAX_ITEMS ) {
			return new WP_Error(
				'too_many_items',
				sprintf(
					'A menu is limited to %d items; this call would produce %d.',
					MenuSupport::MAX_ITEMS,
					$counted + count( $kept )
				)
			);
		}

		$validated = self::validate_tree( $items );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Continue after the highest order already in use, so an appended item
		// cannot land on the same position as one that is staying.
		$position = 0;
		$created  = array();

		foreach ( $kept as $item ) {
			$position = max( $position, (int) $item['position'] );
		}

		$written = self::write_branch( (int) $menu->term_id, $validated, 0, $position, $created );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return $created;
	}

	/**
	 * Counts the nodes in a tree and enforces the depth limit.
	 *
	 * @param array $items Item tree.
	 * @param int   $depth Current depth.
	 *
	 * @return int|\WP_Error Node count, or WP_Error.
	 */
	private static function count_nodes( array $items, int $depth = 1 ) {
		if ( $depth > MenuSupport::MAX_DEPTH ) {
			return new WP_Error(
				'menu_too_deep',
				sprintf( 'Menu items may be nested at most %d levels deep.', MenuSupport::MAX_DEPTH )
			);
		}

		$count = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'invalid_item', 'Every entry in items must be an object.' );
			}

			++$count;

			if ( empty( $item['children'] ) ) {
				continue;
			}

			if ( ! is_array( $item['children'] ) ) {
				return new WP_Error( 'invalid_children', 'The children of a menu item must be an array.' );
			}

			$nested = self::count_nodes( $item['children'], $depth + 1 );

			if ( is_wp_error( $nested ) ) {
				return $nested;
			}

			$count += $nested;
		}

		return $count;
	}

	/**
	 * Resolves every node in a tree into core's `menu-item-*` arguments.
	 *
	 * @param array  $items Item tree.
	 * @param string $path  Human-readable position of the current branch, for error messages.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error The prepared tree, or WP_Error.
	 */
	private static function validate_tree( array $items, string $path = 'items' ) {
		$prepared = array();
		$index    = 0;

		foreach ( $items as $item ) {
			$where = sprintf( '%s[%d]', $path, $index );
			++$index;

			$args = MenuSupport::build_item_args( $item );

			if ( is_wp_error( $args ) ) {
				return new WP_Error(
					(string) $args->get_error_code(),
					sprintf( '%s: %s', $where, $args->get_error_message() )
				);
			}

			$children = array();

			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$children = self::validate_tree( $item['children'], $where . '.children' );

				if ( is_wp_error( $children ) ) {
					return $children;
				}
			}

			$prepared[] = array(
				'args'     => $args,
				'children' => $children,
			);
		}

		return $prepared;
	}

	/**
	 * Writes one branch of a prepared tree, then its children.
	 *
	 * @param int   $menu_id  Menu term ID.
	 * @param array $branch   Prepared nodes.
	 * @param int   $parent   Parent item ID.
	 * @param int   $position Running position counter, passed by reference.
	 * @param array $created  Created item IDs, passed by reference.
	 *
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	private static function write_branch( int $menu_id, array $branch, int $parent, int &$position, array &$created ) {
		foreach ( $branch as $node ) {
			++$position;

			$item_id = MenuSupport::write_item( $menu_id, 0, $node['args'], $parent, $position );

			if ( is_wp_error( $item_id ) ) {
				return $item_id;
			}

			$created[] = (int) $item_id;

			if ( empty( $node['children'] ) ) {
				continue;
			}

			$nested = self::write_branch( $menu_id, $node['children'], (int) $item_id, $position, $created );

			if ( is_wp_error( $nested ) ) {
				return $nested;
			}
		}

		return true;
	}
}
