<?php
/**
 * Rank Math Redirections module integration helpers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - RankMathRedirectsSupport
 *
 * The redirect backend for sites that keep their redirects in Rank Math
 * (Rank Math → Redirections) rather than the Redirection plugin.
 *
 * Writes go through Rank Math's own `RankMath\Redirections\Redirection` model
 * and `DB` class, the same calls its admin screen makes, so its source
 * sanitiser runs (a full URL or "/path/" becomes "path"), its redirection cache
 * is purged and rebuilt, and 410/451 rules lose their target as Rank Math
 * expects. Reads with filters query its table directly, read-only, so a filter
 * on the source alone, the target alone, the status code or regex gives a
 * correct total for paging.
 *
 * A Rank Math rule can have several sources; each has a pattern, a comparison
 * ("exact", "contains", "start", "end" or "regex") and an ignore-case flag.
 * Exact patterns are stored without the leading slash. Statuses are "active",
 * "inactive" and "trashed" (trashed rules are not listed).
 */
final class RankMathRedirectsSupport {

	/**
	 * Status codes Rank Math supports.
	 */
	public const CODES = array( 301, 302, 307, 410, 451 );

	/**
	 * Codes that take no target.
	 */
	private const NO_TARGET = array( 410, 451 );

	/**
	 * Comparisons for plain (non-regex) sources.
	 */
	private const MATCHES = array( 'exact', 'contains', 'start', 'end' );

	/**
	 * Provider name.
	 *
	 * @return string The name.
	 */
	public static function provider(): string {
		return 'rank_math';
	}

	/**
	 * Whether Rank Math's Redirections module is on.
	 *
	 * @return bool True when its model and database classes can be used.
	 */
	public static function is_active(): bool {
		if ( ! class_exists( '\RankMath\Helper' ) || ! class_exists( '\RankMath\Redirections\DB' ) || ! class_exists( '\RankMath\Redirections\Redirection' ) ) {
			return false;
		}

		return ! is_callable( array( '\RankMath\Helper', 'is_module_active' ) ) || (bool) \RankMath\Helper::is_module_active( 'redirections' );
	}

	/**
	 * Rank Math's version string, if known.
	 *
	 * @return string Version, or an empty string.
	 */
	public static function version(): string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) constant( 'RANK_MATH_VERSION' ) : '';
	}

	/**
	 * Guard used by every redirect ability.
	 *
	 * @return true|\WP_Error True when the module is usable.
	 */
	public static function require_active() {
		if ( self::is_active() ) {
			return true;
		}

		$message = class_exists( '\RankMath\Helper' )
			? 'Rank Math is active but its Redirections module is off: turn it on in Rank Math → Dashboard → Modules → Redirections.'
			: 'Rank Math is not active on this site.';

		return new WP_Error( 'rank_math_redirections_not_active', $message );
	}

	/**
	 * Checks Rank Math's redirections capability.
	 *
	 * @param string $action "manage", "add" or "delete" (Rank Math has one capability for all three).
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function require_cap( string $action ) {
		if ( current_user_can( 'rank_math_redirections' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'insufficient_capability',
			sprintf( 'User is not allowed to %s redirects (Rank Math requires its "Redirections" role capability).', $action )
		);
	}

	/**
	 * Rank Math has no redirect groups.
	 *
	 * @return array<int, array<string, mixed>> Always empty.
	 */
	public static function groups(): array {
		return array();
	}

	/**
	 * Lists redirects (trashed ones left out).
	 *
	 * @param array<string, mixed> $args Filters: source, target, status, regex, code, page, per_page.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int} The page of redirects.
	 */
	public static function list( array $args ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'rank_math_redirections';
		$where  = array( "status != 'trashed'" );
		$values = array();

		if ( isset( $args['source'] ) && '' !== (string) $args['source'] ) {
			$where[]  = 'sources LIKE %s';
			$values[] = '%' . $wpdb->esc_like( ltrim( self::relative( (string) $args['source'] ), '/' ) ) . '%';
		}

		if ( isset( $args['target'] ) && '' !== (string) $args['target'] ) {
			$where[]  = 'url_to LIKE %s';
			$values[] = '%' . $wpdb->esc_like( (string) $args['target'] ) . '%';
		}

		if ( isset( $args['status'] ) && in_array( $args['status'], array( 'enabled', 'disabled' ), true ) ) {
			$where[]  = 'status = %s';
			$values[] = 'enabled' === $args['status'] ? 'active' : 'inactive';
		}

		if ( isset( $args['regex'] ) && null !== $args['regex'] ) {
			$where[]  = ContentSupport::to_bool( $args['regex'], false ) ? 'sources LIKE %s' : 'sources NOT LIKE %s';
			$values[] = '%' . $wpdb->esc_like( 's:10:"comparison";s:5:"regex"' ) . '%';
		}

		if ( ! empty( $args['code'] ) ) {
			$where[]  = 'header_code = %d';
			$values[] = (int) $args['code'];
		}

		$per_page = isset( $args['per_page'] ) ? max( 5, min( 200, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$clause   = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name from $wpdb->prefix; values are prepared.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id ASC LIMIT %d OFFSET %d";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, $values ) : $count_sql );
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'format' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Finds existing rules with an exact source equal to this one (not trashed).
	 *
	 * @param string $source Source path or URL.
	 *
	 * @return array<int, array<string, mixed>> Matching redirects.
	 */
	public static function find_by_source( string $source ): array {
		$want = self::normalise( $source );

		if ( '' === $want ) {
			return array();
		}

		$found   = self::list( array( 'source' => $want, 'per_page' => 200 ) );
		$matches = array();

		foreach ( $found['items'] as $item ) {
			foreach ( $item['sources'] as $src ) {
				if ( 'exact' === $src['comparison'] && self::normalise( $src['pattern'] ) === $want ) {
					$matches[] = $item;
					break;
				}
			}
		}

		return $matches;
	}

	/**
	 * Loads one rule as the abilities show it.
	 *
	 * @param int $id Redirect ID.
	 *
	 * @return array<string, mixed>|\WP_Error The redirect.
	 */
	public static function get_formatted( int $id ) {
		$row = self::row( $id );

		return is_wp_error( $row ) ? $row : self::format( $row );
	}

	/**
	 * Validates a rule and builds the data Rank Math's model takes.
	 *
	 * @param array<string, mixed> $rule        Rule fields: source, target, code, regex, match, ignore_case (others are Redirection options and ignored).
	 * @param int                  $existing_id The rule being changed, or 0 for a new one.
	 *
	 * @return array{data: array<string, mixed>, notes: string[]}|\WP_Error The prepared rule.
	 */
	public static function prepare( array $rule, int $existing_id = 0 ) {
		$existing = null;

		if ( $existing_id > 0 ) {
			$existing = self::row( $existing_id );

			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
		}

		$notes   = array();
		$ignored = array_intersect( array_keys( $rule ), array( 'ignore_trailing_slash', 'query', 'group_id', 'position', 'title' ) );

		if ( ! empty( $ignored ) ) {
			$notes[] = sprintf( 'Rank Math has no %s option: ignored.', implode( ', ', $ignored ) );
		}

		$code = array_key_exists( 'code', $rule ) ? (int) $rule['code'] : (int) ( $existing['header_code'] ?? 301 );

		if ( ! in_array( $code, self::CODES, true ) ) {
			$hint = array( 303 => 302, 308 => 301, 404 => 410 );

			return new WP_Error(
				'invalid_code',
				sprintf(
					'Rank Math redirects use %s.%s',
					implode( ', ', self::CODES ),
					isset( $hint[ $code ] ) ? sprintf( ' Use %d instead of %d.', $hint[ $code ], $code ) : ''
				)
			);
		}

		$sources = is_array( $existing['sources'] ?? null ) ? $existing['sources'] : array();

		if ( array_key_exists( 'source', $rule ) || null === $existing ) {
			$source = trim( (string) ( $rule['source'] ?? '' ) );

			if ( '' === $source || '/' === $source ) {
				return new WP_Error( 'missing_source', 'source is required, e.g. "/old-page/" or a regex such as "^bookingstep1\\.en\\.html.*" (with regex true).' );
			}

			$regex = array_key_exists( 'regex', $rule ) ? ContentSupport::to_bool( $rule['regex'], false ) : ( isset( $sources[0]['comparison'] ) && 'regex' === $sources[0]['comparison'] );
			$match = isset( $rule['match'] ) ? strtolower( (string) $rule['match'] ) : 'exact';

			if ( ! in_array( $match, self::MATCHES, true ) ) {
				return new WP_Error( 'invalid_match', sprintf( 'match must be one of: %s.', implode( ', ', self::MATCHES ) ) );
			}

			if ( $regex ) {
				// Rank Math matches a regex against the path without its leading slash.
				if ( 0 === strpos( $source, '^/' ) ) {
					$source  = '^' . substr( $source, 2 );
					$notes[] = 'Rank Math compares a regex with the path without its leading slash: "^/" became "^".';
				}

				if ( false === @preg_match( '@' . $source . '@', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validating a user-supplied pattern.
					return new WP_Error( 'invalid_regex', sprintf( 'source "%s" is not a valid regular expression.', $source ) );
				}
			} elseif ( '' === trim( self::relative( $source ), '/' ) ) {
				return new WP_Error( 'invalid_source', sprintf( 'source "%s" is the homepage or another site: Rank Math redirects a path on this site.', $source ) );
			}

			$ignore_case = array_key_exists( 'ignore_case', $rule )
				? ContentSupport::to_bool( $rule['ignore_case'], false )
				: ! empty( $sources[0]['ignore'] );

			if ( count( $sources ) > 1 ) {
				$notes[] = sprintf( 'This rule had %d sources; it now has only the one given.', count( $sources ) );
			}

			// Rank Math compares plain sources with the path without its leading slash ("rooms/old"); its own
			// sanitiser strips it from exact ones, so strip it from contains / start / end ones too.
			$sources = array(
				array(
					'pattern'    => $regex ? $source : ( 'exact' === $match ? self::relative( $source ) : ltrim( self::relative( $source ), '/' ) ),
					'comparison' => $regex ? 'regex' : $match,
					'ignore'     => $ignore_case ? 'case' : '',
				),
			);
		} elseif ( array_key_exists( 'ignore_case', $rule ) ) {
			foreach ( $sources as $i => $src ) {
				$sources[ $i ]['ignore'] = ContentSupport::to_bool( $rule['ignore_case'], false ) ? 'case' : '';
			}
		}

		if ( array_key_exists( 'target', $rule ) ) {
			$target = trim( (string) $rule['target'] );
		} else {
			$target = (string) ( $existing['url_to'] ?? '' );
		}

		if ( in_array( $code, self::NO_TARGET, true ) ) {
			$target = '';
		} elseif ( '' === $target ) {
			return new WP_Error( 'missing_target', 'target is required for a redirect, e.g. "/rooms-suites/" or a full URL.' );
		}

		$data = array(
			'sources'     => $sources,
			'url_to'      => $target,
			'header_code' => $code,
		);

		if ( null !== $existing ) {
			$data['id']      = (int) $existing['id'];
			$data['status']  = (string) $existing['status'];
			$data['hits']    = (int) ( $existing['hits'] ?? 0 );
			$data['created'] = (string) ( $existing['created'] ?? '' );
		}

		if ( '' !== $target && ! in_array( $code, self::NO_TARGET, true ) ) {
			foreach ( $sources as $src ) {
				if ( 'exact' === $src['comparison'] && self::normalise( $target ) === self::normalise( $src['pattern'] ) ) {
					return new WP_Error( 'redirect_loop', sprintf( 'source and target are the same page ("%s"): that would loop.', $target ) );
				}
			}
		}

		return array(
			'data'  => $data,
			'notes' => $notes,
		);
	}

	/**
	 * Creates a rule.
	 *
	 * @param array{data: array<string, mixed>, notes: string[]} $prepared From prepare().
	 * @param bool                                               $enabled  Active or inactive.
	 *
	 * @return array<string, mixed>|\WP_Error The new rule.
	 */
	public static function create( array $prepared, bool $enabled ) {
		$data           = $prepared['data'];
		$data['status'] = $enabled ? 'active' : 'inactive';
		unset( $data['id'] );

		return self::save( $data );
	}

	/**
	 * Changes a rule.
	 *
	 * @param int                                                $id       Rule ID.
	 * @param array{data: array<string, mixed>, notes: string[]} $prepared From prepare( $rule, $id ).
	 *
	 * @return array<string, mixed>|\WP_Error The rule after the change.
	 */
	public static function update( int $id, array $prepared ) {
		$data       = $prepared['data'];
		$data['id'] = $id;

		return self::save( $data );
	}

	/**
	 * Enables or disables a rule.
	 *
	 * @param int  $id      Rule ID.
	 * @param bool $enabled Active or inactive.
	 *
	 * @return true|\WP_Error True on success.
	 */
	public static function set_enabled( int $id, bool $enabled ) {
		$row = self::row( $id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		\RankMath\Redirections\DB::change_status( array( $id ), $enabled ? 'active' : 'inactive' );
		self::purge_cache( $id );

		return true;
	}

	/**
	 * Deletes a rule for good (Rank Math's own delete, which also clears its cache rows).
	 *
	 * @param int $id Rule ID.
	 *
	 * @return true|\WP_Error True on success.
	 */
	public static function delete( int $id ) {
		$row = self::row( $id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		\RankMath\Redirections\DB::delete( array( $id ) );

		return true;
	}

	/**
	 * Shapes a rule for the response, in the same fields Redirection rules have.
	 *
	 * @param array<string, mixed> $row Table row (sources serialized or not).
	 *
	 * @return array<string, mixed> The redirect.
	 */
	public static function format( array $row ): array {
		$sources = $row['sources'] ?? array();
		$sources = is_string( $sources ) ? maybe_unserialize( $sources ) : $sources;
		$sources = is_array( $sources ) ? array_values( $sources ) : array();
		$clean   = array();

		foreach ( $sources as $src ) {
			if ( ! is_array( $src ) ) {
				continue;
			}

			$comparison = (string) ( $src['comparison'] ?? 'exact' );
			$pattern    = wp_specialchars_decode( (string) ( $src['pattern'] ?? '' ) );
			$clean[]    = array(
				'pattern'     => $pattern,
				'comparison'  => $comparison,
				'ignore_case' => 'case' === ( $src['ignore'] ?? '' ),
			);
		}

		$first = $clean[0] ?? array( 'pattern' => '', 'comparison' => 'exact', 'ignore_case' => false );
		$code  = (int) ( $row['header_code'] ?? 0 );

		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'provider'    => 'rank_math',
			// Shown the way the abilities take it: an exact source as a path with its leading slash.
			'source'      => 'exact' === $first['comparison'] && '' !== $first['pattern'] && '#' !== $first['pattern'][0]
				? '/' . ltrim( $first['pattern'], '/' ) : $first['pattern'],
			'sources'     => $clean,
			'target'      => (string) ( $row['url_to'] ?? '' ),
			'code'        => $code,
			'action_type' => in_array( $code, self::NO_TARGET, true ) ? 'error' : 'url',
			'match_type'  => 'url',
			'match'       => 'regex' === $first['comparison'] ? 'regex' : $first['comparison'],
			'regex'       => 'regex' === $first['comparison'],
			'flags'       => array( 'flag_case' => $first['ignore_case'] ),
			'title'       => '',
			'group_id'    => 0,
			'position'    => 0,
			'enabled'     => 'active' === ( $row['status'] ?? '' ),
			'status'      => (string) ( $row['status'] ?? '' ),
			'hits'        => (int) ( $row['hits'] ?? 0 ),
			'last_access' => (string) ( $row['last_accessed'] ?? '' ),
		);
	}

	/**
	 * Saves through Rank Math's model and reads the rule back.
	 *
	 * @param array<string, mixed> $data Model data.
	 *
	 * @return array<string, mixed>|\WP_Error The saved rule.
	 */
	private static function save( array $data ) {
		unset( $data['last_accessed'] );

		$item = \RankMath\Redirections\Redirection::from( $data );

		if ( ! $item->has_sources() ) {
			return new WP_Error( 'invalid_source', 'Rank Math refused the source (an external URL, the homepage or an invalid pattern).' );
		}

		$id = (int) $item->save();

		if ( $id <= 0 ) {
			return new WP_Error( 'redirect_not_saved', 'Rank Math did not save the redirect.' );
		}

		return self::get_formatted( $id );
	}

	/**
	 * Loads one table row with its sources unserialized.
	 *
	 * @param int $id Rule ID.
	 *
	 * @return array<string, mixed>|\WP_Error The row.
	 */
	private static function row( int $id ) {
		$row = $id > 0 ? \RankMath\Redirections\DB::get_redirection_by_id( $id ) : false;

		if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
			return new WP_Error( 'redirect_not_found', sprintf( 'No Rank Math redirect found with ID %d.', $id ) );
		}

		return $row;
	}

	/**
	 * Clears Rank Math's cached lookups for a rule, when its cache class is there.
	 *
	 * @param int $id Rule ID.
	 *
	 * @return void
	 */
	private static function purge_cache( int $id ): void {
		if ( class_exists( '\RankMath\Redirections\Cache' ) && is_callable( array( '\RankMath\Redirections\Cache', 'purge' ) ) ) {
			\RankMath\Redirections\Cache::purge( $id );
		}
	}

	/**
	 * A source as a path on this site ("https://site.com/old/" -> "/old/"); other values as given.
	 *
	 * @param string $source Source path or URL.
	 *
	 * @return string The path.
	 */
	private static function relative( string $source ): string {
		$source = trim( $source );
		$home   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host   = (string) wp_parse_url( $source, PHP_URL_HOST );

		if ( '' !== $host ) {
			if ( strtolower( preg_replace( '/^www\./i', '', $host ) ) !== strtolower( preg_replace( '/^www\./i', '', $home ) ) ) {
				return '';
			}

			$path  = (string) wp_parse_url( $source, PHP_URL_PATH );
			$query = (string) wp_parse_url( $source, PHP_URL_QUERY );

			return '/' . ltrim( $path, '/' ) . ( '' !== $query ? '?' . $query : '' );
		}

		return $source;
	}

	/**
	 * A source or target for comparison: the path, lower-cased, without slashes at either end.
	 *
	 * @param string $url Path or URL.
	 *
	 * @return string The normalised path.
	 */
	private static function normalise( string $url ): string {
		return strtolower( trim( self::relative( $url ), '/' ) );
	}
}
