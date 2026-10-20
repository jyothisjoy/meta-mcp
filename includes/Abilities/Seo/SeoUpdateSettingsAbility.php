<?php
/**
 * Ability for changing Rank Math's site-wide settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Seo;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\RankMathSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SEO Update Settings - Merges values into Rank Math's General, Titles & Meta or Sitemap settings.
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
				'label'               => 'Update Rank Math Settings',
				'description'         => 'Change Rank Math\'s site-wide settings on one screen ("titles", "sitemap" or "general"). Only the keys you pass change; everything else is kept. Examples: {"title_separator":"|", "knowledgegraph_type":"company", "knowledgegraph_name":"Red Castle Hotel"} on titles; {"pt_facility_custom_robots":"on", "pt_facility_robots":["noindex"]} on titles to noindex a post type by default; {"pt_facility_sitemap":"off", "authors_sitemap":"off"} on sitemap. Switches take "on"/"off" (true/false is converted). Keys that do not exist yet are refused unless allow_new_keys is set, so typos are caught; if any key is refused, nothing is saved. The sitemap cache is cleared automatically. Note a post\'s own robots setting overrides the post type default; clear those with seo-update-posts.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group'          => array(
							'type'        => 'string',
							'enum'        => array_keys( RankMathSupport::OPTION_GROUPS ),
							'description' => 'Which settings screen to change.',
						),
						'settings'       => array(
							'type'        => 'object',
							'description' => 'Setting keys and their new values.',
						),
						'allow_new_keys' => array(
							'type'        => 'boolean',
							'description' => 'Allow adding keys that are not stored yet, e.g. settings for a post type Rank Math has not seen. Default false.',
						),
						'dry_run'        => array(
							'type'        => 'boolean',
							'description' => 'Report what would change without saving. Default false.',
						),
					),
					'required'   => array( 'group', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'group'      => array( 'type' => 'string' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'saved'      => array( 'type' => 'boolean' ),
						'changed'    => array( 'type' => 'object' ),
						'unchanged'  => array( 'type' => 'array' ),
						'errors'     => array( 'type' => 'object' ),
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
		$active = RankMathSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$group = RankMathSupport::validate_group( $input['group'] ?? '' );

		if ( is_wp_error( $group ) ) {
			return AbilityRegistrar::failure( $group );
		}

		$allowed = RankMathSupport::require_can_manage_settings( $group );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$values = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( empty( $values ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_settings', 'settings must contain at least one key.' ) );
		}

		$dry_run = ContentSupport::to_bool( $input['dry_run'] ?? null, false );
		$result  = RankMathSupport::update_settings(
			$group,
			$values,
			ContentSupport::to_bool( $input['allow_new_keys'] ?? null, false ),
			$dry_run
		);

		$response = array(
			'success'   => empty( $result['errors'] ),
			'group'     => $group,
			'dry_run'   => $dry_run,
			'saved'     => $result['saved'],
			'changed'   => (object) $result['changed'],
			'unchanged' => $result['unchanged'],
			'errors'    => (object) $result['errors'],
		);

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
