<?php
/**
 * Shared write path for creating and updating posts.
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
 * Class - PostWriter
 *
 * Create and update take the same fields and have the same side effects (terms,
 * meta, featured image, template, language, Elementor data). Keeping that in one
 * place means a field added to one is available to the other, and that the
 * capability checks around publishing cannot drift apart.
 */
final class PostWriter {

	/**
	 * The core post fields accepted from a client, mapped to their column names.
	 */
	private const FIELD_MAP = array(
		'title'          => 'post_title',
		'content'        => 'post_content',
		'excerpt'        => 'post_excerpt',
		'slug'           => 'post_name',
		'author'         => 'post_author',
		'date'           => 'post_date',
		'parent'         => 'post_parent',
		'menu_order'     => 'menu_order',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
	);

	/**
	 * Builds the array passed to wp_insert_post or wp_update_post.
	 *
	 * @param array         $input     Ability input.
	 * @param string        $post_type Post type slug.
	 * @param \WP_Post|null $existing  The post being updated, or null when creating.
	 *
	 * @return array<string, mixed>|\WP_Error The post array, or WP_Error when validation fails.
	 */
	public static function build_postarr( array $input, string $post_type, ?WP_Post $existing = null ) {
		$postarr = array( 'post_type' => $post_type );

		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
		}

		foreach ( self::FIELD_MAP as $key => $column ) {
			if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
				continue;
			}

			$value = $input[ $key ];

			switch ( $key ) {
				case 'author':
					$author_id = (int) $value;

					if ( ! get_userdata( $author_id ) ) {
						return new WP_Error( 'invalid_author', sprintf( 'No user found with ID %d.', $author_id ) );
					}

					$caps = ContentSupport::capabilities( $post_type );
					$current_author = $existing ? (int) $existing->post_author : get_current_user_id();

					// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- From the post type object.
					if ( $author_id !== $current_author && $caps && ! current_user_can( $caps->edit_others_posts ) ) {
						return new WP_Error(
							'cannot_assign_author',
							'User lacks the capability to assign posts to another author.'
						);
					}

					$postarr[ $column ] = $author_id;
					break;

				case 'parent':
					$parent_id = (int) $value;

					if ( $parent_id > 0 ) {
						$parent = get_post( $parent_id );

						if ( ! $parent || $parent->post_type !== $post_type ) {
							return new WP_Error(
								'invalid_parent',
								sprintf( 'Parent %d does not exist or is not a %s.', $parent_id, $post_type )
							);
						}

						if ( $existing && $parent_id === $existing->ID ) {
							return new WP_Error( 'invalid_parent', 'A post cannot be its own parent.' );
						}
					}

					$postarr[ $column ] = $parent_id;
					break;

				case 'menu_order':
					$postarr[ $column ] = (int) $value;
					break;

				case 'date':
					$date = self::parse_date( (string) $value );

					if ( is_wp_error( $date ) ) {
						return $date;
					}

					$postarr['post_date']     = $date['local'];
					$postarr['post_date_gmt'] = $date['gmt'];
					$postarr['edit_date']     = true;
					break;

				case 'comment_status':
				case 'ping_status':
					$postarr[ $column ] = in_array( (string) $value, array( 'open', 'closed' ), true ) ? (string) $value : 'closed';
					break;

				default:
					$postarr[ $column ] = (string) $value;
					break;
			}
		}

		if ( array_key_exists( 'status', $input ) && null !== $input['status'] && '' !== $input['status'] ) {
			$status  = (string) $input['status'];
			$allowed = ContentSupport::require_can_set_status( $status, $post_type, $existing ? $existing->ID : 0 );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$postarr['post_status'] = $status;
		}

		return $postarr;
	}

	/**
	 * Applies everything that is not a column on the posts table.
	 *
	 * Each step reports its own outcome rather than aborting the whole call, so a
	 * failed taxonomy assignment does not silently discard a successful content
	 * update. The caller surfaces the details to the client.
	 *
	 * @param \WP_Post $post  The saved post.
	 * @param array    $input Ability input.
	 *
	 * @return array<string, mixed> A report of what was applied.
	 */
	public static function apply_side_effects( WP_Post $post, array $input ): array {
		$report = array();

		if ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) {
			$report['terms'] = self::apply_terms(
				$post,
				$input['terms'],
				ContentSupport::to_bool( $input['create_missing_terms'] ?? null, false ),
				ContentSupport::to_bool( $input['append_terms'] ?? null, false )
			);
		}

		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$report['meta'] = MetaSupport::write_meta( $post->ID, $input['meta'], $post->post_type );
		}

		if ( array_key_exists( 'featured_media', $input ) && null !== $input['featured_media'] ) {
			$report['featured_media'] = self::apply_featured_media( $post->ID, (int) $input['featured_media'] );
		}

		if ( array_key_exists( 'template', $input ) && null !== $input['template'] ) {
			$report['template'] = self::apply_template( $post, (string) $input['template'] );
		}

		if ( ! empty( $input['language'] ) ) {
			$report['language'] = self::apply_language( $post, (string) $input['language'] );
		}

		if ( ! empty( $input['translation_of'] ) ) {
			$report['translation_of'] = self::apply_translation_link( $post, (int) $input['translation_of'] );
		}

		if ( isset( $input['elementor_data'] ) && null !== $input['elementor_data'] ) {
			$report['elementor'] = self::apply_elementor_data( $post, $input['elementor_data'] );
		}

		return $report;
	}

	/**
	 * Assigns taxonomy terms to a post.
	 *
	 * @param \WP_Post $post           The post.
	 * @param array    $terms          Taxonomy slug to a list of term IDs, slugs or names.
	 * @param bool     $create_missing Whether to create terms that do not exist.
	 * @param bool     $append         Whether to add to the existing terms instead of replacing them.
	 *
	 * @return array<string, mixed> Per-taxonomy outcome.
	 */
	public static function apply_terms( WP_Post $post, array $terms, bool $create_missing, bool $append ): array {
		$result     = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $terms as $taxonomy => $values ) {
			$taxonomy = (string) $taxonomy;

			if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
				$result[ $taxonomy ] = array(
					'error' => sprintf( 'Taxonomy "%s" is not registered for post type "%s".', $taxonomy, $post->post_type ),
				);
				continue;
			}

			$taxonomy_object = get_taxonomy( $taxonomy );

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- From the taxonomy object.
			if ( ! $taxonomy_object || ! current_user_can( $taxonomy_object->cap->assign_terms ) ) {
				$result[ $taxonomy ] = array(
					'error' => sprintf( 'User lacks the capability to assign terms in "%s".', $taxonomy ),
				);
				continue;
			}

			$resolved = self::resolve_terms( (array) $values, $taxonomy, $create_missing, $taxonomy_object );

			if ( ! empty( $resolved['errors'] ) ) {
				$result[ $taxonomy ]['errors'] = $resolved['errors'];
			}

			$assigned = wp_set_object_terms( $post->ID, $resolved['ids'], $taxonomy, $append );

			if ( is_wp_error( $assigned ) ) {
				$result[ $taxonomy ]['error'] = $assigned->get_error_message();
				continue;
			}

			$result[ $taxonomy ]['assigned'] = count( $resolved['ids'] );
		}

		return $result;
	}

	/**
	 * Turns a mixed list of term identifiers into term IDs.
	 *
	 * @param array         $values          Term IDs, slugs or names.
	 * @param string        $taxonomy        Taxonomy slug.
	 * @param bool          $create_missing  Whether to create missing terms.
	 * @param \WP_Taxonomy  $taxonomy_object The taxonomy object.
	 *
	 * @return array{ids: int[], errors: string[]} Resolved IDs and any problems encountered.
	 */
	private static function resolve_terms( array $values, string $taxonomy, bool $create_missing, $taxonomy_object ): array {
		$ids    = array();
		$errors = array();

		foreach ( $values as $value ) {
			if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
				$term = get_term( (int) $value, $taxonomy );

				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $term->term_id;
					continue;
				}

				$errors[] = sprintf( 'No term with ID %d in "%s".', (int) $value, $taxonomy );
				continue;
			}

			$value = (string) $value;
			$term  = get_term_by( 'slug', $value, $taxonomy );

			if ( ! $term ) {
				$term = get_term_by( 'name', $value, $taxonomy );
			}

			if ( $term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}

			if ( ! $create_missing ) {
				$errors[] = sprintf( 'Term "%s" does not exist in "%s". Set create_missing_terms to create it.', $value, $taxonomy );
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- From the taxonomy object.
			if ( ! current_user_can( $taxonomy_object->cap->edit_terms ) ) {
				$errors[] = sprintf( 'User lacks the capability to create terms in "%s".', $taxonomy );
				continue;
			}

			$created = wp_insert_term( $value, $taxonomy );

			if ( is_wp_error( $created ) ) {
				$errors[] = sprintf( 'Could not create term "%s": %s', $value, $created->get_error_message() );
				continue;
			}

			$ids[] = (int) $created['term_id'];
		}

		return array(
			'ids'    => $ids,
			'errors' => $errors,
		);
	}

	/**
	 * Sets or clears the featured image.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $attachment_id Attachment ID, or 0 to clear.
	 *
	 * @return array<string, mixed> Outcome.
	 */
	private static function apply_featured_media( int $post_id, int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			delete_post_thumbnail( $post_id );

			return array( 'cleared' => true );
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'error' => sprintf( 'Attachment %d does not exist.', $attachment_id ) );
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return array( 'set' => $attachment_id );
	}

	/**
	 * Sets the page template.
	 *
	 * @param \WP_Post $post     The post.
	 * @param string   $template Template file name, or an empty string for the default.
	 *
	 * @return array<string, mixed> Outcome.
	 */
	private static function apply_template( WP_Post $post, string $template ): array {
		if ( '' === $template || 'default' === $template ) {
			delete_post_meta( $post->ID, '_wp_page_template' );

			return array( 'cleared' => true );
		}

		$available = wp_get_theme()->get_page_templates( $post, $post->post_type );

		if ( ! isset( $available[ $template ] ) ) {
			return array(
				'error'     => sprintf( 'Template "%s" is not available for this post type.', $template ),
				'available' => array_keys( $available ),
			);
		}

		update_post_meta( $post->ID, '_wp_page_template', $template );

		return array( 'set' => $template );
	}

	/**
	 * Assigns the Polylang language of a post.
	 *
	 * @param \WP_Post $post     The post.
	 * @param string   $language Language slug.
	 *
	 * @return array<string, mixed> Outcome.
	 */
	private static function apply_language( WP_Post $post, string $language ): array {
		if ( ! PolylangSupport::is_active() ) {
			return array( 'error' => 'Polylang is not active; the language field was ignored.' );
		}

		$applied = PolylangSupport::set_post_language( $post->ID, $language );

		if ( is_wp_error( $applied ) ) {
			return array( 'error' => $applied->get_error_message() );
		}

		return array( 'set' => $language );
	}

	/**
	 * Links a post as a translation of another post.
	 *
	 * @param \WP_Post $post      The post.
	 * @param int      $source_id The post it translates.
	 *
	 * @return array<string, mixed> Outcome.
	 */
	private static function apply_translation_link( WP_Post $post, int $source_id ): array {
		if ( ! PolylangSupport::is_active() ) {
			return array( 'error' => 'Polylang is not active; translation_of was ignored.' );
		}

		$source_language = PolylangSupport::get_post_language( $source_id );
		$target_language = PolylangSupport::get_post_language( $post->ID );

		if ( ! $source_language ) {
			return array( 'error' => sprintf( 'Post %d has no language assigned; assign one before linking translations.', $source_id ) );
		}

		if ( ! $target_language ) {
			return array( 'error' => sprintf( 'Post %d has no language assigned; set the language field as well.', $post->ID ) );
		}

		if ( $source_language['slug'] === $target_language['slug'] ) {
			return array( 'error' => 'A post cannot be linked as a translation of another post in the same language.' );
		}

		$existing = PolylangSupport::get_post_translations( $source_id );
		$map      = array();

		foreach ( $existing as $slug => $translation ) {
			$map[ $slug ] = (int) $translation['id'];
		}

		$map[ $source_language['slug'] ] = $source_id;
		$map[ $target_language['slug'] ] = $post->ID;

		$saved = PolylangSupport::save_post_translations( $map );

		if ( is_wp_error( $saved ) ) {
			return array( 'error' => $saved->get_error_message() );
		}

		return array( 'linked_to' => $source_id );
	}

	/**
	 * Writes an Elementor element tree supplied alongside the post fields.
	 *
	 * @param \WP_Post $post The post.
	 * @param mixed    $data Element tree as an array, or as a JSON string.
	 *
	 * @return array<string, mixed> Outcome.
	 */
	private static function apply_elementor_data( WP_Post $post, $data ): array {
		if ( ! ElementorSupport::is_active() ) {
			return array( 'error' => 'Elementor is not active; elementor_data was ignored.' );
		}

		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return array( 'error' => 'elementor_data is not valid JSON: ' . json_last_error_msg() );
			}

			$data = $decoded;
		}

		$normalized = ElementorSupport::normalize_tree( $data );

		if ( is_wp_error( $normalized ) ) {
			return array( 'error' => $normalized->get_error_message() );
		}

		$saved = ElementorSupport::save_elements( $post->ID, $normalized );

		if ( is_wp_error( $saved ) ) {
			return array( 'error' => $saved->get_error_message() );
		}

		return array( 'element_count' => count( ElementorSupport::flatten( $normalized ) ) );
	}

	/**
	 * Parses a date into the local and UTC forms WordPress stores.
	 *
	 * A bare date with no offset is read as site time, which is what a client
	 * means by "publish at 09:00". A date carrying an offset or a trailing Z is
	 * honoured as given and converted. Both forms are returned because
	 * `post_date` and `post_date_gmt` disagreeing is what makes scheduled posts
	 * fire at the wrong hour.
	 *
	 * @param string $value Date string, with or without a timezone.
	 *
	 * @return array{local: string, gmt: string, timestamp: int}|\WP_Error The parsed date, or WP_Error.
	 */
	public static function parse_date( string $value ) {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		try {
			$date = new \DateTimeImmutable( $value, $timezone );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'invalid_date',
				sprintf( 'Could not parse "%s" as a date. Use the format YYYY-MM-DD HH:MM:SS.', $value )
			);
		}

		return array(
			'local'     => $date->setTimezone( $timezone )->format( 'Y-m-d H:i:s' ),
			'gmt'       => $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'timestamp' => $date->getTimestamp(),
		);
	}

	/**
	 * The input schema fragment shared by create-post and update-post.
	 *
	 * @return array<string, mixed> JSON schema properties.
	 */
	public static function shared_schema_properties(): array {
		return array(
			'title'                => array(
				'type'        => 'string',
				'description' => 'The post title.',
			),
			'content'              => array(
				'type'        => 'string',
				'description' => 'The post content. Accepts block markup, classic HTML or plain text. For Elementor pages, use elementor_data or the elementor-* abilities instead; this field is ignored by the Elementor front end.',
			),
			'excerpt'              => array(
				'type'        => 'string',
				'description' => 'The post excerpt.',
			),
			'status'               => array(
				'type'        => 'string',
				'enum'        => ContentSupport::WRITABLE_STATUSES,
				'description' => 'Post status. Setting publish, future or private requires publish permission on the post type. Use "future" together with a date in the future to schedule.',
			),
			'slug'                 => array(
				'type'        => 'string',
				'description' => 'The URL slug. Generated from the title when omitted.',
			),
			'author'               => array(
				'type'        => 'integer',
				'description' => 'Author user ID. Assigning another user requires the edit_others capability.',
			),
			'date'                 => array(
				'type'        => 'string',
				'description' => 'Publish date as YYYY-MM-DD HH:MM:SS in site time. A future date combined with status "publish" schedules the post.',
			),
			'parent'               => array(
				'type'        => 'integer',
				'description' => 'Parent post ID for hierarchical post types. Use 0 for no parent.',
			),
			'menu_order'           => array(
				'type'        => 'integer',
				'description' => 'Sort order for hierarchical post types.',
			),
			'comment_status'       => array(
				'type'        => 'string',
				'enum'        => array( 'open', 'closed' ),
				'description' => 'Whether comments are open.',
			),
			'ping_status'          => array(
				'type'        => 'string',
				'enum'        => array( 'open', 'closed' ),
				'description' => 'Whether pingbacks are open.',
			),
			'template'             => array(
				'type'        => 'string',
				'description' => 'Page template file name, e.g. "elementor_canvas" or "elementor_header_footer". Pass an empty string for the theme default.',
			),
			'featured_media'       => array(
				'type'        => 'integer',
				'description' => 'Attachment ID to use as the featured image. Use 0 to clear it.',
			),
			'terms'                => array(
				'type'        => 'object',
				'description' => 'Taxonomy terms as an object of taxonomy slug to an array of term IDs, slugs or names, e.g. {"category": ["news"], "post_tag": ["launch"]}.',
			),
			'create_missing_terms' => array(
				'type'        => 'boolean',
				'description' => 'Create terms that do not exist yet instead of reporting them as errors. Default false.',
			),
			'append_terms'         => array(
				'type'        => 'boolean',
				'description' => 'Add the given terms to the existing ones instead of replacing them. Default false.',
			),
			'meta'                 => array(
				'type'        => 'object',
				'description' => 'Custom fields as key/value pairs. A null value deletes the key. Protected keys starting with an underscore are rejected unless allowlisted on the site.',
			),
			'language'             => array(
				'type'        => 'string',
				'description' => 'Polylang language slug to assign to the post, e.g. "en". Ignored when Polylang is inactive.',
			),
			'translation_of'       => array(
				'type'        => 'integer',
				'description' => 'Post ID this post is a translation of. Links the two in Polylang; requires language to be set to a different language than the source.',
			),
			'elementor_data'       => array(
				'type'        => array( 'array', 'string' ),
				'description' => 'Elementor element tree, as an array of elements or a JSON string. Setting this marks the post as built with Elementor and rebuilds its CSS.',
			),
		);
	}
}
