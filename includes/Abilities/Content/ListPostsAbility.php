<?php
/**
 * Ability for querying posts of any post type.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;
use WP_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Posts - Queries content of any post type.
 */
final class ListPostsAbility {

	/**
	 * Maximum number of posts returned in one call.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-posts',
			array(
				'label'               => 'List Posts',
				'description'         => 'Query posts of any post type with filters for status, search term, author, taxonomy terms, parent, and language. Returns a paginated summary list; use get-post to read a single item in full. Non-public statuses require edit permission on the post type.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'       => array(
							'type'        => 'string',
							'description' => 'Post type slug. Default "post".',
						),
						'status'          => array(
							'type'        => array( 'string', 'array' ),
							'items'       => array( 'type' => 'string' ),
							'description' => 'Post status or list of statuses, e.g. "publish", "draft", "any". Default "any" for users who can edit, "publish" otherwise.',
						),
						'search'          => array(
							'type'        => 'string',
							'description' => 'Free-text search across title and content.',
						),
						'author'          => array(
							'type'        => 'integer',
							'description' => 'Restrict to a single author user ID.',
						),
						'parent'          => array(
							'type'        => 'integer',
							'description' => 'Restrict to children of this post ID. Use 0 for top-level items only.',
						),
						'terms'           => array(
							'type'        => 'object',
							'description' => 'Taxonomy filter as an object of taxonomy slug to an array of term slugs, e.g. {"category": ["news"]}. All listed taxonomies must match.',
						),
						'meta_key'        => array(
							'type'        => 'string',
							'description' => 'Restrict to posts having this meta key.',
						),
						'meta_value'      => array(
							'type'        => 'string',
							'description' => 'Restrict to posts where meta_key equals this value. Requires meta_key.',
						),
						'language'        => array(
							'type'        => 'string',
							'description' => 'Polylang language slug to filter by. Ignored when Polylang is inactive.',
						),
						'orderby'         => array(
							'type'        => 'string',
							'enum'        => array( 'date', 'modified', 'title', 'menu_order', 'ID', 'rand' ),
							'description' => 'Sort field. Default "date".',
						),
						'order'           => array(
							'type'        => 'string',
							'enum'        => array( 'ASC', 'DESC' ),
							'description' => 'Sort direction. Default "DESC".',
						),
						'per_page'        => array(
							'type'        => 'integer',
							'description' => 'Results per page, 1-100. Default 20.',
						),
						'page'            => array(
							'type'        => 'integer',
							'description' => 'Page number, starting at 1. Default 1.',
						),
						'include_content' => array(
							'type'        => 'boolean',
							'description' => 'Include the full post content of every result. Default false, because it is large.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'posts'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
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
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		$valid     = ContentSupport::validate_post_type( $post_type );

		if ( is_wp_error( $valid ) ) {
			return AbilityRegistrar::failure( $valid );
		}

		$can_edit_type = ContentSupport::require_post_type_cap( $post_type, 'edit_posts' );
		$can_edit      = ! is_wp_error( $can_edit_type );

		$status = self::resolve_status( $input['status'] ?? null, $can_edit );

		if ( is_wp_error( $status ) ) {
			return AbilityRegistrar::failure( $status );
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => self::resolve_orderby( $input['orderby'] ?? null ),
			'order'          => isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC',
			// The abilities are called interactively; a stale count is worse than the query cost.
			'no_found_rows'  => false,
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		if ( ! empty( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}

		if ( isset( $input['parent'] ) && '' !== $input['parent'] ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		if ( ! empty( $input['meta_key'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicitly requested by the caller.
			$args['meta_key'] = (string) $input['meta_key'];

			if ( isset( $input['meta_value'] ) && '' !== $input['meta_value'] ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Explicitly requested by the caller.
				$args['meta_value'] = (string) $input['meta_value'];
			}
		}

		$tax_query = self::build_tax_query( $input['terms'] ?? null, $post_type );

		if ( is_wp_error( $tax_query ) ) {
			return AbilityRegistrar::failure( $tax_query );
		}

		if ( ! empty( $tax_query ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Explicitly requested by the caller.
			$args['tax_query'] = $tax_query;
		}

		if ( ! empty( $input['language'] ) && PolylangSupport::is_active() ) {
			$language = (string) $input['language'];
			$valid_pl = PolylangSupport::validate_language( $language );

			if ( is_wp_error( $valid_pl ) ) {
				return AbilityRegistrar::failure( $valid_pl );
			}

			$args['lang'] = $language;
		}

		$query           = new WP_Query( $args );
		$include_content = ContentSupport::to_bool( $input['include_content'] ?? null, false );

		$posts = array_map(
			static fn( $post ): array => ContentSupport::format_post(
				$post,
				array( 'include_content' => $include_content )
			),
			$query->posts
		);

		return array(
			'success'     => true,
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Resolves the requested status against the user's capabilities.
	 *
	 * @param mixed $requested Raw status input.
	 * @param bool  $can_edit  Whether the user can edit this post type.
	 *
	 * @return string|string[]|\WP_Error The status argument, or WP_Error when not permitted.
	 */
	private static function resolve_status( $requested, bool $can_edit ) {
		if ( null === $requested || '' === $requested ) {
			return $can_edit ? 'any' : 'publish';
		}

		$statuses = is_array( $requested ) ? array_map( 'strval', $requested ) : array( (string) $requested );
		$statuses = array_values( array_filter( $statuses ) );

		if ( empty( $statuses ) ) {
			return $can_edit ? 'any' : 'publish';
		}

		$public_statuses = array( 'publish' );
		$needs_edit      = array_diff( $statuses, $public_statuses );

		if ( ! empty( $needs_edit ) && ! $can_edit ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf(
					'Reading the %s status requires edit permission on this post type.',
					implode( ', ', $needs_edit )
				)
			);
		}

		return 1 === count( $statuses ) ? $statuses[0] : $statuses;
	}

	/**
	 * Resolves the orderby argument against an allowlist.
	 *
	 * @param mixed $requested Raw orderby input.
	 *
	 * @return string The orderby value.
	 */
	private static function resolve_orderby( $requested ): string {
		$allowed = array( 'date', 'modified', 'title', 'menu_order', 'ID', 'rand' );
		$value   = (string) $requested;

		return in_array( $value, $allowed, true ) ? $value : 'date';
	}

	/**
	 * Builds a tax_query from the simplified `terms` input.
	 *
	 * @param mixed  $terms     Raw terms input.
	 * @param string $post_type Post type slug.
	 *
	 * @return array<int|string, mixed>|\WP_Error The tax_query, or WP_Error when a taxonomy is invalid.
	 */
	private static function build_tax_query( $terms, string $post_type ) {
		if ( empty( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$taxonomies = get_object_taxonomies( $post_type );
		$tax_query  = array( 'relation' => 'AND' );

		foreach ( $terms as $taxonomy => $slugs ) {
			$taxonomy = (string) $taxonomy;

			if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
				return new WP_Error(
					'invalid_taxonomy',
					sprintf( 'Taxonomy "%s" is not registered for post type "%s".', $taxonomy, $post_type )
				);
			}

			$slugs = array_values( array_filter( array_map( 'strval', (array) $slugs ) ) );

			if ( empty( $slugs ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
			);
		}

		return count( $tax_query ) > 1 ? $tax_query : array();
	}
}
