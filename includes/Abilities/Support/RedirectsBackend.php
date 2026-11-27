<?php
/**
 * Picks the redirect manager the redirect abilities work with.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - RedirectsBackend
 *
 * A site keeps its redirects in the Redirection plugin or in Rank Math's
 * Redirections module (or, rarely, both). The redirect abilities speak one
 * format and leave the storage to a backend class with the same static methods:
 *
 *   provider(), is_active(), version(), require_active(), require_cap( $action ),
 *   list( $args ), groups(), find_by_source( $source ), get_formatted( $id ),
 *   prepare( $rule, $existing_id ), create( $prepared, $enabled ),
 *   update( $id, $prepared ), set_enabled( $id, $enabled ), delete( $id ).
 *
 * Without a `provider` input the one that is active is used; when both are,
 * Redirection (the one the abilities were first written for) unless the input
 * says otherwise.
 */
final class RedirectsBackend {

	/**
	 * A URL or path as a comparable path on this site, or null for another site.
	 *
	 * @param string $url URL or path.
	 *
	 * @return string|null Lower-case path with one trailing slash, e.g. "/rooms/".
	 */
	public static function local_path( string $url ): ?string {
		$url = trim( $url );

		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$home  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' !== $host && preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $home ) ) {
			return null;
		}

		return '/' . trim( strtolower( (string) ( $parts['path'] ?? '' ) ), '/' ) . '/';
	}

	/**
	 * Follows a target through plain redirects and reports a loop back to the source.
	 *
	 * @param string                $source Plain source path of the new rule.
	 * @param string                $target Its target.
	 * @param array<string, string> $batch  Other new plain rules: source path => target.
	 * @param string                $class  Backend class (for its existing rules).
	 *
	 * @return string|null The loop as "a -> b -> a", or null when there is none.
	 */
	public static function find_loop( string $source, string $target, array $batch, string $class ): ?string {
		$start = self::local_path( $source );
		$next  = self::local_path( $target );
		$chain = array( $start );

		for ( $hop = 0; null !== $start && null !== $next && $hop < 10; $hop++ ) {
			$chain[] = $next;

			if ( $next === $start ) {
				return implode( ' -> ', $chain );
			}

			if ( isset( $batch[ $next ] ) ) {
				$next = self::local_path( $batch[ $next ] );
				continue;
			}

			$existing = $class::find_by_source( $next );

			if ( empty( $existing ) || empty( $existing[0]['enabled'] ) ) {
				return null;
			}

			$next = self::local_path( (string) $existing[0]['target'] );
		}

		return null;
	}

	/**
	 * Backend classes by provider name, in default order.
	 */
	private const PROVIDERS = array(
		'redirection' => RedirectionSupport::class,
		'rank_math'   => RankMathRedirectsSupport::class,
	);

	/**
	 * Provider names.
	 *
	 * @return string[] Names.
	 */
	public static function names(): array {
		return array_keys( self::PROVIDERS );
	}

	/**
	 * Whether any redirect manager is usable.
	 *
	 * @return bool True when Redirection or Rank Math's Redirections module is active.
	 */
	public static function any_active(): bool {
		foreach ( self::PROVIDERS as $class ) {
			if ( $class::is_active() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The active providers.
	 *
	 * @return string[] Names.
	 */
	public static function active(): array {
		return array_values( array_filter( self::names(), static fn( string $name ): bool => self::PROVIDERS[ $name ]::is_active() ) );
	}

	/**
	 * The backend class for this call.
	 *
	 * @param array<string, mixed> $input Ability input (optional "provider").
	 *
	 * @return string|\WP_Error Backend class name.
	 */
	public static function resolve( array $input ) {
		$wanted = isset( $input['provider'] ) ? strtolower( trim( (string) $input['provider'] ) ) : '';

		if ( '' !== $wanted ) {
			if ( ! isset( self::PROVIDERS[ $wanted ] ) ) {
				return new WP_Error( 'invalid_provider', sprintf( 'provider must be one of: %s.', implode( ', ', self::names() ) ) );
			}

			$class  = self::PROVIDERS[ $wanted ];
			$active = $class::require_active();

			return is_wp_error( $active ) ? $active : $class;
		}

		foreach ( self::PROVIDERS as $class ) {
			if ( $class::is_active() ) {
				return $class;
			}
		}

		return new WP_Error(
			'no_redirect_manager',
			'No redirect manager is active on this site. Turn on Rank Math → Dashboard → Modules → Redirections, or install and activate the Redirection plugin.'
		);
	}

	/**
	 * Input property for choosing the provider.
	 *
	 * @return array<string, mixed> Schema property.
	 */
	public static function provider_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => self::names(),
			'description' => '"redirection" (Redirection plugin) or "rank_math" (Rank Math Redirections). Default: the active one (Redirection if both).',
		);
	}

	/**
	 * Input schema properties shared by create and update, for either provider.
	 *
	 * @return array<string, array<string, mixed>> Schema properties.
	 */
	public static function rule_schema(): array {
		$schema = RedirectionSupport::rule_schema();

		$schema['code']['enum']        = array_values( array_unique( array_merge( RedirectionSupport::REDIRECT_CODES, RedirectionSupport::ERROR_CODES, RankMathRedirectsSupport::CODES ) ) );
		$schema['code']['description'] = '301 (default) permanent, 302/307 temporary, 410 gone. Redirection also: 303, 308, 404. Rank Math also: 451.';
		$schema['match']               = array(
			'type'        => 'string',
			'enum'        => array( 'exact', 'contains', 'start', 'end' ),
			'description' => 'Rank Math only: how a plain source is compared with the URL. "exact" (default), or the URL "contains", "start"s or "end"s with the source. Use regex true for a pattern.',
		);

		foreach ( array( 'ignore_trailing_slash', 'query', 'group_id', 'position', 'title' ) as $key ) {
			if ( isset( $schema[ $key ]['description'] ) ) {
				$schema[ $key ]['description'] .= ' Redirection only.';
			}
		}

		return $schema;
	}
}
