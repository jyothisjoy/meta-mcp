<?php
/**
 * Ability for changing a redirect rule.
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
 * Update Redirect - Changes one redirect rule, or switches it on or off.
 */
final class UpdateRedirectAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$properties = array_merge(
			array(
				'id'       => array(
					'type'        => 'integer',
					'description' => 'The redirect rule to change, from redirects-list.',
				),
				'enabled'  => array(
					'type'        => 'boolean',
					'description' => 'Enable or disable the rule.',
				),
				'provider' => RedirectsBackend::provider_schema(),
			),
			RedirectsBackend::rule_schema()
		);

		AbilityRegistrar::register(
			'redirects-update',
			array(
				'label'               => 'Update Redirect',
				'description'         => 'Change one redirect rule in the Redirection plugin or Rank Math → Redirections: its source, target, status code, regex flag, matching options, or enable/disable it (title and group are Redirection only). Only the fields you pass change; the rest are kept. E.g. {"id":12,"target":"/rooms-suites/"} retargets a rule. Redirection rules that match on something other than the URL (login state, referrer, cookie...) can only be enabled or disabled here.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'provider'   => array( 'type' => 'string' ),
						'redirect'   => array( 'type' => 'object' ),
						'previous'   => array( 'type' => 'object' ),
						'notes'      => array( 'type' => 'array' ),
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
	 * @return array<string, mixed> The updated rule.
	 */
	public static function execute( $input = array() ): array {
		$input   = is_array( $input ) ? $input : array();
		$backend = RedirectsBackend::resolve( $input );

		if ( is_wp_error( $backend ) ) {
			return AbilityRegistrar::failure( $backend );
		}

		$allowed = $backend::require_cap( 'add' );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$id       = (int) ( $input['id'] ?? 0 );
		$previous = $backend::get_formatted( $id );

		if ( is_wp_error( $previous ) ) {
			return AbilityRegistrar::failure( $previous );
		}

		$changes = array_intersect_key( $input, RedirectsBackend::rule_schema() );

		if ( empty( $changes ) && ! array_key_exists( 'enabled', $input ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'nothing_to_change', 'Pass at least one field to change, or enabled.' )
			);
		}

		$notes = array();

		if ( ! empty( $changes ) ) {
			$prepared = $backend::prepare( $changes, $id );

			if ( is_wp_error( $prepared ) ) {
				return AbilityRegistrar::failure( $prepared );
			}

			$notes = $prepared['notes'];
			$saved = $backend::update( $id, $prepared );

			if ( is_wp_error( $saved ) ) {
				return AbilityRegistrar::failure( $saved );
			}
		}

		if ( array_key_exists( 'enabled', $input ) ) {
			$toggled = $backend::set_enabled( $id, ContentSupport::to_bool( $input['enabled'], true ) );

			if ( is_wp_error( $toggled ) ) {
				return AbilityRegistrar::failure( $toggled );
			}
		}

		$fresh = $backend::get_formatted( $id );

		return array(
			'success'  => true,
			'provider' => $backend::provider(),
			'redirect' => is_wp_error( $fresh ) ? $previous : $fresh,
			'previous' => $previous,
			'notes'    => $notes,
		);
	}
}
