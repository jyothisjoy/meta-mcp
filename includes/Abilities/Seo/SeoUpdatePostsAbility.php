<?php
/**
 * Ability for writing the Rank Math SEO fields of posts, one or many at a time.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\RankMathSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Update Posts - Writes Rank Math SEO fields on one or many posts in one call.
 *
 * Batching is the point: a typical SEO pass clears canonicals on a dozen posts,
 * rewrites titles on a dozen pages and noindexes a handful of account pages,
 * and doing that as one reviewable call is far easier to approve than forty.
 */
final class SeoUpdatePostsAbility {

	/**
	 * Largest batch accepted in one call.
	 */
	private const MAX_POSTS = 100;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$item_properties = array_merge(
			array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The post to change.',
				),
			),
			RankMathSupport::post_field_schema()
		);

		AbilityRegistrar::register(
			'seo-update-posts',
			array(
				'label'               => 'Update Rank Math SEO Fields',
				'description'         => 'Write Rank Math SEO fields on up to 100 posts in one call: SEO title, meta description, focus keyword, canonical URL, robots, advanced robots, breadcrumb title and social fields. Only the fields you pass are changed. Passing null or an empty value clears a field so Rank Math falls back to its default: clearing canonical_url makes the page canonical to itself, and clearing robots lets the post type default from Titles & Meta apply (use this when a sitewide noindex is overridden by pages that carry their own "index"). Each post is checked and reported separately. Set dry_run to validate without saving. Page caches (LiteSpeed etc.) are not purged; call purge-cache afterwards.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'posts'   => array(
							'type'        => 'array',
							'description' => 'One entry per post: post_id plus the fields to change.',
							'items'       => array(
								'type'       => 'object',
								'properties' => $item_properties,
								'required'   => array( 'post_id' ),
							),
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => 'Validate and report what would change without saving anything. Default false.',
						),
					),
					'required'   => array( 'posts' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'results'    => array( 'type' => 'array' ),
						'updated'    => array( 'type' => 'integer' ),
						'failed'     => array( 'type' => 'integer' ),
						'error'      => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The per-post outcome.
	 */
	public static function execute( $input = array() ): array {
		$active = RankMathSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$items = isset( $input['posts'] ) && is_array( $input['posts'] ) ? array_values( $input['posts'] ) : array();

		if ( empty( $items ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_posts', 'posts must list at least one post_id with the fields to change.' ) );
		}

		if ( count( $items ) > self::MAX_POSTS ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'too_many_posts', sprintf( 'At most %d posts can be changed in one call; split the batch.', self::MAX_POSTS ) )
			);
		}

		$dry_run = ContentSupport::to_bool( $input['dry_run'] ?? null, false );
		$results = array();
		$updated = 0;
		$failed  = 0;

		foreach ( $items as $item ) {
			$item    = is_array( $item ) ? $item : array();
			$post_id = (int) ( $item['post_id'] ?? 0 );
			$post    = ContentSupport::require_can_edit( $post_id );

			if ( is_wp_error( $post ) ) {
				++$failed;
				$results[] = array(
					'post_id' => $post_id,
					'success' => false,
					'error'   => $post->get_error_message(),
				);
				continue;
			}

			$changes = array_intersect_key( $item, RankMathSupport::POST_FIELDS );

			if ( empty( $changes ) ) {
				++$failed;
				$results[] = array(
					'post_id' => $post->ID,
					'success' => false,
					'error'   => sprintf( 'No SEO fields given. Pass any of: %s.', implode( ', ', array_keys( RankMathSupport::POST_FIELDS ) ) ),
				);
				continue;
			}

			$before = $dry_run ? RankMathSupport::read_post( $post )['seo'] : null;

			// Validate every field before writing any, so a post is never left
			// half-updated because one of its fields was rejected.
			$write = RankMathSupport::write_post( $post, $changes, true );
			$ok    = empty( $write['errors'] );

			if ( $ok && ! $dry_run ) {
				$write = RankMathSupport::write_post( $post, $changes, false );
			}

			if ( $ok ) {
				++$updated;
			} else {
				++$failed;
			}

			$entry = array(
				'post_id' => $post->ID,
				'title'   => $post->post_title,
				'success' => $ok,
				'updated' => $write['updated'],
				'deleted' => $write['deleted'],
				'errors'  => (object) $write['errors'],
			);

			if ( $dry_run ) {
				$entry['current'] = array_intersect_key( (array) $before, $changes );
			} else {
				$after             = RankMathSupport::read_post( $post );
				$entry['seo']      = array_intersect_key( $after['seo'], $changes );
				$entry['effective'] = $after['effective'];
			}

			$results[] = $entry;
		}

		return array(
			'success' => true,
			'dry_run' => $dry_run,
			'results' => $results,
			'updated' => $updated,
			'failed'  => $failed,
		);
	}
}
