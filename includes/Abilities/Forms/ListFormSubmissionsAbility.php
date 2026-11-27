<?php
/**
 * Ability for listing Elementor Pro form submissions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Forms;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\FormSubmissionsSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * List Form Submissions - Pages through Elementor Pro form entries.
 */
final class ListFormSubmissionsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-list-form-submissions',
			array(
				'label'               => 'List Elementor Form Submissions',
				'description'         => 'List the entries sent through Elementor Pro forms (contact, booking enquiry, newsletter...), newest first, with every field the visitor filled in, the form name, the page it was sent from, and whether it has been read. Filter by form_name, post_id (the page holding the form), status, a date range or a search term found in any field. The response also lists every form name with its count. Entries contain guests\' personal data: use them only for the task at hand and do not copy them elsewhere unasked. Visitor IP addresses and browser details are never returned. Read-only; requires manage_options.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_name' => array(
							'type'        => 'string',
							'description' => 'Only entries from the form with this name (as set in the form widget).',
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => 'Only entries sent from this page or template.',
						),
						'status'    => array(
							'type'        => 'string',
							'enum'        => FormSubmissionsSupport::STATUSES,
							'description' => '"new" (unread), "read", "trash", or "all" (default: everything not in the trash).',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Only entries with a field value containing this text, e.g. an email address.',
						),
						'after'     => array(
							'type'        => 'string',
							'description' => 'Only entries sent at or after this date/time, e.g. "2026-11-01" (UTC).',
						),
						'before'    => array(
							'type'        => 'string',
							'description' => 'Only entries sent at or before this date/time (UTC).',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Entries per page. Default 20, maximum 100.',
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'submissions' => array( 'type' => 'array' ),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'forms'       => array( 'type' => 'array' ),
						'error'       => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The submissions.
	 */
	public static function execute( $input = array() ): array {
		$available = FormSubmissionsSupport::require_available();

		if ( is_wp_error( $available ) ) {
			return AbilityRegistrar::failure( $available );
		}

		$input = is_array( $input ) ? $input : array();

		if ( isset( $input['status'] ) && ! in_array( (string) $input['status'], FormSubmissionsSupport::STATUSES, true ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'invalid_status', sprintf( 'status must be one of: %s.', implode( ', ', FormSubmissionsSupport::STATUSES ) ) )
			);
		}

		return array_merge( array( 'success' => true ), FormSubmissionsSupport::list( $input ) );
	}
}
