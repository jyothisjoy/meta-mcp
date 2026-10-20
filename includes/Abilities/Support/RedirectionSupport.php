<?php
/**
 * Redirection plugin integration helpers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - RedirectionSupport
 *
 * Works through the Redirection plugin's own models, `Red_Item` and
 * `Red_Group`, which are what its REST API calls. Redirection 5.10 moved those
 * classes into namespaces but still maps the old names onto them, so the same
 * calls work across versions. Going through the models means Redirection's own
 * sanitiser validates every rule and its own cache flush runs after each change.
 *
 * Only URL-matched rules are written: a plain or regex source redirecting to a
 * target (301/302/303/307/308), or answering with an error (404/410).
 * Rules of other kinds that already exist are listed and can be enabled,
 * disabled or deleted, but not rewritten.
 */
final class RedirectionSupport {

	/**
	 * Redirect status codes.
	 */
	public const REDIRECT_CODES = array( 301, 302, 303, 307, 308 );

	/**
	 * Error status codes that need no target.
	 */
	public const ERROR_CODES = array( 404, 410 );

	/**
	 * Query parameter handling modes Redirection supports.
	 */
	public const QUERY_MODES = array( 'exact', 'ignore', 'pass', 'exactorder' );

	/**
	 * Whether the Redirection plugin is active.
	 *
	 * @return bool True when its models can be loaded.
	 */
	public static function is_active(): bool {
		return defined( 'REDIRECTION_VERSION' ) || class_exists( 'Red_Item' );
	}

	/**
	 * Returns the Redirection version string, if known.
	 *
	 * @return string Version, or an empty string.
	 */
	public static function version(): string {
		return defined( 'REDIRECTION_VERSION' ) ? (string) constant( 'REDIRECTION_VERSION' ) : '';
	}

	/**
	 * Guard used by every redirect ability.
	 *
	 * @return true|\WP_Error True when Redirection is usable.
	 */
	public static function require_active() {
		if ( ! self::is_active() || ! class_exists( 'Red_Item' ) || ! class_exists( 'Red_Group' ) ) {
			return new WP_Error(
				'redirection_not_active',
				'The Redirection plugin is not active on this site. Install and activate Redirection to use this ability.'
			);
		}

		return true;
	}

	/**
	 * Checks one of Redirection's own capabilities.
	 *
	 * Redirection maps each action to a capability that defaults to
	 * `manage_options` and can be delegated through its own filters.
	 *
	 * @param string $action "manage", "add" or "delete".
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function require_cap( string $action ) {
		$constant = 'Redirection_Capabilities::CAP_REDIRECT_' . strtoupper( $action );

		if ( class_exists( 'Redirection_Capabilities' ) && defined( $constant ) && is_callable( array( 'Redirection_Capabilities', 'has_access' ) ) ) {
			$allowed = \Redirection_Capabilities::has_access( constant( $constant ) );
		} else {
			$allowed = current_user_can( 'manage_options' );
		}

		if ( ! $allowed ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf( 'User is not allowed to %s redirects (Redirection requires "manage_options" by default).', $action )
			);
		}

		return true;
	}

	/**
	 * Lists the redirect groups.
	 *
	 * @return array<int, array<string, mixed>> Groups.
	 */
	public static function groups(): array {
		$groups = \Red_Group::get_all();

		return is_array( $groups ) ? array_values( $groups ) : array();
	}

	/**
	 * Picks the group a new redirect goes into when none is given.
	 *
	 * Redirection refuses a redirect without a valid group. Its install creates
	 * "Redirections" in the WordPress module (module 1), which is what its own
	 * "Add new" form defaults to.
	 *
	 * @return int|\WP_Error Group ID.
	 */
	public static function default_group_id() {
		$fallback = 0;

		foreach ( self::groups() as $group ) {
			$id = (int) ( $group['id'] ?? 0 );

			if ( $id <= 0 || empty( $group['enabled'] ) ) {
				continue;
			}

			if ( 1 === (int) ( $group['module_id'] ?? 0 ) ) {
				return $id;
			}

			if ( 0 === $fallback ) {
				$fallback = $id;
			}
		}

		if ( $fallback > 0 ) {
			return $fallback;
		}

		return new WP_Error( 'no_redirect_group', 'Redirection has no enabled group to add redirects to. Create one in Tools → Redirection → Groups.' );
	}

	/**
	 * Loads a redirect.
	 *
	 * @param int $id Redirect ID.
	 *
	 * @return \Red_Item|\WP_Error The redirect.
	 */
	public static function get( int $id ) {
		$item = $id > 0 ? \Red_Item::get_by_id( $id ) : false;

		if ( ! $item ) {
			return new WP_Error( 'redirect_not_found', sprintf( 'No redirect found with ID %d.', $id ) );
		}

		return $item;
	}

	/**
	 * Lists redirects.
	 *
	 * @param array<string, mixed> $args Filters: source, target, title, group_id, status, regex, code, page, per_page.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int} The page of redirects.
	 */
	public static function list( array $args ): array {
		$filter = array();

		foreach ( array( 'source' => 'url', 'target' => 'target', 'title' => 'title' ) as $input => $key ) {
			if ( isset( $args[ $input ] ) && '' !== (string) $args[ $input ] ) {
				$filter[ $key ] = (string) $args[ $input ];
			}
		}

		if ( ! empty( $args['group_id'] ) ) {
			$filter['group'] = (string) (int) $args['group_id'];
		}

		if ( isset( $args['status'] ) && in_array( $args['status'], array( 'enabled', 'disabled' ), true ) ) {
			$filter['status'] = (string) $args['status'];
		}

		if ( isset( $args['regex'] ) && null !== $args['regex'] ) {
			$filter['url-match'] = ContentSupport::to_bool( $args['regex'], false ) ? 'regular' : 'plain';
		}

		if ( ! empty( $args['code'] ) ) {
			$filter['http'] = (string) (int) $args['code'];
		}

		$per_page = isset( $args['per_page'] ) ? max( 5, min( 200, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$params = array(
			'per_page'  => $per_page,
			// Redirection pages from zero.
			'page'      => $page - 1,
			'orderby'   => 'id',
			'direction' => 'asc',
		);

		if ( ! empty( $filter ) ) {
			$params['filterBy'] = $filter;
		}

		$result = \Red_Item::get_filtered( $params );

		return array(
			'items' => array_map( array( self::class, 'format' ), (array) ( $result['items'] ?? array() ) ),
			'total' => (int) ( $result['total'] ?? 0 ),
		);
	}

	/**
	 * Finds existing plain (non-regex) redirects with exactly this source.
	 *
	 * @param string $source Source path.
	 *
	 * @return array<int, array<string, mixed>> Matching redirects.
	 */
	public static function find_by_source( string $source ): array {
		$normalised = self::normalise_source( $source );
		$matches    = array();
		$result     = \Red_Item::get_filtered(
			array(
				'per_page' => 200,
				'page'     => 0,
				'filterBy' => array( 'url' => $normalised ),
			)
		);

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( empty( $item['regex'] ) && self::normalise_source( (string) ( $item['url'] ?? '' ) ) === $normalised ) {
				$matches[] = self::format( $item );
			}
		}

		return $matches;
	}

	/**
	 * Normalises a source for duplicate detection.
	 *
	 * @param string $source Source path or URL.
	 *
	 * @return string The path, lower-cased, without a trailing slash.
	 */
	private static function normalise_source( string $source ): string {
		$path = (string) preg_replace( '#^https?://[^/]+#i', '', trim( $source ) );
		$path = '/' . ltrim( $path, '/' );

		return strtolower( '/' === $path ? $path : rtrim( $path, '/' ) );
	}

	/**
	 * Builds the details array Redirection's sanitiser expects.
	 *
	 * @param array<string, mixed>      $input    Rule fields: source, target, code, regex, title, group_id, ignore_case, ignore_trailing_slash, query, position.
	 * @param array<string, mixed>|null $existing The current rule as returned by to_json(), when updating.
	 *
	 * @return array<string, mixed>|\WP_Error The details.
	 */
	public static function build_details( array $input, ?array $existing = null ) {
		if ( null !== $existing && 'url' !== ( $existing['match_type'] ?? 'url' ) ) {
			return new WP_Error(
				'unsupported_redirect',
				sprintf( 'Redirect %d matches on "%s", not the URL alone. Edit it in Tools → Redirection; it can still be enabled, disabled or deleted here.', (int) $existing['id'], (string) $existing['match_type'] )
			);
		}

		$existing_source_flags = isset( $existing['match_data']['source'] ) && is_array( $existing['match_data']['source'] ) ? $existing['match_data']['source'] : array();

		$source = array_key_exists( 'source', $input ) ? trim( (string) $input['source'] ) : (string) ( $existing['url'] ?? '' );

		if ( '' === $source ) {
			return new WP_Error( 'missing_source', 'source is required, e.g. "/old-page/" or a regex such as "^/bookingstep1\\.en\\.html.*".' );
		}

		$code = array_key_exists( 'code', $input ) ? (int) $input['code'] : (int) ( $existing['action_code'] ?? 301 );

		if ( ! in_array( $code, array_merge( self::REDIRECT_CODES, self::ERROR_CODES ), true ) ) {
			return new WP_Error(
				'invalid_code',
				sprintf( 'code must be one of %s.', implode( ', ', array_merge( self::REDIRECT_CODES, self::ERROR_CODES ) ) )
			);
		}

		$is_error = in_array( $code, self::ERROR_CODES, true );

		if ( array_key_exists( 'target', $input ) ) {
			$target = trim( (string) $input['target'] );
		} else {
			$target = isset( $existing['action_data']['url'] ) ? (string) $existing['action_data']['url'] : '';
		}

		if ( ! $is_error && '' === $target ) {
			return new WP_Error( 'missing_target', 'target is required for a redirect, e.g. "/rooms-suites/" or a full URL.' );
		}

		$regex = array_key_exists( 'regex', $input )
			? ContentSupport::to_bool( $input['regex'], false )
			: ! empty( $existing['regex'] );

		if ( $regex && false === @preg_match( '@' . str_replace( '@', '\\@', $source ) . '@', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validating a user-supplied pattern.
			return new WP_Error( 'invalid_regex', sprintf( 'source "%s" is not a valid regular expression.', $source ) );
		}

		$flags = array( 'flag_regex' => $regex );

		foreach ( array( 'ignore_case' => 'flag_case', 'ignore_trailing_slash' => 'flag_trailing' ) as $name => $flag ) {
			if ( array_key_exists( $name, $input ) ) {
				$flags[ $flag ] = ContentSupport::to_bool( $input[ $name ], false );
			} elseif ( array_key_exists( $flag, $existing_source_flags ) ) {
				$flags[ $flag ] = (bool) $existing_source_flags[ $flag ];
			}
		}

		if ( array_key_exists( 'query', $input ) ) {
			if ( ! in_array( $input['query'], self::QUERY_MODES, true ) ) {
				return new WP_Error( 'invalid_query', sprintf( 'query must be one of: %s.', implode( ', ', self::QUERY_MODES ) ) );
			}

			$flags['flag_query'] = (string) $input['query'];
		} elseif ( isset( $existing_source_flags['flag_query'] ) ) {
			$flags['flag_query'] = (string) $existing_source_flags['flag_query'];
		}

		if ( array_key_exists( 'group_id', $input ) && (int) $input['group_id'] > 0 ) {
			$group_id = (int) $input['group_id'];
		} elseif ( isset( $existing['group_id'] ) ) {
			$group_id = (int) $existing['group_id'];
		} else {
			$group_id = self::default_group_id();

			if ( is_wp_error( $group_id ) ) {
				return $group_id;
			}
		}

		$details = array(
			'url'         => $source,
			'match_type'  => 'url',
			'action_type' => $is_error ? 'error' : 'url',
			'action_code' => $code,
			'action_data' => $is_error ? array() : array( 'url' => $target ),
			'regex'       => $regex ? 1 : 0,
			'group_id'    => $group_id,
			'match_data'  => array( 'source' => $flags ),
			'title'       => array_key_exists( 'title', $input ) ? (string) $input['title'] : (string) ( $existing['title'] ?? '' ),
		);

		// Keep the rule's other source options (such as "do not log"), which
		// Redirection would otherwise drop on save.
		if ( isset( $existing['match_data']['options'] ) && is_array( $existing['match_data']['options'] ) ) {
			$details['match_data']['options'] = $existing['match_data']['options'];
		}

		if ( array_key_exists( 'position', $input ) ) {
			$details['position'] = max( 0, (int) $input['position'] );
		} elseif ( isset( $existing['position'] ) ) {
			$details['position'] = (int) $existing['position'];
		}

		return $details;
	}

	/**
	 * Shapes a redirect for the response.
	 *
	 * @param array<string, mixed>|\Red_Item $item Redirect JSON or object.
	 *
	 * @return array<string, mixed> The redirect.
	 */
	public static function format( $item ): array {
		if ( is_object( $item ) && method_exists( $item, 'to_json' ) ) {
			$item = $item->to_json();
		}

		$item = (array) $item;

		return array(
			'id'          => (int) ( $item['id'] ?? 0 ),
			'source'      => (string) ( $item['url'] ?? '' ),
			'target'      => isset( $item['action_data']['url'] ) ? (string) $item['action_data']['url'] : '',
			'code'        => (int) ( $item['action_code'] ?? 0 ),
			'action_type' => (string) ( $item['action_type'] ?? '' ),
			'match_type'  => (string) ( $item['match_type'] ?? '' ),
			'regex'       => ! empty( $item['regex'] ),
			'flags'       => isset( $item['match_data']['source'] ) && is_array( $item['match_data']['source'] ) ? $item['match_data']['source'] : array(),
			'title'       => (string) ( $item['title'] ?? '' ),
			'group_id'    => (int) ( $item['group_id'] ?? 0 ),
			'position'    => (int) ( $item['position'] ?? 0 ),
			'enabled'     => ! empty( $item['enabled'] ),
			'hits'        => (int) ( $item['hits'] ?? 0 ),
			'last_access' => (string) ( $item['last_access'] ?? '' ),
		);
	}

	/**
	 * Input schema properties shared by create and update.
	 *
	 * @return array<string, array<string, mixed>> Schema properties.
	 */
	public static function rule_schema(): array {
		return array(
			'source'                => array(
				'type'        => 'string',
				'description' => 'The URL to match, as a path such as "/offers-2/". With regex true, a regular expression such as "^/bookingstep1\\.en\\.html.*" (capture groups can be used in the target as $1).',
			),
			'target'                => array(
				'type'        => 'string',
				'description' => 'Where to send the visitor, e.g. "/rooms-suites/" or a full URL. Not needed for 404/410.',
			),
			'code'                  => array(
				'type'        => 'integer',
				'enum'        => array_merge( self::REDIRECT_CODES, self::ERROR_CODES ),
				'description' => 'HTTP status. 301 (default) for a permanent move, 302/307 temporary, 410 to tell search engines the page is gone for good, 404 not found.',
			),
			'regex'                 => array(
				'type'        => 'boolean',
				'description' => 'Treat source as a regular expression. Default false.',
			),
			'ignore_case'           => array(
				'type'        => 'boolean',
				'description' => 'Match regardless of letter case. Defaults to Redirection\'s site setting.',
			),
			'ignore_trailing_slash' => array(
				'type'        => 'boolean',
				'description' => 'Match with or without a trailing slash. Defaults to Redirection\'s site setting.',
			),
			'query'                 => array(
				'type'        => 'string',
				'enum'        => self::QUERY_MODES,
				'description' => 'Query string handling: "exact" match, "ignore" it, or "pass" it through to the target. Defaults to Redirection\'s site setting.',
			),
			'title'                 => array(
				'type'        => 'string',
				'description' => 'An optional note shown in Redirection\'s list.',
			),
			'group_id'              => array(
				'type'        => 'integer',
				'description' => 'Redirection group. Defaults to the main "Redirections" group.',
			),
			'position'              => array(
				'type'        => 'integer',
				'description' => 'Order within the group; lower runs first.',
			),
		);
	}
}
