<?php
/**
 * Ability for deleting Redirection rules.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Redirects;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\RedirectsBackend;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Delete Redirects - Permanently removes Redirection rules.
 */
final class DeleteRedirectsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'redirects-delete',
			array(
				'label'               => 'Delete Redirects',
				'description'         => 'Permanently delete redirect rules by id, in the Redirection plugin or Rank Math → Redirections (provider picks one when both are active). There is no trash, so confirm must be true. To stop a rule without losing it, use redirects-update with enabled false instead. Returns the deleted rules so they can be recreated if needed.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'ids'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'The redirect rule ids to delete.',
						),
						'confirm'  => array(
							'type'        => 'boolean',
							'description' => 'Must be true. Deletion cannot be undone.',
						),
						'provider' => RedirectsBackend::provider_schema(),
					),
					'required'   => array( 'ids', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'provider'   => array( 'type' => 'string' ),
						'deleted'    => array( 'type' => 'array' ),
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
	 * @return array<string, mixed> The deleted rules.
	 */
	public static function execute( $input = array() ): array {
		$input   = is_array( $input ) ? $input : array();
		$backend = RedirectsBackend::resolve( $input );

		if ( is_wp_error( $backend ) ) {
			return AbilityRegistrar::failure( $backend );
		}

		$allowed = $backend::require_cap( 'delete' );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		if ( ! ContentSupport::to_bool( $input['confirm'] ?? null, false ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'confirmation_required', 'Deleting redirects cannot be undone. Pass confirm true, or disable the rules with redirects-update instead.' )
			);
		}

		$ids = isset( $input['ids'] ) && is_array( $input['ids'] )
			? array_values( array_unique( array_filter( array_map( 'intval', $input['ids'] ) ) ) )
			: array();

		if ( empty( $ids ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_ids', 'ids must list at least one redirect id.' ) );
		}

		// Resolve them all first, so an unknown id deletes nothing.
		$items = array();

		foreach ( $ids as $id ) {
			$item = $backend::get_formatted( $id );

			if ( is_wp_error( $item ) ) {
				return AbilityRegistrar::failure( $item );
			}

			$items[] = $item;
		}

		$deleted = array();

		foreach ( $items as $item ) {
			$gone = $backend::delete( (int) $item['id'] );

			if ( is_wp_error( $gone ) ) {
				return AbilityRegistrar::failure_with_details(
					$gone->get_error_message(),
					'redirect_not_deleted',
					array(
						'deleted'  => $deleted,
						'provider' => $backend::provider(),
					)
				);
			}

			$deleted[] = $item;
		}

		return array(
			'success'  => true,
			'provider' => $backend::provider(),
			'deleted'  => $deleted,
		);
	}
}
