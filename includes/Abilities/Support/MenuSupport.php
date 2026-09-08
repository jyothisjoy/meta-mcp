<?php
/**
 * WordPress navigation menu helpers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;
use WP_Post;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - MenuSupport
 *
 * A classic WordPress menu is two things at once: a `nav_menu` term that names
 * it, and a run of `nav_menu_item` posts whose meaning lives entirely in post
 * meta — `_menu_item_type`, `_menu_item_object`, `_menu_item_object_id` and the
 * rest. That is why `nav_menu_item` is on the hard denylist in
 * {@see ContentSupport}: editing one through a generic post tool writes a title
 * and content that the menu walker never reads, and leaves the meta that does
 * matter untouched.
 *
 * So menus get their own layer. Everything here routes through core's
 * `wp_update_nav_menu_item()` and friends, which own the meta contract, and this
 * class supplies the parts core leaves to the caller: resolving a menu by id,
 * slug or name; validating what an item points at before it becomes a dead
 * link; merging a partial edit over an existing item, because core's updater
 * treats every omitted field as an instruction to blank it; and keeping
 * `menu_order` contiguous so a tree written back looks like the tree that was
 * asked for.
 *
 * Block themes are handled by saying so rather than by pretending: a Navigation
 * block is a `wp_navigation` post, which the ordinary content tools already
 * reach, and {@see self::navigation_posts()} points a client at them.
 */
final class MenuSupport {

	/**
	 * Menu item types core understands.
	 */
	public const ITEM_TYPES = array( 'custom', 'post_type', 'taxonomy', 'post_type_archive' );

	/**
	 * Link targets a menu item may carry.
	 */
	public const ITEM_TARGETS = array( '', '_blank' );

	/**
	 * Upper bound on the number of items accepted in a single structure write.
	 *
	 * A menu this large is already a design problem, and the cap keeps a runaway
	 * client from writing thousands of posts in one call.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * Deepest nesting accepted in a submitted item tree.
	 */
	public const MAX_DEPTH = 10;

	/**
	 * Capability required to manage menus, matching wp-admin's own gate.
	 */
	public const MANAGE_CAPABILITY = 'edit_theme_options';

	/**
	 * Whether the site can use classic navigation menus at all.
	 *
	 * The `nav_menu` taxonomy is always registered by core, so menus can be
	 * created and edited even under a block theme; what a block theme usually
	 * lacks is registered locations to assign them to.
	 *
	 * @return bool True when classic menus are usable.
	 */
	public static function is_supported(): bool {
		return taxonomy_exists( 'nav_menu' );
	}

	/**
	 * Whether the active theme is a block theme.
	 *
	 * @return bool True for a block theme.
	 */
	public static function is_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Guard for every menu-writing ability.
	 *
	 * Menus are site structure rather than content, so WordPress gates them on
	 * `edit_theme_options` rather than on any post type capability. The same gate
	 * is applied here, which means an Editor who can publish pages still cannot
	 * rewrite the site navigation through MCP.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function require_can_manage() {
		if ( ! self::is_supported() ) {
			return new WP_Error(
				'menus_unsupported',
				'The nav_menu taxonomy is not registered on this site, so navigation menus cannot be managed.'
			);
		}

		if ( ! current_user_can( self::MANAGE_CAPABILITY ) ) {
			return new WP_Error(
				'cannot_manage_menus',
				sprintf( 'User lacks the "%s" capability required to manage navigation menus.', self::MANAGE_CAPABILITY )
			);
		}

		return true;
	}

	/**
	 * Guard for the menu-reading abilities.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function require_readable() {
		if ( ! self::is_supported() ) {
			return new WP_Error(
				'menus_unsupported',
				'The nav_menu taxonomy is not registered on this site, so there are no navigation menus to read.'
			);
		}

		return true;
	}

	/**
	 * Returns every menu on the site.
	 *
	 * @return \WP_Term[] Menu terms.
	 */
	public static function all_menus(): array {
		$menus = wp_get_nav_menus( array( 'hide_empty' => false ) );

		return is_array( $menus ) ? $menus : array();
	}

	/**
	 * Resolves a menu given an ID, slug or name.
	 *
	 * @param mixed $identifier Menu ID, slug or name.
	 *
	 * @return \WP_Term|\WP_Error The menu term, or WP_Error when not found.
	 */
	public static function get_menu( $identifier ) {
		if ( is_string( $identifier ) ) {
			$identifier = trim( $identifier );
		}

		if ( null === $identifier || '' === $identifier || ( is_numeric( $identifier ) && (int) $identifier <= 0 ) ) {
			return new WP_Error( 'missing_menu', 'A menu ID, slug or name is required.' );
		}

		$menu = wp_get_nav_menu_object( is_numeric( $identifier ) ? (int) $identifier : (string) $identifier );

		if ( ! $menu instanceof WP_Term ) {
			return new WP_Error(
				'menu_not_found',
				sprintf( 'No navigation menu matches "%s". Call list-menus to see what exists.', (string) $identifier )
			);
		}

		return $menu;
	}

	/**
	 * Converts a menu into the array shape the abilities return.
	 *
	 * @param \WP_Term $menu Menu term.
	 * @param array    $args {
	 *     Optional. Shaping options.
	 *
	 *     @type bool $include_items Include the item tree. Default false.
	 *     @type bool $include_flat  Include the flat item list. Default false.
	 * }
	 *
	 * @return array<string, mixed> The formatted menu.
	 */
	public static function format_menu( WP_Term $menu, array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'include_items' => false,
				'include_flat'  => false,
			)
		);

		$data = array(
			'id'          => (int) $menu->term_id,
			'name'        => (string) $menu->name,
			'slug'        => (string) $menu->slug,
			'description' => (string) $menu->description,
			'item_count'  => (int) $menu->count,
			'locations'   => self::locations_for_menu( (int) $menu->term_id ),
			'edit_link'   => admin_url( 'nav-menus.php?action=edit&menu=' . (int) $menu->term_id ),
		);

		if ( PolylangSupport::is_active() ) {
			$data['language'] = self::menu_language( (int) $menu->term_id );
		}

		if ( $args['include_items'] || $args['include_flat'] ) {
			$items = self::menu_items( $menu );

			$data['item_count'] = count( $items );

			if ( $args['include_items'] ) {
				$data['items'] = self::to_tree( $items );
			}

			if ( $args['include_flat'] ) {
				$data['flat_items'] = $items;
			}
		}

		return $data;
	}

	/**
	 * Returns the formatted items of a menu, in menu order.
	 *
	 * @param \WP_Term $menu Menu term.
	 *
	 * @return array<int, array<string, mixed>> Formatted items.
	 */
	public static function menu_items( WP_Term $menu ): array {
		$items = wp_get_nav_menu_items(
			(int) $menu->term_id,
			array(
				'post_status' => 'publish,draft',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			)
		);

		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values( array_map( array( self::class, 'format_item' ), $items ) );
	}

	/**
	 * Converts a menu item into the array shape the abilities return.
	 *
	 * `wp_setup_nav_menu_item()` has already resolved the title and URL of a
	 * post or term item by the time this runs, so the output carries both what
	 * the item points at and what a visitor will actually see.
	 *
	 * @param \WP_Post|object $item Menu item, as decorated by wp_setup_nav_menu_item().
	 *
	 * @return array<string, mixed> The formatted item.
	 */
	public static function format_item( $item ): array {
		$classes = isset( $item->classes ) ? (array) $item->classes : array();
		$classes = array_values( array_filter( array_map( 'strval', $classes ), static fn( string $class ): bool => '' !== $class ) );

		$data = array(
			'id'          => (int) $item->ID,
			'parent'      => (int) ( $item->menu_item_parent ?? 0 ),
			'position'    => (int) ( $item->menu_order ?? 0 ),
			'title'       => (string) ( $item->title ?? '' ),
			'type'        => (string) ( $item->type ?? 'custom' ),
			'type_label'  => (string) ( $item->type_label ?? '' ),
			'object'      => (string) ( $item->object ?? '' ),
			'object_id'   => (int) ( $item->object_id ?? 0 ),
			'url'         => (string) ( $item->url ?? '' ),
			'target'      => (string) ( $item->target ?? '' ),
			'attr_title'  => (string) ( $item->attr_title ?? '' ),
			'description' => (string) ( $item->description ?? '' ),
			'classes'     => $classes,
			'xfn'         => (string) ( $item->xfn ?? '' ),
			'status'      => (string) ( $item->post_status ?? 'publish' ),
		);

		// A menu item whose target was deleted still renders in the admin, so
		// say plainly that it is broken rather than returning a bare zero.
		if ( in_array( $data['type'], array( 'post_type', 'taxonomy' ), true ) ) {
			$data['object_exists'] = self::object_exists( $data['type'], $data['object'], $data['object_id'] );
		}

		return $data;
	}

	/**
	 * Nests a flat, ordered item list into a tree.
	 *
	 * Items whose parent is missing from the menu are kept at the top level
	 * rather than dropped, and so are items caught in a parent cycle, so a
	 * damaged menu is still reported in full rather than silently shrinking.
	 *
	 * @param array<int, array<string, mixed>> $items Flat items in menu order.
	 *
	 * @return array<int, array<string, mixed>> The nested tree.
	 */
	public static function to_tree( array $items ): array {
		$by_id    = array();
		$children = array();

		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		foreach ( $items as $item ) {
			$parent = (int) $item['parent'];

			if ( $parent > 0 && isset( $by_id[ $parent ] ) ) {
				$children[ $parent ][] = (int) $item['id'];
				continue;
			}

			$children[0][] = (int) $item['id'];
		}

		$seen = array();

		$build = static function ( int $parent ) use ( &$build, $by_id, $children, &$seen ): array {
			$branch = array();

			foreach ( $children[ $parent ] ?? array() as $id ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}

				$seen[ $id ]      = true;
				$node             = $by_id[ $id ];
				$node['children'] = $build( $id );
				$branch[]         = $node;
			}

			return $branch;
		};

		$tree = $build( 0 );

		// Anything left over is in a parent cycle, which only happens on damaged
		// data. Surface it at the top level instead of losing it.
		foreach ( $items as $item ) {
			$id = (int) $item['id'];

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ]      = true;
			$item['children'] = $build( $id );
			$tree[]           = $item;
		}

		return $tree;
	}

	/**
	 * Resolves a menu item and checks it belongs to the expected menu.
	 *
	 * @param mixed $item_id Menu item ID.
	 * @param int   $menu_id Menu ID the item must belong to, or 0 to skip the check.
	 *
	 * @return \WP_Post|\WP_Error The item post, or WP_Error.
	 */
	public static function get_item( $item_id, int $menu_id = 0 ) {
		$item_id = is_numeric( $item_id ) ? (int) $item_id : 0;

		if ( $item_id <= 0 ) {
			return new WP_Error( 'invalid_item_id', 'A positive integer menu item id is required.' );
		}

		$item = get_post( $item_id );

		if ( ! $item instanceof WP_Post || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error( 'item_not_found', sprintf( 'No menu item found with ID %d.', $item_id ) );
		}

		$menus = wp_get_post_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
		$menus = is_array( $menus ) ? array_map( 'intval', $menus ) : array();

		if ( $menu_id > 0 && ! in_array( $menu_id, $menus, true ) ) {
			return new WP_Error(
				'item_not_in_menu',
				sprintf( 'Menu item %d does not belong to menu %d.', $item_id, $menu_id )
			);
		}

		return $item;
	}

	/**
	 * Returns the menu a given item belongs to.
	 *
	 * @param int $item_id Menu item ID.
	 *
	 * @return int Menu term ID, or 0 when the item is orphaned.
	 */
	public static function menu_id_for_item( int $item_id ): int {
		$menus = wp_get_post_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );

		if ( ! is_array( $menus ) || empty( $menus ) ) {
			return 0;
		}

		return (int) $menus[0];
	}

	/**
	 * Builds the `menu-item-*` argument array core's updater expects.
	 *
	 * `wp_update_nav_menu_item()` fills every key it was not given with an empty
	 * default and then writes all of them, so calling it with a partial payload
	 * silently erases the fields that were left out. Passing `$existing` here
	 * turns that into the partial update a client expects: anything absent from
	 * the input keeps the value the item already has.
	 *
	 * @param array      $input    Ability input, using the friendly key names.
	 * @param array|null $existing Existing formatted item when updating, null when creating.
	 *
	 * @return array<string, mixed>|\WP_Error The arguments, or WP_Error when the input is unusable.
	 */
	public static function build_item_args( array $input, ?array $existing = null ) {
		$is_update = null !== $existing;

		$type = isset( $input['type'] ) ? (string) $input['type'] : '';

		if ( '' === $type ) {
			$type = $is_update ? (string) $existing['type'] : self::infer_type( $input );
		}

		if ( ! in_array( $type, self::ITEM_TYPES, true ) ) {
			return new WP_Error(
				'invalid_item_type',
				sprintf( 'Menu item type "%s" is not valid. Allowed: %s.', $type, implode( ', ', self::ITEM_TYPES ) )
			);
		}

		$object    = isset( $input['object'] ) ? (string) $input['object'] : ( $is_update ? (string) $existing['object'] : '' );
		$object_id = isset( $input['object_id'] ) ? (int) $input['object_id'] : ( $is_update ? (int) $existing['object_id'] : 0 );
		$url       = isset( $input['url'] ) ? (string) $input['url'] : ( $is_update ? (string) $existing['url'] : '' );
		$title     = isset( $input['title'] ) ? (string) $input['title'] : ( $is_update ? (string) $existing['title'] : '' );

		$target = isset( $input['target'] ) ? (string) $input['target'] : ( $is_update ? (string) $existing['target'] : '' );

		if ( ! in_array( $target, self::ITEM_TARGETS, true ) ) {
			return new WP_Error(
				'invalid_target',
				'target must be an empty string (same tab) or "_blank" (new tab).'
			);
		}

		$validated = self::validate_item_target( $type, $object, $object_id, $url );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$object = $validated['object'];
		$url    = $validated['url'];

		// A post or term item with no explicit title inherits the object's own
		// title, which is what the admin does when you tick a page and add it.
		if ( 'custom' === $type && '' === $title ) {
			return new WP_Error( 'missing_title', 'A custom link menu item needs a title.' );
		}

		$classes = self::normalize_classes(
			$input['classes'] ?? ( $is_update ? $existing['classes'] : array() )
		);

		return array(
			'menu-item-object-id'   => $object_id,
			'menu-item-object'      => $object,
			'menu-item-type'        => $type,
			'menu-item-title'       => $title,
			'menu-item-url'         => $url,
			'menu-item-description' => isset( $input['description'] ) ? (string) $input['description'] : ( $is_update ? (string) $existing['description'] : '' ),
			'menu-item-attr-title'  => isset( $input['attr_title'] ) ? (string) $input['attr_title'] : ( $is_update ? (string) $existing['attr_title'] : '' ),
			'menu-item-target'      => $target,
			'menu-item-classes'     => $classes,
			'menu-item-xfn'         => isset( $input['xfn'] ) ? (string) $input['xfn'] : ( $is_update ? (string) $existing['xfn'] : '' ),
			// Core writes a draft for anything that is not explicitly published,
			// and a draft item never renders on the front end.
			'menu-item-status'      => 'publish',
		);
	}

	/**
	 * Guesses the item type from the fields that were supplied.
	 *
	 * @param array $input Ability input.
	 *
	 * @return string The inferred type.
	 */
	private static function infer_type( array $input ): string {
		$object    = isset( $input['object'] ) ? (string) $input['object'] : '';
		$object_id = isset( $input['object_id'] ) ? (int) $input['object_id'] : 0;

		if ( $object_id > 0 ) {
			return taxonomy_exists( $object ) ? 'taxonomy' : 'post_type';
		}

		if ( '' !== $object && post_type_exists( $object ) ) {
			return 'post_type_archive';
		}

		return 'custom';
	}

	/**
	 * Checks that a menu item points at something that exists.
	 *
	 * Catching this here is the difference between "post 4210 does not exist"
	 * and a menu entry that silently renders as a link to the home page.
	 *
	 * @param string $type      Item type.
	 * @param string $object    Post type or taxonomy slug.
	 * @param int    $object_id Post or term ID.
	 * @param string $url       Custom URL.
	 *
	 * @return array{object: string, url: string}|\WP_Error Normalized target, or WP_Error.
	 */
	private static function validate_item_target( string $type, string $object, int $object_id, string $url ) {
		switch ( $type ) {
			case 'custom':
				$url = trim( $url );

				if ( '' === $url ) {
					return new WP_Error( 'missing_url', 'A custom link menu item needs a url.' );
				}

				$safe = esc_url_raw( $url );

				if ( '' === $safe ) {
					return new WP_Error( 'invalid_url', sprintf( 'The url "%s" is not a valid link target.', $url ) );
				}

				return array(
					'object' => 'custom',
					'url'    => $safe,
				);

			case 'post_type':
				$post = $object_id > 0 ? get_post( $object_id ) : null;

				if ( ! $post instanceof WP_Post ) {
					return new WP_Error(
						'item_object_not_found',
						sprintf( 'No post found with ID %d to link from the menu.', $object_id )
					);
				}

				if ( '' !== $object && $object !== $post->post_type ) {
					return new WP_Error(
						'item_object_mismatch',
						sprintf( 'Post %d is a "%s", not a "%s".', $object_id, $post->post_type, $object )
					);
				}

				return array(
					'object' => $post->post_type,
					'url'    => '',
				);

			case 'taxonomy':
				$term = $object_id > 0 ? get_term( $object_id ) : null;

				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error(
						'item_object_not_found',
						sprintf( 'No term found with ID %d to link from the menu.', $object_id )
					);
				}

				if ( '' !== $object && $object !== $term->taxonomy ) {
					return new WP_Error(
						'item_object_mismatch',
						sprintf( 'Term %d belongs to "%s", not to "%s".', $object_id, $term->taxonomy, $object )
					);
				}

				return array(
					'object' => $term->taxonomy,
					'url'    => '',
				);

			case 'post_type_archive':
			default:
				if ( ! post_type_exists( $object ) ) {
					return new WP_Error(
						'invalid_post_type',
						sprintf( 'Post type "%s" is not registered, so it has no archive to link to.', $object )
					);
				}

				$post_type = get_post_type_object( $object );

				if ( ! $post_type || empty( $post_type->has_archive ) ) {
					return new WP_Error(
						'no_post_type_archive',
						sprintf( 'Post type "%s" has no archive page to link to.', $object )
					);
				}

				return array(
					'object' => $object,
					'url'    => '',
				);
		}
	}

	/**
	 * Whether the object a menu item points at still exists.
	 *
	 * @param string $type      Item type.
	 * @param string $object    Post type or taxonomy slug.
	 * @param int    $object_id Post or term ID.
	 *
	 * @return bool True when the target resolves.
	 */
	private static function object_exists( string $type, string $object, int $object_id ): bool {
		if ( 'post_type' === $type ) {
			return get_post( $object_id ) instanceof WP_Post;
		}

		if ( 'taxonomy' === $type ) {
			$term = get_term( $object_id, $object );

			return $term && ! is_wp_error( $term );
		}

		return true;
	}

	/**
	 * Normalizes CSS classes into the space-separated string core stores.
	 *
	 * @param mixed $classes Array of classes or a space-separated string.
	 *
	 * @return string The normalized class string.
	 */
	private static function normalize_classes( $classes ): string {
		if ( is_string( $classes ) ) {
			$classes = preg_split( '/\s+/', $classes ) ?: array();
		}

		if ( ! is_array( $classes ) ) {
			return '';
		}

		$clean = array_filter( array_map( 'sanitize_html_class', array_map( 'strval', $classes ) ) );

		return implode( ' ', array_unique( $clean ) );
	}

	/**
	 * Writes a menu item, creating it when `$item_id` is 0.
	 *
	 * Core reads a `menu-item-position` of 0 as "put this at the end", which is
	 * right for a new item and wrong for an edit that never mentioned position.
	 * An existing item therefore keeps its own `menu_order` unless the caller
	 * asked for a different one.
	 *
	 * @param int      $menu_id Menu term ID.
	 * @param int      $item_id Existing item ID, or 0 to create.
	 * @param array    $args    Arguments from {@see self::build_item_args()}.
	 * @param int      $parent  Parent item ID.
	 * @param int|null $order   Explicit `menu_order`, or null to keep or append.
	 *
	 * @return int|\WP_Error The item ID, or WP_Error.
	 */
	public static function write_item( int $menu_id, int $item_id, array $args, int $parent = 0, ?int $order = null ) {
		$args['menu-item-parent-id'] = $parent;

		if ( null === $order && $item_id > 0 ) {
			$existing = get_post( $item_id );
			$order    = $existing instanceof WP_Post ? (int) $existing->menu_order : null;
		}

		if ( null !== $order && $order > 0 ) {
			$args['menu-item-position'] = $order;
		}

		$result = wp_update_nav_menu_item( $menu_id, $item_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = (int) $result;

		if ( $result <= 0 ) {
			return new WP_Error( 'item_write_failed', 'WordPress did not return a menu item ID for the write.' );
		}

		return $result;
	}

	/**
	 * Validates a parent item for a given menu.
	 *
	 * @param mixed $parent  Parent item ID, or 0 for a top level item.
	 * @param int   $menu_id Menu term ID.
	 * @param int   $item_id The item being moved, when updating.
	 *
	 * @return int|\WP_Error The parent ID, or WP_Error.
	 */
	public static function validate_parent( $parent, int $menu_id, int $item_id = 0 ) {
		$parent = is_numeric( $parent ) ? (int) $parent : 0;

		if ( $parent <= 0 ) {
			return 0;
		}

		if ( $parent === $item_id ) {
			return new WP_Error( 'invalid_parent', 'A menu item cannot be its own parent.' );
		}

		$parent_item = self::get_item( $parent, $menu_id );

		if ( is_wp_error( $parent_item ) ) {
			return $parent_item;
		}

		if ( $item_id > 0 && in_array( $item_id, self::ancestor_ids( $parent, $menu_id ), true ) ) {
			return new WP_Error(
				'invalid_parent',
				sprintf( 'Menu item %d cannot be moved under %d, which is one of its own descendants.', $item_id, $parent )
			);
		}

		return $parent;
	}

	/**
	 * Returns the ancestors of a menu item, nearest first.
	 *
	 * @param int $item_id Menu item ID.
	 * @param int $menu_id Menu term ID.
	 *
	 * @return int[] Ancestor item IDs.
	 */
	private static function ancestor_ids( int $item_id, int $menu_id ): array {
		$parents = array();
		$current = $item_id;

		// Bounded by the item count, so a corrupted parent cycle cannot hang.
		for ( $depth = 0; $depth < self::MAX_ITEMS; $depth++ ) {
			$parent = (int) get_post_meta( $current, '_menu_item_menu_item_parent', true );

			if ( $parent <= 0 || in_array( $parent, $parents, true ) ) {
				break;
			}

			$parents[] = $parent;
			$current   = $parent;
		}

		unset( $menu_id );

		return $parents;
	}

	/**
	 * Returns the IDs of an item and everything nested beneath it.
	 *
	 * @param int                              $item_id Menu item ID.
	 * @param array<int, array<string, mixed>> $items   Flat item list for the menu.
	 *
	 * @return int[] The item and its descendants.
	 */
	public static function descendant_ids( int $item_id, array $items ): array {
		$found = array( $item_id );

		// Repeat until no new children are picked up, which handles children
		// stored before their parents in menu order.
		do {
			$added = false;

			foreach ( $items as $item ) {
				$id = (int) $item['id'];

				if ( in_array( $id, $found, true ) ) {
					continue;
				}

				if ( in_array( (int) $item['parent'], $found, true ) ) {
					$found[] = $id;
					$added   = true;
				}
			}
		} while ( $added );

		return $found;
	}

	/**
	 * Re-points the children of an item at a new parent.
	 *
	 * @param int $item_id    The item whose children move.
	 * @param int $new_parent The new parent item ID.
	 *
	 * @return int Number of children moved.
	 */
	public static function reparent_children( int $item_id, int $new_parent ): int {
		$menu_id = self::menu_id_for_item( $item_id );

		if ( $menu_id <= 0 ) {
			return 0;
		}

		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu instanceof WP_Term ) {
			return 0;
		}

		$moved = 0;

		foreach ( self::menu_items( $menu ) as $item ) {
			if ( (int) $item['parent'] !== $item_id ) {
				continue;
			}

			update_post_meta( (int) $item['id'], '_menu_item_menu_item_parent', (string) $new_parent );
			++$moved;
		}

		return $moved;
	}

	/**
	 * Renumbers a menu so `menu_order` runs 1..n with no gaps.
	 *
	 * Menu order is global to a menu rather than per branch, so an item is only
	 * rendered under its parent when its order also falls after it. Rewriting
	 * the whole run in tree order is what keeps a reordered menu looking like
	 * the tree that was submitted.
	 *
	 * @param int[] $ordered_ids Item IDs in their intended order.
	 *
	 * @return void
	 */
	public static function apply_order( array $ordered_ids ): void {
		$position = 0;

		foreach ( $ordered_ids as $item_id ) {
			++$position;

			$item_id = (int) $item_id;
			$current = get_post( $item_id, ARRAY_A );

			if ( ! is_array( $current ) || (int) $current['menu_order'] === $position ) {
				continue;
			}

			$current['menu_order'] = $position;

			// wp_update_post() expects slashed data and merges what it is given
			// over the unslashed row it reads back, so a description holding a
			// backslash would lose it on every reorder without this.
			wp_update_post( wp_slash( $current ) );
		}
	}

	/**
	 * Flattens a formatted item tree into the depth-first order menus render in.
	 *
	 * @param array<int, array<string, mixed>> $tree Nested items.
	 *
	 * @return int[] Item IDs in tree order.
	 */
	public static function tree_order( array $tree ): array {
		$order = array();

		foreach ( $tree as $node ) {
			$order[] = (int) $node['id'];

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$order = array_merge( $order, self::tree_order( $node['children'] ) );
			}
		}

		return $order;
	}

	/**
	 * Places an item at a 1-based position within its siblings and renumbers.
	 *
	 * @param \WP_Term $menu     Menu term.
	 * @param int      $item_id  The item being placed.
	 * @param int      $parent   Its parent item ID.
	 * @param int|null $position 1-based position among its siblings, or null to append.
	 *
	 * @return void
	 */
	public static function place_item( WP_Term $menu, int $item_id, int $parent, ?int $position ): void {
		$items = self::menu_items( $menu );
		$tree  = self::to_tree( $items );

		if ( null === $position ) {
			self::apply_order( self::tree_order( $tree ) );

			return;
		}

		$reordered = self::move_within_tree( $tree, $item_id, $parent, max( 1, $position ) );

		self::apply_order( self::tree_order( $reordered ) );
	}

	/**
	 * Moves a node to a position among the children of a given parent.
	 *
	 * @param array<int, array<string, mixed>> $tree     Nested items.
	 * @param int                              $item_id  Node to move.
	 * @param int                              $parent   Target parent ID, 0 for top level.
	 * @param int                              $position 1-based target position.
	 *
	 * @return array<int, array<string, mixed>> The rearranged tree.
	 */
	private static function move_within_tree( array $tree, int $item_id, int $parent, int $position ): array {
		$node = null;

		$extract = static function ( array $branch ) use ( &$extract, $item_id, &$node ): array {
			$kept = array();

			foreach ( $branch as $child ) {
				if ( (int) $child['id'] === $item_id ) {
					$node = $child;
					continue;
				}

				$child['children'] = $extract( $child['children'] ?? array() );
				$kept[]            = $child;
			}

			return $kept;
		};

		$tree = $extract( $tree );

		if ( null === $node ) {
			return $tree;
		}

		$insert = static function ( array $branch ) use ( &$insert, $parent, $position, $node ): array {
			$result = array();

			foreach ( $branch as $child ) {
				if ( (int) $child['id'] === $parent ) {
					$children = $child['children'] ?? array();
					array_splice( $children, min( $position - 1, count( $children ) ), 0, array( $node ) );
					$child['children'] = $children;
				} else {
					$child['children'] = $insert( $child['children'] ?? array() );
				}

				$result[] = $child;
			}

			return $result;
		};

		if ( 0 === $parent ) {
			array_splice( $tree, min( $position - 1, count( $tree ) ), 0, array( $node ) );

			return $tree;
		}

		return $insert( $tree );
	}

	/**
	 * Returns the theme locations registered by the active theme.
	 *
	 * @return array<string, string> Description keyed by location slug.
	 */
	public static function registered_locations(): array {
		$locations = get_registered_nav_menus();

		return is_array( $locations ) ? array_map( 'strval', $locations ) : array();
	}

	/**
	 * Returns the stored location-to-menu map, unfiltered.
	 *
	 * `get_nav_menu_locations()` runs through `theme_mod_nav_menu_locations`,
	 * which Polylang and other plugins hook to swap in a per-language menu for
	 * the current request. Reading the raw theme mod is what tells a client what
	 * is actually stored, rather than what this particular request would render.
	 *
	 * @return array<string, int> Menu ID keyed by location slug.
	 */
	public static function stored_locations(): array {
		$mods = get_option( 'theme_mods_' . get_stylesheet(), array() );

		if ( ! is_array( $mods ) || empty( $mods['nav_menu_locations'] ) || ! is_array( $mods['nav_menu_locations'] ) ) {
			return array();
		}

		return array_map( 'intval', $mods['nav_menu_locations'] );
	}

	/**
	 * Returns the effective location map for the current request.
	 *
	 * @return array<string, int> Menu ID keyed by location slug.
	 */
	public static function effective_locations(): array {
		$locations = get_nav_menu_locations();

		return is_array( $locations ) ? array_map( 'intval', $locations ) : array();
	}

	/**
	 * Lists the locations a given menu is assigned to.
	 *
	 * @param int $menu_id Menu term ID.
	 *
	 * @return string[] Location slugs.
	 */
	public static function locations_for_menu( int $menu_id ): array {
		$slugs = array();

		foreach ( self::stored_locations() as $location => $assigned ) {
			if ( (int) $assigned === $menu_id ) {
				$slugs[] = (string) $location;
			}
		}

		foreach ( self::polylang_locations() as $location => $languages ) {
			foreach ( $languages as $assigned ) {
				if ( (int) $assigned === $menu_id && ! in_array( (string) $location, $slugs, true ) ) {
					$slugs[] = (string) $location;
				}
			}
		}

		return $slugs;
	}

	/**
	 * Assigns menus to theme locations.
	 *
	 * @param array<string, int> $assignments Menu ID keyed by location slug; 0 clears the location.
	 *
	 * @return array<string, int>|\WP_Error The stored map, or WP_Error.
	 */
	public static function assign_locations( array $assignments ) {
		$registered = self::registered_locations();
		$locations  = self::stored_locations();

		foreach ( $assignments as $location => $menu_id ) {
			$location = (string) $location;

			if ( ! isset( $registered[ $location ] ) ) {
				return new WP_Error(
					'unknown_location',
					sprintf(
						'The active theme does not register a menu location called "%s". Available: %s.',
						$location,
						$registered ? implode( ', ', array_keys( $registered ) ) : 'none'
					)
				);
			}

			$menu_id = is_numeric( $menu_id ) ? (int) $menu_id : 0;

			if ( $menu_id <= 0 ) {
				unset( $locations[ $location ] );
				continue;
			}

			$menu = self::get_menu( $menu_id );

			if ( is_wp_error( $menu ) ) {
				return $menu;
			}

			$locations[ $location ] = (int) $menu->term_id;
		}

		set_theme_mod( 'nav_menu_locations', $locations );

		return $locations;
	}

	/**
	 * Returns Polylang's per-language location map for the active theme.
	 *
	 * Polylang keeps a menu per language for each location, in its own option,
	 * and only mirrors one of them into the theme mod. Reporting both means a
	 * client on a multilingual site can see why a location appears to hold a
	 * menu it did not assign.
	 *
	 * @return array<string, array<string, int>> Menu ID keyed by location, then language slug.
	 */
	public static function polylang_locations(): array {
		if ( ! PolylangSupport::is_active() ) {
			return array();
		}

		$options = get_option( 'polylang', array() );

		if ( ! is_array( $options ) || empty( $options['nav_menus'] ) || ! is_array( $options['nav_menus'] ) ) {
			return array();
		}

		$theme = get_stylesheet();

		if ( empty( $options['nav_menus'][ $theme ] ) || ! is_array( $options['nav_menus'][ $theme ] ) ) {
			return array();
		}

		$map = array();

		foreach ( $options['nav_menus'][ $theme ] as $location => $languages ) {
			if ( ! is_array( $languages ) ) {
				continue;
			}

			$map[ (string) $location ] = array_map( 'intval', $languages );
		}

		return $map;
	}

	/**
	 * Assigns a menu to a location for one Polylang language.
	 *
	 * @param string $location Location slug.
	 * @param string $language Language slug.
	 * @param int    $menu_id  Menu term ID; 0 clears the assignment.
	 *
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	public static function assign_polylang_location( string $location, string $language, int $menu_id ) {
		if ( ! PolylangSupport::is_active() ) {
			return new WP_Error(
				'polylang_not_active',
				'Polylang is not active, so menu locations cannot be assigned per language.'
			);
		}

		$valid = PolylangSupport::validate_language( $language );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$options = get_option( 'polylang', array() );

		if ( ! is_array( $options ) ) {
			return new WP_Error( 'polylang_options_unreadable', 'The Polylang options could not be read.' );
		}

		$theme = get_stylesheet();

		if ( ! isset( $options['nav_menus'] ) || ! is_array( $options['nav_menus'] ) ) {
			$options['nav_menus'] = array();
		}

		if ( ! isset( $options['nav_menus'][ $theme ] ) || ! is_array( $options['nav_menus'][ $theme ] ) ) {
			$options['nav_menus'][ $theme ] = array();
		}

		if ( ! isset( $options['nav_menus'][ $theme ][ $location ] ) || ! is_array( $options['nav_menus'][ $theme ][ $location ] ) ) {
			$options['nav_menus'][ $theme ][ $location ] = array();
		}

		if ( $menu_id > 0 ) {
			$options['nav_menus'][ $theme ][ $location ][ $language ] = $menu_id;
		} else {
			unset( $options['nav_menus'][ $theme ][ $location ][ $language ] );
		}

		update_option( 'polylang', $options );

		return true;
	}

	/**
	 * Returns the Polylang language of a menu, derived from its assignments.
	 *
	 * Polylang does not translate the `nav_menu` taxonomy; a menu is tied to a
	 * language by being assigned to a location for that language. This reports
	 * that relationship rather than inventing a language property.
	 *
	 * @param int $menu_id Menu term ID.
	 *
	 * @return array<string, mixed> Language information for the menu.
	 */
	public static function menu_language( int $menu_id ): array {
		$languages = array();

		foreach ( self::polylang_locations() as $location => $per_language ) {
			foreach ( $per_language as $language => $assigned ) {
				if ( (int) $assigned !== $menu_id ) {
					continue;
				}

				$languages[ (string) $language ][] = (string) $location;
			}
		}

		return array(
			'assigned_languages' => array_keys( $languages ),
			'by_language'        => $languages,
		);
	}

	/**
	 * Lists the Navigation block menus a block theme uses.
	 *
	 * These are ordinary `wp_navigation` posts holding block markup, so the
	 * content tools already read and write them. Listing them here is what stops
	 * a client on a block theme concluding the site has no menus at all.
	 *
	 * @return array<int, array<string, mixed>> Navigation posts.
	 */
	public static function navigation_posts(): array {
		if ( ! post_type_exists( 'wp_navigation' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => 50,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		return array_map(
			static fn( WP_Post $post ): array => array(
				'id'     => (int) $post->ID,
				'title'  => $post->post_title,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
			),
			is_array( $posts ) ? $posts : array()
		);
	}
}
