<?php
/**
 * Ability for creating a user.
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
 * Create User - Adds a new user account.
 */
final class CreateUserAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'create-user',
			array(
				'label'               => 'Create User',
				'description'         => 'Create a new user account with a username, email address and role. The account is given a random password that is never returned; set `notify` to "user" or "both" to email the new user a link for setting their own, or call update-user later with send_password_reset. Requires the "create_users" capability, and setting a role also requires "promote_users".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'login'        => array(
							'type'        => 'string',
							'description' => 'The username. Required, and cannot be changed afterwards.',
						),
						'email'        => array(
							'type'        => 'string',
							'description' => 'The email address. Required, and must not already be in use.',
						),
						'role'         => array(
							'type'        => 'string',
							'description' => 'Role slug, e.g. "author". Defaults to the site\'s default role for new users.',
						),
						'first_name'   => array(
							'type'        => 'string',
							'description' => 'First name.',
						),
						'last_name'    => array(
							'type'        => 'string',
							'description' => 'Last name.',
						),
						'display_name' => array(
							'type'        => 'string',
							'description' => 'Publicly displayed name. Defaults to the username.',
						),
						'nickname'     => array(
							'type'        => 'string',
							'description' => 'Nickname. Defaults to the username.',
						),
						'slug'         => array(
							'type'        => 'string',
							'description' => 'URL slug for the author archive. Generated from the username when omitted.',
						),
						'url'          => array(
							'type'        => 'string',
							'description' => 'The user\'s website URL.',
						),
						'description'  => array(
							'type'        => 'string',
							'description' => 'Biographical description.',
						),
						'locale'       => array(
							'type'        => 'string',
							'description' => 'Admin locale for this user, e.g. "de_DE". Empty string means the site default.',
						),
						'notify'       => array(
							'type'        => 'string',
							'enum'        => array( 'none', 'user', 'admin', 'both' ),
							'description' => 'Who to email about the new account. "user" and "both" send the new user a link to set their password, which is how they sign in for the first time. Default "none", so no mail is sent unless it is asked for.',
						),
					),
					'required'   => array( 'login', 'email' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'user'       => array( 'type' => 'object' ),
						'notified'   => array( 'type' => 'string' ),
						'warnings'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The created user.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$allowed = UserSupport::require_can_create();

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$login = isset( $input['login'] ) ? sanitize_user( (string) $input['login'], true ) : '';
		$email = isset( $input['email'] ) ? (string) $input['email'] : '';

		$valid = self::validate_identity( $login, $email );

		if ( is_wp_error( $valid ) ) {
			return AbilityRegistrar::failure( $valid );
		}

		$args = UserSupport::build_profile_args( $input );

		$args['user_login'] = $login;
		$args['user_email'] = $email;

		// A password is required by wp_insert_user, so generate a strong one and
		// discard it. Nobody, including the caller, ever learns what it was; the
		// account is reached through the password-set link instead.
		$args['user_pass'] = wp_generate_password( 24, true, true );

		if ( isset( $input['role'] ) && '' !== $input['role'] ) {
			$role       = (string) $input['role'];
			$role_valid = UserSupport::validate_role( $role );

			if ( is_wp_error( $role_valid ) ) {
				return AbilityRegistrar::failure( $role_valid );
			}

			$args['role'] = $role;
		}

		$user_id = wp_insert_user( $args );

		if ( is_wp_error( $user_id ) ) {
			return AbilityRegistrar::failure( $user_id );
		}

		$user     = UserSupport::get_user( $user_id );
		$warnings = array();

		if ( is_wp_error( $user ) ) {
			return AbilityRegistrar::failure( $user );
		}

		$notify = self::resolve_notify( $input['notify'] ?? null );

		if ( 'none' !== $notify ) {
			// The account exists either way; a mail server problem is worth
			// reporting but is not a reason to claim the user was not created.
			$sent = self::notify( $user_id, $notify );

			if ( is_wp_error( $sent ) ) {
				$warnings[] = $sent->get_error_message();
			}
		}

		$result = array(
			'success'  => true,
			'user'     => UserSupport::format_user( $user ),
			'notified' => $notify,
		);

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	/**
	 * Checks the username and email before anything is written.
	 *
	 * @param string $login The sanitized username.
	 * @param string $email The email address.
	 *
	 * @return true|\WP_Error True when both are usable.
	 */
	private static function validate_identity( string $login, string $email ) {
		if ( '' === $login ) {
			return new WP_Error( 'invalid_login', 'A username is required, and must contain usable characters.' );
		}

		if ( ! validate_username( $login ) ) {
			return new WP_Error( 'invalid_login', sprintf( 'The username "%s" is not allowed on this site.', $login ) );
		}

		if ( username_exists( $login ) ) {
			return new WP_Error( 'login_exists', sprintf( 'The username "%s" is already taken.', $login ) );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.' );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'email_exists', sprintf( 'The email address "%s" already belongs to another user.', $email ) );
		}

		return true;
	}

	/**
	 * Normalizes the notify argument.
	 *
	 * @param mixed $requested Raw notify input.
	 *
	 * @return string One of none, user, admin, both.
	 */
	private static function resolve_notify( $requested ): string {
		$allowed = array( 'none', 'user', 'admin', 'both' );
		$value   = strtolower( (string) $requested );

		return in_array( $value, $allowed, true ) ? $value : 'none';
	}

	/**
	 * Sends the new user notification.
	 *
	 * @param int    $user_id The new user ID.
	 * @param string $notify  One of user, admin, both.
	 *
	 * @return true|\WP_Error True when WordPress accepted the mail.
	 */
	private static function notify( int $user_id, string $notify ) {
		if ( ! function_exists( 'wp_new_user_notification' ) ) {
			return new WP_Error( 'notification_unavailable', 'wp_new_user_notification() is not available on this site.' );
		}

		wp_new_user_notification( $user_id, null, $notify );

		return true;
	}
}
