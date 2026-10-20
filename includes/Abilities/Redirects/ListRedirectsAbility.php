<?php
/**
 * Ability for listing Redirection rules.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Redirects;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\RedirectionSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Redirects - Lists the Redirection plugin's rules and groups.
 */
final class ListRedirectsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'redirects-list',
			array(
				'label'               => 'List Redirects',
				'description'         => 'List the Redirection plugin\'s redirect rules, with their source, target, status code, regex flag, hit count and whether they are enabled, plus the redirect groups. Filter by source or target text (partial match), title, group, enabled/disabled, regex or status code. Use it to find a rule\'s id before redirects-update or redirects-delete, or to check a source is not already redirected.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'source'   => array(
							'type'        => 'string',
							'description' => 'Only rules whose source contains this text.',
						),
						'target'   => array(
							'type'        => 'string',
							'description' => 'Only rules whose target contains this text.',
						),
						'title'    => array(
							'type'        => 'string',
							'description' => 'Only rules whose title contains this text.',
						),
						'group_id' => array(
							'type'        => 'integer',
							'description' => 'Only rules in this group.',
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'enabled', 'disabled' ),
							'description' => 'Only enabled or only disabled rules.',
						),
						'regex'    => array(
							'type'        => 'boolean',
							'description' => 'Only regex rules (true) or only plain rules (false).',
						),
						'code'     => array(
							'type'        => 'integer',
							'description' => 'Only rules with this status code, e.g. 301.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Rules per page, 5 to 200. Default 50.',
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'redirects'  => array( 'type' => 'array' ),
						'total'      => array( 'type' => 'integer' ),
						'groups'     => array( 'type' => 'array' ),
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
	 * @return array<string, mixed> The redirects.
	 */
	public static function execute( $input = array() ): array {
		$active = RedirectionSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$allowed = RedirectionSupport::require_cap( 'manage' );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$result = RedirectionSupport::list( is_array( $input ) ? $input : array() );

		return array(
			'success'   => true,
			'redirects' => $result['items'],
			'total'     => $result['total'],
			'groups'    => RedirectionSupport::groups(),
		);
	}
}
