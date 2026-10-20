<?php
/**
 * Ability for deleting a user.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Users;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\UserSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Delete User - Removes a user account.
 */
final class DeleteUserAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'delete-user',
			array(
				'label'               => 'Delete User',
				'description'         => 'Delete a user account. There is no trash for users, so this cannot be undone. Pass reassign with another user\'s ID to move the deleted user\'s posts and links to them; without it, everything they authored is deleted too. Requires permission to delete that specific user, and a user cannot delete their own account.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'  => array(
							'type'        => 'integer',
							'description' => 'The ID of the user to delete.',
						),
						'reassign' => array(
							'type'        => 'integer',
							'description' => 'User ID to inherit the deleted user\'s content. Omit, or pass 0, to delete their content along with the account.',
						),
						'confirm'  => array(
							'type'        => 'boolean',
							'description' => 'Must be true. A deliberate second step, because deleting a user is immediate and irreversible.',
						),
					),
					'required'   => array( 'user_id', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'deleted'         => array( 'type' => 'integer' ),
						'login'           => array( 'type' => 'string' ),
						'reassigned_to'   => array( 'type' => 'integer' ),
						'content_deleted' => array( 'type' => 'boolean' ),
						'network_note'    => array( 'type' => 'string' ),
						'error'           => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'destructive'         => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The result.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		if ( empty( $input['confirm'] ) ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'confirmation_required',
					'Deleting a user is immediate and cannot be undone. Pass confirm: true to proceed.'
				)
			);
		}

		$user = UserSupport::require_can_delete( $input['user_id'] ?? 0 );

		if ( is_wp_error( $user ) ) {
			return AbilityRegistrar::failure( $user );
		}

		$reassign = isset( $input['reassign'] ) ? (int) $input['reassign'] : 0;

		if ( $reassign > 0 ) {
			$valid = self::validate_reassign( $reassign, $user->ID );

			if ( is_wp_error( $valid ) ) {
				return AbilityRegistrar::failure( $valid );
			}
		}

		// wp_delete_user() lives in the admin includes, which are not loaded on
		// a REST request.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$login   = $user->user_login;
		$user_id = $user->ID;

		$deleted = wp_delete_user( $user_id, $reassign > 0 ? $reassign : null );

		if ( ! $deleted ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'delete_failed', sprintf( 'WordPress could not delete user %d.', $user_id ) )
			);
		}

		$result = array(
			'success'         => true,
			'deleted'         => $user_id,
			'login'           => $login,
			'reassigned_to'   => $reassign > 0 ? $reassign : 0,
			'content_deleted' => 0 === $reassign,
		);

		if ( is_multisite() ) {
			// wp_delete_user() is per-site on a network: the account itself
			// survives elsewhere. Saying so avoids a false "the user is gone".
			$result['network_note'] = 'This is a multisite network, so the user was removed from this site only. Their network account still exists and must be deleted from the network admin.';
		}

		return $result;
	}

	/**
	 * Checks that content can be reassigned to the given user.
	 *
	 * @param int $reassign The inheriting user ID.
	 * @param int $user_id  The user being deleted.
	 *
	 * @return true|\WP_Error True when the reassignment target is usable.
	 */
	private static function validate_reassign( int $reassign, int $user_id ) {
		if ( $reassign === $user_id ) {
			return new WP_Error(
				'invalid_reassign',
				'Content cannot be reassigned to the user being deleted.'
			);
		}

		$target = UserSupport::get_user( $reassign );

		if ( is_wp_error( $target ) ) {
			return new WP_Error(
				'invalid_reassign',
				sprintf( 'No user found with ID %d to reassign content to.', $reassign )
			);
		}

		return true;
	}
}
