<?php
/**
 * Ability for reading one Elementor Pro form submission.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Forms;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\FormSubmissionsSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get Form Submission - Reads one Elementor Pro form entry with all its fields.
 */
final class GetFormSubmissionAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-get-form-submission',
			array(
				'label'               => 'Get Elementor Form Submission',
				'description'         => 'Read one Elementor Pro form entry by ID, with every field the visitor filled in, the form name, the page it was sent from and whether it has been read. Contains a guest\'s personal data; IP address and browser details are never returned. Read-only; requires manage_options.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'submission_id' => array(
							'type'        => 'integer',
							'description' => 'The submission ID, from elementor-list-form-submissions.',
						),
					),
					'required'   => array( 'submission_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'submission' => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The submission.
	 */
	public static function execute( $input = array() ): array {
		$available = FormSubmissionsSupport::require_available();

		if ( is_wp_error( $available ) ) {
			return AbilityRegistrar::failure( $available );
		}

		$submission = FormSubmissionsSupport::get( (int) ( $input['submission_id'] ?? 0 ) );

		if ( is_wp_error( $submission ) ) {
			return AbilityRegistrar::failure( $submission );
		}

		return array(
			'success'    => true,
			'submission' => $submission,
		);
	}
}
