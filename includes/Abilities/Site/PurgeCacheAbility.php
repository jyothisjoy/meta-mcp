<?php
/**
 * Ability for purging page caches.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Site;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\SiteSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Purge Cache - Clears page caches so edits are visible to visitors and crawlers.
 */
final class PurgeCacheAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'purge-cache',
			array(
				'label'               => 'Purge Cache',
				'description'         => 'Purge the page cache so recent edits show to visitors and search engines. Calls whichever of LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache and SiteGround Optimizer are installed, and on a full purge also clears Elementor\'s generated CSS. Pass post_ids to purge only those pages; omit it to purge everything (requires "manage_options"). Reports which caches were purged.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Only purge these posts\' pages. Omit to purge the whole site.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'scope'      => array( 'type' => 'string' ),
						'purged'     => array( 'type' => 'array' ),
						'skipped'    => array( 'type' => 'array' ),
						'note'       => array( 'type' => 'string' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> Which caches were purged.
	 */
	public static function execute( $input = array() ): array {
		$ids = isset( $input['post_ids'] ) && is_array( $input['post_ids'] )
			? array_values( array_unique( array_filter( array_map( 'intval', $input['post_ids'] ) ) ) )
			: array();

		if ( empty( $ids ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'insufficient_capability', 'Purging the whole site requires the "manage_options" capability. Pass post_ids to purge specific pages.' )
				);
			}
		} else {
			foreach ( $ids as $id ) {
				$post = ContentSupport::require_can_edit( $id );

				if ( is_wp_error( $post ) ) {
					return AbilityRegistrar::failure( $post );
				}
			}
		}

		$result = SiteSupport::purge_caches( $ids );

		$response = array(
			'success' => true,
			'scope'   => empty( $ids ) ? 'all' : 'posts',
			'purged'  => $result['purged'],
			'skipped' => $result['skipped'],
		);

		if ( empty( $result['purged'] ) ) {
			$response['note'] = 'No supported page cache plugin was found. A CDN or host-level cache may still need purging from its own dashboard.';
		}

		return $response;
	}
}
