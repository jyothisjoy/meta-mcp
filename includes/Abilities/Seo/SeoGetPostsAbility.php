<?php
/**
 * Ability for reading the Rank Math SEO fields of posts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\RankMathSupport;
use WP_Error;
use WP_Post;
use WP_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Get Posts - Reads Rank Math titles, descriptions, canonicals and robots.
 */
final class SeoGetPostsAbility {

	/**
	 * Largest page size accepted.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Statuses read when listing by post type.
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'seo-get-posts',
			array(
				'label'               => 'Get Rank Math SEO Fields',
				'description'         => 'Read the Rank Math SEO fields of posts: SEO title, meta description, focus keyword, canonical URL, robots and social fields, plus what Rank Math will actually output (effective robots and where they come from, effective canonical, title template) and the post type defaults. Pass post_ids, or post_type to page through a whole type. Use filter to find problem pages, e.g. "robots_override" lists posts whose own robots setting overrides the sitewide default, and "canonical_override" lists posts canonicalised elsewhere.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_ids'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Specific posts to read, up to 100.',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Read every post of this type instead, paginated. Ignored when post_ids is given.',
						),
						'filter'    => array(
							'type'        => 'string',
							'enum'        => array( 'robots_override', 'canonical_override', 'missing_title', 'missing_description', 'noindex' ),
							'description' => 'With post_type, only return posts that: carry their own robots setting; carry a canonical override; have no SEO title; have no meta description; or have "noindex" stored on the post.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Posts per page with post_type. Default 20, maximum 100.',
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number with post_type. Default 1.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'posts'       => array( 'type' => 'array' ),
						'errors'      => array( 'type' => 'object' ),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
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
	 * @return array<string, mixed> The SEO data.
	 */
	public static function execute( $input = array() ): array {
		$active = RankMathSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$input = is_array( $input ) ? $input : array();

		if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
			return self::by_ids( $input['post_ids'] );
		}

		if ( ! empty( $input['post_type'] ) ) {
			return self::by_type( $input );
		}

		return AbilityRegistrar::failure( new WP_Error( 'missing_target', 'Pass post_ids or post_type.' ) );
	}

	/**
	 * Reads specific posts.
	 *
	 * @param array<mixed> $ids Post IDs.
	 *
	 * @return array<string, mixed> The result.
	 */
	private static function by_ids( array $ids ): array {
		$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, self::MAX_PER_PAGE );

		$posts  = array();
		$errors = array();

		foreach ( $ids as $id ) {
			$post = ContentSupport::require_can_read( $id );

			if ( is_wp_error( $post ) ) {
				$errors[ (string) $id ] = $post->get_error_message();
				continue;
			}

			$posts[] = RankMathSupport::read_post( $post );
		}

		return array(
			'success' => true,
			'posts'   => $posts,
			'errors'  => (object) $errors,
			'total'   => count( $posts ),
		);
	}

	/**
	 * Reads a page of posts of one type.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 *
	 * @return array<string, mixed> The result.
	 */
	private static function by_type( array $input ): array {
		$post_type = (string) $input['post_type'];
		$valid     = ContentSupport::validate_post_type( $post_type );

		if ( is_wp_error( $valid ) ) {
			return AbilityRegistrar::failure( $valid );
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'              => $post_type,
			'post_status'            => self::STATUSES,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'update_post_term_cache' => false,
			// Polylang would otherwise narrow the query to one language.
			'lang'                   => '',
		);

		$meta_query = self::filter_meta_query( (string) ( $input['filter'] ?? '' ) );

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- Admin-only filtered read.
		}

		$query = new WP_Query( $args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$posts[] = RankMathSupport::read_post( $post );
		}

		return array(
			'success'     => true,
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Builds the meta query for a filter.
	 *
	 * @param string $filter Filter name.
	 *
	 * @return array<mixed> The meta query, or an empty array.
	 */
	private static function filter_meta_query( string $filter ): array {
		switch ( $filter ) {
			case 'robots_override':
				return array(
					array(
						'key'     => 'rank_math_robots',
						'compare' => 'EXISTS',
					),
				);

			case 'noindex':
				return array(
					array(
						'key'     => 'rank_math_robots',
						'value'   => '"noindex"',
						'compare' => 'LIKE',
					),
				);

			case 'canonical_override':
				return array(
					array(
						'key'     => 'rank_math_canonical_url',
						'value'   => '',
						'compare' => '!=',
					),
				);

			case 'missing_title':
				return array(
					'relation' => 'OR',
					array(
						'key'     => 'rank_math_title',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'rank_math_title',
						'value' => '',
					),
				);

			case 'missing_description':
				return array(
					'relation' => 'OR',
					array(
						'key'     => 'rank_math_description',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'rank_math_description',
						'value' => '',
					),
				);
		}

		return array();
	}
}
