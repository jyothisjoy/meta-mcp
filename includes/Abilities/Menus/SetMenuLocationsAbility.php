<?php
/**
 * Ability for assigning menus to theme locations.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Menus;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\MenuSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Menu Locations - Puts a menu into, or takes it out of, a theme slot.
 *
 * A menu that is not assigned to a location is invisible: it exists under
 * Appearance → Menus and appears nowhere on the site. This is the call that
 * makes one live.
 */
final class SetMenuLocationsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'set-menu-locations',
			array(
				'label'               => 'Set Menu Locations',
				'description'         => 'Assign menus to the theme\'s menu locations, which is what makes a menu appear on the site. Pass an object of location slug to menu ID, slug or name, e.g. {"primary": "Main Menu", "footer": 0}, where 0 empties a location. Locations not named are left alone. On a Polylang site, pass language to set the menu for one language only.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'locations' => array(
							'type'        => 'object',
							'description' => 'Object of theme location slug to a menu ID, slug or name. Use 0 or an empty string to clear a location.',
						),
						'language'  => array(
							'type'        => 'string',
							'description' => 'Polylang language slug. When given, the assignment is stored for that language instead of site-wide. Ignored when Polylang is not active.',
						),
					),
					'required'   => array( 'locations' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'locations'  => array( 'type' => 'object' ),
						'language'   => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The resulting location map.
	 */
	public static function execute( $input = array() ): array {
		$permitted = MenuSupport::require_can_manage();

		if ( is_wp_error( $permitted ) ) {
			return AbilityRegistrar::failure( $permitted );
		}

		if ( empty( $input['locations'] ) || ! is_array( $input['locations'] ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'missing_locations', 'locations must be an object of location slug to menu.' )
			);
		}

		$resolved = self::resolve( $input['locations'] );

		if ( is_wp_error( $resolved ) ) {
			return AbilityRegistrar::failure( $resolved );
		}

		$language = isset( $input['language'] ) ? (string) $input['language'] : '';

		if ( '' !== $language && PolylangSupport::is_active() ) {
			foreach ( $resolved as $location => $menu_id ) {
				$assigned = MenuSupport::assign_polylang_location( (string) $location, $language, (int) $menu_id );

				if ( is_wp_error( $assigned ) ) {
					return AbilityRegistrar::failure( $assigned );
				}
			}
		}

		// The theme mod is written in every case: Polylang reads it as the
		// fallback for a location that has no entry for the current language,
		// and on a single language site it is the only place assignments live.
		$stored = MenuSupport::assign_locations( $resolved );

		if ( is_wp_error( $stored ) ) {
			return AbilityRegistrar::failure( $stored );
		}

		return array(
			'success'   => true,
			'locations' => $stored,
			'language'  => PolylangSupport::is_active() ? $language : '',
		);
	}

	/**
	 * Turns menu identifiers into menu IDs.
	 *
	 * @param array $locations Location slug to menu ID, slug or name.
	 *
	 * @return array<string, int>|\WP_Error Menu IDs keyed by location, or WP_Error.
	 */
	private static function resolve( array $locations ) {
		$resolved = array();

		foreach ( $locations as $location => $identifier ) {
			$location = (string) $location;

			if ( null === $identifier || '' === $identifier || ( is_numeric( $identifier ) && 0 === (int) $identifier ) ) {
				$resolved[ $location ] = 0;
				continue;
			}

			$menu = MenuSupport::get_menu( $identifier );

			if ( is_wp_error( $menu ) ) {
				return $menu;
			}

			$resolved[ $location ] = (int) $menu->term_id;
		}

		return $resolved;
	}
}
