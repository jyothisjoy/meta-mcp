<?php
/**
 * Ability for reading the SEO fields (Rank Math or Yoast SEO) of posts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\SeoBackend;
use WP_Error;
use WP_Post;
use WP_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Get Posts - Reads SEO titles, descriptions, canonicals and robots from Rank Math or Yoast SEO.
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
				'label'               => 'Get SEO Fields',
				'description'         => 'Read SEO fields of posts from the active SEO plugin (Rank Math or Yoast): title, description, focus keyword, canonical, robots, breadcrumb title, social fields, plus what the plugin actually outputs ("effective" robots with their source, canonical, title/description templates) and post type defaults. With Yoast, "effective" adds frontend_* values and "indexable" shows what the page prints. Pass post_ids, or post_type to page through a type. filter finds problem pages, e.g. "robots_override" (post overrides type default) or "canonical_override".',
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
						'provider'  => SeoBackend::provider_schema(),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'provider'    => array( 'type' => 'string' ),
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
		$input   = is_array( $input ) ? $input : array();
		$backend = SeoBackend::resolve( $input );

		if ( is_wp_error( $backend ) ) {
			return AbilityRegistrar::failure( $backend );
		}

		if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
			return array( 'provider' => SeoBackend::name_of( $backend ) ) + self::by_ids( $backend, $input['post_ids'] );
		}

		if ( ! empty( $input['post_type'] ) ) {
			$result = self::by_type( $backend, $input );

			return empty( $result['success'] ) ? $result : array( 'provider' => SeoBackend::name_of( $backend ) ) + $result;
		}

		return AbilityRegistrar::failure( new WP_Error( 'missing_target', 'Pass post_ids or post_type.' ) );
	}

	/**
	 * Reads specific posts.
	 *
	 * @param string       $backend Backend class.
	 * @param array<mixed> $ids     Post IDs.
	 *
	 * @return array<string, mixed> The result.
	 */
	private static function by_ids( string $backend, array $ids ): array {
		$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, self::MAX_PER_PAGE );

		$posts  = array();
		$errors = array();

		foreach ( $ids as $id ) {
			$post = ContentSupport::require_can_read( $id );

			if ( is_wp_error( $post ) ) {
				$errors[ (string) $id ] = $post->get_error_message();
				continue;
			}

			$posts[] = $backend::read_post( $post );
		}

		$defaults = self::hoist_defaults( $posts );

		return array(
			'success'            => true,
			'posts'              => $posts,
			'post_type_defaults' => (object) $defaults,
			'errors'             => (object) $errors,
			'total'              => count( $posts ),
		);
	}

	/**
	 * Reads a page of posts of one type.
	 *
	 * @param string               $backend Backend class.
	 * @param array<string, mixed> $input   Input parameters.
	 *
	 * @return array<string, mixed> The result.
	 */
	private static function by_type( string $backend, array $input ): array {
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

		$meta_query = $backend::filter_meta_query( (string) ( $input['filter'] ?? '' ) );

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

			$posts[] = $backend::read_post( $post );
		}

		$defaults = self::hoist_defaults( $posts );

		return array(
			'success'            => true,
			'posts'              => $posts,
			'post_type_defaults' => (object) $defaults,
			'total'              => (int) $query->found_posts,
			'total_pages'        => (int) $query->max_num_pages,
		);
	}

	/**
	 * Moves each post's post_type_defaults to one entry per post type.
	 *
	 * The defaults are the same for every post of a type, and repeating them on
	 * each of up to 100 rows made most of the response.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts as read_post() returns them; changed in place.
	 *
	 * @return array<string, mixed> Defaults keyed by post type.
	 */
	private static function hoist_defaults( array &$posts ): array {
		$defaults = array();

		foreach ( $posts as $i => $post ) {
			if ( isset( $post['post_type_defaults'] ) ) {
				$defaults[ (string) $post['post_type'] ] = $post['post_type_defaults'];
				unset( $posts[ $i ]['post_type_defaults'] );
			}
		}

		return $defaults;
	}
}
