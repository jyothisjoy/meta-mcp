<?php
/**
 * Ability for reading site-wide WordPress settings.
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
 * Get Site Settings - Reads Settings → General / Reading values such as the site title.
 */
final class GetSiteSettingsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'get-site-settings',
			array(
				'label'               => 'Get Site Settings',
				'description'         => 'Read the site-wide WordPress settings this connection may manage: blogname (Site Title), blogdescription (Tagline), timezone, date and time formats, show_on_front / page_on_front / page_for_posts (homepage and posts page), posts_per_page, default comment status and site_icon. Change them with update-site-settings.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'settings'   => array( 'type' => 'object' ),
						'kinds'      => array( 'type' => 'object' ),
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
	 * @param array $input Input parameters (unused).
	 *
	 * @return array<string, mixed> The settings.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the ability callback.
	public static function execute( $input = array() ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'insufficient_capability', 'User lacks the "manage_options" capability needed for site settings.' )
			);
		}

		$kinds    = SiteSupport::settings();
		$settings = array();

		foreach ( array_keys( $kinds ) as $option ) {
			$settings[ $option ] = get_option( $option );
		}

		return array(
			'success'  => true,
			'settings' => (object) $settings,
			'kinds'    => (object) $kinds,
		);
	}
}
