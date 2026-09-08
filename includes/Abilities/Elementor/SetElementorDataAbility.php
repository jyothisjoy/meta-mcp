<?php
/**
 * Ability for replacing the Elementor document of a page.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Elementor Data - Writes a complete Elementor element tree onto a page.
 *
 * This replaces the whole layout, so it is the tool for building a page from
 * scratch or restructuring one. The incoming tree is validated and normalized
 * first: missing element ids are generated, and a tree that is not shaped like
 * Elementor data is rejected rather than written and left for the editor to
 * choke on.
 */
final class SetElementorDataAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-set-data',
			array(
				'label'               => 'Set Elementor Data',
				'description'         => 'Replace the entire Elementor layout of a page with a new element tree, marking the page as built with Elementor and rebuilding its CSS. Each element needs an "elType" (section, column, container or widget), widgets also need a "widgetType", and "settings" holds the widget content. Element ids are generated when omitted. This overwrites the existing layout: read it with elementor-get-data first if you need to preserve any of it, and prefer elementor-update-element for single-widget edits.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'         => array(
							'type'        => 'integer',
							'description' => 'The ID of the page or post to write to.',
						),
						'elements'        => array(
							'type'        => array( 'array', 'string' ),
							'description' => 'The element tree, as an array of top-level elements or a JSON string of the same. Pass an empty array to clear the layout.',
						),
						'page_settings'   => array(
							'type'        => 'object',
							'description' => 'Optional Elementor page settings, e.g. {"hide_title": "yes"}. Merged into the existing settings.',
						),
						'template_type'   => array(
							'type'        => 'string',
							'description' => 'Elementor document type, e.g. "wp-page", "wp-post", "section" or "page". Defaults to "wp-" plus the post type when the page has none.',
						),
						'regenerate_ids'  => array(
							'type'        => 'boolean',
							'description' => 'Assign a fresh id to every element, even ones that already have one. Use when copying a tree from another page. Default false.',
						),
					),
					'required'   => array( 'post_id', 'elements' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'element_count' => array( 'type' => 'integer' ),
						'edit_url'      => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The outcome.
	 */
	public static function execute( $input = array() ): array {
		$active = ElementorSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$elements = $input['elements'] ?? null;

		if ( is_string( $elements ) ) {
			$decoded = json_decode( $elements, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'invalid_json', 'elements is not valid JSON: ' . json_last_error_msg() )
				);
			}

			$elements = $decoded;
		}

		$normalized = ElementorSupport::normalize_tree( $elements );

		if ( is_wp_error( $normalized ) ) {
			return AbilityRegistrar::failure( $normalized );
		}

		if ( ContentSupport::to_bool( $input['regenerate_ids'] ?? null, false ) ) {
			$normalized = ElementorSupport::regenerate_ids( $normalized );
		}

		if ( ! empty( $input['template_type'] ) ) {
			update_post_meta( $post->ID, '_elementor_template_type', sanitize_text_field( (string) $input['template_type'] ) );
		}

		if ( isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ) {
			$existing = get_post_meta( $post->ID, '_elementor_page_settings', true );
			$existing = is_array( $existing ) ? $existing : array();

			update_post_meta(
				$post->ID,
				'_elementor_page_settings',
				wp_slash( array_merge( $existing, $input['page_settings'] ) )
			);
		}

		$saved = ElementorSupport::save_elements( $post->ID, $normalized );

		if ( is_wp_error( $saved ) ) {
			return AbilityRegistrar::failure( $saved );
		}

		return array(
			'success'       => true,
			'post_id'       => $post->ID,
			'element_count' => count( ElementorSupport::flatten( $normalized ) ),
			'edit_url'      => add_query_arg(
				array(
					'post'   => $post->ID,
					'action' => 'elementor',
				),
				admin_url( 'post.php' )
			),
		);
	}
}
