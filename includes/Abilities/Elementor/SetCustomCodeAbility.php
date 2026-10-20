<?php
/**
 * Ability for creating and editing Elementor Pro Custom Code snippets.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\CustomCodeSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Set Custom Code - Creates a snippet, or changes an existing one's code, placement or conditions.
 */
final class SetCustomCodeAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-set-custom-code',
			array(
				'label'               => 'Set Elementor Custom Code',
				'description'         => 'Create an Elementor → Custom Code snippet, or change an existing one by post_id. Only the fields you pass change. Use it to replace a schema JSON-LD block, move a pixel to the body, or limit a snippet to certain pages with display conditions, e.g. ["include/singular/front_page"] for the homepage only, ["include/general"] for the whole site, ["include/singular/page/12"] for one page. A snippet with no conditions prints on every page. New snippets are created as drafts unless status is "publish". To unpublish a snippet use set-post-status; to remove it use delete-post. Writing code requires "unfiltered_html".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => 'The snippet to change. Omit to create a new one.',
						),
						'title'         => array(
							'type'        => 'string',
							'description' => 'Snippet title. Required when creating.',
						),
						'code'          => array(
							'type'        => 'string',
							'description' => 'The full code, including its <script>, <style> or <meta> tags. Replaces the existing code.',
						),
						'location'      => array(
							'type'        => 'string',
							'enum'        => CustomCodeSupport::LOCATIONS,
							'description' => 'Where it prints: elementor_head (in <head>, the default for new snippets), elementor_body_start or elementor_body_end.',
						),
						'priority'      => array(
							'type'        => 'integer',
							'description' => 'Order within the location, 1 (first) to 10. Default 1 for new snippets.',
						),
						'ensure_jquery' => array(
							'type'        => 'boolean',
							'description' => 'Always load jQuery before this snippet.',
						),
						'conditions'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Display conditions, each "include/..." or "exclude/...". Replaces the existing ones. An empty array clears them, which makes the snippet print on every page.',
						),
						'status'        => array(
							'type'        => 'string',
							'enum'        => CustomCodeSupport::STATUSES,
							'description' => 'Post status. New snippets default to "draft", which does not print.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'created'    => array( 'type' => 'boolean' ),
						'snippet'    => array( 'type' => 'object' ),
						'previous'   => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The snippet after the write.
	 */
	public static function execute( $input = array() ): array {
		$available = CustomCodeSupport::require_available();

		if ( is_wp_error( $available ) ) {
			return AbilityRegistrar::failure( $available );
		}

		$input = is_array( $input ) ? $input : array();
		$valid = CustomCodeSupport::validate( $input );

		if ( is_wp_error( $valid ) ) {
			return AbilityRegistrar::failure( $valid );
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( $post_id > 0 ) {
			return self::update( $post_id, $input );
		}

		return self::create( $input );
	}

	/**
	 * Changes an existing snippet.
	 *
	 * @param int                  $post_id Snippet ID.
	 * @param array<string, mixed> $input   Input parameters.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private static function update( int $post_id, array $input ): array {
		$post = CustomCodeSupport::get( $post_id );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$previous = CustomCodeSupport::format( $post, true );
		$postarr  = array( 'ID' => $post->ID );

		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
		}

		if ( isset( $input['status'] ) ) {
			$postarr['post_status'] = (string) $input['status'];
		}

		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $updated ) ) {
				return AbilityRegistrar::failure( $updated );
			}

			// Elementor Pro's save_post hook rebuilds the snippet options from
			// $_POST, which is empty here, so it has just reset "always load
			// jQuery". Put the previous value back unless a new one was given.
			if ( ! array_key_exists( 'ensure_jquery', $input ) ) {
				$input['ensure_jquery'] = $previous['ensure_jquery'];
			}
		}

		$written = CustomCodeSupport::write( $post, $input );

		if ( is_wp_error( $written ) ) {
			return AbilityRegistrar::failure( $written );
		}

		$fresh = get_post( $post->ID );

		return array(
			'success'  => true,
			'created'  => false,
			'snippet'  => CustomCodeSupport::format( $fresh instanceof \WP_Post ? $fresh : $post, true ),
			'previous' => $previous,
		);
	}

	/**
	 * Creates a new snippet.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private static function create( array $input ): array {
		$title = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';

		if ( '' === $title ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_title', 'title is required when creating a snippet.' ) );
		}

		if ( ! isset( $input['code'] ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_code', 'code is required when creating a snippet.' ) );
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => CustomCodeSupport::CPT,
					'post_title'  => sanitize_text_field( $title ),
					'post_status' => isset( $input['status'] ) ? (string) $input['status'] : 'draft',
					'post_author' => get_current_user_id(),
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return AbilityRegistrar::failure( $post_id );
		}

		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return AbilityRegistrar::failure( new WP_Error( 'create_failed', 'The snippet could not be created.' ) );
		}

		$input += array(
			'location' => 'elementor_head',
			'priority' => 1,
		);

		$written = CustomCodeSupport::write( $post, $input );

		if ( is_wp_error( $written ) ) {
			return AbilityRegistrar::failure( $written );
		}

		return array(
			'success' => true,
			'created' => true,
			'snippet' => CustomCodeSupport::format( $post, true ),
		);
	}
}
