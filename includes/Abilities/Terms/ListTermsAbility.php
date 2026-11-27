<?php
/**
 * Ability for listing the terms of a taxonomy.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Terms;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP\MCP\Abilities\Support\TermSupport;
use WP_Term;
use WP_Term_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Terms - Lists categories, tags or custom taxonomy terms.
 */
final class ListTermsAbility {

	/**
	 * Largest page size accepted.
	 */
	private const MAX_PER_PAGE = 200;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'list-terms',
			array(
				'label'               => 'List Terms',
				'description'         => 'List the terms of a taxonomy (categories, tags or a custom taxonomy such as a room or location type), with id, name, slug, parent, post count and, on a Polylang site, each term\'s language and translations. Empty terms are included by default. Use list-post-types to see which taxonomies exist.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'   => array(
							'type'        => 'string',
							'description' => 'Taxonomy slug, e.g. "category" or "post_tag".',
						),
						'search'     => array(
							'type'        => 'string',
							'description' => 'Only terms whose name or slug contains this text.',
						),
						'parent'     => array(
							'type'        => 'integer',
							'description' => 'Only direct children of this term ID. 0 for top-level terms.',
						),
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => 'Leave out terms with no posts. Default false.',
						),
						'language'   => array(
							'type'        => 'string',
							'description' => 'Polylang language slug to restrict to. Default: every language.',
						),
						'per_page'   => array(
							'type'        => 'integer',
							'description' => 'Terms per page. Default 50, maximum 200.',
						),
						'page'       => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
						),
					),
					'required'   => array( 'taxonomy' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'taxonomy'    => array( 'type' => 'string' ),
						'terms'       => array(
							'type'  => 'array',
							'items' => TermSupport::term_schema(),
						),
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
	 * @return array<string, mixed> The terms.
	 */
	public static function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = TermSupport::require_taxonomy( $input['taxonomy'] ?? '' );

		if ( is_wp_error( $taxonomy ) ) {
			return AbilityRegistrar::failure( $taxonomy );
		}

		$can = TermSupport::require_can_view( $taxonomy );

		if ( is_wp_error( $can ) ) {
			return AbilityRegistrar::failure( $can );
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, (int) $input['per_page'] ) ) : 50;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'taxonomy'   => $taxonomy->name,
			'hide_empty' => ContentSupport::to_bool( $input['hide_empty'] ?? null, false ),
			'orderby'    => 'name',
			'order'      => 'ASC',
			// Polylang otherwise narrows term queries to the current language.
			'lang'       => '',
		);

		if ( isset( $input['search'] ) && '' !== (string) $input['search'] ) {
			$args['search'] = (string) $input['search'];
		}

		if ( isset( $input['parent'] ) ) {
			$args['parent'] = max( 0, (int) $input['parent'] );
		}

		if ( ! empty( $input['language'] ) && PolylangSupport::is_active() ) {
			$language = PolylangSupport::validate_language( (string) $input['language'] );

			if ( is_wp_error( $language ) ) {
				return AbilityRegistrar::failure( $language );
			}

			$args['lang'] = (string) $input['language'];
		}

		$total = (int) wp_count_terms( array_merge( $args, array( 'fields' => 'count' ) ) );

		$query = new WP_Term_Query(
			array_merge(
				$args,
				array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				)
			)
		);

		$terms = array();

		foreach ( (array) $query->get_terms() as $term ) {
			if ( $term instanceof WP_Term ) {
				$terms[] = TermSupport::format_term( $term, true );
			}
		}

		return array(
			'success'     => true,
			'taxonomy'    => $taxonomy->name,
			'terms'       => $terms,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}
}
