<?php
/**
 * Ability for finding text anywhere it is stored on the site.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\TextSearchSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Find Text - Locates text in post fields, Elementor widgets and templates, Custom Code and menus.
 *
 * Answers "where does this text actually live?", which is what has to be
 * known before it can be changed: on an Elementor site it is rarely in
 * post_content, and often in a header or footer template shared by every page.
 */
final class FindTextAbility {

	/**
	 * Default and maximum number of posts reported in one call.
	 */
	private const DEFAULT_POSTS = 100;
	private const MAX_POSTS     = 500;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$properties = TextSearchSupport::input_properties();

		$properties['limit'] = array(
			'type'        => 'integer',
			'description' => sprintf( 'Stop after this many posts with matches, 1-%d. Default %d.', self::MAX_POSTS, self::DEFAULT_POSTS ),
		);

		AbilityRegistrar::register(
			'find-text',
			array(
				'label'               => 'Find Text',
				'description'         => 'Find where a piece of text is stored on the site: in post titles, excerpts and content, inside Elementor widgets (headings, text editors, buttons, icon lists, links, repeater rows) on pages AND in Elementor templates such as headers, footers and global widgets, in Elementor Custom Code snippets, and in menu items. Each match reports the post, the field, and for Elementor the element_id, widget_type and setting path (e.g. "icon_list.2.text", "link.url"), with surrounding context. Matches in a template, snippet or menu are marked "shared": they show on many pages, so fix them once there. Use match_mode "phone" for phone numbers. Change the text with replace-text, or edit one element with elementor-update-element. Read-only.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'find' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'               => array( 'type' => 'boolean' ),
						'find'                  => array( 'type' => 'string' ),
						'match_mode'            => array( 'type' => 'string' ),
						'posts'                 => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'posts_matched'         => array( 'type' => 'integer' ),
						'total_matches'         => array( 'type' => 'integer' ),
						'shared_posts'          => array( 'type' => 'integer' ),
						'scanned'               => array( 'type' => 'integer' ),
						'skipped_no_permission' => array( 'type' => 'integer' ),
						'more'                  => array( 'type' => 'boolean' ),
						'next_after_id'         => array( 'type' => 'integer' ),
						'notes'                 => array( 'type' => 'array' ),
						'error'                 => array( 'type' => 'string' ),
						'error_code'            => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> Where the text was found.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();
		$limit = isset( $input['limit'] ) ? max( 1, min( self::MAX_POSTS, (int) $input['limit'] ) ) : self::DEFAULT_POSTS;

		$parsed = TextSearchSupport::parse_input( $input, $limit );

		if ( is_wp_error( $parsed ) ) {
			return AbilityRegistrar::failure( $parsed );
		}

		$result = TextSearchSupport::run( $parsed['matcher'], $parsed['args'] );
		$shared = count( array_filter( $result['posts'], static fn( array $post ): bool => ! empty( $post['shared'] ) ) );

		$response = array(
			'success'               => true,
			'find'                  => (string) $parsed['matcher']['find'],
			'match_mode'            => (string) $parsed['matcher']['mode'],
			'posts'                 => $result['posts'],
			'posts_matched'         => $result['posts_matched'],
			'total_matches'         => $result['total_matches'],
			'shared_posts'          => $shared,
			'scanned'               => $result['scanned'],
			'skipped_no_permission' => $result['skipped_no_permission'],
			'more'                  => $result['more'],
			'notes'                 => $result['notes'],
		);

		if ( $result['more'] ) {
			$response['next_after_id'] = $result['next_after_id'];
		}

		return $response;
	}
}
