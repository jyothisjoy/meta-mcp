<?php
/**
 * Ability for pushing non-textual changes from a post onto its translations.
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
 * Sync Post Translation - Copies structure from a source post to its translations.
 *
 * Polylang Pro synchronises selected fields automatically as you edit; on the
 * free edition nothing is synchronised after the initial copy. This ability
 * does the same job on demand for both, and is the tool to reach for after
 * restructuring an Elementor page that has translations: the layout is pushed
 * across while each translation keeps its own text where you ask it to.
 */
final class SyncPostTranslationAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-sync-post',
			array(
				'label'               => 'Sync Post To Translations',
				'description'         => 'Push the non-textual parts of a post onto its translations: custom fields, taxonomy terms (mapped per language), featured image, page template and Elementor layout. Use this after restructuring a page so its translations get the same structure. Titles, slugs and post content are never overwritten. Works on both Polylang and Polylang Pro.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'source_post_id' => array(
							'type'        => 'integer',
							'description' => 'The post whose structure should be pushed out.',
						),
						'languages'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Only sync these language slugs. Defaults to every translation of the source.',
						),
						'sync_meta'      => array(
							'type'        => 'boolean',
							'description' => 'Sync custom fields. Default true.',
						),
						'sync_taxonomies' => array(
							'type'        => 'boolean',
							'description' => 'Sync taxonomy terms, mapped to their translations. Default true.',
						),
						'sync_template'  => array(
							'type'        => 'boolean',
							'description' => 'Sync the page template. Default true.',
						),
						'sync_elementor' => array(
							'type'        => 'boolean',
							'description' => 'Overwrite the Elementor layout of each translation with the source layout. This discards any layout changes made in the translation, including its translated widget text. Default false.',
						),
					),
					'required'   => array( 'source_post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'source_post_id' => array( 'type' => 'integer' ),
						'results'    => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> Per-language results.
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

		$source_language = PolylangSupport::get_post_language( $source->ID );

		if ( ! $source_language ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'source_has_no_language', sprintf( 'Post %d has no language assigned.', $source->ID ) )
			);
		}

		$translations = PolylangSupport::get_post_translations( $source->ID );
		unset( $translations[ $source_language['slug'] ] );

		if ( empty( $translations ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'no_translations', sprintf( 'Post %d has no translations to sync to.', $source->ID ) )
			);
		}

		$only = isset( $input['languages'] ) && is_array( $input['languages'] )
			? array_map( 'strval', $input['languages'] )
			: array();

		$sync_meta       = ContentSupport::to_bool( $input['sync_meta'] ?? null, true );
		$sync_taxonomies = ContentSupport::to_bool( $input['sync_taxonomies'] ?? null, true );
		$sync_template   = ContentSupport::to_bool( $input['sync_template'] ?? null, true );
		$sync_elementor  = ContentSupport::to_bool( $input['sync_elementor'] ?? null, false );

		$results = array();

		foreach ( $translations as $language => $translation ) {
			$language = (string) $language;

			if ( ! empty( $only ) && ! in_array( $language, $only, true ) ) {
				continue;
			}

			$target_id = (int) $translation['id'];
			$target    = ContentSupport::require_can_edit( $target_id );

			if ( is_wp_error( $target ) ) {
				$results[ $language ] = array( 'error' => $target->get_error_message() );
				continue;
			}

			$outcome = array( 'post_id' => $target_id );

			if ( $sync_taxonomies ) {
				$outcome['taxonomies'] = PolylangSupport::copy_taxonomies( $source, $target_id, $language );
			}

			if ( $sync_meta ) {
				$outcome['meta_keys'] = PolylangSupport::copy_metas( $source->ID, $target_id, $language, true );
			}

			if ( $sync_template ) {
				$template = (string) get_page_template_slug( $source );

				if ( '' === $template ) {
					delete_post_meta( $target_id, '_wp_page_template' );
				} else {
					update_post_meta( $target_id, '_wp_page_template', $template );
				}

				$outcome['template'] = $template;
			}

			if ( $sync_elementor && ElementorSupport::is_built_with_elementor( $source->ID ) ) {
				$copied = ElementorSupport::copy_document( $source->ID, $target_id );

				$outcome['elementor'] = is_wp_error( $copied ) ? $copied->get_error_message() : (bool) $copied;
			}

			clean_post_cache( $target_id );

			$results[ $language ] = $outcome;
		}

		return array(
			'success'        => true,
			'source_post_id' => $source->ID,
			'results'        => $results,
		);
	}
}
