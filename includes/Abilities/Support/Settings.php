<?php
/**
 * Stored site settings for the MCP content abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Settings
 *
 * Reads and validates the options set on the Meta MCP settings screen. Kept
 * separate from the admin UI so the ability layer never depends on anything
 * under `wp-admin`, and so the resolved values can be unit tested without
 * loading an admin page.
 */
final class Settings {

	/**
	 * Option name holding the settings array.
	 */
	public const OPTION = 'meta_mcp_settings';

	/**
	 * Default settings for a site that has never opened the settings screen.
	 *
	 * `expose_all_post_types` defaults to true so a fresh install behaves like
	 * the plugin did before this screen existed, and so a post type registered
	 * later is picked up without anyone having to revisit the settings.
	 *
	 * @return array<string, mixed> The defaults.
	 */
	public static function defaults(): array {
		return array(
			'expose_all_post_types' => true,
			'post_types'            => array(),
			'writes_enabled'        => true,
			'menus_enabled'         => true,
		);
	}

	/**
	 * Returns the stored settings merged over the defaults.
	 *
	 * @return array<string, mixed> The settings.
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Returns the post types selected for MCP exposure.
	 *
	 * Always intersected with what is actually registered, so a post type whose
	 * plugin has since been deactivated cannot linger in the list.
	 *
	 * @return string[] Post type slugs.
	 */
	public static function active_post_types(): array {
		$settings   = self::get();
		$selectable = ContentSupport::registered_post_types();

		if ( ! empty( $settings['expose_all_post_types'] ) ) {
			return $selectable;
		}

		$selected = is_array( $settings['post_types'] ) ? array_map( 'strval', $settings['post_types'] ) : array();

		return array_values( array_intersect( $selectable, $selected ) );
	}

	/**
	 * Whether write abilities are enabled in the settings.
	 *
	 * @return bool True when writes are enabled.
	 */
	public static function writes_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['writes_enabled'] );
	}

	/**
	 * Whether the navigation menu abilities are enabled in the settings.
	 *
	 * @return bool True when the menu tools should be registered.
	 */
	public static function menus_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['menus_enabled'] );
	}

	/**
	 * Validates a settings array submitted from the settings screen.
	 *
	 * @param mixed $input Raw submitted value.
	 *
	 * @return array<string, mixed> The sanitized settings.
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$selectable = ContentSupport::registered_post_types();
		$submitted  = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_map( 'strval', $input['post_types'] )
			: array();

		return array(
			'expose_all_post_types' => ! empty( $input['expose_all_post_types'] ),
			// Only ever store slugs that are really registered and not internal.
			'post_types'            => array_values( array_intersect( $selectable, $submitted ) ),
			'writes_enabled'        => ! empty( $input['writes_enabled'] ),
			'menus_enabled'         => ! empty( $input['menus_enabled'] ),
		);
	}
}
