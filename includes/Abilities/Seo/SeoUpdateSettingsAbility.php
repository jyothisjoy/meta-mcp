<?php
/**
 * Ability for changing the SEO plugin's (Rank Math or Yoast SEO) site-wide settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\SeoBackend;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Update Settings - Merges values into Rank Math's or Yoast's site-wide settings.
 */
final class SeoUpdateSettingsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'seo-update-settings',
			array(
				'label'               => 'Update SEO Settings',
				'description'         => 'Change the SEO plugin\'s site-wide settings in one group (Rank Math: titles, sitemap, general; Yoast: titles, general, social). Only passed keys change. Examples: {"pt_facility_custom_robots":"on","pt_facility_robots":["noindex"]} (titles) noindexes a post type; {"pt_facility_sitemap":"off"} (sitemap). Booleans are converted to the plugin\'s format; with Yoast, values its validation changed are reported under "adjusted". Unknown keys are refused unless allow_new_keys; one refused key means nothing is saved. null is refused. The sitemap cache is cleared. A post\'s own robots overrides the type default; clear those with seo-update-posts.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group'          => array(
							'type'        => 'string',
							'enum'        => SeoBackend::all_groups(),
							'description' => 'Which settings group to change. Rank Math: general, titles, sitemap. Yoast SEO: general, titles, social.',
						),
						'settings'       => array(
							'type'        => 'object',
							'description' => 'Setting keys and their new values.',
						),
						'allow_new_keys' => array(
							'type'        => 'boolean',
							'description' => 'Allow adding keys that are not stored yet, e.g. settings for a post type the SEO plugin has not seen. Default false.',
						),
						'dry_run'        => array(
							'type'        => 'boolean',
							'description' => 'Report what would change without saving. Default false.',
						),
						'provider'       => SeoBackend::provider_schema(),
					),
					'required'   => array( 'group', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'provider'   => array( 'type' => 'string' ),
						'group'      => array( 'type' => 'string' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'saved'      => array( 'type' => 'boolean' ),
						'changed'    => array( 'type' => 'object' ),
						'unchanged'  => array( 'type' => 'array' ),
						'errors'     => array( 'type' => 'object' ),
						'adjusted'   => array( 'type' => 'object' ),
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
	 * @return array<string, mixed> The change report.
	 */
	public static function execute( $input = array() ): array {
		$input   = is_array( $input ) ? $input : array();
		$backend = SeoBackend::resolve( $input );

		if ( is_wp_error( $backend ) ) {
			return AbilityRegistrar::failure( $backend );
		}

		$group = $backend::validate_group( $input['group'] ?? '' );

		if ( is_wp_error( $group ) ) {
			return AbilityRegistrar::failure( $group );
		}

		$allowed = $backend::require_can_manage_settings( $group );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$values = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( empty( $values ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_settings', 'settings must contain at least one key.' ) );
		}

		$secrets = array_values( array_filter( array_map( 'strval', array_keys( $values ) ), array( SeoBackend::class, 'is_secret_key' ) ) );

		if ( ! empty( $secrets ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'secret_setting', sprintf( 'These settings hold credentials and can only be changed on the SEO plugin screen: %s.', implode( ', ', $secrets ) ) )
			);
		}

		$dry_run = ContentSupport::to_bool( $input['dry_run'] ?? null, false );
		$result  = $backend::update_settings(
			$group,
			$values,
			ContentSupport::to_bool( $input['allow_new_keys'] ?? null, false ),
			$dry_run
		);

		$response = array(
			'success'   => empty( $result['errors'] ),
			'provider'  => SeoBackend::name_of( $backend ),
			'group'     => $group,
			'dry_run'   => $dry_run,
			'saved'     => $result['saved'],
			'changed'   => (object) $result['changed'],
			'unchanged' => $result['unchanged'],
			'errors'    => (object) $result['errors'],
		);

		if ( ! empty( $result['adjusted'] ) ) {
			$response['adjusted'] = (object) $result['adjusted'];
		}

		if ( ! empty( $result['errors'] ) ) {
			return AbilityRegistrar::failure_with_details(
				'Nothing was saved because some keys were rejected.',
				'settings_rejected',
				array( 'errors' => $result['errors'] )
			);
		}

		return $response;
	}
}
