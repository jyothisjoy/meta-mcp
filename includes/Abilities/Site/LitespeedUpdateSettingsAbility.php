<?php
/**
 * Ability for changing LiteSpeed Cache settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Site;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\LitespeedSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * LiteSpeed Update Settings - Changes existing LiteSpeed Cache settings, all or nothing.
 */
final class LitespeedUpdateSettingsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'litespeed-update-settings',
			array(
				'label'               => 'Update LiteSpeed Cache Settings',
				'description'         => 'Change LiteSpeed Cache settings, e.g. {"optm-css_min": true, "optm-js_defer": 1, "media-lazy": true}. Only ids that already exist (see litespeed-get-settings) are accepted, and each value is converted to the type LiteSpeed stores: booleans stay booleans, 0/1/2 switches stay integers, lists take an array or a newline-separated string. Settings holding API keys, tokens, passwords or account emails are refused. Every value is validated first and nothing is saved if any is rejected. Saved through LiteSpeed\'s own settings API, so it purges and rewrites its rules as its settings screen does. CSS/JS optimisation changes can break a page\'s layout or scripts: check the front end afterwards and keep the "before" values to restore them. Set dry_run to preview. Requires "manage_options".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'settings' => array(
							'type'        => 'object',
							'description' => 'Setting ids and their new values.',
						),
						'dry_run'  => array(
							'type'        => 'boolean',
							'description' => 'Validate and report the change without saving. Default false.',
						),
					),
					'required'   => array( 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'changed'    => array( 'type' => 'object' ),
						'unchanged'  => array( 'type' => 'array' ),
						'saved_via'  => array( 'type' => 'string' ),
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
		$active = LitespeedSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$allowed = LitespeedSupport::require_can_manage();

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$values = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( empty( $values ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_settings', 'settings must contain at least one LiteSpeed setting id.' ) );
		}

		$dry_run   = ContentSupport::to_bool( $input['dry_run'] ?? null, false );
		$all       = LitespeedSupport::all();
		$errors    = array();
		$clean     = array();
		$changed   = array();
		$unchanged = array();

		foreach ( $values as $id => $value ) {
			$id = (string) $id;

			if ( LitespeedSupport::is_secret( $id ) ) {
				$errors[ $id ] = 'This setting holds a credential or account detail and cannot be changed through MCP. Change it on the LiteSpeed Cache screen.';
				continue;
			}

			if ( ! array_key_exists( $id, $all ) ) {
				$errors[ $id ] = sprintf( '"%s" is not an existing LiteSpeed setting. Check the id with litespeed-get-settings.', $id );
				continue;
			}

			$before  = $all[ $id ];
			$coerced = LitespeedSupport::coerce( $value, $before );

			if ( is_wp_error( $coerced ) ) {
				$errors[ $id ] = sprintf( '%s Current value: %s (%s).', $coerced->get_error_message(), wp_json_encode( $before ), LitespeedSupport::type_of( $before ) );
				continue;
			}

			$comparable_before = is_array( $before ) ? array_values( $before ) : $before;

			if ( $comparable_before === $coerced ) {
				$unchanged[] = $id;
				continue;
			}

			$clean[ $id ]   = $coerced;
			$changed[ $id ] = array(
				'before' => $before,
				'after'  => $coerced,
				'type'   => LitespeedSupport::type_of( $before ),
			);
		}

		if ( ! empty( $errors ) ) {
			return AbilityRegistrar::failure_with_details(
				'Nothing was saved because some settings were rejected.',
				'settings_rejected',
				array( 'errors' => $errors )
			);
		}

		$saved_via = '';

		if ( ! $dry_run && ! empty( $clean ) ) {
			$saved_via = LitespeedSupport::save( $clean );

			foreach ( $clean as $id => $value ) {
				$changed[ $id ]['after'] = LitespeedSupport::stored( (string) $id, (string) $changed[ $id ]['type'] );
			}
		}

		return array(
			'success'   => true,
			'dry_run'   => $dry_run,
			'changed'   => (object) $changed,
			'unchanged' => $unchanged,
			'saved_via' => $saved_via,
			'errors'    => (object) array(),
		);
	}
}
