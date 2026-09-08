<?php
/**
 * Ability for reading a single navigation menu and its items.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Menus;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\MenuSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Menu - The full structure of one navigation menu.
 */
final class GetMenuAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'get-menu',
			array(
				'label'               => 'Get Navigation Menu',
				'description'         => 'Read one navigation menu and its items. The menu can be given by ID, slug or name. Items come back as a nested tree by default, each with its item id, the page, post, term or custom URL it points to, and whether that target still exists. Use format "flat" when you are about to move items around and need parent ids and positions.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu'   => array(
							'type'        => 'string',
							'description' => 'Menu ID, slug or name.',
						),
						'format' => array(
							'type'        => 'string',
							'enum'        => array( 'tree', 'flat', 'both' ),
							'description' => 'Shape of the item list. "tree" nests children, "flat" lists every item with its parent and position, "both" returns each. Default "tree".',
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
	 * @return array<string, mixed> The menu and its items.
	 */
	public static function execute( $input = array() ): array {
		$supported = MenuSupport::require_readable();

		if ( is_wp_error( $supported ) ) {
			return AbilityRegistrar::failure( $supported );
		}

		$menu = MenuSupport::get_menu( $input['menu'] ?? null );

		if ( is_wp_error( $menu ) ) {
			return AbilityRegistrar::failure( $menu );
		}

		$format = isset( $input['format'] ) ? (string) $input['format'] : 'tree';
		$format = in_array( $format, array( 'tree', 'flat', 'both' ), true ) ? $format : 'tree';

		return array(
			'success' => true,
			'menu'    => MenuSupport::format_menu(
				$menu,
				array(
					'include_items' => 'flat' !== $format,
					'include_flat'  => 'tree' !== $format,
				)
			),
		);
	}
}
