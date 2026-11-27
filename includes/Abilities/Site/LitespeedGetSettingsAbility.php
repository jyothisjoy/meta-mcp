<?php
/**
 * Ability for reading LiteSpeed Cache settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Site;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\LitespeedSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * LiteSpeed Get Settings - Reads LiteSpeed Cache's stored settings, secrets redacted.
 */
final class LitespeedGetSettingsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'litespeed-get-settings',
			array(
				'label'               => 'Get LiteSpeed Cache Settings',
				'description'         => 'Read LiteSpeed Cache settings with their type: page cache (e.g. "cache", "cache-ttl_pub"), CSS/JS/HTML optimisation ("optm-css_min", "optm-css_comb", "optm-js_min", "optm-js_comb", "optm-js_defer", "optm-ucss", "optm-ccss_gen", "optm-html_min", "optm-emoji_rm", "optm-qs_rm", "optm-ggfonts_async"), lazy loading ("media-lazy", "media-iframe_lazy"), image optimisation ("img_optm-*"), object cache ("object*"), CDN and crawler settings. Ids are LiteSpeed\'s own (the option name without "litespeed.conf."). Use search to filter ids by substring (e.g. "optm-js", "lazy") or keys for exact ids. Settings that hold API keys, tokens, passwords or account emails are listed as "[redacted]" and cannot be changed through MCP. Change values with litespeed-update-settings. Requires "manage_options".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Only return ids containing this text, case-insensitive.',
						),
						'keys'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Only return these exact ids.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'version'    => array( 'type' => 'string' ),
						'settings'   => array( 'type' => 'object' ),
						'types'      => array( 'type' => 'object' ),
						'redacted'   => array( 'type' => 'array' ),
						'missing'    => array( 'type' => 'array' ),
						'count'      => array( 'type' => 'integer' ),
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
	 * @return array<string, mixed> The settings.
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

		$input  = is_array( $input ) ? $input : array();
		$search = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$keys   = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_filter( array_map( 'strval', $input['keys'] ) ) ) : array();

		$all      = LitespeedSupport::all();
		$settings = array();
		$types    = array();
		$redacted = array();
		$missing  = array();

		if ( ! empty( $keys ) ) {
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $all ) ) {
					$missing[] = $key;
				}
			}

			$all = array_intersect_key( $all, array_flip( $keys ) );
		}

		foreach ( $all as $id => $value ) {
			$id = (string) $id;

			if ( '' !== $search && false === strpos( strtolower( $id ), $search ) ) {
				continue;
			}

			$types[ $id ] = LitespeedSupport::type_of( $value );

			if ( LitespeedSupport::is_secret( $id ) ) {
				$has               = is_array( $value ) ? ! empty( $value ) : ( '' !== (string) $value && false !== $value );
				$settings[ $id ]   = $has ? '[redacted]' : '';
				$redacted[]        = $id;
				continue;
			}

			$settings[ $id ] = $value;
		}

		return array(
			'success'  => true,
			'version'  => LitespeedSupport::version(),
			'settings' => (object) $settings,
			'types'    => (object) $types,
			'redacted' => $redacted,
			'missing'  => $missing,
			'count'    => count( $settings ),
		);
	}
}
