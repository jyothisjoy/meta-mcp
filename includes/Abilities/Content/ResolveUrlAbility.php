<?php
/**
 * Ability for turning page URLs into post ids.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\UrlSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Resolve URL - Maps front-end URLs to the posts behind them.
 *
 * Every write ability takes a post id; audits, crawls and people name pages by
 * URL. This is the bridge, and also returns the Elementor display condition
 * that targets each page, ready for elementor-set-custom-code or
 * elementor-set-conditions.
 */
final class ResolveUrlAbility {

	/**
	 * Largest batch accepted in one call.
	 */
	private const MAX_URLS = 100;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'resolve-url',
			array(
				'label'               => 'Resolve URL',
				'description'         => 'Turn up to 100 page URLs (absolute, or paths such as "/room/deluxe-suite/") into the posts that render them: post_id, post_type, title, status, language and whether the page is built with Elementor, plus "condition", the Elementor display condition that targets exactly that page (e.g. "include/singular/hotel_room/48"), ready to pass to elementor-set-custom-code or elementor-set-conditions. Works with custom post types, child pages and Polylang language prefixes, and resolves the homepage of each language. Every match is confirmed against the post\'s own permalink. Read-only.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'urls' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'URLs or site-relative paths.',
						),
					),
					'required'   => array( 'urls' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'results'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'resolved'   => array( 'type' => 'integer' ),
						'unresolved' => array( 'type' => 'integer' ),
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
	 * @return array<string, mixed> One result per URL, in the order given.
	 */
	public static function execute( $input = array() ): array {
		$urls = isset( $input['urls'] ) && is_array( $input['urls'] ) ? array_values( $input['urls'] ) : array();

		if ( empty( $urls ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_urls', 'urls must list at least one URL.' ) );
		}

		if ( count( $urls ) > self::MAX_URLS ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'too_many_urls', sprintf( 'At most %d URLs can be resolved in one call.', self::MAX_URLS ) )
			);
		}

		$results    = array();
		$unresolved = 0;

		foreach ( $urls as $url ) {
			$url      = is_scalar( $url ) ? (string) $url : '';
			$resolved = UrlSupport::resolve( $url );

			if ( is_wp_error( $resolved ) ) {
				++$unresolved;
				$results[] = array(
					'input'    => $url,
					'resolved' => false,
					'error'    => $resolved->get_error_message(),
				);
				continue;
			}

			// Readers may only see what they could open themselves.
			if ( ! empty( $resolved['post_id'] ) && 'publish' !== $resolved['status'] && ! current_user_can( 'read_post', (int) $resolved['post_id'] ) ) {
				++$unresolved;
				$results[] = array(
					'input'    => $url,
					'resolved' => false,
					'error'    => 'The page exists but you cannot read it.',
				);
				continue;
			}

			$results[] = array(
				'input'    => $url,
				'resolved' => true,
			) + $resolved;
		}

		return array(
			'success'    => true,
			'results'    => $results,
			'resolved'   => count( $results ) - $unresolved,
			'unresolved' => $unresolved,
		);
	}
}
