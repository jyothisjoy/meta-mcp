<?php
/**
 * Ability for listing media library items, e.g. images without alt text.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Media - Pages through the media library, with a "missing alt text" filter.
 *
 * The other half of update-media: an alt-text pass over hundreds of images
 * needs to know which ones lack it, what each shows (file name, title,
 * caption) and where it is used (the post it was uploaded to).
 */
final class ListMediaAbility {

	/**
	 * Maximum number of items returned in one call.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-media',
			array(
				'label'               => 'List Media',
				'description'         => 'Page through the media library, up to 100 items per call. Set missing_alt to true to list only images that have no alt text: the input for a bulk alt-text pass, written back 100 at a time with update-media. Each item has attachment_id, url, file name, title, caption, current alt_text, dimensions, and the post it was uploaded to (often the page it appears on). Filter by mime_type (default "image"), search (title or file name), parent post or language. Read-only.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'missing_alt' => array(
							'type'        => 'boolean',
							'description' => 'Only items whose alt text is missing or empty. Default false.',
						),
						'mime_type'   => array(
							'type'        => 'string',
							'description' => 'Mime type or prefix, e.g. "image", "image/webp", "application/pdf". Default "image".',
						),
						'search'      => array(
							'type'        => 'string',
							'description' => 'Search titles and file names.',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'Only items uploaded to this post. 0 for unattached items.',
						),
						'language'    => array(
							'type'        => 'string',
							'description' => 'Polylang language slug, when media is translated. Ignored otherwise.',
						),
						'per_page'    => array(
							'type'        => 'integer',
							'description' => 'Items per page, 1-100. Default 50.',
						),
						'page'        => array(
							'type'        => 'integer',
							'description' => 'Page number, starting at 1.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'items'       => array(
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
				'permission_callback' => array( self::class, 'permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => true,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Media library access is the same as in wp-admin: "upload_files".
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function permission() {
		$allowed = AbilityRegistrar::allow_read();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'insufficient_capability', 'Listing the media library requires the "upload_files" capability.' );
		}

		return true;
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The page of media items.
	 */
	public static function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$per_page = isset( $input['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, (int) $input['per_page'] ) ) : 50;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$mime     = isset( $input['mime_type'] ) && '' !== trim( (string) $input['mime_type'] ) ? trim( (string) $input['mime_type'] ) : 'image';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => $mime,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( ContentSupport::to_bool( $input['missing_alt'] ?? false, false ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- The whole point of the filter.
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wp_attachment_image_alt',
					'value' => '',
				),
			);
		}

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = trim( (string) $input['search'] );
		}

		if ( isset( $input['parent'] ) && is_numeric( $input['parent'] ) ) {
			$args['post_parent'] = max( 0, (int) $input['parent'] );
		}

		$language = isset( $input['language'] ) ? trim( (string) $input['language'] ) : '';

		if ( '' !== $language && PolylangSupport::is_active() ) {
			$valid = PolylangSupport::validate_language( $language );

			if ( is_wp_error( $valid ) ) {
				return AbilityRegistrar::failure( $valid );
			}

			$args['lang'] = $language;
		} else {
			// Polylang filters attachments to the admin language otherwise.
			$args['lang'] = '';
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = self::format( $post );
			}
		}

		return array(
			'success'     => true,
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Shapes one attachment.
	 *
	 * @param \WP_Post $post The attachment.
	 *
	 * @return array<string, mixed> The item.
	 */
	private static function format( WP_Post $post ): array {
		$meta = wp_get_attachment_metadata( $post->ID );
		$file = (string) get_attached_file( $post->ID );
		$item = array(
			'attachment_id' => $post->ID,
			'url'           => (string) wp_get_attachment_url( $post->ID ),
			'file'          => '' !== $file ? wp_basename( $file ) : '',
			'mime_type'     => $post->post_mime_type,
			'title'         => $post->post_title,
			'caption'       => $post->post_excerpt,
			'alt_text'      => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'width'         => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
			'height'        => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
			'parent_id'     => (int) $post->post_parent,
		);

		if ( $post->post_parent > 0 ) {
			$item['parent_title'] = get_the_title( $post->post_parent );
			$item['parent_url']   = (string) get_permalink( $post->post_parent );
		}

		$language = PolylangSupport::get_post_language( $post->ID );

		if ( null !== $language ) {
			$item['language'] = $language['slug'];
		}

		return $item;
	}
}
