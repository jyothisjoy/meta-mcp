<?php
/**
 * Shared helpers for the user abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - UserSupport
 *
 * Capability checks, lookup and formatting for the user abilities.
 *
 * Two rules run through the whole of this class and are worth stating once.
 *
 * First, no ability ever accepts or returns a password. `user_pass` and
 * `user_activation_key` are stripped from every response, and creating a user
 * generates a random password that is thrown away rather than handed back. An
 * MCP transcript is not a safe place for a credential, and a caller that could
 * set one could also set a known one on somebody else's account. Sign-in is
 * arranged instead by sending the account holder a password-set link, which
 * only ever reaches their own inbox.
 *
 * Second, editing people is not editing content. Every write here is gated on
 * the real WordPress capability for the specific user being touched, on top of
 * the site-wide switch, and the handful of things core's own user screens
 * refuse to do — deleting yourself, changing your own role, an ordinary admin
 * touching a super admin — are refused here too.
 */
final class UserSupport {

	/**
	 * Maximum number of users returned in one call.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * User fields that must never leave the site.
	 *
	 * @var string[]
	 */
	private const SECRET_FIELDS = array( 'user_pass', 'user_activation_key' );

	/**
	 * Whether the user abilities are switched on for this site.
	 *
	 * Registration is decided separately, in ContentAbilities; this is the
	 * run-time guard, so a site that turns the tools off after a client has
	 * already listed them gets a clear refusal rather than a silent no-op.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function require_enabled() {
		if ( ! Settings::users_enabled() ) {
			return new WP_Error(
				'users_disabled',
				'The MCP user abilities are switched off on this site.'
			);
		}

		return true;
	}

	/**
	 * Guard for the user-reading abilities.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function require_can_list() {
		$enabled = self::require_enabled();

		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		if ( ! current_user_can( 'list_users' ) ) {
			return new WP_Error(
				'cannot_list_users',
				'User lacks the "list_users" capability required to read the user list.'
			);
		}

		return true;
	}

	/**
	 * Guard for creating a user.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function require_can_create() {
		$enabled = self::require_enabled();

		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		if ( ! current_user_can( 'create_users' ) ) {
			return new WP_Error(
				'cannot_create_users',
				'User lacks the "create_users" capability.'
			);
		}

		return true;
	}

	/**
	 * Resolves a user and checks that the current user may edit them.
	 *
	 * @param mixed $user_id The user ID.
	 *
	 * @return \WP_User|\WP_Error The user when permitted, WP_Error otherwise.
	 */
	public static function require_can_edit( $user_id ) {
		$enabled = self::require_enabled();

		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		$user = self::get_user( $user_id );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new WP_Error(
				'cannot_edit_user',
				sprintf( 'User lacks permission to edit user %d.', $user->ID )
			);
		}

		$protected = self::require_not_protected( $user );

		if ( is_wp_error( $protected ) ) {
			return $protected;
		}

		return $user;
	}

	/**
	 * Resolves a user and checks that the current user may delete them.
	 *
	 * @param mixed $user_id The user ID.
	 *
	 * @return \WP_User|\WP_Error The user when permitted, WP_Error otherwise.
	 */
	public static function require_can_delete( $user_id ) {
		$enabled = self::require_enabled();

		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		$user = self::get_user( $user_id );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( get_current_user_id() === $user->ID ) {
			return new WP_Error(
				'cannot_delete_self',
				'A user cannot delete their own account through this ability. Ask another administrator, or use the WordPress admin.'
			);
		}

		if ( ! current_user_can( 'delete_user', $user->ID ) ) {
			return new WP_Error(
				'cannot_delete_user',
				sprintf( 'User lacks permission to delete user %d.', $user->ID )
			);
		}

		$protected = self::require_not_protected( $user );

		if ( is_wp_error( $protected ) ) {
			return $protected;
		}

		return $user;
	}

	/**
	 * Refuses to touch a network super admin unless the caller is one too.
	 *
	 * `edit_user` alone is not enough here: on a single site in a network an
	 * administrator holds it for every user on that site, including someone who
	 * outranks them network-wide.
	 *
	 * @param \WP_User $user The target user.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	private static function require_not_protected( WP_User $user ) {
		if ( ! is_multisite() || ! function_exists( 'is_super_admin' ) ) {
			return true;
		}

		if ( is_super_admin( $user->ID ) && ! is_super_admin( get_current_user_id() ) ) {
			return new WP_Error(
				'cannot_modify_super_admin',
				sprintf( 'User %d is a network super admin and can only be modified by another super admin.', $user->ID )
			);
		}

		return true;
	}

	/**
	 * Looks up a user by ID.
	 *
	 * @param mixed $user_id The user ID.
	 *
	 * @return \WP_User|\WP_Error The user, or WP_Error when it does not exist.
	 */
	public static function get_user( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user_id', 'A positive user ID is required.' );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'user_not_found', sprintf( 'No user found with ID %d.', $user_id ) );
		}

		return $user;
	}

	/**
	 * Looks up a user by ID, login, email or slug.
	 *
	 * @param array $input Raw ability input.
	 *
	 * @return \WP_User|\WP_Error The user, or WP_Error when nothing matches.
	 */
	public static function resolve_user( array $input ) {
		if ( ! empty( $input['user_id'] ) ) {
			return self::get_user( $input['user_id'] );
		}

		$fields = array(
			'login' => 'login',
			'email' => 'email',
			'slug'  => 'slug',
		);

		foreach ( $fields as $key => $by ) {
			if ( empty( $input[ $key ] ) ) {
				continue;
			}

			$value = (string) $input[ $key ];
			$user  = get_user_by( $by, $value );

			if ( $user instanceof WP_User ) {
				return $user;
			}

			return new WP_Error(
				'user_not_found',
				sprintf( 'No user found with %s "%s".', $key, $value )
			);
		}

		return new WP_Error(
			'missing_identifier',
			'One of user_id, login, email or slug is required.'
		);
	}

	/**
	 * Checks that a role exists and that the caller may assign it.
	 *
	 * @param string $role The role slug.
	 *
	 * @return true|\WP_Error True when the role can be assigned, WP_Error otherwise.
	 */
	public static function validate_role( string $role ) {
		if ( '' === $role ) {
			return new WP_Error( 'invalid_role', 'A role slug is required.' );
		}

		if ( ! get_role( $role ) ) {
			return new WP_Error(
				'unknown_role',
				sprintf( 'Role "%s" is not registered on this site. Registered roles: %s.', $role, implode( ', ', self::role_slugs() ) )
			);
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			return new WP_Error(
				'cannot_promote_users',
				'User lacks the "promote_users" capability required to set a role.'
			);
		}

		return true;
	}

	/**
	 * Returns the role slugs registered on this site.
	 *
	 * @return string[] Role slugs.
	 */
	public static function role_slugs(): array {
		$roles = wp_roles()->get_names();

		return array_map( 'strval', array_keys( $roles ) );
	}

	/**
	 * Formats a user for output.
	 *
	 * @param \WP_User $user The user.
	 * @param array    $args {
	 *     Optional. Formatting options.
	 *
	 *     @type bool $include_meta Include public user meta. Default false.
	 * }
	 *
	 * @return array<string, mixed> The formatted user.
	 */
	public static function format_user( WP_User $user, array $args = array() ): array {
		$formatted = array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'nickname'     => (string) $user->nickname,
			'slug'         => $user->user_nicename,
			'url'          => $user->user_url,
			'description'  => (string) $user->description,
			'locale'       => (string) get_user_locale( $user ),
			'roles'        => array_values( (array) $user->roles ),
			'registered'   => $user->user_registered,
			'post_count'   => (int) count_user_posts( $user->ID, 'post', true ),
			'avatar_url'   => (string) get_avatar_url( $user->ID ),
			'edit_link'    => current_user_can( 'edit_user', $user->ID )
				? (string) get_edit_user_link( $user->ID )
				: '',
		);

		if ( is_multisite() && function_exists( 'is_super_admin' ) ) {
			$formatted['super_admin'] = is_super_admin( $user->ID );
		}

		if ( ! empty( $args['include_meta'] ) ) {
			$formatted['meta'] = self::public_meta( $user->ID );
		}

		return $formatted;
	}

	/**
	 * Returns a user's meta, minus anything private or secret.
	 *
	 * Underscore-prefixed keys are WordPress's own convention for internal
	 * state, and the password and activation key are never exposed at all.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array<string, mixed> The readable meta.
	 */
	private static function public_meta( int $user_id ): array {
		$meta   = get_user_meta( $user_id );
		$public = array();

		if ( ! is_array( $meta ) ) {
			return $public;
		}

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;

			if ( '_' === substr( $key, 0, 1 ) || in_array( $key, self::SECRET_FIELDS, true ) ) {
				continue;
			}

			$public[ $key ] = is_array( $values ) && 1 === count( $values ) ? $values[0] : $values;
		}

		return $public;
	}

	/**
	 * Builds the profile fields shared by create and update.
	 *
	 * Only keys actually present in the input are returned, so an update leaves
	 * everything the caller did not mention alone.
	 *
	 * @param array $input Raw ability input.
	 *
	 * @return array<string, mixed> Arguments for wp_insert_user()/wp_update_user().
	 */
	public static function build_profile_args( array $input ): array {
		$map = array(
			'email'        => 'user_email',
			'url'          => 'user_url',
			'slug'         => 'user_nicename',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'nickname'     => 'nickname',
			'description'  => 'description',
			'locale'       => 'locale',
		);

		$args = array();

		foreach ( $map as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$args[ $field ] = (string) $input[ $key ];
		}

		return $args;
	}

	/**
	 * Sends the "set your password" email for a user.
	 *
	 * Always an explicit opt-in on the calling ability: it puts a message in a
	 * real person's inbox, which is not something to do as a side effect of
	 * editing a record.
	 *
	 * @param \WP_User $user The user to notify.
	 *
	 * @return true|\WP_Error True when the mail was handed to WordPress.
	 */
	public static function send_password_reset( WP_User $user ) {
		$sent = retrieve_password( $user->user_login );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		if ( true !== $sent ) {
			return new WP_Error(
				'password_reset_failed',
				sprintf( 'WordPress could not send a password reset email to user %d.', $user->ID )
			);
		}

		return true;
	}
}
