<?php
/**
 * Ability for listing Elementor Pro Custom Code snippets.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\CustomCodeSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Custom Code - Lists Elementor → Custom Code snippets.
 */
final class ListCustomCodeAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-list-custom-code',
			array(
				'label'               => 'List Elementor Custom Code',
				'description'         => 'List the snippets under Elementor → Custom Code (tracking pixels, schema JSON-LD, meta tags, scripts): title, status, location (elementor_head, elementor_body_start, elementor_body_end), priority, display conditions and code length. A snippet with no display conditions prints on every page. Set include_code to also return the code, or read one snippet with elementor-get-custom-code.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'include_code' => array(
							'type'        => 'boolean',
							'description' => 'Include each snippet\'s code. Default false.',
						),
						'search'       => array(
							'type'        => 'string',
							'description' => 'Only snippets whose title or code contains this text, e.g. "LocalBusiness".',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippets'   => array( 'type' => 'array' ),
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
	 * @return array<string, mixed> The snippets.
	 */
	public static function execute( $input = array() ): array {
		$available = CustomCodeSupport::require_available();

		if ( is_wp_error( $available ) ) {
			return AbilityRegistrar::failure( $available );
		}

		$include_code = ContentSupport::to_bool( $input['include_code'] ?? null, false );
		$search       = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

		$posts = get_posts(
			array(
				'post_type'        => CustomCodeSupport::CPT,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'lang'             => '',
			)
		);

		$snippets = array();

		foreach ( $posts as $post ) {
			if ( '' !== $search ) {
				$haystack = $post->post_title . "\n" . (string) get_post_meta( $post->ID, '_elementor_code', true );

				if ( false === stripos( $haystack, $search ) ) {
					continue;
				}
			}

			$snippets[] = CustomCodeSupport::format( $post, $include_code );
		}

		return array(
			'success'  => true,
			'snippets' => $snippets,
		);
	}
}
