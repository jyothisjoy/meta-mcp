<?php
/**
 * Ability for querying the site's users.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Users;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\UserSupport;
use WP_User_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Users - Queries the site's user accounts.
 */
final class ListUsersAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-users',
			array(
				'label'               => 'List Users',
				'description'         => 'Query the site\'s user accounts with filters for role, search term and registration date. Returns a paginated summary list; use get-user to read one in full. Requires the "list_users" capability. Passwords are never returned.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'       => array(
							'type'        => 'string',
							'description' => 'Free-text search across login, email, display name and URL.',
						),
						'role'         => array(
							'type'        => 'string',
							'description' => 'Restrict to users holding this role slug, e.g. "editor".',
						),
						'exclude_role' => array(
							'type'        => 'string',
							'description' => 'Exclude users holding this role slug.',
						),
						'orderby'      => array(
							'type'        => 'string',
							'enum'        => array( 'ID', 'login', 'display_name', 'email', 'registered', 'post_count' ),
							'description' => 'Sort field. Default "login".',
						),
						'order'        => array(
							'type'        => 'string',
							'enum'        => array( 'ASC', 'DESC' ),
							'description' => 'Sort direction. Default "ASC".',
						),
						'per_page'     => array(
							'type'        => 'integer',
							'description' => 'Results per page, 1-100. Default 20.',
						),
						'page'         => array(
							'type'        => 'integer',
							'description' => 'Page number, starting at 1. Default 1.',
						),
						'include_meta' => array(
							'type'        => 'boolean',
							'description' => 'Include public user meta for every result. Default false, because it is large.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'users'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'roles'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'error'       => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The query results.
	 */
	public static function execute( $input = array() ): array {
		$allowed = UserSupport::require_can_list();

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( UserSupport::MAX_PER_PAGE, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => self::resolve_orderby( $input['orderby'] ?? null ),
			'order'   => isset( $input['order'] ) && 'DESC' === strtoupper( (string) $input['order'] ) ? 'DESC' : 'ASC',
		);

		if ( ! empty( $input['search'] ) ) {
			// Wildcards on both sides, so a partial name behaves the way the
			// caller almost certainly meant it to.
			$args['search']         = '*' . (string) $input['search'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name', 'user_url' );
		}

		if ( ! empty( $input['role'] ) ) {
			$args['role'] = (string) $input['role'];
		}

		if ( ! empty( $input['exclude_role'] ) ) {
			$args['role__not_in'] = array( (string) $input['exclude_role'] );
		}

		$query        = new WP_User_Query( $args );
		$include_meta = ContentSupport::to_bool( $input['include_meta'] ?? null, false );

		$users = array_map(
			static fn( $user ): array => UserSupport::format_user( $user, array( 'include_meta' => $include_meta ) ),
			$query->get_results()
		);

		$total = (int) $query->get_total();

		return array(
			'success'     => true,
			'users'       => $users,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'roles'       => UserSupport::role_slugs(),
		);
	}

	/**
	 * Resolves the orderby argument against an allowlist.
	 *
	 * @param mixed $requested Raw orderby input.
	 *
	 * @return string The orderby value.
	 */
	private static function resolve_orderby( $requested ): string {
		$allowed = array( 'ID', 'login', 'display_name', 'email', 'registered', 'post_count' );
		$value   = (string) $requested;

		return in_array( $value, $allowed, true ) ? $value : 'login';
	}
}
