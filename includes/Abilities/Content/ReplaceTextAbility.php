<?php
/**
 * Ability for replacing text wherever it is stored on the site.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\SiteSupport;
use WP\MCP\Abilities\Support\TextSearchSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Replace Text - Find and replace across post fields, Elementor widgets and templates, Custom Code and menus.
 *
 * Each kind of storage is written back the way its dedicated ability writes it:
 * Elementor through its document API (revision, CSS and companion metas, with a
 * template's type and display conditions protected), post fields through
 * wp_update_post (revision), snippet code and menu links as their own meta.
 */
final class ReplaceTextAbility {

	/**
	 * Default and maximum number of posts changed in one call.
	 */
	private const DEFAULT_POSTS = 50;
	private const MAX_POSTS     = 200;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$properties = TextSearchSupport::input_properties();

		$properties['replace']     = array(
			'type'        => 'string',
			'description' => 'The new text. May be empty to delete the match. In "phone" mode give the number formatted as it should be displayed, e.g. "+971 2 444 1234"; where the old number was written without separators (tel: links, WhatsApp URLs, schema) the compact form "+97124441234" is written instead.',
		);
		$properties['dry_run']     = array(
			'type'        => 'boolean',
			'description' => 'Report every match and what would be replaced, without saving. Default false. Recommended first on a site-wide replace.',
		);
		$properties['max_posts']   = array(
			'type'        => 'integer',
			'description' => sprintf( 'Change at most this many posts in one call, 1-%d. Default %d. When more remain, more is true: call again (changed posts no longer match, so the same call picks up the rest).', self::MAX_POSTS, self::DEFAULT_POSTS ),
		);
		$properties['purge_cache'] = array(
			'type'        => 'boolean',
			'description' => 'Purge the page cache afterwards: the changed posts, or the whole site when a template, snippet or menu changed. Default true.',
		);

		AbilityRegistrar::register(
			'replace-text',
			array(
				'label'               => 'Replace Text',
				'description'         => 'Find and replace text across the site in one call: post titles, excerpts and content; every text, link and repeater setting of every Elementor widget, on pages AND in Elementor templates (headers, footers, global widgets, popups) that render on many pages; Elementor Custom Code snippets such as JSON-LD schema; and menu item labels and custom link URLs. This is the way to change text that update-post cannot reach because it lives in Elementor data or a shared template. Use match_mode "phone" to replace a phone number in all its formats, including tel: links. Narrow the scope with post_ids, post_types or language (e.g. only the Arabic pages). Run with dry_run first to see every match. Writes go through Elementor\'s document API and wp_update_post, so revisions are kept; to undo, run again with find and replace swapped. Reports per post what was replaced and where.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'find', 'replace' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'               => array( 'type' => 'boolean' ),
						'dry_run'               => array( 'type' => 'boolean' ),
						'find'                  => array( 'type' => 'string' ),
						'replace'               => array( 'type' => 'string' ),
						'match_mode'            => array( 'type' => 'string' ),
						'posts'                 => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'posts_changed'         => array( 'type' => 'integer' ),
						'replacements'          => array( 'type' => 'integer' ),
						'total_matches'         => array( 'type' => 'integer' ),
						'failed'                => array( 'type' => 'array' ),
						'skipped_no_permission' => array( 'type' => 'integer' ),
						'more'                  => array( 'type' => 'boolean' ),
						'next_after_id'         => array( 'type' => 'integer' ),
						'cache'                 => array( 'type' => 'object' ),
						'notes'                 => array( 'type' => 'array' ),
						'error'                 => array( 'type' => 'string' ),
						'error_code'            => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> What was replaced, and where.
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		if ( ! array_key_exists( 'replace', $input ) || ! is_scalar( $input['replace'] ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_replace', 'replace is required; pass an empty string to delete the text.' ) );
		}

		$replace   = (string) $input['replace'];
		$dry_run   = ContentSupport::to_bool( $input['dry_run'] ?? false, false );
		$max_posts = isset( $input['max_posts'] ) ? max( 1, min( self::MAX_POSTS, (int) $input['max_posts'] ) ) : self::DEFAULT_POSTS;

		$parsed = TextSearchSupport::parse_input( $input, $max_posts );

		if ( is_wp_error( $parsed ) ) {
			return AbilityRegistrar::failure( $parsed );
		}

		if ( 'exact' === $parsed['matcher']['mode'] && $replace === $parsed['matcher']['find'] ) {
			return AbilityRegistrar::failure( new WP_Error( 'nothing_to_change', 'find and replace are the same text.' ) );
		}

		$args            = $parsed['args'];
		$args['replace'] = $replace;
		$args['dry_run'] = $dry_run;

		$result = TextSearchSupport::run( $parsed['matcher'], $args );

		$response = array(
			'success'               => true,
			'dry_run'               => $dry_run,
			'find'                  => (string) $parsed['matcher']['find'],
			'replace'               => $replace,
			'match_mode'            => (string) $parsed['matcher']['mode'],
			'posts'                 => $result['posts'],
			'posts_changed'         => $dry_run ? 0 : count( $result['changed_ids'] ),
			'replacements'          => $dry_run ? 0 : $result['replaced'],
			'total_matches'         => $result['total_matches'],
			'failed'                => $result['errors'],
			'skipped_no_permission' => $result['skipped_no_permission'],
			'more'                  => $result['more'],
			'notes'                 => $result['notes'],
		);

		if ( $dry_run ) {
			$response['would_replace'] = $result['replaced'];
			$response['notes'][]       = 'Dry run: nothing was saved.';
		}

		if ( $result['more'] ) {
			$response['next_after_id'] = $result['next_after_id'];
		}

		if ( ! $dry_run && ! empty( $result['changed_ids'] ) && ContentSupport::to_bool( $input['purge_cache'] ?? true, true ) ) {
			$response['cache'] = self::purge( $result['changed_ids'], (bool) $result['shared_changed'] );
		}

		return $response;
	}

	/**
	 * Purges what the change made stale.
	 *
	 * Text in a template, snippet or menu is part of every page that shows it,
	 * so anything shared purges the whole site; otherwise only the changed pages.
	 *
	 * @param int[] $post_ids      Changed posts.
	 * @param bool  $shared_change Whether a template, snippet or menu changed.
	 *
	 * @return array<string, mixed> What was purged.
	 */
	private static function purge( array $post_ids, bool $shared_change ): array {
		if ( $shared_change ) {
			// Elementor caches the rendered HTML of each element per page; a page
			// that embeds a changed template would keep the old text.
			delete_post_meta_by_key( '_elementor_element_cache' );
		}

		$purged = SiteSupport::purge_caches( $shared_change ? array() : $post_ids );

		$purged['scope'] = $shared_change ? 'site' : 'posts';

		return $purged;
	}
}
