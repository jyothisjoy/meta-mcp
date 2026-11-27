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
	 * `users_enabled` is the one default that is off. Managing user accounts is
	 * a different kind of power from editing content, so a site has to switch it
	 * on deliberately rather than acquire it by updating the plugin.
	 *
	 * `site_tools_enabled` covers the site-wide tools: General settings, Rank
	 * Math settings and per-page SEO fields, Redirection rules, Elementor Custom
	 * Code snippets and cache purging. Every one of them still requires the
	 * capability an administrator would need for the same screen in wp-admin.
	 *
	 * `forms_enabled` is off by default too: form submissions are what guests
	 * typed into the site (names, emails, phone numbers), not site content.
	 *
	 * `writable_protected_meta` lists underscore-prefixed meta keys the meta
	 * tools may write, one per entry; an entry ending in "*" is a prefix.
	 *
	 * @return array<string, mixed> The defaults.
	 */
	public static function defaults(): array {
		return array(
			'expose_all_post_types' => true,
			'post_types'            => array(),
			'writes_enabled'        => true,
			'menus_enabled'         => true,
			'users_enabled'         => false,
			'site_tools_enabled'    => true,
			'comments_enabled'      => true,
			'forms_enabled'         => false,
			'writable_protected_meta' => array(),
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
	 * Whether the user abilities are enabled in the settings.
	 *
	 * @return bool True when the user tools should be registered.
	 */
	public static function users_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['users_enabled'] );
	}

	/**
	 * Whether the site-wide tools (settings, SEO, redirects, custom code, cache) are enabled.
	 *
	 * @return bool True when those tools should be registered.
	 */
	public static function site_tools_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['site_tools_enabled'] );
	}

	/**
	 * Whether the comment moderation abilities are enabled in the settings.
	 *
	 * @return bool True when the comment tools should be registered.
	 */
	public static function comments_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['comments_enabled'] );
	}

	/**
	 * Whether the form submission abilities are enabled in the settings.
	 *
	 * @return bool True when the form submission tools should be registered.
	 */
	public static function forms_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['forms_enabled'] );
	}

	/**
	 * The protected meta keys and prefixes allowlisted for writing.
	 *
	 * @return string[] Keys; an entry ending in "*" is a prefix.
	 */
	public static function writable_protected_meta(): array {
		$settings = self::get();

		return is_array( $settings['writable_protected_meta'] ) ? array_values( array_map( 'strval', $settings['writable_protected_meta'] ) ) : array();
	}

	/**
	 * Cleans the protected meta allowlist submitted from the settings screen.
	 *
	 * Accepts a newline or comma separated string or a list. Only keys that
	 * start with an underscore are kept, since unprotected keys need no
	 * allowlisting.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[] The cleaned keys.
	 */
	public static function sanitize_meta_keys( $value ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[
,]+/', (string) $value );
		$keys  = array();

		foreach ( (array) $items as $item ) {
			$item   = trim( (string) $item );
			$prefix = '*' === substr( $item, -1 );
			$key    = preg_replace( '/[^A-Za-z0-9_\-]/', '', $prefix ? substr( $item, 0, -1 ) : $item );

			if ( '' === $key || '_' !== $key[0] || ( $prefix && strlen( $key ) < 3 ) ) {
				continue;
			}

			$keys[] = $key . ( $prefix ? '*' : '' );
		}

		return array_values( array_unique( $keys ) );
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
			'users_enabled'         => ! empty( $input['users_enabled'] ),
			'site_tools_enabled'    => ! empty( $input['site_tools_enabled'] ),
			'comments_enabled'      => ! empty( $input['comments_enabled'] ),
			'forms_enabled'         => ! empty( $input['forms_enabled'] ),
			'writable_protected_meta' => self::sanitize_meta_keys( $input['writable_protected_meta'] ?? array() ),
		);
	}
}
