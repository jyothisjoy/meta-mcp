<?php
/**
 * Ability for creating a translation of an existing post.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Polylang;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Create Post Translation - Duplicates a post into another language and links it.
 *
 * This is the step Polylang's "add translation" button performs, done in one
 * call: create the counterpart post, assign it the target language, link it to
 * the source, and carry over the parts that are not themselves translations —
 * the taxonomy terms mapped to their translated equivalents, the custom fields,
 * the featured image, and the Elementor layout with fresh element ids.
 *
 * The copied text is the source text. Translating it is a separate step: read
 * the new post, translate the strings, and write them back with update-post or
 * elementor-update-element.
 */
final class CreatePostTranslationAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-create-post-translation',
			array(
				'label'               => 'Create Post Translation',
				'description'         => 'Create the counterpart of an existing post in another language and link the two in Polylang, carrying over the taxonomy terms (mapped to their translated equivalents), custom fields, featured image and Elementor layout. The new post starts as a draft holding the source language\'s text, so translate it afterwards with update-post or elementor-update-element. Fails if a translation in that language already exists, returning its ID.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'source_post_id'  => array(
							'type'        => 'integer',
							'description' => 'The ID of the post to translate.',
						),
						'language'        => array(
							'type'        => 'string',
							'description' => 'Target language slug, e.g. "fr". Must differ from the source language.',
						),
						'title'           => array(
							'type'        => 'string',
							'description' => 'Title for the translation. Defaults to the source title.',
						),
						'content'         => array(
							'type'        => 'string',
							'description' => 'Content for the translation. Defaults to a copy of the source content.',
						),
						'excerpt'         => array(
							'type'        => 'string',
							'description' => 'Excerpt for the translation. Defaults to a copy of the source excerpt.',
						),
						'slug'            => array(
							'type'        => 'string',
							'description' => 'URL slug for the translation. Generated from the title when omitted.',
						),
						'status'          => array(
							'type'        => 'string',
							'enum'        => ContentSupport::WRITABLE_STATUSES,
							'description' => 'Status of the new post. Default "draft".',
						),
						'copy_content'    => array(
							'type'        => 'boolean',
							'description' => 'Copy the source post content when no content is given. Default true.',
						),
						'copy_meta'       => array(
							'type'        => 'boolean',
							'description' => 'Copy the custom fields and featured image. Default true.',
						),
						'copy_taxonomies' => array(
							'type'        => 'boolean',
							'description' => 'Copy the taxonomy terms, mapped to their translations where they have one. Default true.',
						),
						'copy_elementor'  => array(
							'type'        => 'boolean',
							'description' => 'Copy the Elementor layout, with fresh element ids. Default true.',
						),
					),
					'required'   => array( 'source_post_id', 'language' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'post'             => array( 'type' => 'object' ),
						'source_post_id'   => array( 'type' => 'integer' ),
						'copied'           => array( 'type' => 'object' ),
						'translations'     => array( 'type' => 'object' ),
						'existing_post_id' => array( 'type' => 'integer' ),
						'error'            => array( 'type' => 'string' ),
						'error_code'       => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The created translation.
	 */
	public static function execute( $input = array() ): array {
		$active = PolylangSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$source = ContentSupport::require_can_read( $input['source_post_id'] ?? 0 );

		if ( is_wp_error( $source ) ) {
			return AbilityRegistrar::failure( $source );
		}

		$language = isset( $input['language'] ) ? (string) $input['language'] : '';
		$valid    = PolylangSupport::validate_language( $language );

		if ( is_wp_error( $valid ) ) {
			return AbilityRegistrar::failure( $valid );
		}

		if ( ! PolylangSupport::is_translated_post_type( $source->post_type ) ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'post_type_not_translatable',
					sprintf( 'Post type "%s" is not enabled for translation in the Polylang settings.', $source->post_type )
				)
			);
		}

		$create = ContentSupport::require_post_type_cap( $source->post_type, 'create_posts' );

		if ( is_wp_error( $create ) ) {
			return AbilityRegistrar::failure( $create );
		}

		$source_language = PolylangSupport::get_post_language( $source->ID );

		if ( ! $source_language ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'source_has_no_language',
					sprintf( 'Post %d has no language assigned. Set one with polylang-set-post-language first.', $source->ID )
				)
			);
		}

		if ( $source_language['slug'] === $language ) {
			return AbilityRegistrar::failure(
				new WP_Error(
					'same_language',
					sprintf( 'Post %d is already in language "%s".', $source->ID, $language )
				)
			);
		}

		$existing = PolylangSupport::get_translated_post( $source->ID, $language );

		if ( $existing > 0 ) {
			return array(
				'success'          => false,
				'error'            => sprintf(
					'A "%s" translation of post %d already exists (post %d). Edit it with update-post instead.',
					$language,
					$source->ID,
					$existing
				),
				'error_code'       => 'translation_exists',
				'existing_post_id' => $existing,
			);
		}

		$status  = isset( $input['status'] ) ? (string) $input['status'] : 'draft';
		$allowed = ContentSupport::require_can_set_status( $status, $source->post_type );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$copy_content = ContentSupport::to_bool( $input['copy_content'] ?? null, true );

		$postarr = array(
			'post_type'      => $source->post_type,
			'post_status'    => $status,
			'post_title'     => isset( $input['title'] ) ? (string) $input['title'] : $source->post_title,
			'post_content'   => isset( $input['content'] ) ? (string) $input['content'] : ( $copy_content ? $source->post_content : '' ),
			'post_excerpt'   => isset( $input['excerpt'] ) ? (string) $input['excerpt'] : ( $copy_content ? $source->post_excerpt : '' ),
			'post_author'    => get_current_user_id(),
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_parent'    => self::translated_parent( $source, $language ),
		);

		if ( ! empty( $input['slug'] ) ) {
			$postarr['post_name'] = (string) $input['slug'];
		}

		// wp_insert_post expects slashed data.
		$new_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $new_id ) ) {
			return AbilityRegistrar::failure( $new_id );
		}

		$new_id = (int) $new_id;
		$copied = array();

		// Language first: Polylang needs it before the translation set is saved,
		// and copy_taxonomies resolves terms relative to it.
		$assigned = PolylangSupport::set_post_language( $new_id, $language );

		if ( is_wp_error( $assigned ) ) {
			wp_delete_post( $new_id, true );

			return AbilityRegistrar::failure( $assigned );
		}

		$map = array();

		foreach ( PolylangSupport::get_post_translations( $source->ID ) as $slug => $translation ) {
			$map[ $slug ] = (int) $translation['id'];
		}

		$map[ $source_language['slug'] ] = $source->ID;
		$map[ $language ]                = $new_id;

		$linked = PolylangSupport::save_post_translations( $map );

		$copied['linked'] = ! is_wp_error( $linked );

		if ( is_wp_error( $linked ) ) {
			$copied['link_error'] = $linked->get_error_message();
		}

		if ( ContentSupport::to_bool( $input['copy_taxonomies'] ?? null, true ) ) {
			$copied['taxonomies'] = PolylangSupport::copy_taxonomies( $source, $new_id, $language );
		}

		if ( ContentSupport::to_bool( $input['copy_meta'] ?? null, true ) ) {
			$copied['meta_keys'] = PolylangSupport::copy_metas( $source->ID, $new_id, $language );
		}

		$template = (string) get_page_template_slug( $source );

		if ( '' !== $template ) {
			update_post_meta( $new_id, '_wp_page_template', $template );
			$copied['template'] = $template;
		}

		if ( ContentSupport::to_bool( $input['copy_elementor'] ?? null, true ) && ElementorSupport::is_built_with_elementor( $source->ID ) ) {
			$elementor = ElementorSupport::copy_document( $source->ID, $new_id );

			if ( is_wp_error( $elementor ) ) {
				$copied['elementor_error'] = $elementor->get_error_message();
			} else {
				$copied['elementor'] = (bool) $elementor;
			}
		}

		clean_post_cache( $new_id );
		$created = get_post( $new_id );

		if ( ! $created instanceof \WP_Post ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'translation_not_readable', 'The translation was created but could not be read back.' )
			);
		}

		return array(
			'success'        => true,
			'source_post_id' => $source->ID,
			'post'           => ContentSupport::format_post(
				$created,
				array(
					'include_content' => false,
					'include_terms'   => true,
				)
			),
			'copied'         => $copied,
			'translations'   => PolylangSupport::get_post_translations( $new_id ),
		);
	}

	/**
	 * Resolves the parent for the translated post.
	 *
	 * A translation should sit under the translated parent, not under the
	 * source language's parent, or the permalink hierarchy ends up mixing
	 * languages.
	 *
	 * @param \WP_Post $source   The source post.
	 * @param string   $language Target language slug.
	 *
	 * @return int The parent post ID, or 0.
	 */
	private static function translated_parent( $source, string $language ): int {
		if ( ! $source->post_parent ) {
			return 0;
		}

		$translated_parent = PolylangSupport::get_translated_post( (int) $source->post_parent, $language );

		return $translated_parent > 0 ? $translated_parent : 0;
	}
}
