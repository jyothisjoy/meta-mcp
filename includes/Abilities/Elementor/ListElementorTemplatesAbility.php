<?php
/**
 * Ability for listing saved Elementor templates.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Elementor Templates - Enumerates the saved templates in the Elementor library.
 *
 * A saved template is the practical starting point for a new page: read one
 * with elementor-get-data and write it onto a page with elementor-set-data and
 * regenerate_ids, rather than composing a layout from nothing.
 */
final class ListElementorTemplatesAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-list-templates',
			array(
				'label'               => 'List Elementor Templates',
				'description'         => 'List the saved templates in the Elementor library, with their type (page, section, container, popup, header, footer). To build a new page from one: read it with elementor-get-data, then write it onto the target page with elementor-set-data and regenerate_ids set to true.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'template_type' => array(
							'type'        => 'string',
							'description' => 'Filter by Elementor template type, e.g. "page", "section", "container", "popup", "header", "footer".',
						),
						'search'        => array(
							'type'        => 'string',
							'description' => 'Filter templates by title.',
						),
						'per_page'      => array(
							'type'        => 'integer',
							'description' => 'Results per page, 1-100. Default 50.',
						),
						'page'          => array(
							'type'        => 'integer',
							'description' => 'Page number, starting at 1. Default 1.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'templates'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'      => array( 'type' => 'integer' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The template list.
	 */
	public static function execute( $input = array() ): array {
		$active = ElementorSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		if ( ! post_type_exists( 'elementor_library' ) ) {
			return array(
				'success'   => true,
				'templates' => array(),
				'total'     => 0,
			);
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 50;
		$per_page = max( 1, min( 100, $per_page ) );

		$args = array(
			'post_type'      => 'elementor_library',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => $per_page,
			'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		if ( ! empty( $input['template_type'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Elementor indexes templates by this taxonomy.
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'elementor_library_type',
					'field'    => 'slug',
					'terms'    => (string) $input['template_type'],
				),
			);
		}

		$query     = new WP_Query( $args );
		$templates = array();

		foreach ( $query->posts as $template ) {
			if ( ! current_user_can( 'edit_post', $template->ID ) && 'publish' !== $template->post_status ) {
				continue;
			}

			$templates[] = array(
				'id'            => $template->ID,
				'title'         => $template->post_title,
				'status'        => $template->post_status,
				'template_type' => (string) get_post_meta( $template->ID, '_elementor_template_type', true ),
				'modified'      => $template->post_modified,
			);
		}

		return array(
			'success'   => true,
			'templates' => $templates,
			'total'     => (int) $query->found_posts,
		);
	}
}
