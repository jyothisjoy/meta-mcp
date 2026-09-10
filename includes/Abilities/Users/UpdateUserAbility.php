<?php
/**
 * Ability for editing an existing user.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Users;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\UserSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update User - Edits a user's profile and role.
 */
final class UpdateUserAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-user',
			array(
				'label'               => 'Update User',
				'description'         => 'Edit an existing user\'s profile fields and role. Only the fields you pass are changed. The username cannot be changed, and no ability sets a password: use send_password_reset to email the account holder a link so they can set their own. Requires permission to edit that specific user, and changing a role also requires "promote_users".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'              => array(
							'type'        => 'integer',
							'description' => 'The ID of the user to update.',
						),
						'email'                => array(
							'type'        => 'string',
							'description' => 'New email address. Must not already belong to another user.',
						),
						'role'                 => array(
							'type'        => 'string',
							'description' => 'New role slug, replacing the user\'s existing roles. A user cannot change their own role.',
						),
						'first_name'           => array(
							'type'        => 'string',
							'description' => 'First name.',
						),
						'last_name'            => array(
							'type'        => 'string',
							'description' => 'Last name.',
						),
						'display_name'         => array(
							'type'        => 'string',
							'description' => 'Publicly displayed name.',
						),
						'nickname'             => array(
							'type'        => 'string',
							'description' => 'Nickname.',
						),
						'slug'                 => array(
							'type'        => 'string',
							'description' => 'URL slug for the author archive.',
						),
						'url'                  => array(
							'type'        => 'string',
							'description' => 'The user\'s website URL.',
						),
						'description'          => array(
							'type'        => 'string',
							'description' => 'Biographical description.',
						),
						'locale'               => array(
							'type'        => 'string',
							'description' => 'Admin locale for this user, e.g. "de_DE". Empty string means the site default.',
						),
						'send_password_reset'  => array(
							'type'        => 'boolean',
							'description' => 'Email the user a link to set a new password. Default false. This puts a message in a real person\'s inbox, so it only happens when asked for explicitly.',
						),
					),
					'required'   => array( 'user_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'user'            => array( 'type' => 'object' ),
						'applied'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'password_reset_sent' => array( 'type' => 'boolean' ),
						'warnings'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'error'           => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The updated user.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$user = UserSupport::require_can_edit( $input['user_id'] ?? 0 );

		if ( is_wp_error( $user ) ) {
			return AbilityRegistrar::failure( $user );
		}

		$args = UserSupport::build_profile_args( $input );

		if ( isset( $args['user_email'] ) ) {
			$email_valid = self::validate_email( (string) $args['user_email'], $user->ID );

			if ( is_wp_error( $email_valid ) ) {
				return AbilityRegistrar::failure( $email_valid );
			}
		}

		if ( isset( $input['role'] ) && '' !== $input['role'] ) {
			$role = (string) $input['role'];

			// Core's own profile screen refuses this for the same reason: an
			// administrator who demotes themselves by accident can lock the
			// site's last admin out of it.
			if ( get_current_user_id() === $user->ID ) {
				return AbilityRegistrar::failure(
					new WP_Error(
						'cannot_change_own_role',
						'A user cannot change their own role. Ask another administrator to make the change.'
					)
				);
			}

			$role_valid = UserSupport::validate_role( $role );

			if ( is_wp_error( $role_valid ) ) {
				return AbilityRegistrar::failure( $role_valid );
			}

			$args['role'] = $role;
		}

		$applied = array_keys( $args );

		if ( ! empty( $args ) ) {
			$args['ID'] = $user->ID;

			$updated = wp_update_user( $args );

			if ( is_wp_error( $updated ) ) {
				return AbilityRegistrar::failure( $updated );
			}
		}

		$fresh = UserSupport::get_user( $user->ID );

		if ( is_wp_error( $fresh ) ) {
			return AbilityRegistrar::failure( $fresh );
		}

		$warnings   = array();
		$reset_sent = false;

		if ( ContentSupport::to_bool( $input['send_password_reset'] ?? null, false ) ) {
			$sent = UserSupport::send_password_reset( $fresh );

			if ( is_wp_error( $sent ) ) {
				// The profile edit already succeeded, so report the mail failure
				// rather than pretending the whole call failed.
				$warnings[] = $sent->get_error_message();
			} else {
				$reset_sent = true;
			}
		}

		$result = array(
			'success'             => true,
			'user'                => UserSupport::format_user( $fresh ),
			'applied'             => $applied,
			'password_reset_sent' => $reset_sent,
		);

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	/**
	 * Checks that an email address is usable for this user.
	 *
	 * @param string $email   The requested address.
	 * @param int    $user_id The user being updated.
	 *
	 * @return true|\WP_Error True when the address can be used.
	 */
	private static function validate_email( string $email, int $user_id ) {
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.' );
		}

		$owner = email_exists( $email );

		if ( $owner && (int) $owner !== $user_id ) {
			return new WP_Error(
				'email_exists',
				sprintf( 'The email address "%s" already belongs to user %d.', $email, (int) $owner )
			);
		}

		return true;
	}
}
