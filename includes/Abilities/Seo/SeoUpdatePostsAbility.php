<?php
/**
 * Ability for writing the SEO fields (Rank Math or Yoast SEO) of posts, one or many at a time.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\SeoBackend;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Update Posts - Writes Rank Math or Yoast SEO fields on one or many posts in one call.
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
			SeoBackend::post_field_schema()
		);

		AbilityRegistrar::register(
			'seo-update-posts',
			array(
				'label'               => 'Update SEO Fields',
				'description'         => 'Write SEO fields in the active SEO plugin (Rank Math or Yoast) on up to 100 posts per call. advanced_robots and twitter_use_facebook are Rank Math only; twitter_image and schema_* are Yoast only; unsupported fields are listed under "ignored". Only passed fields change. null or empty clears a field back to the plugin default: canonical_url becomes the page itself, robots falls back to the post type default (use this when pages carry their own "index" over a sitewide noindex). Each post is validated and reported separately; one bad field means nothing is written for that post. dry_run validates without saving. Call purge-cache afterwards.',
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
						'provider' => SeoBackend::provider_schema(),
					),
					'required'   => array( 'posts' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'provider'   => array( 'type' => 'string' ),
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
		$input   = is_array( $input ) ? $input : array();
		$backend = SeoBackend::resolve( $input );

		if ( is_wp_error( $backend ) ) {
			return AbilityRegistrar::failure( $backend );
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

			$changes = array_intersect_key( $item, $backend::POST_FIELDS );
			$ignored = array_values( array_diff( array_keys( $item ), array_keys( $backend::POST_FIELDS ), array( 'post_id' ) ) );

			if ( empty( $changes ) ) {
				++$failed;
				$results[] = array(
					'post_id' => $post->ID,
					'success' => false,
					'error'   => sprintf( 'No SEO fields given. Pass any of: %s.', implode( ', ', array_keys( $backend::POST_FIELDS ) ) ),
					'ignored' => $ignored,
				);
				continue;
			}

			$before = $dry_run ? $backend::read_post( $post )['seo'] : null;

			// Validate every field before writing any, so a post is never left
			// half-updated because one of its fields was rejected.
			$write = $backend::write_post( $post, $changes, true );
			$ok    = empty( $write['errors'] );

			if ( $ok && ! $dry_run ) {
				$write = $backend::write_post( $post, $changes, false );
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

			if ( ! empty( $ignored ) ) {
				$entry['ignored'] = $ignored;
			}

			if ( isset( $write['indexable_rebuilt'] ) ) {
				$entry['indexable_rebuilt'] = (bool) $write['indexable_rebuilt'];
			}

			if ( $dry_run ) {
				$entry['current'] = array_intersect_key( (array) $before, $changes );
			} else {
				$after             = $backend::read_post( $post );
				$entry['seo']      = array_intersect_key( $after['seo'], $changes );
				$entry['effective'] = $after['effective'];

				if ( isset( $after['indexable'] ) ) {
					$entry['indexable'] = $after['indexable'];
				}
			}

			$results[] = $entry;
		}

		return array(
			'success'  => true,
			'provider' => SeoBackend::name_of( $backend ),
			'dry_run'  => $dry_run,
			'results' => $results,
			'updated' => $updated,
			'failed'  => $failed,
		);
	}
}
