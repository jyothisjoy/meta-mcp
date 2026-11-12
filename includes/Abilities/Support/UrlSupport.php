<?php
/**
 * Resolves front-end URLs to the posts that render them.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - UrlSupport
 *
 * Every write ability addresses a post by id, while an SEO audit, a crawler or
 * a person names pages by URL. This bridges the two.
 *
 * `url_to_postid()` alone is not trustworthy here: it ignores custom post type
 * rewrites on some setups, and on a Polylang site whose languages share slugs it
 * can return the post in the wrong language. So every candidate is confirmed by
 * comparing its own permalink with the URL that was asked for, and a slug lookup
 * is used when `url_to_postid()` finds nothing that confirms.
 */
final class UrlSupport {

	/**
	 * Statuses a URL may resolve to. Drafts have no public URL to match.
	 */
	private const STATUSES = array( 'publish', 'private', 'future' );

	/**
	 * Resolves one URL, or site-relative path, to a post.
	 *
	 * @param string $url Absolute URL on this site, or a path such as "/room/deluxe/".
	 *
	 * @return array<string, mixed>|\WP_Error The post it resolves to, with a ready-made Elementor display condition.
	 */
	public static function resolve( string $url ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return new WP_Error( 'empty_url', 'URL is empty.' );
		}

		$normalized = self::normalize( $url );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		// The homepage, or a language's homepage on a Polylang site.
		$front = self::resolve_front_page( $normalized );

		if ( null !== $front ) {
			return $front;
		}

		$post_id = (int) url_to_postid( $normalized );

		if ( $post_id > 0 && self::permalink_matches( $post_id, $normalized ) ) {
			return self::describe( $post_id, 'url_to_postid' );
		}

		$by_slug = self::resolve_by_slug( $normalized );

		if ( $by_slug > 0 ) {
			return self::describe( $by_slug, 'permalink' );
		}

		// url_to_postid found something whose permalink differs, e.g. because the
		// URL carries a trailing segment WordPress ignores. Better than nothing,
		// but say so.
		if ( $post_id > 0 ) {
			return self::describe( $post_id, 'url_to_postid_unconfirmed' );
		}

		return new WP_Error(
			'url_not_resolved',
			sprintf(
				'No post on this site has the URL "%s". It may be an archive, a redirect (see redirects-list), or a page served by another system such as a booking engine.',
				$url
			)
		);
	}

	/**
	 * Whether a string looks like a URL or a site-relative path rather than an
	 * Elementor condition.
	 *
	 * @param string $value Candidate.
	 *
	 * @return bool True for "https://...", "//host/..." and "/path".
	 */
	public static function looks_like_url( string $value ): bool {
		return (bool) preg_match( '#^(https?:)?//#i', $value ) || 0 === strpos( $value, '/' );
	}

	/**
	 * Builds the Elementor Pro display condition that targets one post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $mode    "include" or "exclude".
	 *
	 * @return string E.g. "include/singular/page/12" or "include/singular/hotel_room/48".
	 */
	public static function condition_for_post( int $post_id, string $mode = 'include' ): string {
		$post_type = (string) get_post_type( $post_id );

		return sprintf( '%s/singular/%s/%d', 'exclude' === $mode ? 'exclude' : 'include', $post_type, $post_id );
	}

	/**
	 * Makes a URL absolute, on this site, and without query or fragment.
	 *
	 * @param string $url Raw URL or path.
	 *
	 * @return string|\WP_Error The normalized URL.
	 */
	private static function normalize( string $url ) {
		$home = (string) home_url( '/' );

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = rtrim( $home, '/' ) . $url;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid_url', sprintf( '"%s" is not a valid URL.', $url ) );
		}

		$home_host = (string) wp_parse_url( $home, PHP_URL_HOST );

		if ( self::bare_host( (string) $parts['host'] ) !== self::bare_host( $home_host ) ) {
			return new WP_Error(
				'foreign_url',
				sprintf( '"%s" is not on this site (%s).', $url, $home_host )
			);
		}

		$scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );
		$path   = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '/';

		return $scheme . '://' . $home_host . ( '' === $path ? '/' : $path );
	}

	/**
	 * Lower-cases a host and drops a leading "www.".
	 *
	 * @param string $host Host name.
	 *
	 * @return string The comparable host.
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( $host );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Compares two URLs by path, ignoring scheme, "www.", trailing slash and case.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 *
	 * @return bool True when they name the same page.
	 */
	private static function same_url( string $a, string $b ): bool {
		$key = static function ( string $url ): string {
			$host = self::bare_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );

			return $host . '/' . trim( mb_strtolower( $path ), '/' );
		};

		return $key( $a ) === $key( $b );
	}

	/**
	 * Whether a post's permalink is the URL that was asked for.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Normalized URL.
	 *
	 * @return bool True on a match.
	 */
	private static function permalink_matches( int $post_id, string $url ): bool {
		$permalink = get_permalink( $post_id );

		return is_string( $permalink ) && self::same_url( $permalink, $url );
	}

	/**
	 * Resolves the site's homepage, or a Polylang language's homepage.
	 *
	 * @param string $url Normalized URL.
	 *
	 * @return array<string, mixed>|null The front page, or null when the URL is not a homepage.
	 */
	private static function resolve_front_page( string $url ): ?array {
		$homes = array( '' => (string) home_url( '/' ) );

		if ( PolylangSupport::is_active() && function_exists( 'pll_home_url' ) ) {
			foreach ( PolylangSupport::language_slugs() as $slug ) {
				$homes[ $slug ] = (string) pll_home_url( $slug );
			}
		}

		foreach ( $homes as $language => $home ) {
			if ( ! self::same_url( $home, $url ) ) {
				continue;
			}

			$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

			if ( $front_id > 0 && '' !== $language ) {
				$translated = PolylangSupport::get_translated_post( $front_id, (string) $language );
				$front_id   = $translated > 0 ? $translated : $front_id;
			}

			if ( $front_id > 0 ) {
				$described              = self::describe( $front_id, 'front_page' );
				$described['is_front']  = true;
				$described['condition'] = '' === $language
					? 'include/singular/front_page'
					: self::condition_for_post( $front_id );

				return $described;
			}

			// A blog-style homepage has no post behind it.
			return array(
				'url'        => $url,
				'post_id'    => 0,
				'post_type'  => '',
				'title'      => (string) get_bloginfo( 'name' ),
				'is_front'   => true,
				'matched_by' => 'front_page',
				'condition'  => 'include/singular/front_page',
			);
		}

		return null;
	}

	/**
	 * Finds a post by the last path segment, confirmed against its permalink.
	 *
	 * @param string $url Normalized URL.
	 *
	 * @return int Post ID, or 0.
	 */
	private static function resolve_by_slug( string $url ): int {
		$path     = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$segments = '' === $path ? array() : explode( '/', $path );
		$slug     = end( $segments );

		if ( ! is_string( $slug ) || '' === $slug ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'name'                   => sanitize_title( $slug ),
				'post_type'              => array_values( get_post_types( array( 'public' => true ) ) ),
				'post_status'            => self::STATUSES,
				'posts_per_page'         => 20,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lang'                   => '',
			)
		);

		foreach ( $query->posts as $candidate ) {
			if ( self::permalink_matches( (int) $candidate, $url ) ) {
				return (int) $candidate;
			}
		}

		return 0;
	}

	/**
	 * Shapes a resolved post for a response.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $matched_by How it was found.
	 *
	 * @return array<string, mixed> The post summary.
	 */
	private static function describe( int $post_id, string $matched_by ): array {
		$post = get_post( $post_id );
		$data = array(
			'post_id'    => $post_id,
			'post_type'  => $post instanceof WP_Post ? $post->post_type : '',
			'title'      => $post instanceof WP_Post ? $post->post_title : '',
			'status'     => $post instanceof WP_Post ? $post->post_status : '',
			'url'        => (string) get_permalink( $post_id ),
			'is_front'   => false,
			'matched_by' => $matched_by,
			'condition'  => self::condition_for_post( $post_id ),
			'elementor'  => ElementorSupport::is_built_with_elementor( $post_id ),
		);

		$language = PolylangSupport::get_post_language( $post_id );

		if ( null !== $language ) {
			$data['language'] = $language['slug'];
		}

		return $data;
	}
}
