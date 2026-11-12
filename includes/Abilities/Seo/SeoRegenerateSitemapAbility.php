<?php
/**
 * Ability for regenerating Rank Math's XML sitemaps.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\RankMathSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Regenerate Sitemap - Clears Rank Math's sitemap cache, rebuilds it and checks the result.
 *
 * Rank Math caches each sitemap. After a post is trashed, made private,
 * redirected or noindexed, the cached sitemap can keep listing it until the
 * cache expires. This clears the cache, optionally fetches the sitemaps so
 * they are rebuilt at once, and can confirm that given URLs are, or are no
 * longer, listed.
 */
final class SeoRegenerateSitemapAbility {

	/**
	 * Child sitemaps fetched at most when warming.
	 */
	private const MAX_SITEMAPS = 50;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'seo-regenerate-sitemap',
			array(
				'label'               => 'SEO Regenerate Sitemap',
				'description'         => 'Regenerate Rank Math\'s XML sitemaps: clears its sitemap cache (all sitemaps, or only the types given, e.g. ["offer"]), then by default fetches the sitemap index and each sitemap so they are rebuilt immediately, and reports how many URLs each lists. Pass check_urls to confirm specific pages are, or are no longer, in the sitemaps - e.g. after set-post-status made a duplicate offer private, or a redirect was added. Use flush_rewrites if the sitemap URLs return 404.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'types'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Sitemap types to clear, e.g. ["offer", "page"]. Default all.',
						),
						'warm'           => array(
							'type'        => 'boolean',
							'description' => 'Fetch the regenerated sitemaps right away. Default true.',
						),
						'check_urls'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Page URLs to look up in the regenerated sitemaps. Requires warm.',
						),
						'flush_rewrites' => array(
							'type'        => 'boolean',
							'description' => 'Also flush WordPress rewrite rules, for sitemaps that return 404. Default false.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'cleared'   => array( 'type' => 'array' ),
						'index_url' => array( 'type' => 'string' ),
						'sitemaps'  => array( 'type' => 'array' ),
						'checks'    => array( 'type' => 'array' ),
						'notes'     => array( 'type' => 'array' ),
						'error'     => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> What was cleared and what the sitemaps now list.
	 */
	public static function execute( $input = array() ): array {
		$input  = is_array( $input ) ? $input : array();
		$active = RankMathSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$allowed = RankMathSupport::require_can_manage_settings( 'sitemap' );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$types = isset( $input['types'] ) && is_array( $input['types'] )
			? array_values( array_filter( array_map( 'sanitize_key', $input['types'] ) ) )
			: array();

		$notes = array();

		if ( ContentSupport::to_bool( $input['flush_rewrites'] ?? false, false ) ) {
			flush_rewrite_rules( false );
			$notes[] = 'Rewrite rules flushed.';
		}

		$response = array(
			'success'   => true,
			'cleared'   => RankMathSupport::flush_sitemap_cache( $types ),
			'index_url' => RankMathSupport::sitemap_index_url(),
		);

		if ( ! ContentSupport::to_bool( $input['warm'] ?? true, true ) ) {
			$response['notes'] = array_merge( $notes, array( 'Not warmed: each sitemap is rebuilt the next time it is requested.' ) );
			return $response;
		}

		$index = self::fetch( $response['index_url'] );

		if ( ! $index['ok'] ) {
			$notes[]               = sprintf( 'The cache was cleared, but the sitemap index could not be fetched from the server itself (%s). Loopback requests may be blocked; the sitemaps will rebuild when a crawler requests them. If it is a 404, try again with flush_rewrites.', $index['error'] );
			$response['sitemaps'] = array();
			$response['notes']    = $notes;
			return $response;
		}

		$children = self::locs( $index['body'] );
		$sitemaps = array();
		$listed   = array();

		foreach ( array_slice( $children, 0, self::MAX_SITEMAPS ) as $child ) {
			if ( ! empty( $types ) && ! self::is_type( $child, $types ) ) {
				continue;
			}

			$fetched = self::fetch( $child );
			$urls    = $fetched['ok'] ? self::locs( $fetched['body'] ) : array();

			$sitemaps[] = array(
				'url'    => $child,
				'status' => $fetched['status'],
				'urls'   => count( $urls ),
			) + ( $fetched['ok'] ? array() : array( 'error' => $fetched['error'] ) );

			foreach ( $urls as $url ) {
				$listed[ self::key( $url ) ] = $child;
			}
		}

		if ( count( $children ) > self::MAX_SITEMAPS ) {
			$notes[] = sprintf( 'Only the first %d of %d sitemaps were fetched.', self::MAX_SITEMAPS, count( $children ) );
		}

		$response['sitemaps'] = $sitemaps;

		$check_urls = isset( $input['check_urls'] ) && is_array( $input['check_urls'] ) ? $input['check_urls'] : array();

		if ( ! empty( $check_urls ) ) {
			$checks = array();

			foreach ( $check_urls as $url ) {
				$url = is_scalar( $url ) ? (string) $url : '';
				$key = self::key( $url );

				$checks[] = array(
					'url'        => $url,
					'in_sitemap' => isset( $listed[ $key ] ),
					'sitemap'    => $listed[ $key ] ?? '',
				);
			}

			$response['checks'] = $checks;

			if ( ! empty( $types ) ) {
				$notes[] = 'check_urls only looked in the sitemaps of the types given.';
			}
		}

		if ( ! empty( $notes ) ) {
			$response['notes'] = $notes;
		}

		return $response;
	}

	/**
	 * Fetches a sitemap from this site.
	 *
	 * @param string $url Sitemap URL.
	 *
	 * @return array{ok: bool, status: int, body: string, error: string} The response.
	 */
	private static function fetch( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 2,
				// The same allowance core makes for its own loopback requests.
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => '',
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		return array(
			'ok'     => 200 === $status && false !== strpos( $body, '<loc>' ),
			'status' => $status,
			'body'   => $body,
			'error'  => 200 === $status ? 'no <loc> entries in the response' : sprintf( 'HTTP %d', $status ),
		);
	}

	/**
	 * Extracts the <loc> entries of a sitemap or sitemap index.
	 *
	 * @param string $xml Sitemap XML.
	 *
	 * @return string[] URLs.
	 */
	private static function locs( string $xml ): array {
		if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#s', $xml, $m ) ) {
			return array();
		}

		return array_map( static fn( string $loc ): string => html_entity_decode( $loc, ENT_QUOTES | ENT_XML1, 'UTF-8' ), $m[1] );
	}

	/**
	 * Whether a child sitemap URL is one of the given types, e.g. "offer-sitemap2.xml" for "offer".
	 *
	 * @param string   $url   Child sitemap URL.
	 * @param string[] $types Types.
	 *
	 * @return bool True on a match.
	 */
	private static function is_type( string $url, array $types ): bool {
		$file = (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		foreach ( $types as $type ) {
			if ( preg_match( '/^' . preg_quote( $type, '/' ) . '-sitemap\d*\.xml$/', $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Comparable form of a URL: host without "www.", path without trailing slash.
	 *
	 * @param string $url URL.
	 *
	 * @return string The key.
	 */
	private static function key( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		return $host . '/' . trim( mb_strtolower( $path ), '/' );
	}
}
