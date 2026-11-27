<?php
/**
 * Picks the SEO plugin the SEO abilities work with.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - SeoBackend
 *
 * A site keeps its per-page SEO fields and its titles, robots and sitemap
 * settings in Rank Math or in Yoast SEO. The SEO abilities speak one format
 * (the same input names for title, description, canonical_url, robots ...) and
 * leave the storage to a backend class with the same static methods:
 *
 *   is_active(), version(), require_active(), OPTION_GROUPS, validate_group( $group ),
 *   require_can_manage_settings( $group ), get_settings( $group ),
 *   update_settings( $group, $values, $allow_new, $dry_run ), POST_FIELDS,
 *   read_post( $post ), write_post( $post, $changes, $dry_run ), post_field_schema(),
 *   filter_meta_query( $filter ), flush_sitemap_cache( $types ), sitemap_index_url(),
 *   sitemap_group().
 *
 * Without a `provider` input the one that is active is used; when both are,
 * Rank Math (the one the abilities were first written for) unless the input
 * says otherwise.
 */
final class SeoBackend {

	/**
	 * Setting keys that hold credentials (Yoast's SEMrush and Wincher OAuth
	 * tokens, API keys, licence keys). Never returned, never writable here.
	 */
	private const SECRET_KEY_PATTERN = '/(token|secret|api_?key|_key$|^key$|license|licence|refresh|access_code|client_id|(^|_)(pass|passwd|password|pwd)$)/i';

	/**
	 * Whether a settings key holds a credential.
	 *
	 * @param string $key Settings key.
	 *
	 * @return bool True for a credential.
	 */
	public static function is_secret_key( string $key ): bool {
		return 1 === preg_match( self::SECRET_KEY_PATTERN, $key );
	}

	/**
	 * Replaces credential values in a settings array.
	 *
	 * @param array<string, mixed> $settings Settings.
	 *
	 * @return array<string, mixed> The settings, credentials shown as "[redacted]".
	 */
	public static function redact( array $settings ): array {
		foreach ( $settings as $key => $value ) {
			if ( self::is_secret_key( (string) $key ) && '' !== $value && array() !== $value && null !== $value ) {
				$settings[ $key ] = '[redacted]';
			}
		}

		return $settings;
	}

	/**
	 * Backend classes by provider name, in default order.
	 */
	private const PROVIDERS = array(
		'rank_math' => RankMathSupport::class,
		'yoast'     => YoastSupport::class,
	);

	/**
	 * Human-readable plugin names, for messages.
	 */
	private const LABELS = array(
		'rank_math' => 'Rank Math',
		'yoast'     => 'Yoast SEO',
	);

	/**
	 * Provider names.
	 *
	 * @return string[] Names.
	 */
	public static function names(): array {
		return array_keys( self::PROVIDERS );
	}

	/**
	 * Whether any supported SEO plugin is active.
	 *
	 * @return bool True when Rank Math or Yoast SEO is loaded.
	 */
	public static function any_active(): bool {
		foreach ( self::PROVIDERS as $class ) {
			if ( $class::is_active() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The active providers.
	 *
	 * @return string[] Names.
	 */
	public static function active(): array {
		return array_values( array_filter( self::names(), static fn( string $name ): bool => self::PROVIDERS[ $name ]::is_active() ) );
	}

	/**
	 * The provider name of a backend class.
	 *
	 * @param string $class Backend class name.
	 *
	 * @return string Provider name, or an empty string.
	 */
	public static function name_of( string $class ): string {
		$name = array_search( $class, self::PROVIDERS, true );

		return false === $name ? '' : (string) $name;
	}

	/**
	 * The plugin label of a provider, e.g. "Yoast SEO".
	 *
	 * @param string $name Provider name.
	 *
	 * @return string Label.
	 */
	public static function label( string $name ): string {
		return self::LABELS[ $name ] ?? $name;
	}

	/**
	 * The backend class for this call.
	 *
	 * @param array<string, mixed> $input Ability input (optional "provider").
	 *
	 * @return string|\WP_Error Backend class name.
	 */
	public static function resolve( array $input ) {
		$wanted = isset( $input['provider'] ) ? strtolower( trim( (string) $input['provider'] ) ) : '';

		if ( '' !== $wanted ) {
			if ( ! isset( self::PROVIDERS[ $wanted ] ) ) {
				return new WP_Error( 'invalid_provider', sprintf( 'provider must be one of: %s.', implode( ', ', self::names() ) ) );
			}

			$class  = self::PROVIDERS[ $wanted ];
			$active = $class::require_active();

			return is_wp_error( $active ) ? $active : $class;
		}

		foreach ( self::PROVIDERS as $class ) {
			if ( $class::is_active() ) {
				return $class;
			}
		}

		return new WP_Error(
			'no_seo_plugin',
			'No supported SEO plugin is active on this site. Install and activate Rank Math or Yoast SEO to use this ability.'
		);
	}

	/**
	 * Input property for choosing the provider.
	 *
	 * @return array<string, mixed> Schema property.
	 */
	public static function provider_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => self::names(),
			'description' => 'Which SEO plugin to use: "rank_math" or "yoast". Default: the one active on the site (Rank Math when both are). content-integrations-status shows which are active.',
		);
	}

	/**
	 * Every settings group either backend knows, for the input enum.
	 *
	 * @return string[] Group keys.
	 */
	public static function all_groups(): array {
		$groups = array();

		foreach ( self::PROVIDERS as $class ) {
			$groups = array_merge( $groups, array_keys( $class::OPTION_GROUPS ) );
		}

		return array_values( array_unique( $groups ) );
	}

	/**
	 * Per-post field schema covering both backends.
	 *
	 * A field only one plugin has is labelled as such, so a client knows a
	 * value it sends to the other is ignored rather than stored.
	 *
	 * @return array<string, array<string, mixed>> Schema properties.
	 */
	public static function post_field_schema(): array {
		$schemas = array();

		foreach ( self::PROVIDERS as $name => $class ) {
			$schemas[ $name ] = $class::post_field_schema();
		}

		$merged = array();

		foreach ( $schemas as $name => $schema ) {
			foreach ( $schema as $field => $definition ) {
				if ( isset( $merged[ $field ] ) ) {
					continue;
				}

				$owners = array_keys( array_filter( $schemas, static fn( array $other ): bool => isset( $other[ $field ] ) ) );

				if ( count( $owners ) < count( $schemas ) ) {
					$definition['description'] = trim( ( $definition['description'] ?? '' ) . ' ' . self::label( $owners[0] ) . ' only.' );
				}

				$merged[ $field ] = $definition;
			}
		}

		return $merged;
	}
}
