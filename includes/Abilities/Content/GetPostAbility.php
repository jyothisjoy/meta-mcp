<?php
/**
 * Ability for reading a single post in full.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Post - Reads one post with its terms, meta, translations and Elementor state.
 */
final class GetPostAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'get-post',
			array(
				'label'               => 'Get Post',
				'description'         => 'Read one post of any post type in full, by ID or by slug. Optionally includes its taxonomy terms, custom fields, Polylang translations, and a summary of its Elementor layout. For the Elementor element tree itself use elementor-get-structure.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'              => array(
							'type'        => 'integer',
							'description' => 'The post ID. Either post_id or slug is required.',
						),
						'slug'                 => array(
							'type'        => 'string',
							'description' => 'The post slug. Requires post_type when used.',
						),
						'post_type'            => array(
							'type'        => 'string',
							'description' => 'Post type slug, used together with slug. Default "post".',
						),
						'include_content'      => array(
							'type'        => 'boolean',
							'description' => 'Include the raw post content. Default true.',
						),
						'include_rendered'     => array(
							'type'        => 'boolean',
							'description' => 'Include the rendered front-end HTML, with shortcodes and blocks expanded. Default false.',
						),
						'include_meta'         => array(
							'type'        => 'boolean',
							'description' => 'Include public custom fields. Default false.',
						),
						'include_terms'        => array(
							'type'        => 'boolean',
							'description' => 'Include taxonomy terms. Default true.',
						),
						'include_elementor'    => array(
							'type'        => 'boolean',
							'description' => 'Include a summary of the Elementor layout. Default true.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'post'       => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The post payload.
	 */
	public static function execute( $input = array() ): array {
		$post_id = self::resolve_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return AbilityRegistrar::failure( $post_id );
		}

		$post = ContentSupport::require_can_read( $post_id );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$data = ContentSupport::format_post(
			$post,
			array(
				'include_content'   => ContentSupport::to_bool( $input['include_content'] ?? null, true ),
				'include_terms'     => ContentSupport::to_bool( $input['include_terms'] ?? null, true ),
				'include_meta'      => ContentSupport::to_bool( $input['include_meta'] ?? null, false ),
				'include_elementor' => ContentSupport::to_bool( $input['include_elementor'] ?? null, true ),
			)
		);

		if ( ContentSupport::to_bool( $input['include_rendered'] ?? null, false ) ) {
			$data['rendered_content'] = self::render_content( $post->post_content );
		}

		if ( ContentSupport::to_bool( $input['include_elementor'] ?? null, true ) && ElementorSupport::is_built_with_elementor( $post->ID ) ) {
			$elements = ElementorSupport::get_elements( $post->ID );

			$data['elementor']['element_count'] = is_wp_error( $elements )
				? 0
				: count( ElementorSupport::flatten( $elements ) );

			if ( is_wp_error( $elements ) ) {
				$data['elementor']['error'] = $elements->get_error_message();
			}
		}

		return array(
			'success' => true,
			'post'    => $data,
		);
	}

	/**
	 * Resolves the target post ID from either the id or the slug input.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return int|\WP_Error The post ID, or WP_Error when it cannot be resolved.
	 */
	private static function resolve_post_id( array $input ) {
		if ( ! empty( $input['post_id'] ) ) {
			return (int) $input['post_id'];
		}

		if ( empty( $input['slug'] ) ) {
			return new WP_Error( 'missing_identifier', 'Either post_id or slug is required.' );
		}

		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		$valid     = ContentSupport::validate_post_type( $post_type );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post = get_page_by_path( (string) $input['slug'], OBJECT, $post_type );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				sprintf( 'No %s found with slug "%s".', $post_type, (string) $input['slug'] )
			);
		}

		return (int) $post->ID;
	}

	/**
	 * Renders post content the way the front end would.
	 *
	 * @param string $content Raw post content.
	 *
	 * @return string The rendered HTML.
	 */
	private static function render_content( string $content ): string {
		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}

		$content = wptexturize( $content );
		$content = wpautop( $content );

		return do_shortcode( $content );
	}
}
