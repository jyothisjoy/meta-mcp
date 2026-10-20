<?php
/**
 * Ability for changing site-wide WordPress settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Site;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\SiteSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Site Settings - Changes Settings → General / Reading values.
 */
final class UpdateSiteSettingsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-site-settings',
			array(
				'label'               => 'Update Site Settings',
				'description'         => 'Change site-wide WordPress settings, e.g. {"blogname":"Red Castle Hotel Sharjah","blogdescription":"4-Star Hotel Suites in Sharjah"}. Only the settings listed by get-site-settings are accepted; the site URL, admin email, registration and default role can never be changed here. Every value is validated first and nothing is saved if any is rejected. Requires "manage_options".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'settings' => array(
							'type'        => 'object',
							'description' => 'Option names and their new values.',
						),
					),
					'required'   => array( 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'insufficient_capability', 'User lacks the "manage_options" capability needed for site settings.' )
			);
		}

		$values = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( empty( $values ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_settings', 'settings must contain at least one option.' ) );
		}

		$kinds   = SiteSupport::settings();
		$clean   = array();
		$errors  = array();

		foreach ( $values as $option => $value ) {
			$option = (string) $option;

			if ( ! isset( $kinds[ $option ] ) ) {
				$errors[ $option ] = sprintf( '"%s" is not a setting this connection may change. Allowed: %s.', $option, implode( ', ', array_keys( $kinds ) ) );
				continue;
			}

			$cleaned = SiteSupport::clean_value( (string) $kinds[ $option ], $value );

			if ( is_wp_error( $cleaned ) ) {
				$errors[ $option ] = $cleaned->get_error_message();
				continue;
			}

			$clean[ $option ] = $cleaned;
		}

		if ( ! empty( $errors ) ) {
			return AbilityRegistrar::failure_with_details(
				'Nothing was saved because some settings were rejected.',
				'settings_rejected',
				array( 'errors' => $errors )
			);
		}

		$changed   = array();
		$unchanged = array();

		foreach ( $clean as $option => $value ) {
			$before = get_option( $option );

			// Compare loosely: WordPress returns most options as strings.
			if ( (string) $before === (string) $value ) {
				$unchanged[] = $option;
				continue;
			}

			update_option( $option, $value );

			$changed[ $option ] = array(
				'before' => $before,
				'after'  => get_option( $option ),
			);
		}

		return array(
			'success'   => true,
			'changed'   => (object) $changed,
			'unchanged' => $unchanged,
			'errors'    => (object) array(),
		);
	}
}
