<?php
/**
 * Ability for reading a single user.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Users;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\UserSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get User - Reads one user account.
 */
final class GetUserAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'get-user',
			array(
				'label'               => 'Get User',
				'description'         => 'Read one user account in full, by ID, login, email or slug. Optionally includes their public user meta and the capabilities their roles grant. Requires the "list_users" capability. Passwords are never returned.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'              => array(
							'type'        => 'integer',
							'description' => 'The user ID. One of user_id, login, email or slug is required.',
						),
						'login'                => array(
							'type'        => 'string',
							'description' => 'The username. Used when user_id is omitted.',
						),
						'email'                => array(
							'type'        => 'string',
							'description' => 'The email address. Used when user_id and login are omitted.',
						),
						'slug'                 => array(
							'type'        => 'string',
							'description' => 'The user slug (nicename). Used when the other identifiers are omitted.',
						),
						'include_meta'         => array(
							'type'        => 'boolean',
							'description' => 'Include public user meta. Default false.',
						),
						'include_capabilities' => array(
							'type'        => 'boolean',
							'description' => 'Include the capability list granted by the user\'s roles. Verbose. Default false.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'user'       => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The user.
	 */
	public static function execute( $input = array() ): array {
		$allowed = UserSupport::require_can_list();

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$user = UserSupport::resolve_user( is_array( $input ) ? $input : array() );

		if ( is_wp_error( $user ) ) {
			return AbilityRegistrar::failure( $user );
		}

		$formatted = UserSupport::format_user(
			$user,
			array( 'include_meta' => ContentSupport::to_bool( $input['include_meta'] ?? null, false ) )
		);

		if ( ContentSupport::to_bool( $input['include_capabilities'] ?? null, false ) ) {
			$formatted['capabilities'] = array_values( array_keys( array_filter( (array) $user->allcaps ) ) );
		}

		return array(
			'success' => true,
			'user'    => $formatted,
		);
	}
}
