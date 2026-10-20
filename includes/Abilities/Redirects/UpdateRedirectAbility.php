<?php
/**
 * Ability for editing a Redirection rule.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Redirects;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\RedirectionSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Redirect - Changes one Redirection rule, or switches it on or off.
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
				'id'      => array(
					'type'        => 'integer',
					'description' => 'The redirect rule to change, from redirects-list.',
				),
				'enabled' => array(
					'type'        => 'boolean',
					'description' => 'Enable or disable the rule.',
				),
			),
			RedirectionSupport::rule_schema()
		);

		AbilityRegistrar::register(
			'redirects-update',
			array(
				'label'               => 'Update Redirect',
				'description'         => 'Change one Redirection rule: its source, target, status code, regex flag, matching options, title or group, or enable/disable it. Only the fields you pass change; the rest are kept. E.g. {"id":12,"target":"/rooms-suites/"} retargets a rule. Rules that match on something other than the URL (login state, referrer, cookie...) can only be enabled or disabled here.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'redirect'   => array( 'type' => 'object' ),
						'previous'   => array( 'type' => 'object' ),
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
		$active = RedirectionSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$allowed = RedirectionSupport::require_cap( 'add' );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$item = RedirectionSupport::get( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $item ) ) {
			return AbilityRegistrar::failure( $item );
		}

		$current  = $item->to_json();
		$previous = RedirectionSupport::format( $current );
		$changes  = array_intersect_key( $input, RedirectionSupport::rule_schema() );

		if ( empty( $changes ) && ! array_key_exists( 'enabled', $input ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'nothing_to_change', 'Pass at least one field to change, or enabled.' )
			);
		}

		if ( ! empty( $changes ) ) {
			$details = RedirectionSupport::build_details( $changes, $current );

			if ( is_wp_error( $details ) ) {
				return AbilityRegistrar::failure( $details );
			}

			$saved = $item->update( $details );

			if ( is_wp_error( $saved ) ) {
				return AbilityRegistrar::failure( $saved );
			}
		}

		if ( array_key_exists( 'enabled', $input ) ) {
			ContentSupport::to_bool( $input['enabled'], true ) ? $item->enable() : $item->disable();
		}

		$fresh = RedirectionSupport::get( (int) $previous['id'] );

		return array(
			'success'  => true,
			'redirect' => RedirectionSupport::format( is_wp_error( $fresh ) ? $item : $fresh ),
			'previous' => $previous,
		);
	}
}
